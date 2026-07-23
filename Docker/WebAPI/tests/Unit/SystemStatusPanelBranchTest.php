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

        return [['mission_id' => 7, 'mission_name' => $missionName, 'vm_id' => null, 'vm_name' => '', 'issues' => $list]];
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

    /** @param array<string,mixed>|null $stateRow */
    private function renderAnsible(string $state, ?array $stateRow): string
    {
        $snapshot = ['ansible' => ['rows' => [[
            'credential' => ['id' => 5, 'name' => 'ansible-01', 'host' => '10.0.0.9'],
            'state_row' => $stateRow,
            'state' => $state,
        ]]]];
        ob_start();
        system_status_render_ansible($snapshot, ['id' => 1, 'role' => 'admin']);

        return (string) ob_get_clean();
    }

    public function testAPreflightWarningExplainsItselfInsteadOfClaimingAFailure(): void
    {
        $html = $this->renderAnsible('warning', ['last_status' => 'warning', 'last_component' => 'allowlist', 'last_checked_at' => '2026-07-23 10:00:00']);
        self::assertStringContainsString(__t('system_status.ansible_allowlist_detail'), $html);
        self::assertStringNotContainsString(__t('system_status.ansible_failed_component', ['component' => 'allowlist']), $html);
        self::assertStringContainsString(__t('system_status.ansible_state_warning'), $html);
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

    public function testAnUnconnectedMecmSaysSoOnceInsteadOfPerRow(): void
    {
        $snapshot = [
            'by_source' => [],
            'mecm_ip_mismatch' => false,
            'mecm_fresh_ips' => [],
            'mecm_sync' => ['state' => 'unknown', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => null, 'state' => 'unknown'],
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC, 'row' => null, 'state' => 'unknown'],
            ]],
            'mecm_network' => ['state' => 'unknown', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE, 'row' => null, 'state' => 'unknown'],
            ]],
        ];
        $probe = ['mode' => VIRTUSPHERE_PROBE_MODE_AUTO, 'host' => null, 'port' => 445, 'source_ip' => null, 'source_seen_at' => null];

        ob_start();
        system_status_render_mecm($snapshot, $probe, ['id' => 1, 'role' => 'admin']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString(h(__t('system_status.mecm_not_configured')), $html);
        self::assertSame(0, substr_count($html, 'status-action'), 'an unconnected MECM must not repeat repair hints per row');
        // The rows themselves stay, so the operator still sees what is expected.
        self::assertStringContainsString(h(integration_source_label(VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC)), $html);
    }

    public function testAConnectedMecmKeepsItsRepairHints(): void
    {
        $snapshot = [
            'by_source' => [],
            'mecm_ip_mismatch' => false,
            'mecm_fresh_ips' => [],
            'mecm_sync' => ['state' => 'danger', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => ['last_seen_at' => '2026-07-23 08:00:00', 'last_checked_at' => '2026-07-23 08:00:00', 'interval_seconds' => 30, 'last_detail' => ''], 'state' => 'danger'],
            ]],
            'mecm_network' => ['state' => 'danger', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE, 'row' => ['last_seen_at' => null, 'last_checked_at' => '2026-07-23 10:00:00', 'interval_seconds' => 300, 'last_detail' => ''], 'state' => 'danger'],
            ]],
        ];
        $probe = ['mode' => VIRTUSPHERE_PROBE_MODE_AUTO, 'host' => '10.0.0.5', 'port' => 445, 'source_ip' => '10.0.0.5', 'source_seen_at' => null];

        ob_start();
        system_status_render_mecm($snapshot, $probe, ['id' => 1, 'role' => 'admin']);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString(h(__t('system_status.mecm_not_configured')), $html);
        self::assertStringContainsString(h(__t('system_status.action_device_sync')), $html);
        self::assertStringContainsString(h(__t('system_status.action_mecm_server_probe')), $html);
    }

    public function testAProbeWithoutATargetDoesNotCallTheServerUnreachable(): void
    {
        $snapshot = [
            'by_source' => [],
            'mecm_ip_mismatch' => false,
            'mecm_fresh_ips' => [],
            'mecm_sync' => ['state' => 'danger', 'rows' => []],
            'mecm_network' => ['state' => 'danger', 'rows' => [
                ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE, 'row' => ['last_seen_at' => null, 'last_checked_at' => '2026-07-23 10:00:00', 'interval_seconds' => 300, 'last_detail' => ''], 'state' => 'danger'],
            ]],
        ];
        // Auto mode, first device sync still outstanding: nothing was contacted,
        // so nothing can be declared unreachable.
        $probe = ['mode' => VIRTUSPHERE_PROBE_MODE_AUTO, 'host' => null, 'port' => 445, 'source_ip' => null, 'source_seen_at' => null];

        ob_start();
        system_status_render_mecm($snapshot, $probe, ['id' => 1, 'role' => 'admin']);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString(h(__t('system_status.action_mecm_server_probe')), $html);
        self::assertStringContainsString(h(__t('system_status.probe_target_waiting')), $html);
    }
}
