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

    public function testDistributionFindingsAreNotesThatBlockNothingInBothLocales(): void
    {
        // AV2 (ADR-0034 Amendment 7): DP states are MECM's business. failed and
        // in_progress are notes; they never claim that a deployment is missing.
        foreach (['de', 'en'] as $locale) {
            $catalog = require __DIR__ . '/../../lang/' . $locale . '/system_status.php';
            $help = require __DIR__ . '/../../lang/' . $locale . '/help_system_status.php';
            foreach (['package_content_failed', 'package_content_in_progress'] as $code) {
                $finding = (string) $catalog['finding_' . $code];
                self::assertStringStartsWith($locale === 'de' ? 'Hinweis:' : 'Note:', $finding, $code);
                self::assertStringContainsString($locale === 'de' ? 'blockiert nichts' : 'Nothing is blocked', $finding, $code);
                self::assertStringContainsString(
                    $locale === 'de' ? 'Hinweis, kein offener Punkt' : 'A note, not an open item',
                    (string) $help['system_status_status_code_' . $code],
                    $code
                );
            }
            $failed = (string) $catalog['finding_package_content_failed'];
            self::assertStringContainsString('offline', $failed);
            self::assertStringNotContainsString($locale === 'de' ? 'DP-Gruppe' : 'DP group', $failed, 'a missing DP group has its own code now');
            $requestFailed = (string) $catalog['finding_package_content_request_failed'];
            self::assertStringContainsString($locale === 'de' ? 'DP-Gruppe' : 'DP group', $requestFailed);
            self::assertStringContainsString($locale === 'de' ? 'Tageslog' : 'daily autoimporter log', $requestFailed);
            self::assertStringContainsString($locale === 'de' ? 'Collection und Deployments' : 'collections and deployments', $requestFailed);
            self::assertStringContainsString($locale === 'de' ? 'Collection und Deployments' : 'collections and deployments', (string) $catalog['finding_package_content_unknown']);
            self::assertStringContainsString('VirtuSphere_Applications', (string) $catalog['finding_package_definition_drift']);
            self::assertStringContainsString($locale === 'de' ? 'noch kein DP erfolgreich' : 'no DP is successful yet', (string) $help['system_status_status_counters']);
            foreach (['Kopiernachweis', 'copy evidence', 'copy record', 'Zielverlust', 'target loss', 'Zielprojektion', 'target projection'] as $retired) {
                self::assertStringNotContainsString($retired, (string) $help['system_status_status_counters'], $retired);
                self::assertStringNotContainsString($retired, (string) $help['system_status_status_cleanup_p4'], $retired);
                self::assertStringNotContainsString($retired, (string) $catalog['finding_package_cleanup_failed'], $retired);
            }
            $cleanup = (string) $catalog['finding_package_cleanup_failed'];
            self::assertStringContainsString('removeOldVersion', $cleanup);
            self::assertStringContainsString($locale === 'de' ? 'automatisch entfernt' : 'removed automatically', $cleanup);
            $cleanupHelp = (string) $help['system_status_status_cleanup_p4'];
            self::assertStringContainsString($locale === 'de' ? 'null erfolgreiche DPs' : 'zero successful DPs', $cleanupHelp);
            self::assertStringContainsString('removeOldVersion=true', $cleanupHelp);
            self::assertStringContainsString($locale === 'de' ? 'Deployments, Applications und Collections' : 'deployments, applications, and collections', $cleanupHelp);
            $packageHelp = (string) $help['system_status_status_package_p5'];
            self::assertStringContainsString('Get-VsPackageRetainedNames', $packageHelp);
            self::assertStringContainsString('install-VirtuSphere-MECM.ps1 -Upgrade', $packageHelp);
            self::assertStringContainsString($locale === 'de' ? 'gemischter MECM-Server-Dateisatz' : 'mixed MECM server file set', $packageHelp);
        }
    }

    public function testAnOkScanStillShowsItsDistributionNote(): void
    {
        $html = $this->renderAutoimporter([
            'last_event' => VIRTUSPHERE_RUN_EVENT_COMPLETED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
            'last_result_at' => '2026-11-04 10:05:00',
            'last_detail' => 'package_content_failed target=Agent-1',
        ], 'ok');
        self::assertStringContainsString(__t('system_status.run_result_ok'), $html);
        self::assertStringNotContainsString(__t('system_status.run_result_warning'), $html);
        self::assertStringContainsString(htmlspecialchars(__t('system_status.finding_package_content_failed'), ENT_QUOTES, 'UTF-8'), $html);
    }

    public function testProducerSendsDistributionNotesWithoutOpenPoints(): void
    {
        $producer = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/mecm_autoimporter.ps1');
        $common = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/VirtuSphere-Common.ps1');
        self::assertStringContainsString('Add-VsRunCause -Causes $hintCauses -Cause $distributionHint -Target $fullName', $producer);
        self::assertStringContainsString("Add-VsRunCause -Causes \$causes -Cause 'package_content_request_failed'", $producer);
        self::assertStringContainsString("'dp_group_missing' '' 'package_content_request_failed'", $common);
        self::assertStringContainsString("if (\$DistributionState -eq 'failed') { return 'package_content_failed' }", $common);
        self::assertStringNotContainsString('$contentCause', $producer);

        $reportedDpFailure = system_status_autoimporter_findings('package_content_failed target=Offline-DP', true);
        $requestFailed = system_status_autoimporter_findings('package_content_request_failed target=Missing-Group', true);
        $unknown = system_status_autoimporter_findings('package_content_unknown target=Unreadable', true);
        self::assertSame([__t('system_status.finding_package_content_failed')], $reportedDpFailure);
        self::assertSame([__t('system_status.finding_package_content_request_failed')], $requestFailed);
        self::assertSame([__t('system_status.finding_package_content_unknown')], $unknown);
        self::assertNotSame($reportedDpFailure, $requestFailed);
    }

    public function testGroupAndFolderCausesAreLabelledInBothLocalesAndSortedByDistribution(): void
    {
        // AV-F27 is distribution evidence (prefix package_content_), PS-09 is placement.
        $group = 'package_content_group_empty target=Leer-Gruppe';
        self::assertSame([__t('system_status.finding_package_content_group_empty')], system_status_autoimporter_findings($group, true));
        self::assertSame([], system_status_autoimporter_findings($group, false));
        $folder = 'package_folder_failed target=Agent-1.0';
        self::assertSame([__t('system_status.finding_package_folder_failed')], system_status_autoimporter_findings($folder, false));
        self::assertSame([], system_status_autoimporter_findings($folder, true));

        foreach (['de', 'en'] as $locale) {
            $catalog = require __DIR__ . '/../../lang/' . $locale . '/system_status.php';
            $help = require __DIR__ . '/../../lang/' . $locale . '/help_system_status.php';
            foreach (['package_content_group_empty', 'package_content_request_failed', 'package_folder_failed'] as $code) {
                self::assertArrayHasKey('finding_' . $code, $catalog, $locale);
                self::assertNotSame('', trim((string) $catalog['finding_' . $code]), $locale);
                self::assertArrayHasKey('system_status_status_code_' . $code, $help, $locale);
                self::assertNotSame('', trim((string) $help['system_status_status_code_' . $code]), $locale);
            }
        }
    }

    public function testProducerNamesTheEmptyGroupBeforeAnyContentRequestAndCountsTheFolderMove(): void
    {
        $producer = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/mecm_autoimporter.ps1');
        $common = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/VirtuSphere-Common.ps1');
        // The initial request is refused for an empty group: the decision names it before any request.
        $groupCheck = strrpos($common, "'blocked' 'dp_group_empty' '' 'package_content_group_empty')");
        $initialRequest = strpos($common, "'request' 'initial' 'initial'");
        self::assertNotFalse($groupCheck);
        self::assertNotFalse($initialRequest);
        self::assertLessThan($initialRequest, $groupCheck);
        self::assertStringContainsString('Add-VsRunCause -Causes $causes -Cause $decision.Cause -Target $fullName', $producer);
        // The folder move is retried and counted; no SilentlyContinue swallows it.
        self::assertStringNotContainsString('Move-CMObject -FolderPath $appOrgFolder -ErrorAction SilentlyContinue', $producer);
        self::assertStringContainsString("Add-VsRunCause -Causes \$causes -Cause 'package_folder_failed'", $producer);
    }

    public function testEveryPackageCodeOfTheServerVocabularyIsLabelledAndExplained(): void
    {
        // Derived from the PowerShell owner ($script:VsRunCauseVocabulary), not listed here:
        // a new package_ code without the portal list, a card label in both locales or a
        // help entry in both locales fails this test (NZ1; before, only package_content_
        // codes were checked and three configuration codes had neither label nor help).
        $common = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/VirtuSphere-Common.ps1');
        self::assertSame(1, preg_match('/\$script:VsRunCauseVocabulary\s*=\s*@\((.*?)\r?\n\)/s', $common, $block));
        self::assertGreaterThan(0, preg_match_all("/^\s*'(package_[a-z_]+)'/m", (string) ($block[1] ?? ''), $matches));
        $vocabulary = array_values(array_unique($matches[1]));
        // An empty or one-code derivation would make the loop below vacuous.
        self::assertGreaterThan(5, count($vocabulary));
        self::assertContains('package_content_failed', $vocabulary);
        self::assertContains('package_config_invalid', $vocabulary);
        self::assertSame($vocabulary, VIRTUSPHERE_AUTOIMPORTER_PACKAGE_CAUSES, 'portal list follows the PowerShell vocabulary');

        $helpView = (string) file_get_contents(__DIR__ . '/../../lib/help/system_status.php');
        self::assertStringContainsString('...VIRTUSPHERE_AUTOIMPORTER_PACKAGE_CAUSES', $helpView);
        $catalogs = [];
        foreach (['de', 'en'] as $locale) {
            $catalogs[$locale] = [
                'card' => require __DIR__ . '/../../lang/' . $locale . '/system_status.php',
                'help' => require __DIR__ . '/../../lang/' . $locale . '/help_system_status.php',
            ];
        }
        foreach ($vocabulary as $code) {
            $distribution = str_starts_with($code, 'package_content_');
            self::assertSame(
                [__t('system_status.finding_' . $code)],
                system_status_autoimporter_findings($code . ' target=Agent-1', $distribution),
                $code
            );
            self::assertSame([], system_status_autoimporter_findings($code . ' target=Agent-1', !$distribution), $code);
            foreach ($catalogs as $locale => $catalog) {
                self::assertNotSame('', trim((string) ($catalog['card']['finding_' . $code] ?? '')), $locale . ' card ' . $code);
                self::assertNotSame('', trim((string) ($catalog['help']['system_status_status_code_' . $code] ?? '')), $locale . ' help ' . $code);
            }
        }
    }

    public function testHintRefreshIntervalFollowsThePowerShellOwner(): void
    {
        // NZ1: card and help state the refresh interval from the PHP mirror, never
        // as a written-out number (bounds-sync); the mirror must equal the server value.
        $common = (string) file_get_contents(__DIR__ . '/../../../../Powershell-MECM/mecm/VirtuSphere-Common.ps1');
        self::assertSame(1, preg_match('/^\$script:VsDistributionHintRefreshSeconds\s*=\s*(\d+)\s*$/m', $common, $match));
        self::assertSame((int) $match[1], VIRTUSPHERE_AUTOIMPORTER_HINT_REFRESH_SECONDS);
        self::assertSame(0, VIRTUSPHERE_AUTOIMPORTER_HINT_REFRESH_SECONDS % 60, 'stated in whole minutes');
        foreach (['de', 'en'] as $locale) {
            $catalog = require __DIR__ . '/../../lang/' . $locale . '/system_status.php';
            $help = require __DIR__ . '/../../lang/' . $locale . '/help_system_status.php';
            self::assertStringContainsString(':minutes', (string) $catalog['run_distribution_scope'], $locale);
            self::assertStringContainsString(':minutes', (string) $help['system_status_status_counters'], $locale);
        }
        $html = $this->renderAutoimporter(null, 'unknown');
        self::assertStringContainsString(
            htmlspecialchars(__t('system_status.run_distribution_scope', ['minutes' => (string) intdiv(VIRTUSPHERE_AUTOIMPORTER_HINT_REFRESH_SECONDS, 60)]), ENT_QUOTES, 'UTF-8'),
            $html
        );
    }

    public function testDeployFailureWithCollectionStillMatchesItsTarget(): void
    {
        // Add-VsRunCause writes target= before collection=; the DeployTo variant must still match.
        $detail = 'package_deploy_failed target=Agent-1.0 collection=Pilot-Gruppe; package_source_missing target=D:%3Bfiles';
        self::assertSame(
            [__t('system_status.finding_package_source_missing'), __t('system_status.finding_package_deploy_failed')],
            system_status_autoimporter_findings($detail, false)
        );
    }

    public function testDeployToAndPackageFrameCausesAreConfigurationFindings(): void
    {
        // AR1: the DeployTo check names the collection after target=, a package that
        // stopped on its own MECM error is package_scan_failed; the filled detail line
        // ends with the rest count. None of them is a distribution note.
        $detail = 'package_deployto_missing target=Agent-1.0 collection=Pilot-Gruppe; '
            . 'package_scan_failed target=Agent-2.0; package_deployto_ambiguous target=Agent-3.0 collection=Doppelt; '
            . 'package_deployto_invalid target=Agent-4.0 collection=Agent-4.0; (+3 weitere)';
        self::assertSame(
            [
                __t('system_status.finding_package_deployto_missing'),
                __t('system_status.finding_package_deployto_ambiguous'),
                __t('system_status.finding_package_deployto_invalid'),
                __t('system_status.finding_package_scan_failed'),
            ],
            system_status_autoimporter_findings($detail, false)
        );
        self::assertSame([], system_status_autoimporter_findings($detail, true));
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
