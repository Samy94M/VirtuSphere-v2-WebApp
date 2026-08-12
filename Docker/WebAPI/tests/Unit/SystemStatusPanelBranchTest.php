<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Branches of the System status panels that a browser pass cannot reach on a
 * stack that has an ESXi credential and no Ansible one. Each of them used to
 * state something untrue:
 *
 * - no inventory at all was reported as a green "0 deviations", a clean bill of
 *   health for a comparison that never ran;
 * - the deviation count read ":count deviations" for a single one;
 * - a preflight warning reused the failure slot, so a credential whose test
 *   passed was labelled "failed at: allowlist".
 */
final class SystemStatusPanelBranchTest extends TestCase
{
    protected function setUp(): void
    {
        // The panels render timestamps and a CSRF-protected repair form, both of
        // which the portal bootstrap normally supplies.
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_panels.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_esxi_panels.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    /** @param array<string,mixed> $state @param array<string,mixed>|null $pending */
    private function renderEsxi(array $state, ?array $pending = null): string
    {
        $snapshot = ['esxi' => [
            'interval_hours' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT,
            'ansible_selected' => true,
            'deploy_worker_alive' => true,
            'rows' => [[
                'credential' => ['id' => 23, 'name' => 'esxi-23', 'host' => 'esxi-23.example.test'],
                'state' => $state,
                'pending_job' => $pending,
                'counts' => [],
                'health' => (string) ($state['last_status'] ?? '') === 'failed' ? 'warning' : 'ok',
            ]],
        ]];
        ob_start();
        system_status_render_esxi($snapshot, ['id' => 1, 'role' => 'admin'], 0, null);

        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function inventoryState(string $status, ?int $jobId): array
    {
        return [
            'last_status' => $status,
            'last_attempt_at' => '2026-07-26 11:23:57',
            'last_success_at' => $status === 'ok' ? '2026-07-26 11:23:57' : null,
            'last_error_category' => $status === 'failed' ? VIRTUSPHERE_INVENTORY_ERROR_PARSE : null,
            'last_job_id' => $jobId,
            'failure_streak' => $status === 'failed' ? 1 : 0,
            'paused_until_credential_change' => 0,
        ];
    }

    public function testAFailedInventoryResultLinksTheExactJobThatProducedIt(): void
    {
        $html = $this->renderEsxi($this->inventoryState('failed', 9717));

        self::assertStringContainsString('href="deploy_log.php?id=9717"', $html);
        self::assertStringContainsString(h(__t('system_status.inv_open_failed_job_log')), $html);
        self::assertStringNotContainsString(h(__t('system_status.inv_job_log_unavailable')), $html);
    }

    public function testARetainedSuccessfulResultKeepsItsDiagnosticLogReachable(): void
    {
        $html = $this->renderEsxi($this->inventoryState('ok', 9718));

        self::assertStringContainsString('href="deploy_log.php?id=9718"', $html);
        self::assertStringContainsString(h(__t('system_status.inv_open_last_job_log')), $html);
    }

    public function testAnExpiredFailedJobRendersAFallbackInsteadOfADeadLink(): void
    {
        $html = $this->renderEsxi($this->inventoryState('failed', null));

        self::assertStringContainsString(h(__t('system_status.inv_job_log_unavailable')), $html);
        self::assertStringNotContainsString('deploy_log.php?id=', $html);
    }

    public function testAPendingRetryAndThePreviousFailureKeepDistinctLogLinks(): void
    {
        $html = $this->renderEsxi($this->inventoryState('failed', 9717), [
            'id' => 9719,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_QUEUED,
        ]);

        self::assertStringContainsString('href="deploy_log.php?id=9717"', $html);
        self::assertStringContainsString('href="deploy_log.php?id=9719"', $html);
        self::assertStringContainsString(h(__t('system_status.inv_open_failed_job_log')), $html);
        self::assertStringContainsString(h(__t('system_status.inv_open_job_log')), $html);
    }

    /** @param array<int,array<string,mixed>> $deviations */
    private function renderDeviations(array $deviations, bool $hasInventory): string
    {
        $admin = ['id' => 1, 'role' => 'admin'];
        ob_start();
        system_status_render_deviations($deviations, ['VLAN_701'], $admin, '', $hasInventory);

        return (string) ob_get_clean();
    }

    /** @return array<int,array<string,mixed>> */
    private function deviation(string $missionName, int $issues): array
    {
        $list = [];
        for ($i = 0; $i < $issues; $i++) {
            $list[] = ['field' => 'vlan', 'value' => 'VLAN_' . (700 + $i)];
        }

        // is_template is part of the scan's output shape, not something the
        // renderer re-derives from the name: one predicate, one answer.
        return [[
            'mission_id' => 7,
            'mission_name' => $missionName,
            'is_template' => mission_name_is_template($missionName),
            'vm_id' => null,
            'vm_name' => '',
            'issues' => $list,
        ]];
    }

    public function testWithoutInventoryTheScanReportsNotCheckedInsteadOfZero(): void
    {
        $html = $this->renderDeviations([], false);
        self::assertStringContainsString(__t('system_status.dev_count_unknown'), $html);
        self::assertStringContainsString(__t('system_status.dev_no_inventory'), $html);
        self::assertStringNotContainsString(__t('system_status.dev_count_none'), $html);
        // Neutral, not green: nothing was verified, so nothing may look verified.
        self::assertStringContainsString('badge-neutral', $html);
        self::assertStringNotContainsString('badge-success', $html);
    }

    public function testWithInventoryAndNoFindingsTheScanReportsAllClear(): void
    {
        $html = $this->renderDeviations([], true);
        self::assertStringContainsString(__t('system_status.dev_count_none'), $html);
        self::assertStringContainsString(__t('system_status.dev_none'), $html);
        self::assertStringContainsString('badge-success', $html);
    }

    public function testTheCountPicksTheSentenceByNumber(): void
    {
        $one = $this->renderDeviations($this->deviation('PROD-WEB', 1), true);
        self::assertStringContainsString(__t('system_status.dev_count_one'), $one);
        self::assertStringNotContainsString(':count', $one);

        $many = $this->renderDeviations($this->deviation('PROD-WEB', 3), true);
        self::assertStringContainsString(__t('system_status.dev_count_many', ['count' => 3]), $many);
        self::assertStringNotContainsString(':count', $many);
    }

    public function testATemplateDeviationIsMarkedAsOne(): void
    {
        $template = $this->renderDeviations($this->deviation(VIRTUSPHERE_TEMPLATE_PREFIX . 'GOLD', 1), true);
        self::assertStringContainsString(__t('system_status.dev_template_badge'), $template);

        $mission = $this->renderDeviations($this->deviation('PROD-WEB', 1), true);
        self::assertStringNotContainsString(__t('system_status.dev_template_badge'), $mission);
    }

    public function testTheTemplateBadgeFollowsTheScanFlagAndNotTheName(): void
    {
        // The scan owns the predicate (mission_name_is_template trims to match
        // stored names). A renderer that re-derived it from the name would be a
        // second implementation of the same rule, free to disagree with the
        // scope decision that produced the entry.
        $entry = $this->deviation('PROD-WEB', 1);
        $entry[0]['is_template'] = true;

        self::assertStringContainsString(__t('system_status.dev_template_badge'), $this->renderDeviations($entry, true));
    }

    /**
     * @param array<string,mixed>|null $stateRow
     * @param array<string,mixed>|null $lastMissionJob
     * @param array<string,mixed>|null $user
     */
    private function renderAnsible(string $state, ?array $stateRow, ?array $lastMissionJob = null, ?array $user = null): string
    {
        $snapshot = ['ansible' => ['rows' => [[
            'credential' => ['id' => 5, 'name' => 'ansible-01', 'host' => '10.0.0.9'],
            'state_row' => $stateRow,
            'state' => $state,
            'last_mission_job' => $lastMissionJob,
        ]]]];
        ob_start();
        system_status_render_ansible($snapshot, $user ?? ['id' => 1, 'role' => 'admin']);

        return (string) ob_get_clean();
    }

    public function testAPreflightWarningExplainsItselfInsteadOfClaimingAFailure(): void
    {
        $html = $this->renderAnsible('warning', ['last_status' => 'warning', 'last_component' => 'allowlist', 'last_checked_at' => '2026-07-23 10:00:00']);
        self::assertStringContainsString(__t('system_status.ansible_allowlist_detail'), $html);
        self::assertStringNotContainsString(__t('system_status.ansible_failed_component', ['component' => 'allowlist']), $html);
        self::assertStringContainsString(__t('system_status.ansible_state_warning'), $html);
    }

    public function testAStaleAllowlistWarningDoesNotTurnIntoAClaimedFailure(): void
    {
        $html = $this->renderAnsible('stale', ['last_status' => 'warning', 'last_component' => 'allowlist', 'last_checked_at' => '2026-07-01 10:00:00']);
        self::assertStringContainsString(__t('system_status.ansible_allowlist_detail'), $html);
        self::assertStringNotContainsString(__t('system_status.ansible_failed_component', ['component' => 'allowlist']), $html);
        self::assertStringContainsString(__t('system_status.ansible_state_stale'), $html);
        self::assertStringContainsString(__t('system_status.ansible_stale_detail', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS]), $html);
        self::assertStringContainsString('action="credentials.php"', $html);
        self::assertStringContainsString('name="return_to" value="ansible_status"', $html);
        self::assertStringContainsString('href="logs.php?tab=security&amp;category=credentials"', $html);
    }

    public function testMissionHistoryIsSeparateEvidenceAndNeverHidesAStaleFullTest(): void
    {
        $html = $this->renderAnsible(
            'stale',
            ['last_status' => 'ok', 'last_component' => null, 'last_checked_at' => '2026-07-01 10:00:00'],
            [
                'id' => 812,
                'mission_name' => 'MISSION-ALPHA',
                'status' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
                'payload_json' => '{"mode":"start"}',
                'updated_at' => '2026-08-11 08:09:10',
            ]
        );

        self::assertStringContainsString(__t('system_status.ansible_state_stale'), $html);
        self::assertStringContainsString(__t('system_status.ansible_job_succeeded'), $html);
        self::assertStringContainsString(__t('system_status.ansible_job_identity', ['id' => 812, 'mission' => 'MISSION-ALPHA']), $html);
        self::assertStringContainsString('href="deploy_log.php?id=812"', $html);
        // A start job proves less than a full run, and the help says so; the row
        // has to name which one it was or that sentence cannot be acted on.
        self::assertStringContainsString(__t('system_status.ansible_job_mode', ['mode' => 'start']), $html);
    }

    public function testARegularOperatorCanInspectTheJobButCannotStartTheCredentialTest(): void
    {
        $html = $this->renderAnsible(
            'ok',
            ['last_status' => 'ok', 'last_component' => null, 'last_checked_at' => '2026-08-11 08:09:10'],
            [
                'id' => 813,
                'mission_name' => 'MISSION-BETA',
                'status' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
                'updated_at' => '2026-08-11 08:10:11',
            ],
            ['id' => 2, 'role' => 'user']
        );

        self::assertStringContainsString(__t('system_status.ansible_job_cancelled'), $html);
        self::assertStringContainsString('href="deploy_log.php?id=813"', $html);
        self::assertStringNotContainsString('name="return_to" value="ansible_status"', $html);
        self::assertStringNotContainsString('category=credentials', $html);
    }

    public function testAPreflightFailureStillNamesItsBrokenComponent(): void
    {
        $html = $this->renderAnsible('danger', ['last_status' => 'failed', 'last_component' => 'pyvmomi', 'last_checked_at' => '2026-07-23 10:00:00']);
        self::assertStringContainsString(__t('system_status.ansible_failed_component', ['component' => 'pyvmomi']), $html);
        self::assertStringNotContainsString(__t('system_status.ansible_allowlist_detail'), $html);
    }

    public function testAnUntestedCredentialClaimsNothing(): void
    {
        $html = $this->renderAnsible('unknown', null);
        self::assertStringContainsString(__t('system_status.ansible_never_tested'), $html);
        self::assertStringNotContainsString(__t('system_status.ansible_allowlist_detail'), $html);
    }

    public function testTheActionHintOnlyAppearsOnARowThatNeedsRepair(): void
    {
        $row = static fn (string $state): array => [[
            'source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
            'row' => ['last_seen_at' => '2026-07-23 10:00:00', 'last_checked_at' => '2026-07-23 10:00:00', 'interval_seconds' => 60, 'last_detail' => ''],
            'state' => $state,
        ]];

        // The template escapes the hint, and this one carries a typographic
        // quote, so the expectation has to be escaped too.
        $hint = h(__t('system_status.action_maintenance_worker'));

        ob_start();
        system_status_render_source_rows($row('ok'));
        $ok = (string) ob_get_clean();
        self::assertStringNotContainsString($hint, $ok);
        self::assertStringNotContainsString('status-action', $ok);

        foreach (['warning', 'danger', 'missing', 'unknown'] as $state) {
            ob_start();
            system_status_render_source_rows($row($state));
            self::assertStringContainsString(
                $hint,
                (string) ob_get_clean(),
                'the "' . $state . '" state lost its action hint'
            );
        }
    }

    public function testASuppressedGroupDropsHintsButKeepsItsRows(): void
    {
        // A source that was never set up cannot be restarted. The caller says so
        // once for the group; the rows must not repeat a premature instruction,
        // but they must still be listed with their state.
        $rows = [[
            'source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
            'row' => null,
            'state' => 'unknown',
        ]];

        ob_start();
        system_status_render_source_rows($rows, true);
        $suppressed = (string) ob_get_clean();
        self::assertStringNotContainsString(h(__t('system_status.action_device_sync')), $suppressed);
        self::assertStringNotContainsString('status-action', $suppressed);
        self::assertStringContainsString(h(integration_source_label(VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC)), $suppressed);
        self::assertStringContainsString(__t('system_status.status_unknown'), $suppressed);

        ob_start();
        system_status_render_source_rows($rows, false);
        self::assertStringContainsString(h(__t('system_status.action_device_sync')), (string) ob_get_clean());
    }

    /** @param array<string,mixed> $syncGroup @param array<string,mixed> $siteGroup */
    private function renderMecm(array $syncGroup, array $siteGroup): string
    {
        $snapshot = [
            'mecm_ip_mismatch' => false,
            'mecm_fresh_ips' => [],
            'mecm_sync' => $syncGroup,
            'mecm_site' => $siteGroup,
        ];
        ob_start();
        system_status_render_mecm($snapshot, ['id' => 1, 'role' => 'admin']);

        return (string) ob_get_clean();
    }

    private const EMPTY_SITE = ['state' => 'unknown', 'rows' => [
        ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => null, 'state' => 'unknown'],
    ]];

    public function testTheSetupEmptyStateShowsOnceWhenNothingReported(): void
    {
        $html = $this->renderMecm(
            ['state' => 'unknown', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => null, 'state' => 'unknown'],
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC, 'row' => null, 'state' => 'unknown'],
            ]],
            self::EMPTY_SITE
        );

        self::assertStringContainsString(h(__t('system_status.mecm_setup_empty')), $html);
        self::assertSame(0, substr_count($html, 'status-action'), 'a fresh install must not repeat repair hints per row');
        self::assertStringContainsString(h(integration_source_label(VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC)), $html);
        self::assertStringContainsString(h(__t('system_status.mecm_site_empty')), $html);
    }

    public function testAConnectedSyncKeepsItsRepairHint(): void
    {
        $row = ['last_event' => 'completed', 'last_status' => 'fail', 'last_result_at' => '2026-07-23 08:00:00', 'last_failure_at' => '2026-07-23 08:00:00', 'interval_seconds' => 30, 'last_duration_ms' => null, 'last_detail' => '', 'last_error_category' => 'mecm_unavailable'];
        $html = $this->renderMecm(
            ['state' => 'danger', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => $row, 'state' => 'danger'],
            ]],
            ['state' => 'ok', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => ['last_event' => 'completed', 'last_status' => 'ok', 'last_result_at' => '2026-07-23 11:59:00', 'last_success_at' => '2026-07-23 11:59:00', 'interval_seconds' => 300, 'last_detail' => '', 'last_summary' => '{"site_code":"P01","provider":"srv01","raw_status":0}'], 'state' => 'ok'],
            ]]
        );

        self::assertStringNotContainsString(h(__t('system_status.mecm_setup_empty')), $html);
        self::assertStringContainsString(h(__t('system_status.action_device_sync')), $html);
        self::assertStringContainsString(h(__t('system_status.err_mecm_unavailable')), $html);
        // The site badge is its own signal, never a repair hint for the sync.
        self::assertStringContainsString('P01', $html);
    }

    public function testARunningSyncShowsRunningSinceAndKeepsTheLastResult(): void
    {
        $row = ['last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => '2026-07-23 11:00:00', 'last_success_at' => '2026-07-23 11:00:00', 'last_attempt_at' => '2026-07-23 11:59:30', 'interval_seconds' => 60, 'last_duration_ms' => null, 'last_detail' => ''];
        $html = $this->renderMecm(
            ['state' => 'ok', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => $row, 'state' => 'ok'],
            ]],
            self::EMPTY_SITE
        );

        self::assertStringContainsString(h(__t('system_status.run_running_since', ['time' => portal_format_timestamp('2026-07-23 11:59:30')])), $html);
        self::assertStringContainsString(h(__t('system_status.run_reporter_v2')), $html);
    }

    public function testALegacyRowShowsTheLegacyNoteAndUpdateHint(): void
    {
        $row = ['last_event' => 'heartbeat', 'last_status' => 'ok', 'last_seen_at' => '2026-07-23 11:59:30', 'interval_seconds' => 60, 'last_duration_ms' => null, 'last_detail' => ''];
        $html = $this->renderMecm(
            ['state' => 'legacy', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => $row, 'state' => 'legacy'],
            ]],
            self::EMPTY_SITE
        );

        self::assertStringContainsString(h(__t('system_status.run_reporter_legacy')), $html);
        self::assertStringContainsString(h(__t('system_status.run_legacy_hint')), $html);
    }

    public function testSiteCriticalNamesTheConsoleAndShowsTheCode(): void
    {
        $row = ['last_event' => 'completed', 'last_status' => 'fail', 'last_result_at' => '2026-07-23 11:59:00', 'last_failure_at' => '2026-07-23 11:59:00', 'last_error_category' => 'site_critical', 'interval_seconds' => 300, 'last_detail' => '', 'last_summary' => '{"site_code":"P01","provider":"srv01","raw_status":2}'];
        $html = $this->renderMecm(
            ['state' => 'ok', 'rows' => []],
            ['state' => 'danger', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $row, 'state' => 'danger'],
            ]]
        );

        self::assertStringContainsString('P01', $html);
        self::assertStringContainsString(h(__t('system_status.err_site_critical')), $html);
        self::assertStringContainsString(h(__t('system_status.site_hint_console')), $html);
    }

    public function testASiteProviderFaultIsGreyNotCritical(): void
    {
        $row = ['last_event' => 'completed', 'last_status' => 'unknown', 'last_result_at' => '2026-07-23 11:59:00', 'last_error_category' => 'provider_access_denied', 'interval_seconds' => 300, 'last_detail' => '', 'last_summary' => '{"provider":"srv01"}'];
        $html = $this->renderMecm(
            ['state' => 'ok', 'rows' => []],
            ['state' => 'unknown', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $row, 'state' => 'unknown'],
            ]]
        );

        self::assertStringContainsString(h(__t('system_status.err_provider_access_denied')), $html);
        self::assertStringContainsString(h(__t('system_status.site_hint_access_denied')), $html);
        // A provider fault must be textually distinct from a MECM-confirmed
        // critical: grey, never the red critical hint.
        self::assertStringNotContainsString(h(__t('system_status.site_hint_console')), $html);
        self::assertStringNotContainsString(h(__t('system_status.status_danger')), $html);
    }
}
