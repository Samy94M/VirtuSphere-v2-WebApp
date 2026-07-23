<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhaseCContractTest extends TestCase
{
    public function testErrorPagesOnlyExposeDetailsBehindDebugFlag(): void
    {
        $source = $this->source('lib/errors.php');

        self::assertStringContainsString('function virtusphere_debug_enabled()', $source);
        self::assertStringContainsString("envboot_optional('VIRTUSPHERE_DEBUG', '0')", $source);
        self::assertStringContainsString("\$details = ['Reference' => \$refId];", $source);
        self::assertStringContainsString('if ($debug) {', $source);
        self::assertStringContainsString('Stacktrace', $source);
    }

    public function testMachineApiErrorsAndPackageSyncStayGenericAndTypeScoped(): void
    {
        $packages = $this->source('mecm_packages.php');

        self::assertStringContainsString('$hasPackagePayload = false;', $packages);
        self::assertStringContainsString('$hasTaskSequencePayload = false;', $packages);
        self::assertStringContainsString('Package payload absent; leaving deploy_packages untouched.', $packages);
        self::assertStringContainsString('TaskSequence payload absent; leaving deploy_os untouched.', $packages);

        foreach (['mecm-api.php', 'mecm_updateid.php', 'mecm_packages.php', 'mecm_report.php', 'db_importMAC.php'] as $file) {
            $source = $this->source($file);
            self::assertStringContainsString('machine_api_log_warning', $source, $file);
            self::assertStringContainsString("'Interner Serverfehler'", $source, $file);
        }
    }

    public function testReportChannelStaysDisplayOnlyAndTokenIsHashed(): void
    {
        // ADR-0018: the report channel never mutates VM lifecycle state - that
        // stays exclusive to the legacy read surface (mecm-api.php).
        $report = $this->source('mecm_report.php');
        self::assertStringNotContainsString('repo_set_vm_state', $report);
        self::assertStringContainsString('machine_api_report_token_ok', $report);

        // The report token gate is scoped to the server heartbeat channel; client
        // phase reports (reportPhase) authenticate by known MAC only, so the token
        // never has to be provisioned onto ephemeral deploy VMs (ADR-0018).
        self::assertMatchesRegularExpression(
            "/\\\$action === 'heartbeat'\\s*&&\\s*!machine_api_report_token_ok\\(/",
            $report,
            'report token must be enforced for heartbeat only'
        );

        $machineApi = $this->source('lib/machine_api.php');
        self::assertStringContainsString('hash_equals', $machineApi);
        self::assertStringContainsString('machine_api_audit_warning', $machineApi);
        self::assertStringContainsString('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH', $machineApi);

        // The plaintext token must never be persisted - only its hash.
        $settings = $this->source('portal/settings.php');
        self::assertStringContainsString("hash('sha256', \$token)", $settings);
    }

    public function testMaintenanceWorkerOwnsRetentionAndProbe(): void
    {
        // ADR-0018: retention no longer rides on request handling.
        self::assertStringNotContainsString('removeLog($connection);', $this->source('function.php'));

        $worker = $this->source('lib/maintenance_tasks.php');
        self::assertStringContainsString('repo_purge_client_events', $worker);
        self::assertStringContainsString('removeLog', $worker);
        self::assertStringContainsString('maintenance_worker_tcp_check', $worker);
        self::assertStringContainsString('mecm_probe_run', $worker);
        self::assertStringContainsString('repo_mark_integration_failure', $this->source('lib/mecm_probe.php'));
        // Note: docker-compose.yml (which runs this worker) lives outside the
        // container mount and cannot be asserted here.
    }

    public function testDataQualityGuardsArePresent(): void
    {
        // E2: canonical MACs, duplicate guard, global VM names, rename guard.
        $import = $this->source('db_importMAC.php');
        $importPlanner = $this->source('lib/mac_import.php');
        self::assertStringContainsString('virtusphere_normalize_mac', $importPlanner);
        self::assertStringContainsString('duplicate_macs', $import);

        $vms = $this->source('lib/repo/vms.php');
        self::assertStringContainsString('function repo_vm_name_conflict_global', $vms);
        self::assertStringContainsString('netbiosHostname', $vms);

        $missions = $this->source('lib/repo/missions.php');
        self::assertStringContainsString('function repo_mission_has_mecm_active_vms', $missions);
        self::assertStringContainsString('validate.mission_rename_mecm_locked', $missions);
        self::assertStringContainsString('validate.template_clone_name_conflicts', $missions);

        // Lookups must canonicalize before matching.
        self::assertStringContainsString('virtusphere_normalize_mac', $this->source('lib/machine_api.php'));
        self::assertStringContainsString('virtusphere_normalize_mac', $this->source('mecm-api.php'));
        self::assertStringContainsString('virtusphere_normalize_mac', $this->source('mecm_report.php'));
    }

    public function testPackageSyncRetiresInsteadOfDeleting(): void
    {
        // E3: the destructive DELETE (which cascaded into deploy_vm_packages)
        // must never come back; retire + guard + relink replace it.
        $packages = $this->source('mecm_packages.php');
        self::assertStringNotContainsString('DELETE FROM deploy_packages', $packages);
        self::assertStringNotContainsString('DELETE FROM deploy_os', $packages);
        self::assertStringContainsString('function catalog_retire_missing', $packages);
        self::assertStringContainsString('function packages_retire_guard', $packages);
        self::assertStringContainsString('function packages_relink_upgrades', $packages);
        self::assertStringContainsString('retired_at = NULL', $packages);

        $catalog = $this->source('lib/repo/catalog.php');
        self::assertStringContainsString('function repo_package_split_name', $catalog);
        self::assertStringContainsString('function repo_purge_retired_packages', $catalog);
    }

    public function testPortalGuardsAndI18nContractsArePresent(): void
    {
        $users = $this->source('portal/users.php');
        self::assertStringContainsString('function user_is_last_active_admin', $users);
        self::assertStringContainsString("__t('users.err_last_admin')", $users);
        self::assertStringContainsString('portal_error_message($exception)', $users);

        $login = $this->source('portal/login.php');
        self::assertStringContainsString("__t('login.error_ip_locked')", $login);
        self::assertStringContainsString('value="<?php echo h($username); ?>"', $login);

        $validate = $this->source('lib/validate.php');
        self::assertStringContainsString("validator_text('validate.", $validate);
        self::assertStringContainsString("require_once __DIR__ . '/lang.php';", $validate);
    }

    public function testConnectionTestResultIsLocalizedNotEchoed(): void
    {
        // credential_test_connection() returns a code plus an operator detail.
        // A page that echoes a 'message' key instead is both untranslated and,
        // since that key no longer exists, a fatal on every connection test.
        $credentials = $this->source('portal/credentials.php');
        self::assertStringContainsString('function credentials_test_message', $credentials);
        self::assertStringContainsString('connection_error_message($result[', $credentials);
        self::assertStringNotContainsString("\$result['message']", $credentials);

        // The fetch-state category on the system status page is localized too,
        // rather than printing the raw VARCHAR of the state row. Read across all
        // panel modules, so which module owns the ESXi card stays a layout
        // decision instead of silently disarming this check.
        $systemStatus = '';
        foreach (glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/system_status_*panels.php') ?: [] as $panelModule) {
            $systemStatus .= (string) file_get_contents($panelModule);
        }
        self::assertNotSame('', $systemStatus, 'no system status panel module found');
        self::assertStringContainsString('connection_error_message(', $systemStatus);
        self::assertStringNotContainsString("h((string) (\$state['last_error_category'] ?? ''))", $systemStatus);
    }

    public function testHealthMacIndexAndDeployReaperContractsArePresent(): void
    {
        self::assertStringContainsString('Service temporarily unavailable', $this->source('portal/health.php'));
        self::assertStringContainsString('[health]', $this->source('portal/health.php'));
        self::assertStringContainsString('deploy_interfaces_mac_lookup', $this->source('lib/migrate.php'));

        $repo = $this->source('lib/repo/deploy_jobs.php');
        self::assertStringContainsString('function repo_reap_stale_deploy_jobs', $repo);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $repo);
        self::assertStringContainsString('WHERE id = ? AND locked_by = ? AND status = ?', $repo);

        $worker = $this->source('lib/deploy_worker.php');
        self::assertStringContainsString('deploy_worker_reap_stale_jobs($db);', $worker);
        self::assertStringContainsString('function deploy_worker_heartbeat_tick', $worker);
        self::assertStringContainsString('deploy_worker_log_stream_chunk', $worker);

        self::assertGreaterThan(
            VIRTUSPHERE_POWERCYCLE_WAIT_MAX,
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            'The stale-job threshold must exceed the longest configured silent powercycle wait.'
        );
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
