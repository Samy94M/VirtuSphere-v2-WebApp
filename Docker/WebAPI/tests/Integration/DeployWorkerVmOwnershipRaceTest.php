<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/DeployVmOwnershipFixture.php';

/** Real two-session barriers: MySQL reports the wait, never a timing guess. */
final class DeployWorkerVmOwnershipRaceTest extends TestCase
{
    use DeployVmOwnershipFixture;

    public function testFailureWaitsForCallbackCommitAndPreservesItsCurrentResult(): void
    {
        $job = $this->claim('export');
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        $second = $this->secondConnection();
        $timeout = (int) repo_scalar($this->db, 'SELECT @@SESSION.innodb_lock_wait_timeout');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->commitImport($job, $second, function () use ($job, $second): void {
                self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYED, $this->state($second)['lifecycle_state']);
                self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->state()['lifecycle_state'], 'Callback has not committed.');
                $this->assertLockWaitTimeout(fn () => deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'late failure'));
                self::assertSame(1, repo_transaction_depth($second), 'The callback transaction must remain open across the competing writer.');
                self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, repo_deploy_job($second, (int) $job['id'])['status']);
            });
            $before = $this->state();
            deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'late failure');
            self::assertSame($before, $this->state());
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, repo_deploy_job($this->db, (int) $job['id'])['status']);
        } finally {
            $second->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
        }
    }

    public function testEarlierRepeatableReadSnapshotCannotHideTheCallbackResultAtFinish(): void
    {
        $job = $this->claim('export');
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        $second = $this->secondConnection();
        try {
            repo_transaction($this->db, function () use ($job, $second): void {
                self::assertNull(repo_scalar($this->db, 'SELECT result_json FROM deploy_jobs WHERE id = ?', 'i', [(int) $job['id']]));
                $this->commitImport($job, $second);
                // Prove this transaction really holds an OLD snapshot before
                // exercising the ownership lock and its current result read.
                self::assertNull(repo_scalar($this->db, 'SELECT result_json FROM deploy_jobs WHERE id = ?', 'i', [(int) $job['id']]));
                deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$this->vmId]);
            });
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, repo_deploy_job($this->db, (int) $job['id'])['status']);
            self::assertSame($this->state($second), $this->state());
            self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYED, $this->state()['lifecycle_state']);
            self::assertNotSame('', $this->state()['mac']);
        } finally {
            $second->close();
        }
    }

    public function testReaperSkipsCallbackLockAndPreservesItsSuccessAfterCommit(): void
    {
        $this->requireExclusiveJobs();
        $job = $this->claim('export');
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        repo_execute($this->db, 'UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?', 'i', [(int) $job['id']]);
        $second = $this->secondConnection();
        try {
            $this->commitImport($job, $second, function (): void {
                self::assertSame([], repo_reap_stale_deploy_jobs($this->db, 60), 'SKIP LOCKED must leave the in-flight callback alone.');
            });
            $before = $this->state();
            self::assertSame([(int) $job['id']], array_map('intval', array_column(repo_reap_stale_deploy_jobs($this->db, 60), 'id')));
            self::assertSame($before, $this->state());
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, repo_deploy_job($this->db, (int) $job['id'])['status']);
        } finally {
            $second->close();
        }
    }

    public function testSuccessorClaimWaitsForReaperVmCommitAndRejectsEveryOldWrite(): void
    {
        $this->requireExclusiveJobs();
        if (!deploy_claim_state_allows_new_work(repo_deploy_claim_state($this->db)['state'])) {
            self::markTestSkipped('The deploy service is paused; this test never changes its policy.');
        }
        $old = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $old, 'old started', [$this->vmId]);
        repo_execute($this->db, 'UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?', 'i', [(int) $old['id']]);
        $queued = $this->claim('start', VIRTUSPHERE_DEPLOY_STATUS_QUEUED);
        // A pre-existing stagger slot may wait behind the running job.
        repo_execute($this->db, 'UPDATE deploy_jobs SET locked_by = NULL, lock_token = NULL, worker_epoch = NULL, heartbeat_at = NULL, attempts = 0 WHERE id = ?', 'i', [(int) $queued['id']]);
        $second = $this->secondConnection();
        try {
            repo_transaction($this->db, function () use ($old, $second): void {
                $reaped = repo_reap_stale_deploy_jobs($this->db, 60);
                self::assertSame([(int) $old['id']], array_map('intval', array_column($reaped, 'id')));
                self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->state()['lifecycle_state']);
                self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->state($second)['lifecycle_state'], 'Reaper changes are still uncommitted.');
                $this->assertLockWaitTimeout(fn () => repo_claim_next_deploy_job($second, 'phpunit:successor'));
            });
            $next = repo_claim_next_deploy_job($second, 'phpunit:successor');
            self::assertNotNull($next);
            self::assertSame((int) $queued['id'], (int) $next['id']);
            deploy_worker_mark_vms_deploying($second, $next, 'successor started', [$this->vmId]);
            $before = $this->state();
            deploy_worker_handle_cancelled($this->db, $old, [$this->vmId]);
            deploy_worker_handle_failure($this->db, $old, self::WORKER, [$this->vmId], 'old failure');
            self::assertSame(0, deploy_worker_mark_vms_failed($this->db, $old, 'old reaper continuation', [$this->vmId]));
            self::assertSame($before, $this->state());
            self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->state()['lifecycle_state']);
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, repo_deploy_job($second, (int) $next['id'])['status']);
        } finally {
            $second->close();
        }
    }

    private function secondConnection(): mysqli
    {
        $second = new mysqli(envboot_required('DB_HOST'), envboot_required('DB_USER'), envboot_required('DB_PASS'),
            envboot_required('DB_NAME'), (int) envboot_optional('DB_PORT', '3306'));
        $second->set_charset('utf8mb4');
        $second->query("SET time_zone = '+00:00'");
        $second->query('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertNotSame($this->db->thread_id, $second->thread_id, 'The barrier needs two independent server sessions.');

        return $second;
    }

    private function assertLockWaitTimeout(callable $contender): void
    {
        try {
            $contender();
            self::fail('The competing owner must wait while the first transaction owns its mission.');
        } catch (mysqli_sql_exception $error) {
            self::assertSame(1205, $error->getCode(), 'Require actual MySQL lock-wait evidence, not another failure.');
        }
    }

    private function requireExclusiveJobs(): void
    {
        if ((int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE status IN (?, ?, ?)', 'sss',
            VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES) > 0) {
            self::markTestSkipped('Foreign active jobs exist; global reaper/claim must not mutate them.');
        }
    }
}
