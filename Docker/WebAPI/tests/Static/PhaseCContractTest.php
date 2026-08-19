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

        foreach (['mecm-api.php', 'mecm_updateid.php', 'mecm_packages.php', 'mecm_report.php', 'mecm_client_ack.php', 'db_importMAC.php'] as $file) {
            $source = $this->source($file);
            self::assertStringContainsString('machine_api_log_warning', $source, $file);
            self::assertStringContainsString("'Interner Serverfehler'", $source, $file);
        }
    }

    public function testReportChannelStaysDisplayOnlyAndTokenIsHashed(): void
    {
        // ADR-0018: the report channel never mutates VM lifecycle state. The
        // separate explicit ACK owns that write (ADR-0019/E3).
        $report = $this->source('mecm_report.php');
        self::assertStringNotContainsString('repo_set_vm_state', $report);
        self::assertStringContainsString('machine_api_report_token_ok', $report);

        // The report token gate covers the server sync channels (heartbeat and
        // the reportRun result channel) but never the client phase reports
        // (reportPhase), which authenticate by known MAC only, so the token never
        // has to be provisioned onto ephemeral deploy VMs (ADR-0018).
        self::assertStringContainsString("\$action === 'heartbeat' || \$action === 'reportRun'", $report);
        self::assertStringContainsString('!machine_api_report_token_ok(', $report);
        // reportRun is display-only telemetry too: it records run reports, never
        // VM lifecycle state.
        self::assertStringContainsString('repo_record_run_report', $report);

        $read = $this->source('mecm-api.php');
        self::assertStringNotContainsString('repo_set_vm_state', $read, 'getDeviceInfos must remain a side-effect-free GET');
        self::assertStringNotContainsString('getMissionName', $read, 'the redundant E3 action must stay retired');
        $ack = $this->source('mecm_client_ack.php');
        self::assertStringContainsString('repo_set_vm_state_forward', $ack);
        self::assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $ack);

        $machineApi = $this->source('lib/machine_api.php');
        self::assertStringContainsString('hash_equals', $machineApi);
        self::assertStringContainsString('machine_api_audit_warning', $machineApi);
        self::assertStringContainsString('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH', $machineApi);

        // The plaintext token must never be persisted - only its hash.
        $settings = $this->source('portal/settings.php');
        self::assertStringContainsString("hash('sha256', \$token)", $settings);
    }

    public function testMaintenanceWorkerOwnsRetentionAndHasNoProbe(): void
    {
        // ADR-0018: retention no longer rides on request handling.
        self::assertStringNotContainsString('removeLog($connection);', $this->source('function.php'));

        $worker = $this->source('lib/maintenance_tasks.php');
        self::assertStringContainsString('repo_purge_client_events', $worker);
        self::assertStringContainsString('removeLog', $worker);

        // The TCP-445 reachability probe was replaced by reportRun result reports
        // and the site-health task (ADR-0018 amendment). No probe/socket path may
        // remain in the active MECM runtime.
        self::assertStringNotContainsString('mecm_probe', $worker);
        self::assertStringNotContainsString('maintenance_worker_tcp_check', $worker);
        self::assertStringNotContainsString('stream_socket_client', $worker);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/lib/mecm_probe.php');
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
        self::assertStringContainsString('virtusphere_normalize_mac', $this->source('mecm_client_ack.php'));
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
        $users .= $this->source('lib/users_admin.php');
        self::assertStringContainsString('function user_is_last_active_local_admin', $users);
        self::assertStringContainsString("__t('users.err_last_local_admin')", $users);
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
        // Page plus its helper modules, for the same reason the panel scan below
        // globs: the mapper moved into lib/credentials_test_message.php when the
        // page hit its line budget, and a check that names one file would have
        // gone quiet about the code it was written to guard instead of failing.
        $credentials = $this->source('portal/credentials.php');
        foreach (glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/credentials_*.php') ?: [] as $module) {
            $credentials .= (string) file_get_contents($module);
        }
        self::assertStringContainsString('function credentials_test_message', $credentials);
        self::assertStringContainsString('connection_error_message($result[', $credentials);
        self::assertStringNotContainsString("\$result['message']", $credentials);

        // The fetch-state category on the system status page is localized too,
        // rather than printing the raw VARCHAR of the state row. Read across all
        // system-status modules, so which module owns the ESXi card stays a
        // layout decision instead of silently disarming this check. The pattern
        // deliberately stops at "system_status_" rather than "*panels.php": the
        // Ansible mission-activity presenter left the panels file for its own
        // module (Etappe 3) and would have fallen out of this surface.
        $systemStatus = '';
        foreach (glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/system_status_*.php') ?: [] as $panelModule) {
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

        $repo = $this->deployJobRepoSource();
        self::assertStringContainsString('function repo_reap_stale_deploy_jobs', $repo);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $repo);
        self::assertStringContainsString('WHERE id = ? AND locked_by = ? AND status = ?', $repo);

        $worker = $this->workerSource();
        self::assertStringContainsString('deploy_worker_reap_stale_jobs($db);', $worker);
        self::assertStringContainsString('deploy_worker_heartbeat_tick(', $worker);
        self::assertStringContainsString('deploy_worker_log_stream_chunk', $worker);

        // The deploy preflight probes the real MAC return route (portal +
        // allowlist) exactly for the modes whose sequence uploads MACs: a full
        // deploy on a host that cannot reach the portal must fail its preflight
        // instead of stranding VMs at stage 2/5, while a create-only job must
        // not be failed for a route it never uses. The inventory path stays
        // probe-less on purpose (no callback). B6: both call sites used to pass
        // nothing at all.
        self::assertStringContainsString('ansible_mode_expects_mac_result(', $worker);
        self::assertStringContainsString('ansible_preflight_command($preflightApiBaseUrl)', $worker);
        self::assertStringNotContainsString('ansible_preflight_command()', $worker);

        // A non-zero preflight exit code carries its own evidence, so the
        // category is set BEFORE the throw and the outer transport classifier
        // cannot overwrite it (Etappe 8). It names the preflight, not the
        // connection: what failed is a component ON the Ansible host, and the
        // transitional `ssh` sent the operator to the network instead.
        self::assertMatchesRegularExpression(
            '/\$preflightExit !== 0\) \{.*?\$failCategory = VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_PREFLIGHT;.*?throw new RuntimeException/s',
            $worker,
            'the inventory preflight must set ansible_preflight before it throws'
        );
        self::assertStringNotContainsString('$failCategory = VIRTUSPHERE_INVENTORY_ERROR_SSH;', $worker);

        // Thrown inventory failures are classified by phase, never by the old
        // parse-fallback, and failure messages leave through the secret
        // redactor (B6; DeployWorkerFailureClassificationTest proves the map).
        self::assertStringContainsString('deploy_worker_classify_inventory_failure($phase', $worker);
        self::assertStringNotContainsString('?? VIRTUSPHERE_INVENTORY_ERROR_PARSE', $worker);
        self::assertStringContainsString('deploy_worker_redact_secrets($exception->getMessage()', $worker);

        // The tick lives in the requireable outcome module (the entrypoint runs
        // its loop on require), and it must keep carrying the integration report:
        // that is what keeps the System status row green through one long remote
        // step (WorkerTrafficLightTest proves the behaviour; this pins the wiring).
        $outcome = $this->workerSource();
        self::assertStringContainsString('function deploy_worker_heartbeat_tick', $outcome);
        self::assertMatchesRegularExpression(
            '/function deploy_worker_heartbeat_tick.*?deploy_worker_report_alive\(\$db\);/s',
            $outcome,
            'the transport tick must report the integration source, or a long playbook turns the ampel red'
        );

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

    /**
     * The deploy worker is a facade plus domain modules (ADR-0006 amendment
     * 2026-08-11). The phase wiring, preflight probe and redaction assertions
     * below are about the worker layer, not about one filename, so they read
     * the owner registry; DeployWorkerModuleContractTest keeps that registry
     * and the filesystem in agreement.
     */
    private function workerSource(): string
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy_worker_modules.php';

        $source = '';
        foreach (VIRTUSPHERE_DEPLOY_WORKER_MODULES as $module) {
            $source .= $this->source($module) . "\n";
        }
        self::assertNotSame('', $source, 'the deploy worker module registry produced no source.');

        return $source;
    }

    /**
     * The deploy job repository is a facade over domain modules (ADR-0006
     * amendment 2026-08-11); reading the facade alone would leave the reaper
     * and ownership assertions above checking an empty surface.
     */
    private function deployJobRepoSource(): string
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_modules.php';

        $source = '';
        foreach (VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES as $module) {
            $source .= $this->source($module) . "\n";
        }
        self::assertNotSame('', $source, 'the deploy job repo module registry produced no source.');

        return $source;
    }
}
