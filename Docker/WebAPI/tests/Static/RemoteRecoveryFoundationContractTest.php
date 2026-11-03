<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RemoteRecoveryFoundationContractTest extends TestCase
{
    public function testFoundationIsNotWiredIntoCurrentReaperOrMaintenanceWorker(): void
    {
        $root = dirname(__DIR__, 2) . '/lib';
        foreach (['deploy_worker_reaper.php', 'maintenance_tasks.php', 'maintenance_worker.php'] as $path) {
            $source = (string) file_get_contents($root . '/' . $path);
            self::assertStringNotContainsString('deploy_remote_recovery.php', $source, $path);
            self::assertStringNotContainsString('repo_remote_recovery_candidates', $source, $path);
            self::assertStringNotContainsString('repo_request_remote_recovery', $source, $path);
        }
    }

    public function testRecoveryRequestNeverTerminalizesOrDeletesEvidence(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_remote_recovery.php');
        self::assertStringContainsString('function repo_request_remote_recovery(', $source);
        self::assertStringContainsString('recovery_requested_at = COALESCE(recovery_requested_at, NOW())', $source);
        self::assertStringContainsString('locked_by = NULL, lock_token = NULL, worker_epoch = NULL', $source);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|status\s*=\s*[?\'\"]?(?:failed|cancelled|succeeded|partial))\b/i', $source);
    }

    public function testFutureVmSweepCandidateQueryProtectsRecoveryAndManualReview(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_remote_recovery.php');
        self::assertStringContainsString('j.recovery_requested_at IS NOT NULL', $source);
        self::assertStringContainsString("r.reconciliation_state IN ('pending','running','manual_required')", $source);
        self::assertStringContainsString("j.status IN ('queued','running','cancelling')", $source);
    }
}
