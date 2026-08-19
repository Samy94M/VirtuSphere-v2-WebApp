<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * The confirmed cancellation state machine (Entscheidung 4, ADR-0033).
 *
 * repo_cancel_deploy_job() used to set even a running job straight to
 * `cancelled` and null its lock and heartbeat. The worker only honours a stop
 * at step boundaries, so for the length of the current playbook the portal
 * showed a terminal job whose playbook was still creating VMs: the mission
 * could be deleted and a new job enqueued OVER a sequence that was still
 * running, and a valid MAC callback of that sequence bounced with 409 (B4).
 *
 * Now: queued cancels directly; running becomes `cancelling` and keeps lock,
 * heartbeat and every protective effect of an active job until the worker
 * confirms at a step boundary (ownership CAS) or the reaper converges a dead
 * worker. cancelled_at means the END state; the wish carries its own
 * timestamp and actor.
 */
final class DeployCancellationStateMachineTest extends TestCase
{
    private const WORKER = 'phpunit:cancel-worker';

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

        $this->prefix = 'phpunit_cancel_' . bin2hex(random_bytes(4));
        $this->missionId = $this->insertMission();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->missionId > 0) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $this->missionId);
            $stmt->execute();
        }
        $like = $this->prefix . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }

    // --- the two cancel transitions -----------------------------------------

    public function testAQueuedCancelIsImmediatelyTerminal(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, null);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, repo_cancel_deploy_job($this->db, $jobId, 42));

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at'], 'a queued job never started; the wish IS the end state');
        self::assertNotNull($job['cancel_requested_at']);
        self::assertSame(42, (int) $job['cancel_requested_by']);
    }

    public function testARunningCancelBecomesCancellingAndKeepsLockAndHeartbeat(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, repo_cancel_deploy_job($this->db, $jobId, 42));

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, (string) $job['status']);
        // The worker still owns the sequence: lock and heartbeat stay, and
        // cancelled_at stays NULL until somebody CONFIRMS the end state.
        self::assertSame(self::WORKER, (string) $job['locked_by']);
        self::assertNotNull($job['heartbeat_at']);
        self::assertNull($job['cancelled_at']);
        self::assertNotNull($job['cancel_requested_at']);
        self::assertSame(42, (int) $job['cancel_requested_by']);
    }

    public function testASecondCancelOfACancellingJobIsIdempotent(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);
        $firstRequestAt = (string) $this->job($jobId)['cancel_requested_at'];

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, repo_cancel_deploy_job($this->db, $jobId, 43));

        $job = $this->job($jobId);
        self::assertSame($firstRequestAt, (string) $job['cancel_requested_at'], 'the first wish keeps its timestamp');
        self::assertSame(42, (int) $job['cancel_requested_by'], 'and its actor');
    }

    public function testATerminalJobCannotBeCancelled(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, null);

        $this->expectException(RuntimeException::class);
        repo_cancel_deploy_job($this->db, $jobId, 42);
    }

    // --- the worker side ----------------------------------------------------

    public function testTheHeartbeatKeepsBeatingWhileCancelling(): void
    {
        // An aged heartbeat, so the touch visibly changes the row (an
        // identical-value UPDATE reports 0 affected rows in the same second).
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER, staleHeartbeat: true);

        // A cancelling job still runs its current step; without the heartbeat
        // the reaper would fail a perfectly alive worker mid-confirmation.
        self::assertTrue(repo_touch_deploy_job_heartbeat($this->db, $jobId, self::WORKER));
        self::assertFalse(repo_touch_deploy_job_heartbeat($this->db, $jobId, 'phpunit:other'));
    }

    public function testTheWorkerConfirmsCancellingViaOwnershipCas(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        // The wrong worker cannot confirm somebody else's stop.
        self::assertFalse(repo_confirm_deploy_job_cancelled($this->db, $jobId, 'phpunit:other'));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, (string) $this->job($jobId)['status']);

        self::assertTrue(repo_confirm_deploy_job_cancelled($this->db, $jobId, self::WORKER));
        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at'], 'confirmation IS the end state');
        self::assertNull($job['locked_by']);
        self::assertNull($job['heartbeat_at']);
    }

    public function testAssertJobIsOursConfirmsTheCancelAndStopsTheWorker(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        try {
            deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
            self::fail('a cancelling job must stop the worker at the step boundary');
        } catch (DeployWorkerCancelled $stop) {
            self::assertStringContainsString('step boundary', $stop->getMessage());
        }

        // The stop is also the confirmation: the job is terminal afterwards.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $this->job($jobId)['status']);
    }

    // --- the last-step race -------------------------------------------------

    /**
     * The race the previously read status cannot decide (Etappe 8): the last
     * playbook succeeds at the same moment a cancel is committed.
     *
     * The terminal write is a compare-and-swap from `running` under this
     * worker's lock, so it finds zero rows here. What must NOT happen is what
     * happened before: the worker logged a lost lock (the lock was still ours)
     * and walked away, leaving the job in `cancelling` until the reaper noticed
     * a heartbeat that had stopped - minutes of a job that is neither running
     * nor finished.
     */
    public function testACancelThatWinsTheRaceIsConfirmedInsteadOfTheSuccess(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        deploy_worker_finish_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at']);
        self::assertNull($job['locked_by']);

        // And the operator learns the one thing "cancelled" alone would hide:
        // the step that was already running did its work on ESXi.
        $log = $this->logText($jobId);
        self::assertStringContainsString('ran to its end', $log);
        self::assertStringContainsString('no further step was started', $log);
        self::assertStringNotContainsString('no longer running under this worker', $log);
    }

    /**
     * The other side of the same race: the terminal swap won first. A cancel
     * POST arriving afterwards must not touch an already finished job, and the
     * result stays the one the run actually produced.
     */
    public function testATerminalSwapThatWinsFirstSurvivesALateCancel(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);

        deploy_worker_finish_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, (string) $this->job($jobId)['status']);

        try {
            repo_cancel_deploy_job($this->db, $jobId, 1);
            self::fail('a job that already finished must not accept a cancel');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('active', $refused->getMessage());
        }
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, (string) $this->job($jobId)['status']);
    }

    /**
     * A job somebody else concluded is not ours to describe. The message states
     * what the row shows and claims no cause: the old wording said the lock was
     * lost even when the status alone had moved on.
     */
    public function testAForeignTerminalStateIsReportedWithoutOverwritingIt(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED, 'phpunit:other');

        deploy_worker_finish_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 'ours');

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, (string) $job['status']);
        $log = $this->logText($jobId);
        self::assertStringContainsString('status ' . VIRTUSPHERE_DEPLOY_STATUS_FAILED, $log);
        self::assertStringContainsString('phpunit:other', $log);
    }

    /**
     * The third reason the terminal swap can find zero rows: the job is gone.
     *
     * `deploy_job_logs` references `deploy_jobs`, so an append about a deleted
     * job fails on a foreign key - which would turn a clean stop into an
     * unexplained crash the operator reads as an ESXi problem. The finish path
     * therefore reports through the guarded helper.
     */
    public function testAVanishedJobIsReportedWithoutWritingAgainstAForeignKey(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        deploy_worker_finish_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);

        self::assertSame('', $this->logText($jobId), 'a deleted job has nowhere to log; the line goes to error_log.');
    }

    private function logText(int $jobId): string
    {
        $stmt = $this->db->prepare('SELECT line FROM deploy_job_logs WHERE job_id = ? ORDER BY seq');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $lines = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $lines[] = (string) $row['line'];
        }

        return implode("\n", $lines);
    }

    // --- reaper convergence -------------------------------------------------

    public function testTheReaperConvergesAStaleCancellingJobToCancelledNotFailed(): void
    {
        $cancellingId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER, staleHeartbeat: true);
        $runningId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER, staleHeartbeat: true);

        // The reaper only trusts an observer that has been connected longer than
        // its grace; an unset one counts as blind, which is the production
        // default on a fresh connection. Declare this observer as long-established.
        deploy_reap_observer_since(time() - VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS - 1);

        deploy_worker_reap_stale_jobs($this->db);

        // The operator asked for the cancel; a dead worker must not repaint
        // that wish as a failure...
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $this->job($cancellingId)['status']);
        self::assertNotNull($this->job($cancellingId)['cancelled_at']);
        // ...while a stale plain running job keeps its failed verdict.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, (string) $this->job($runningId)['status']);
    }

    // --- protective effects stay while cancelling ----------------------------

    public function testCancellingStillBlocksDeleteAndEnqueue(): void
    {
        $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        // Both the mission-delete guard and the enqueue guard read this
        // predicate: while the playbook may still be running, both stay shut.
        self::assertTrue(repo_transaction($this->db, fn (): bool => repo_deploy_active_job_exists($this->db, $this->missionId)));
    }

    public function testASecondSystemJobIsRefusedWhileOneIsCancelling(): void
    {
        $esxiId = $this->insertCredential('esxi', VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleId = $this->insertCredential('ansible', VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $first = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxiId, $ansibleId);
        self::assertNotNull($first);
        $this->setJobStatus($first, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        self::assertNull(
            repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxiId, $ansibleId),
            'a cancelling pull still owns its credential'
        );
    }

    // --- helpers ------------------------------------------------------------

    private function insertMission(): int
    {
        $name = $this->prefix . '_m';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertJob(string $status, ?string $lockedBy, bool $staleHeartbeat = false): int
    {
        $payload = json_encode(['mode' => 'full', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $heartbeat = $staleHeartbeat ? 'DATE_SUB(NOW(), INTERVAL 7200 SECOND)' : 'NOW()';
        if ($lockedBy === null) {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $this->missionId, $status, $payload);
        } else {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, heartbeat_at) VALUES (?, ?, ?, NOW(), ?, ' . $heartbeat . ')');
            $stmt->bind_param('isss', $this->missionId, $status, $payload, $lockedBy);
        }
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertCredential(string $suffix, string $type): int
    {
        $name = $this->prefix . '_' . $suffix;
        $host = 'host.example.com';
        $port = 443;
        $userName = 'svc';
        $secret = 'x';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $userName, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function setJobStatus(int $jobId, string $status, string $lockedBy): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ?, locked_at = NOW(), locked_by = ?, heartbeat_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $status, $lockedBy, $jobId);
        $stmt->execute();
    }

    /** @return array<string, mixed> */
    private function job(int $jobId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT * FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
        self::assertNotNull($row);

        return $row;
    }
}
