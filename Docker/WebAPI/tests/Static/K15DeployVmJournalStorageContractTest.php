<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class K15DeployVmJournalStorageContractTest extends TestCase
{
    public function testMigrationHasImmutableOwnerAndPerVmEvidenceWithoutHistoryBackfill(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/lib/migrations/0060_deploy_vm_prestate.php');
        self::assertIsString($migration);
        foreach ([
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'job_id INT NOT NULL',
            'vm_id INT NOT NULL',
            'lifecycle_before VARCHAR(32) NOT NULL',
            'mecm_before VARCHAR(32) NOT NULL',
            'marked_deploying TINYINT(1) NOT NULL DEFAULT 0',
            'esxi_touched TINYINT(1) NOT NULL DEFAULT 0',
            'create_launch_intent TINYINT(1) NOT NULL DEFAULT 0',
            'touch_phase VARCHAR(32) NULL',
            'UNIQUE KEY deploy_job_vm_state_unique (job_id, vm_id)',
            'INDEX deploy_job_vm_state_owner (vm_id, id)',
        ] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        self::assertStringNotContainsString('INSERT INTO deploy_job_vm_states SELECT', $migration);
    }

    public function testCaptureNeverReplacesOriginalPrestateAndTouchWritesOnlyJournalEvidence(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php');
        self::assertIsString($repository);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE job_id = deploy_job_vm_states.job_id', $repository);
        self::assertStringNotContainsString('ON DUPLICATE KEY UPDATE lifecycle_before', $repository);
        self::assertStringContainsString("['powercycle', 'export']", $repository);
        self::assertStringContainsString('[VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING]', $repository);
        self::assertStringContainsString('UPDATE deploy_job_vm_states SET esxi_touched = 1', $repository);
        self::assertStringNotContainsString('UPDATE deploy_vms', $repository);
    }

    public function testJournalConvergenceIsOneOwnerAndHonorsUnknownEvidenceAndImportedSuccess(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_vm_journal.php');
        $sweep = file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_job_maintenance.php');
        self::assertIsString($worker);
        self::assertIsString($sweep);
        self::assertStringContainsString("$" . "state['create_launch_intent']", $worker);
        self::assertStringContainsString("$" . "unit['async_jid']", $worker);
        self::assertStringContainsString("['successful_vm_ids']", $worker);
        self::assertStringContainsString('deploy_worker_vm_journal_generation_matches', $worker);
        self::assertStringContainsString('deploy_worker_restore_locked_vm_journal', $sweep);
        self::assertStringContainsString('deploy_worker_apply_locked_vm_journal', $sweep);
        self::assertStringContainsString('ORDER BY id DESC', $sweep);
    }
}
