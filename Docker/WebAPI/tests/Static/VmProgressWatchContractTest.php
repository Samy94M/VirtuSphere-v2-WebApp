<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cross-layer guard for the two dedicated, non-destructive VM progress clocks. */
final class VmProgressWatchContractTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    public function testFreshAndUpgradeSchemaCarryBothClocksAndTheirLookupIndexes(): void
    {
        $schema = $this->source('../mysql/mysql-init/struktur.sql');
        $migration = $this->source('lib/migrate.php');

        foreach (['mecm_pending_since', 'os_install_watch_started_at'] as $column) {
            self::assertStringContainsString($column, $schema);
            self::assertStringContainsString($column, $migration);
        }
        self::assertStringContainsString("'0038_vm_progress_watch'", $migration);
        self::assertStringContainsString('deploy_vms_mecm_pending_watch', $schema);
        self::assertStringContainsString('deploy_vms_os_install_watch', $schema);
    }

    public function testEveryRealStateBoundaryMaintainsTheDedicatedClocks(): void
    {
        $macImport = $this->source('db_importMAC.php');
        $stateWriter = $this->source('lib/repo/status_events.php');
        $vmRepo = $this->source('lib/repo/vms.php');

        self::assertStringContainsString('mecm_pending_since = COALESCE(mecm_pending_since, NOW())', $macImport);
        self::assertStringContainsString('os_install_watch_started_at = NULL', $macImport);
        self::assertStringContainsString('mecm_pending_since = NOW()', $vmRepo);
        self::assertStringContainsString('os_install_watch_started_at = NULL', $vmRepo);
        self::assertStringContainsString('mecm_pending_since = IF(', $stateWriter);
        self::assertStringContainsString('os_install_watch_started_at = IF(', $stateWriter);
    }

    public function testMaintenanceNeverTurnsAnOverdueObservationIntoFailureOrDeletion(): void
    {
        $maintenance = $this->source('lib/maintenance_tasks.php');

        self::assertStringNotContainsString('repo_fail_stale_vm_progress', $maintenance);
        self::assertStringNotContainsString('repo_delete_stale_vm_progress', $maintenance);
        self::assertStringContainsString('display-only', $this->source('lib/vm_progress.php'));
    }

    public function testPortalOffersTheExplicitObservationActionAndBrowserProof(): void
    {
        $portal = $this->source('portal/vms.php');
        $e2e = $this->source('../../tests/e2e/specs/crud-vm.spec.js');

        self::assertStringContainsString("action === 'restart_progress_watch'", $portal);
        self::assertStringContainsString('e2e-covers: vm_edit.php:restart_progress_watch', $e2e);
        self::assertStringContainsString('e2e-covers-cancel: vm_edit.php:restart_progress_watch', $e2e);
    }
}
