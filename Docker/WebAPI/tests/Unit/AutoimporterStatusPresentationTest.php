<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/system_status_mecm_panels.php';

final class AutoimporterStatusPresentationTest extends TestCase
{
    public function testOpenRunNeverClaimsTheProcessIsDownOrAlive(): void
    {
        foreach (['ok', 'warning', 'danger', 'unknown'] as $state) {
            $html = system_status_run_badge($state, true);
            self::assertStringContainsString(__t('system_status.run_completion_open'), $html);
        }
        self::assertSame(heartbeat_badge('danger'), system_status_run_badge('danger', false));
    }

    public function testKnownBoundedCausesReceiveDistinctExplanations(): void
    {
        $detail = 'package_content_failed target=Agent-1; package_config_invalid target=Other; package_content_failed target=Agent-2; (+15 weitere)';
        $findings = system_status_autoimporter_findings($detail);
        self::assertCount(2, $findings);
        self::assertContains(__t('system_status.finding_package_config_invalid'), $findings);
        self::assertContains(__t('system_status.finding_package_content_failed'), $findings);
        self::assertSame([], system_status_autoimporter_findings('unknown target=package_cleanup_failed'));
        self::assertSame([], system_status_autoimporter_findings('free text package_content_failed target=Agent'));
    }

    public function testDistributionDoesNotIncludeConfigurationOrCleanupFindings(): void
    {
        $detail = 'package_config_invalid target=A; package_content_failed target=B; package_cleanup_failed target=C';
        self::assertSame([__t('system_status.finding_package_content_failed')], system_status_autoimporter_findings($detail, true));
        self::assertCount(2, system_status_autoimporter_findings($detail, false));
        self::assertSame([], system_status_autoimporter_findings('package_cleanup_failed target=C', true));
    }

    public function testActivityIsIndependentOfDistributionAndResultSeverity(): void
    {
        foreach ([VIRTUSPHERE_RUN_EVENT_STARTED, VIRTUSPHERE_RUN_EVENT_COMPLETED, 'heartbeat', 'future'] as $event) {
            self::assertStringContainsString('badge-neutral', system_status_autoimporter_activity($event));
        }
        self::assertStringContainsString(__t('system_status.run_activity_unknown'), system_status_autoimporter_activity('future'));
    }

    public function testLastCompletedScanHasItsOwnOutcomeBadge(): void
    {
        foreach ([
            VIRTUSPHERE_RUN_OUTCOME_OK => ['badge-success', 'run_result_ok'],
            VIRTUSPHERE_RUN_OUTCOME_WARNING => ['badge-warning', 'run_result_warning'],
            VIRTUSPHERE_RUN_OUTCOME_FAIL => ['badge-danger', 'run_result_fail'],
            'future' => ['badge-neutral', 'run_result_unknown'],
        ] as $outcome => [$class, $key]) {
            $html = system_status_autoimporter_result($outcome);
            self::assertStringContainsString($class, $html);
            self::assertStringContainsString(__t('system_status.' . $key), $html);
        }
    }

    public function testLegacyHeartbeatDoesNotClaimACompletedScan(): void
    {
        ob_start();
        try {
            system_status_render_run_rows([['source' => 'autoimporter', 'state' => 'legacy', 'row' => [
                'last_event' => VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT,
                'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
                'last_result_at' => null,
            ]]]);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString(__t('system_status.run_result_unknown'), $html);
        self::assertStringNotContainsString(__t('system_status.run_result_ok'), $html);
    }

    public function testMissingReportRendersThreeAxesWithoutClaimingDistributionSuccess(): void
    {
        ob_start();
        try {
            system_status_render_run_rows([['source' => 'autoimporter', 'row' => null, 'state' => 'unknown']]);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        foreach (['run_activity_heading', 'run_result_heading', 'run_distribution_heading', 'run_distribution_unknown'] as $key) {
            self::assertStringContainsString(htmlspecialchars(__t('system_status.' . $key), ENT_QUOTES, 'UTF-8'), $html);
        }
    }

}
