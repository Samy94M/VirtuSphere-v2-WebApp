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

    public function testLegacyHeartbeatInvalidatesTheStoredCompletionTuple(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../lib/repo/heartbeats.php');
        $touch = $this->functionSource($source, 'repo_touch_integration_heartbeat');
        foreach (['last_result_at = NULL', 'last_error_category = NULL', 'last_duration_ms = NULL',
            'last_summary = NULL', 'last_run_id = NULL', 'failure_streak = 0'] as $reset) {
            self::assertStringContainsString($reset, $touch);
        }

        // This also repairs a pre-fix heartbeat row when its first subsequent V2
        // event is `started`, before a fresh completion exists.
        $started = $this->functionSource($source, 'repo_run_report_write_started');
        foreach (['last_result_at', 'last_detail', 'last_error_category', 'last_duration_ms', 'last_summary', 'failure_streak'] as $field) {
            self::assertMatchesRegularExpression('/' . $field . '\s*=\s*IF\(last_event\s*=\s*\?,\s*(?:NULL|0),\s*' . $field . '\)/', $started);
        }
    }

    public function testCompletedFailureThenLegacyHeartbeatCannotRenderAsScanSuccess(): void
    {
        $html = $this->renderAutoimporter([
            'last_event' => VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
            'last_result_at' => '2026-09-12 09:00:00',
            'last_detail' => 'package_content_failed target=Agent-1',
            'last_error_category' => VIRTUSPHERE_RUN_ERROR_PARTIAL_FAILURE,
            'last_duration_ms' => 54321,
            'last_summary' => '{"open_points":987654}',
        ], 'legacy');

        self::assertStringContainsString(__t('system_status.run_result_unknown'), $html);
        self::assertStringNotContainsString(__t('system_status.run_result_ok'), $html);
        self::assertStringNotContainsString(__t('system_status.finding_package_content_failed'), $html);
        self::assertStringNotContainsString('987654', $html);
    }

    public function testWarningThenLegacyHeartbeatThenStartedRemainsUnknownUntilFreshCompletion(): void
    {
        $started = $this->renderAutoimporter([
            'last_event' => VIRTUSPHERE_RUN_EVENT_STARTED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
            'last_attempt_at' => '2026-09-12 10:00:00',
            'last_result_at' => null,
        ], 'ok');
        self::assertStringContainsString(__t('system_status.run_result_unknown'), $started);
        self::assertStringNotContainsString(__t('system_status.run_result_warning'), $started);

        $completed = $this->renderAutoimporter([
            'last_event' => VIRTUSPHERE_RUN_EVENT_COMPLETED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_WARNING,
            'last_result_at' => '2026-09-12 10:05:00',
            'last_detail' => 'package_content_unknown target=Agent-1',
        ], 'warning');
        self::assertStringContainsString(__t('system_status.run_result_warning'), $completed);
        self::assertStringContainsString(__t('system_status.finding_package_content_unknown'), $completed);
    }

    public function testCoarseContentFailureAndUnknownProjectionKeepTheirEvidenceBoundaryInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = require __DIR__ . '/../../lang/' . $locale . '/system_status.php';
            $help = require __DIR__ . '/../../lang/' . $locale . '/help_system_status.php';
            $failed = (string) $catalog['finding_package_content_failed'];
            $unknown = (string) $catalog['finding_package_content_unknown'];
            self::assertStringContainsString($locale === 'de' ? 'unterscheidet nicht' : 'does not distinguish', $failed);
            self::assertStringContainsString($locale === 'de' ? 'DP-Gruppe' : 'DP group', $failed);
            self::assertStringContainsString($locale === 'de' ? 'Tageslog' : 'daily autoimporter log', $failed);
            self::assertStringContainsString($locale === 'de' ? 'Collection und Deployments' : 'collections and deployments', $failed);
            self::assertStringContainsString($locale === 'de' ? 'Projektion' : 'projection', $unknown);
            self::assertNotSame($failed, $unknown);
            self::assertStringContainsString('package_content_failed', (string) $help['system_status_status_p3']);
            self::assertStringContainsString($locale === 'de' ? 'grober Code' : 'coarse code', (string) $help['system_status_status_p3']);
            self::assertStringContainsString($locale === 'de' ? 'noch kein DP erfolgreich' : 'no DP is successful yet', (string) $help['system_status_status_p3']);
            $cleanup = (string) $catalog['finding_package_cleanup_failed'];
            self::assertStringContainsString($locale === 'de' ? 'nicht alle DPs erfolgreich' : 'Not every DP has to be successful', $cleanup);
            self::assertStringContainsString('removeOldVersion', $cleanup);
            self::assertStringContainsString($locale === 'de' ? 'automatisch entfernt' : 'removed automatically', $cleanup);
            $cleanupHelp = (string) $help['system_status_status_cleanup_p4'];
            self::assertStringContainsString($locale === 'de' ? 'null erfolgreiche DPs' : 'Zero successful DPs', $cleanupHelp);
            self::assertStringContainsString($locale === 'de' ? 'Ersatz-Content-ID' : 'replacement content ID', $cleanupHelp);
            self::assertStringContainsString('removeOldVersion=true', $cleanupHelp);
            self::assertStringContainsString($locale === 'de' ? 'Deployments, Applications und Collections' : 'deployments, applications, and collections', $cleanupHelp);
            $packageHelp = (string) $help['system_status_status_package_p5'];
            self::assertStringContainsString('Get-VsPackageRetainedNames', $packageHelp);
            self::assertStringContainsString('install-VirtuSphere-MECM.ps1 -Upgrade', $packageHelp);
            self::assertStringContainsString($locale === 'de' ? 'gemischter MECM-Server-Dateisatz' : 'mixed MECM server file set', $packageHelp);
        }
    }

    public function testProducerCasesMapToOnlyTheEvidenceTheirPresenterExplains(): void
    {
        $producer = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/mecm_autoimporter.ps1');
        self::assertMatchesRegularExpression(
            '/(?s)IsNullOrWhiteSpace\(\$dpGroupName\).*?DP-Gruppe fehlt.*?Cause \'package_content_failed\'/',
            $producer
        );
        self::assertMatchesRegularExpression(
            '/(?s)\$snapshot\.State -eq \'unknown\'.*?nachgelagerte Deployments.*?Cause \'package_content_unknown\'.*?continue/',
            $producer
        );
        self::assertMatchesRegularExpression(
            '/\$contentCause = if \(\$snapshot\.State -eq \'failed\'\) \{ \'package_content_failed\' \} else \{ \'package_content_in_progress\' \}/',
            $producer
        );

        $missingGroup = system_status_autoimporter_findings('package_content_failed target=Missing-Group', true);
        $reportedDpFailure = system_status_autoimporter_findings('package_content_failed target=Reported-DP-Failure', true);
        $unknownProjection = system_status_autoimporter_findings('package_content_unknown target=Unknown-Projection', true);
        self::assertSame([__t('system_status.finding_package_content_failed')], $missingGroup);
        self::assertSame($missingGroup, $reportedDpFailure, 'the coarse wire code must not invent which failure branch produced it');
        self::assertSame([__t('system_status.finding_package_content_unknown')], $unknownProjection);
        self::assertNotSame($missingGroup, $unknownProjection);
    }

    public function testMissingReportRendersThreeAxesWithoutClaimingDistributionSuccess(): void
    {
        $html = $this->renderAutoimporter(null, 'unknown');
        foreach (['run_activity_heading', 'run_result_heading', 'run_distribution_heading', 'run_distribution_unknown'] as $key) {
            self::assertStringContainsString(htmlspecialchars(__t('system_status.' . $key), ENT_QUOTES, 'UTF-8'), $html);
        }
    }

    /** @param array<string,mixed>|null $row */
    private function renderAutoimporter(?array $row, string $state): string
    {
        if ($row !== null) {
            $row += [
                'interval_seconds' => 60,
                'last_duration_ms' => null,
            ];
        }
        ob_start();
        try {
            system_status_render_run_rows([['source' => 'autoimporter', 'state' => $state, 'row' => $row]]);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function functionSource(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        self::assertNotFalse($start);
        $next = strpos($source, "\nfunction ", (int) $start + 1);
        return substr($source, (int) $start, $next === false ? null : $next - (int) $start);
    }
}
