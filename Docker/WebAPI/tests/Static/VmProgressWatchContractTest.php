<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cross-layer guard for the two dedicated, non-destructive VM progress clocks. */
final class VmProgressWatchContractTest extends TestCase
{
    /**
     * A file this contract reads must actually be there. `file_get_contents()`
     * on a missing path returns false, and an empty haystack makes every
     * assertStringNotContainsString() below vacuously true: the guard would go
     * permanently, silently green instead of red.
     */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, 'this contract reads ' . $relative . '; an unreadable file would pass its negative assertions');
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source, $relative . ' is empty; the scan would match nothing and prove nothing');

        return $source;
    }

    /**
     * The PHP container mounts only `Docker/WebAPI`, so a file above it is
     * genuinely absent there rather than wrong. Those assertions belong to the
     * repo-root run (see docs/QA.md); skipping keeps the documented container
     * command green without pretending the check ran.
     */
    private function sourceAboveTheMount(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            self::markTestSkipped('Repo root not visible; ' . $relative . ' only exists outside the container mount.');
        }

        return $this->source($relative);
    }

    public function testFreshAndUpgradeSchemaCarryBothClocksAndTheirLookupIndexes(): void
    {
        $schema = $this->sourceAboveTheMount('../mysql/mysql-init/struktur.sql');
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
        $e2e = $this->sourceAboveTheMount('../../tests/e2e/specs/crud-vm.spec.js');

        self::assertStringContainsString("action === 'restart_progress_watch'", $portal);
        self::assertStringContainsString('e2e-covers: vm_edit.php:restart_progress_watch', $e2e);
        self::assertStringContainsString('e2e-covers-cancel: vm_edit.php:restart_progress_watch', $e2e);
    }
}
