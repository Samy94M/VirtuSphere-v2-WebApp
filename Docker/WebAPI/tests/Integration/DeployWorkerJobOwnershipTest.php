<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
// deploy_worker.php is the CLI entrypoint and runs its main loop on require;
// the stop machinery lives in the outcome module for exactly that reason.
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

/**
 * The worker's stop condition, in all four states it has to recognise.
 *
 * It used to read: "if the row is there AND says cancelled, stop". Three of the
 * four ways a job stops being this worker's therefore passed:
 *
 *  - the row is GONE (its mission was deleted mid-run). The worker carried on
 *    and then failed on a foreign key while writing its next log line, which the
 *    error categorizer reports as a remote problem: an operator reads "the host
 *    answered unexpectedly" for a row somebody deleted in the portal.
 *  - the row is no longer `running` because the heartbeat reaper concluded it.
 *    The reaper has already published a verdict and marked the VMs; a worker
 *    that keeps going overwrites both.
 *  - the row was adopted by ANOTHER worker after that reap, so two workers drive
 *    the same playbook sequence against the same VMs.
 *
 * All four raise DeployWorkerCancelled, because the outcome is the same: this
 * worker stops without publishing a result of its own.
 */
final class DeployWorkerJobOwnershipTest extends TestCase
{
    private const WORKER = 'phpunit:ownership-worker';

    private mysqli $db;
    private string $prefix;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_owner_' . bin2hex(random_bytes(4));
        $this->missionId = $this->insertMission();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->missionId === 0) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();
    }

    public function testAJobRunningUnderThisWorkerPasses(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);

        // No exception is the assertion; the status read afterwards proves the
        // check left the job alone rather than concluding it itself.
        deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);

        self::assertSame(
            VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
            (string) repo_scalar($this->db, 'SELECT status FROM deploy_jobs WHERE id = ?', 'i', [$jobId])
        );
    }

    public function testACancelledJobStopsTheWorker(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, self::WORKER);

        $this->expectException(DeployWorkerCancelled::class);
        $this->expectExceptionMessageMatches('/cancelled/i');
        deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
    }

    public function testAVanishedJobStopsTheWorkerAndSaysSo(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        $this->deleteJob($jobId);

        try {
            deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
            self::fail('a job whose row is gone must stop the worker');
        } catch (DeployWorkerCancelled $stop) {
            self::assertStringContainsString('no longer exists', $stop->getMessage());
            self::assertStringContainsString((string) $jobId, $stop->getMessage(), 'the message must name the job');
        }
    }

    public function testAReapedJobStopsTheWorker(): void
    {
        // What repo_reap_stale_deploy_jobs() leaves behind: a failed job with its
        // own verdict already written.
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED, self::WORKER);

        try {
            deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
            self::fail('a job the reaper concluded must stop the worker');
        } catch (DeployWorkerCancelled $stop) {
            self::assertStringContainsString('no longer running', $stop->getMessage());
        }
    }

    public function testAJobAdoptedByAnotherWorkerStopsThisOne(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'phpunit:other-worker');

        try {
            deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
            self::fail('a job locked by another worker must stop this one');
        } catch (DeployWorkerCancelled $stop) {
            self::assertStringContainsString('phpunit:other-worker', $stop->getMessage());
        }
    }

    /**
     * The stop path itself must not need the row it is reporting about: the log
     * table references deploy_jobs, so a line about a deleted job would fail on
     * a foreign key and turn a clean stop into a crash.
     */
    public function testTheStopHandlerSurvivesAJobThatIsAlreadyGone(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYING);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        $this->deleteJob($jobId);

        deploy_worker_handle_cancelled($this->db, $job, [$vmId], 'Deploy job ' . $jobId . ' no longer exists.');

        // The VM convergence still runs: those VMs are really left in `deploying`
        // and nothing else is coming to move them.
        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->vmLifecycle($vmId));
    }

    private function insertMission(): int
    {
        $name = $this->prefix . '_m';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertVm(string $lifecycleState): int
    {
        $name = strtoupper($this->prefix . '_VM');
        $sync = VIRTUSPHERE_MECM_SYNC_NOT_READY;
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $this->missionId, $name, $name, $lifecycleState, $sync);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertJob(string $status, string $lockedBy): int
    {
        $payload = json_encode(['mode' => 'full', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, heartbeat_at) VALUES (?, ?, ?, NOW(), ?, NOW())');
        $stmt->bind_param('isss', $this->missionId, $status, $payload, $lockedBy);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function deleteJob(int $jobId): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
    }

    private function vmLifecycle(int $vmId): string
    {
        return (string) repo_scalar($this->db, 'SELECT lifecycle_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
    }
}
