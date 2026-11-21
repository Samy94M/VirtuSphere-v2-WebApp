<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/vms_modules.php';

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
        $vmRepo = implode("\n", array_map(
            fn (string $module): string => $this->source($module),
            VIRTUSPHERE_VM_REPO_MODULES
        ));

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
        // The automatic start has its own browser proof: the source line the editor shows.
        // Since VB2 the "check progress" block is a live region with its own renderer.
        self::assertStringContainsString('data-progress-source', $this->source('lib/vm_edit_live.php'));
        self::assertStringContainsString('vm_edit_live_regions(', $this->source('lib/vm_edit_page.php'));
        self::assertStringContainsString('[data-progress-source]', $e2e);
    }

    /**
     * The OS-install clock is started in exactly one module (ADR-0038
     * amendment 2). Derived from every PHP source below the WebAPI root, not
     * from a list: a second place that sets the clock would start an
     * observation the evidence rule never saw.
     */
    public function testTheInstallClockIsStartedInOneModuleOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $writers = [];
        $clearers = [];
        $scanned = 0;
        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests)/#', $path) === 1) {
                continue;
            }
            $scanned++;
            // Every SQL assignment of the column counts, whatever its right side.
            // Two forms never START a clock and are the known clearing writers:
            // `= NULL`, and the state writer's keep-or-clear `IF(..., <column>, NULL)`.
            preg_match_all('/os_install_watch_started_at\s*=(?!=)\s*(.{0,80})/', (string) file_get_contents($file->getPathname()), $assignments);
            foreach ($assignments[1] as $rightSide) {
                $clearing = preg_match('/^NULL\b/', $rightSide) === 1
                    || str_starts_with($rightSide, "IF(? IN ('os_installing','deploying'), os_install_watch_started_at, NULL)");
                if ($clearing) {
                    $clearers[$path] = true;
                } else {
                    $writers[$path] = true;
                }
            }
        }

        self::assertGreaterThan(100, $scanned, 'the scan saw too few files to prove anything');
        self::assertSame(['lib/repo/vms_progress_watch.php'], array_keys($writers));
        // The scan must still see the clearing writers, or its pattern has gone blind.
        foreach (['db_importMAC.php', 'lib/repo/status_events.php', 'lib/repo/vms_mecm_reset.php'] as $known) {
            self::assertArrayHasKey($known, $clearers, 'the scan no longer sees the assignment in ' . $known);
        }
    }

    /**
     * The automatic writer fills an EMPTY clock of a registered, installing VM
     * and leaves updated_at alone; its one decision is the pure evidence rule.
     */
    public function testTheAutomaticStartOnlyFillsAnEmptyClockAndDecidesThroughTheEvidenceRule(): void
    {
        $module = $this->source('lib/repo/vms_progress_watch.php');
        $start = strpos($module, 'function repo_start_vm_install_watch_on_evidence(');
        $end = strpos($module, 'function repo_start_install_watches_after_job(');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $writer = substr($module, $start, $end - $start);

        self::assertStringContainsString(
            'SET os_install_watch_started_at = NOW(), updated_at = updated_at WHERE id = ? AND os_install_watch_started_at IS NULL AND lifecycle_state = ? AND mecm_sync_state = ?',
            $writer
        );
        self::assertSame(1, substr_count($writer, 'UPDATE deploy_vms'), 'the automatic start writes one column of one row');
        self::assertStringContainsString('FOR UPDATE OF v', $writer, 'the decision is taken under the VM row lock');
        self::assertSame(1, substr_count($writer, 'virtusphere_vm_install_watch_auto_source('));
        self::assertStringNotContainsString('virtusphere_vm_power_evidence(', $module, 'the writers ask the gated rule, never the bare evidence');
        self::assertStringContainsString('repo_record_vm_status_event(', $writer, 'the status note is the only stored record of the source');
        // The evidence rule itself stays pure: no database handle reaches it.
        self::assertStringNotContainsString('mysqli', $this->source('lib/vm_progress.php'));
    }

    /**
     * Three triggers, one writer. The registration reads the rollout fence
     * before its state write clears the pending clock and calls the writer
     * inside its transaction; the worker's two triggers run after their own
     * result is published. `start` and `autostart` still own no VM state.
     */
    public function testTheThreeTriggersCallTheOneWriter(): void
    {
        $registration = $this->source('mecm_updateid.php');
        $fence = strpos($registration, '$watchFence = virtusphere_vm_rollout_fence($vm);');
        $stateWrite = strpos($registration, 'repo_set_vm_state_forward($connection, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING');
        $trigger = strpos($registration, 'repo_start_vm_install_watch_at_registration($connection, $vmId, $watchFence);');
        $answer = strpos($registration, "return ['status' => 'ok', 'vm' => \$vm];", (int) $stateWrite);
        self::assertIsInt($fence, 'the registration no longer reads the rollout fence');
        self::assertIsInt($stateWrite);
        self::assertIsInt($trigger, 'the registration no longer starts the observation');
        self::assertIsInt($answer);
        self::assertLessThan($stateWrite, $fence, 'the fence must be read before the registration clears mecm_pending_since');
        self::assertGreaterThan($stateWrite, $trigger);
        self::assertLessThan($answer, $trigger, 'the clock belongs to the registration\'s transaction');
        self::assertStringNotContainsString(
            'repo_start_vm_install_watch_on_evidence(',
            $registration,
            'the registration calls the fail-soft trigger, never the bare writer: a clock must not cost a binding'
        );
        // The fail-soft trigger swallows everything except a discarded transaction.
        $module = $this->source('lib/repo/vms_progress_watch.php');
        $from = strpos($module, 'function repo_start_vm_install_watch_at_registration(');
        $to = strpos($module, 'function repo_start_install_watches_after_job(');
        self::assertIsInt($from);
        self::assertIsInt($to);
        $atRegistration = substr($module, $from, $to - $from);
        self::assertStringContainsString("repo_start_vm_install_watch_on_evidence(\$db, \$vmId, ['fence' => \$fence]);", $atRegistration);
        self::assertStringContainsString('} catch (Throwable $exception) {', $atRegistration);
        self::assertStringContainsString(
            'if ($exception instanceof mysqli_sql_exception && in_array($exception->getCode(), [1213, 1205], true)) {' . "\n" . '            throw $exception;',
            $atRegistration,
            'after a deadlock or lock wait timeout the transaction is gone; committing the rest would publish an empty middle'
        );

        $finish = $this->source('lib/deploy_worker_finish.php');
        self::assertSame(2, substr_count($finish, 'deploy_worker_after_start_step($db, $job);'), 'the success and the failure conclusion, not the cancel');
        $cancel = substr($finish, (int) strpos($finish, 'function deploy_worker_handle_cancelled('), 600);
        self::assertStringNotContainsString('deploy_worker_after_start_step', $cancel);

        $inventory = $this->source('lib/deploy_worker_inventory.php');
        $recorded = strpos($inventory, 'repo_esxi_inventory_record_success(');
        // The claim time of the pull travels with it: the host was read no earlier.
        $afterInventory = strpos($inventory, "deploy_worker_after_vm_inventory(\$channel->connection(), \$credentialId, \$parsed, (string) (\$job['locked_at'] ?? ''));");
        self::assertIsInt($recorded);
        self::assertIsInt($afterInventory, 'an applied inventory no longer starts the observation');
        self::assertGreaterThan($recorded, $afterInventory, 'only a committed pull is evidence');

        $worker = $this->source('lib/deploy_worker_install_watch.php');
        self::assertStringContainsString('repo_start_install_watches_after_job(', $worker);
        self::assertStringContainsString('repo_start_install_watches_after_inventory(', $worker);
        self::assertSame(2, substr_count($worker, '} catch (Throwable $exception) {'), 'both worker triggers are fail-soft: a clock never costs a job its result');
        self::assertStringNotContainsString('UPDATE deploy_vms', $worker);

        self::assertStringContainsString(
            "return in_array(\$mode, ['start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART], true);",
            $this->source('lib/deploy_worker_vm_journal.php'),
            'start and autostart own no lifecycle or MECM state; the clock did not change that'
        );
    }
}
