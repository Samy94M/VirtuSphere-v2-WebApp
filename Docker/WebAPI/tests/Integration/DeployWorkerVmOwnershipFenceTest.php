<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . '/Support/DeployVmOwnershipFixture.php';

/** SC-011: execute the real finish/cancel writers against a persisted claim. */
final class DeployWorkerVmOwnershipFenceTest extends TestCase
{
    use DeployVmOwnershipFixture;

    public function testOldCancelFailureAndRestoreCannotTouchSuccessor(): void
    {
        $old = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $old, 'old started', [$this->vmId]);
        self::assertTrue(repo_finish_deploy_job($this->db, (int) $old['id'], self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_FAILED));
        $next = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $next, 'next started', [$this->vmId]);
        $before = $this->state();
        deploy_worker_handle_cancelled($this->db, $old, [$this->vmId]);
        deploy_worker_handle_failure($this->db, $old, self::WORKER, [$this->vmId], 'old failure');
        self::assertSame(0, deploy_worker_restore_deploying_vms($this->db, $old, 'old restore', [$this->vmId], [$this->vmId => VIRTUSPHERE_LIFECYCLE_READY]));
        self::assertSame([], deploy_worker_mark_vms_deploying($this->db, $old, 'old restart', [$this->vmId]));
        self::assertSame($before, $this->state());
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, repo_deploy_job($this->db, (int) $next['id'])['status']);
    }

    public static function ownershipChanges(): array
    {
        return [
            'worker' => ['locked_by', 'phpunit:replacement'],
            'same worker, new token' => ['lock_token', str_repeat('f', 32)],
            'same token, new lease' => ['worker_epoch', 2],
            'same lease, new attempt' => ['attempts', 2],
            'new execution generation' => ['execution_generation_id', str_repeat('f', 32)],
        ];
    }

    #[DataProvider('ownershipChanges')]
    public function testEveryOwnershipFieldIndependentlyRejectsTheOldClaim(string $field, string|int $value): void
    {
        $old = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $old, 'started', [$this->vmId]);
        $expression = $field === 'execution_generation_id' ? 'UNHEX(?)' : '?';
        repo_execute($this->db, 'UPDATE deploy_jobs SET ' . $field . ' = ' . $expression . ' WHERE id = ?',
            is_int($value) ? 'ii' : 'si', [$value, (int) $old['id']]);
        $before = $this->state();
        self::assertSame(0, deploy_worker_mark_vms_failed($this->db, $old, 'stale', [$this->vmId]));
        self::assertNull(deploy_worker_finish_job($this->db, $old, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_FAILED));
        self::assertSame($before, $this->state());
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, repo_deploy_job($this->db, (int) $old['id'])['status']);
    }

    public function testCallbackCommittedAfterClaimCannotBeOverwrittenByFailure(): void
    {
        $job = $this->claim('export');
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        $this->commitImport($job);
        $before = $this->state();
        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'late failure');
        self::assertSame($before, $this->state());
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, repo_deploy_job($this->db, (int) $job['id'])['status']);
    }

    public function testCurrentCancellationConvergesBeforeTerminalAndIsIdempotent(): void
    {
        $job = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        repo_cancel_deploy_job($this->db, (int) $job['id'], 1);
        deploy_worker_handle_cancelled($this->db, $job, [$this->vmId]);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->state()['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, repo_deploy_job($this->db, (int) $job['id'])['status']);
        $before = $this->state();
        deploy_worker_handle_cancelled($this->db, $job, [$this->vmId]);
        self::assertSame($before, $this->state());
    }

    public function testVmAndTerminalResultRollBackTogether(): void
    {
        $job = $this->claim();
        deploy_worker_mark_vms_deploying($this->db, $job, 'started', [$this->vmId]);
        $before = $this->state();
        try {
            repo_transaction($this->db, function () use ($job): void {
                deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'failure');
                throw new RuntimeException('rollback probe');
            });
        } catch (RuntimeException $error) {
            self::assertSame('rollback probe', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, repo_deploy_job($this->db, (int) $job['id'])['status']);
    }

}
