<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SY3: what a System status card says.
 *
 * - C: every card answers "Do I have to do something?" in a verdict line that
 *   reads the tone of its overview card; a run in progress is a line of its own
 *   apart from the last result, with a warning only past its grace.
 * - D: times relative and absolute in <time datetime>, durations in at most two
 *   units (PortalDurationPresenterTest), counters that say what they count.
 * - E: the autoimporter's bounded detail line becomes one table row per cause,
 *   with the raw line kept for a support ticket.
 */
final class SystemStatusCardContentsTest extends TestCase
{
    private const ADMIN = ['id' => 1, 'role' => 'admin'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_panels.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_esxi_panels.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    // ---- E: the cause table -------------------------------------------------

    public function testNoDetailRendersNoTechnicalDetails(): void
    {
        $html = $this->renderAutoimporter('', VIRTUSPHERE_RUN_OUTCOME_OK);
        self::assertStringNotContainsString('data-autoimporter-details', $html);
        self::assertStringNotContainsString('class="status-causes"', $html);
    }

    public function testOneCauseIsOneRowWithItsCountAndPackageList(): void
    {
        $details = system_status_render_autoimporter_details('package_config_invalid target=Agent-1.0');
        self::assertStringContainsString(h(__t('system_status_cards.details_summary_one', ['count' => 1])), $details);
        self::assertSame(1, substr_count($details, '<tr data-cause='));
        self::assertStringContainsString('<tr data-cause="package_config_invalid"><td>' . h(__t('system_status_cards.cause_package_config_invalid')) . ' <code>package_config_invalid</code></td><td>1</td>', $details);
        self::assertStringContainsString('<li>Agent-1.0</li>', $details);
        self::assertStringNotContainsString('data-cause-more', $details);
    }

    public function testManyCausesAreGroupedCountedAndOrderedProblemsBeforeRunningDistribution(): void
    {
        $detail = 'package_content_in_progress target=Agent-5.0; package_content_failed target=Agent-1.0; '
            . 'package_config_invalid target=Agent-2.0; package_content_failed target=Agent-3.0; '
            . 'package_future_code target=Agent-4.0; free text without grammar; package_deploy_failed target=Agent-6.0 collection=Pilot';
        $parsed = system_status_autoimporter_causes($detail);
        self::assertSame(
            ['package_config_invalid', 'package_deploy_failed', 'package_content_failed', 'package_content_in_progress', 'package_future_code', ''],
            array_column($parsed['causes'], 'code'),
            'configuration, then distribution problems, then running distribution, then unknown codes, then unparsed pieces'
        );
        self::assertSame(2, count($parsed['causes'][2]['entries']));
        self::assertFalse($parsed['causes'][4]['known']);
        self::assertSame(0, $parsed['more']);

        $details = system_status_render_autoimporter_details($detail);
        self::assertStringContainsString(h(__t('system_status_cards.details_summary_many', ['count' => 6])), $details);
        self::assertSame(6, substr_count($details, '<tr data-cause='));
        self::assertStringContainsString('<td>2</td>', $details);
        self::assertStringContainsString(h(__t('system_status_cards.cause_collection', ['name' => 'Pilot'])), $details);
        // An unknown code stays visible with its raw code, a piece outside the grammar raw.
        self::assertStringContainsString('<tr data-cause="package_future_code"><td>' . h(__t('system_status_cards.cause_unknown')) . ' <code>package_future_code</code>', $details);
        self::assertStringContainsString('<li>free text without grammar</li>', $details);
    }

    public function testTheRestCountIsExplainedAndCountsBecomeAtLeast(): void
    {
        $detail = 'package_scan_failed target=Agent-1.0; package_scan_failed target=Agent-2.0; (+12 weitere)';
        $parsed = system_status_autoimporter_causes($detail);
        self::assertSame(12, $parsed['more']);
        self::assertCount(1, $parsed['causes']);
        $details = system_status_render_autoimporter_details($detail);
        self::assertStringContainsString(h(__t('system_status_cards.cause_more', ['count' => 12])), $details);
        $finding = system_status_autoimporter_cause_findings($parsed, false)[0];
        self::assertSame(__t('system_status_cards.cause_count_at_least', ['count' => 2]), $finding['count']);

        // Only the rest: no table, still the note and the raw data.
        $restOnly = system_status_render_autoimporter_details('(+22 weitere)');
        self::assertStringNotContainsString('class="status-causes"', $restOnly);
        self::assertStringContainsString(h(__t('system_status_cards.cause_more', ['count' => 22])), $restOnly);
        self::assertStringContainsString('<summary>' . h(__t('common.technical_details')) . '</summary>', $restOnly);
    }

    public function testTargetsAreDecodedAndEscapedAndTheRawLineStaysAsStored(): void
    {
        $detail = 'package_source_missing target=<script>alert(1)</script>%3BX%25; package_deploy_failed target=A collection="Q" & <b>';
        $details = system_status_render_autoimporter_details($detail);
        self::assertStringNotContainsString('<script>', $details);
        self::assertStringNotContainsString('<b>', $details);
        self::assertStringContainsString('<li>&lt;script&gt;alert(1)&lt;/script&gt;;X%</li>', $details, 'percent-escapes decoded, markup escaped');
        // The raw line is the stored text, escaped, with a copy button carrying the same value.
        self::assertStringContainsString('<pre>' . h($detail) . '</pre>', $details);
        self::assertStringContainsString('data-copy-value="' . h($detail) . '"', $details);
        self::assertStringContainsString(h(__t('system_status_cards.raw_summary')), $details);
    }

    public function testFindingsLeadWithTheirCountAndDistributionNotesAreNotActions(): void
    {
        $html = $this->renderAutoimporter('package_config_invalid target=A; package_config_invalid target=B; package_content_failed target=C', VIRTUSPHERE_RUN_OUTCOME_WARNING);
        self::assertStringContainsString('<p class="status-action" data-finding="package_config_invalid"><strong>' . h(__t('system_status_cards.cause_count_many', ['count' => 2])) . ':</strong>', $html);
        self::assertStringContainsString('<p class="status-note" data-finding="package_content_failed"><strong>' . h(__t('system_status_cards.cause_count_one', ['count' => 1])) . ':</strong>', $html);
    }

    // ---- C: verdict, running run, scan and distribution named apart ---------

    public function testTheAutoimporterVerdictNamesScanAndDistributionApart(): void
    {
        $html = $this->renderAutoimporter('package_content_failed target=A; package_content_in_progress target=B', VIRTUSPHERE_RUN_OUTCOME_OK);
        self::assertSame(1, preg_match('/<p class="status-verdict status-verdict-none"[^>]*>.*?<\/p>/s', $html, $verdict));
        self::assertStringContainsString(h(__t('system_status_cards.verdict_none')), $verdict[0]);
        self::assertStringContainsString(h(__t('system_status_cards.fact_scan_ok')), $verdict[0]);
        self::assertStringContainsString(h(__t('system_status_cards.fact_distribution_many', ['count' => 2])), $verdict[0]);

        $quiet = $this->renderAutoimporter('', VIRTUSPHERE_RUN_OUTCOME_OK);
        self::assertStringContainsString(h(__t('system_status_cards.fact_distribution_none')), $quiet);
    }

    public function testARunningRunIsItsOwnLineAndWarnsOnlyPastItsGrace(): void
    {
        $now = (int) strtotime('2026-11-09 14:00:00 UTC');
        $row = [
            'last_event' => VIRTUSPHERE_RUN_EVENT_STARTED, 'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
            'last_result_at' => '2026-11-09 13:50:00', 'last_success_at' => '2026-11-09 13:50:00',
            'interval_seconds' => 60, 'last_duration_ms' => 495000, 'last_detail' => '',
            'last_attempt_at' => gmdate('Y-m-d H:i:s', $now - 180),
        ];
        $html = $this->renderRows([['source' => 'autoimporter', 'state' => 'ok', 'row' => $row]], $now);
        self::assertStringContainsString('<p data-run-running>' . h(__t('system_status_cards.run_running_last', ['elapsed' => '3 Minuten', 'last' => '8 Minuten 15 Sekunden'])) . '</p>', $html);
        self::assertStringNotContainsString('data-run-overdue', $html, 'no warning while the run is within its grace');
        self::assertStringContainsString(h(__t('system_status.run_result_scope')), $html, 'the scope sentence belongs to a run in progress');

        $row['last_attempt_at'] = gmdate('Y-m-d H:i:s', $now - virtusphere_run_grace_seconds($row) - 60);
        $late = $this->renderRows([['source' => 'autoimporter', 'state' => 'warning', 'row' => $row]], $now);
        self::assertStringContainsString('<p class="status-action" data-run-overdue>' . h(__t('system_status.run_completion_hint', ['limit' => portal_format_duration(virtusphere_run_grace_seconds($row))])) . '</p>', $late);

        $completed = $row;
        $completed['last_event'] = VIRTUSPHERE_RUN_EVENT_COMPLETED;
        $done = $this->renderRows([['source' => 'autoimporter', 'state' => 'ok', 'row' => $completed]], $now);
        self::assertStringNotContainsString('data-run-running', $done);
        self::assertStringNotContainsString(h(__t('system_status.run_result_scope')), $done);
    }

    public function testTheUnchangedCounterSaysWhatItMeans(): void
    {
        $html = $this->renderAutoimporter('', VIRTUSPHERE_RUN_OUTCOME_OK, '{"folders":0,"created":0,"removed":0,"open_points":0,"unchanged":1}');
        self::assertStringContainsString('<dt>' . h(__t('system_status.summary_unchanged')) . '</dt><dd>' . h(__t('common.yes')) . '</dd>', $html);
        self::assertStringContainsString('<dt>' . h(__t('system_status.summary_folders')) . '</dt><dd>0</dd>', $html);
    }

    public function testEveryToneHasOneNeedOnTheHeadlineScale(): void
    {
        foreach (status_tones() as $tone) {
            $need = system_status_tone_need($tone);
            $rank = status_tone_rank($tone);
            self::assertSame($rank >= status_tone_rank('warning') ? 'act' : ($rank > status_tone_rank('ok') ? 'evidence' : 'none'), $need, $tone);
            self::assertStringContainsString('data-verdict="' . $need . '" data-verdict-tone="' . $tone . '"', system_status_verdict($tone), $tone);
        }
        self::assertStringContainsString(h(__t('system_status_cards.verdict_see_entry')), system_status_verdict('critical'));
        self::assertStringNotContainsString(h(__t('system_status_cards.verdict_see_entry')), system_status_verdict('ok'));
    }

    /**
     * Derived, not listed: every area that has an overview card renders a
     * verdict inside its section, found by the anchor constant the card jumps to.
     */
    public function testEveryOverviewAreaRendersAVerdictInItsSection(): void
    {
        $cards = system_status_overview_cards($this->integrationSnapshot(), 0, null, ['snapshot' => ['overall' => 'ok']], [], ['fully_current' => true]);
        $anchors = array_column($cards, 'anchor');
        self::assertCount(8, $anchors);
        $constants = array_flip(array_filter(
            get_defined_constants(),
            static fn ($value, string $name): bool => str_starts_with($name, 'VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_') && in_array($value, $anchors, true),
            ARRAY_FILTER_USE_BOTH
        ));
        $sources = [];
        foreach (glob(dirname(__DIR__, 2) . '/lib/system_status_*panel*.php') ?: [] as $file) {
            $sources[basename($file)] = (string) file_get_contents($file);
        }
        foreach ($anchors as $anchor) {
            $constant = $constants[$anchor] ?? null;
            self::assertNotNull($constant, $anchor);
            $sections = [];
            foreach ($sources as $source) {
                $offset = 0;
                while (($start = strpos($source, 'id="<?php echo h(' . $constant . '); ?>"', $offset)) !== false) {
                    $end = strpos($source, '</section>', $start);
                    $sections[] = substr($source, $start, $end === false ? null : $end - $start);
                    $offset = $start + 1;
                }
            }
            self::assertNotSame([], $sections, $constant . ' has no section');
            self::assertNotSame([], array_filter($sections, static fn (string $s): bool => preg_match('/\b(system_status_verdict|deploy_service_verdict)\(/', $s) === 1), $constant . ' renders no verdict line');
        }
    }

    public function testTheDeployServiceVerdictReadsTheCardToneAndHasAStepForEveryFault(): void
    {
        foreach ([VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY, VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED, VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN, VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE] as $availability) {
            foreach ([VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING, VIRTUSPHERE_DEPLOY_CLAIM_PAUSED] as $claim) {
                foreach ([VIRTUSPHERE_DEPLOY_ATTENTION_NONE, VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING, VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW] as $attention) {
                    $service = $this->serviceSnapshot($availability, $claim, $attention);
                    $card = $this->card(system_status_overview_cards($this->integrationSnapshot(), 0, $service), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE);
                    ob_start();
                    system_status_render_deploy_service($service, self::ADMIN);
                    $html = (string) ob_get_clean();
                    $label = $availability . '/' . $claim . '/' . $attention;
                    self::assertStringContainsString('data-verdict-tone="' . $card['tone'] . '"', $html, $label);
                    $action = deploy_service_verdict_action($service);
                    self::assertSame(system_status_tone_need($card['tone']) === 'none', $action['reason'] === '', $label . ': a state that is not in order has a step');
                }
            }
        }
        $cooldown = $this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN, VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING);
        $cooldown['supervisor'] = ['next_retry_at' => '2026-11-09 14:05:00'];
        self::assertStringContainsString('<time datetime="' . h(portal_time_iso('2026-11-09 14:05:00')) . '">', deploy_service_verdict_action($cooldown)['html']);
    }

    public function testTheHeartbeatRowOfTheDeployServicePointsToItsCard(): void
    {
        $snapshot = $this->integrationSnapshot();
        $snapshot['internal'] = ['state' => 'danger', 'rows' => [
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, 'row' => null, 'state' => 'danger'],
        ]];
        ob_start();
        system_status_render_internal($snapshot, self::ADMIN);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-deploy-worker-pointer', $html);
        self::assertStringContainsString('#' . VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE, $html);
        self::assertStringNotContainsString(h(__t('system_status.action_deploy_worker')), $html, 'the repair instruction lives on the deploy service card');
        self::assertStringContainsString('data-verdict-tone="' . status_tone_for('liveness', 'danger') . '"', $html);
    }

    public function testAnUnencryptedBackupColoursTheHostCardInsteadOfABanner(): void
    {
        $data = ['backup' => null, 'drill' => null, 'scrub' => null, 'https' => null];
        self::assertArrayNotHasKey('backup-encryption', system_status_host_checks($data + ['encryption' => null]), 'no finding, no row');
        $data['encryption'] = ['finding' => VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED];
        self::assertSame('warning', system_status_host_checks($data)['backup-encryption']);
        self::assertGreaterThanOrEqual(status_tone_rank('warning'), status_tone_rank(system_status_host_overview_tone($data)));
        ob_start();
        system_status_render_host_backup($data, self::ADMIN);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('data-host-state="backup-encryption" data-backup-encryption-finding', $html);
        self::assertStringContainsString(h(backup_encryption_finding_message(VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED)), $html);
        self::assertStringContainsString('data-verdict-tone="' . system_status_host_overview_tone($data) . '"', $html);

        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/system_status.php');
        self::assertStringNotContainsString('<div class="alert alert-warning" data-backup-encryption-finding', $page, 'no banner above the overview any more');
    }

    // ---- banners and badges outside the page --------------------------------

    public function testTheDashboardBannerNamesOnlyThePartsThatAreNotInOrder(): void
    {
        self::assertSame([], deploy_service_attention_parts($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY, VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING)));
        self::assertSame(
            [__t('system_status.service_claim_paused')],
            deploy_service_attention_parts($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_CLAIM_PAUSED))
        );
        self::assertSame(
            [__t('system_status.service_offline'), __t('system_status.service_attention_manual')],
            deploy_service_attention_parts($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE, VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING, VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW))
        );
    }

    public function testJobStatusBadgesCarryTheVocabularyIcon(): void
    {
        foreach ([VIRTUSPHERE_DEPLOY_STATUS_RUNNING => 'running', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => 'ok', VIRTUSPHERE_DEPLOY_STATUS_FAILED => 'critical'] as $status => $icon) {
            $badge = deploy_job_status_badge($status);
            self::assertStringContainsString('<span class="badge-icon badge-icon-' . $icon . '" aria-hidden="true"></span>', $badge, $status);
            self::assertStringContainsString(h(deploy_job_status_label($status)), $badge, $status);
        }
        $log = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/deploy_log.php');
        self::assertStringContainsString("'icon' => status_tone_meta(status_tone_for_variant(", $log, 'the live badge payload carries the icon');
        self::assertStringContainsString('<span data-deploy-status class="badge badge-<?php echo h(deploy_job_status_badge_class((string) $job[\'status\'])); ?>"><?php echo portal_badge_icon(', $log);
        self::assertStringContainsString("icon.className = 'badge-icon badge-icon-' + payload.job.icon;", (string) file_get_contents(dirname(__DIR__, 2) . '/portal/assets/deploy_log.js'));
    }

    // ---- fixtures -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function integrationSnapshot(): array
    {
        return [
            'mecm_sync' => ['state' => 'ok'],
            'mecm_site' => ['state' => 'ok', 'site_state' => VIRTUSPHERE_MECM_SITE_VERDICT_OK],
            'ansible' => ['state' => 'ok'],
            'esxi' => ['state' => 'ok'],
            'internal' => ['state' => 'ok', 'rows' => []],
        ];
    }

    /** @return array<string,mixed> */
    private function serviceSnapshot(string $availability, string $claim, string $attention = VIRTUSPHERE_DEPLOY_ATTENTION_NONE): array
    {
        return [
            'availability' => $availability,
            'claim_state' => $claim,
            'recovery_attention' => $attention,
            'source_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER,
            'badge' => deploy_service_badge_variant($availability, $attention, $claim),
            'queue' => ['due' => 0, 'scheduled' => 0, 'oldest_due_at' => null, 'overdue_seconds' => 0],
            'active' => ['job_id' => null, 'heartbeat_at' => null, 'count' => 0, 'jobs' => []],
            'service_heartbeat_at' => null,
            'claim' => ['state' => $claim, 'changed_at' => null, 'changed_by' => null, 'changed_by_name' => null],
            'recovery' => ['manual_required' => 0, 'legacy_uncertain_active' => 0, 'recovering' => 0, 'cases' => []],
            'supervisor' => [],
        ];
    }

    /**
     * @param list<array{anchor: string, label: string, tone: string, html: string}> $cards
     * @return array{anchor: string, label: string, tone: string, html: string}
     */
    private function card(array $cards, string $anchor): array
    {
        foreach ($cards as $card) {
            if ($card['anchor'] === $anchor) {
                return $card;
            }
        }
        self::fail('no card for ' . $anchor);
    }

    private function renderAutoimporter(string $detail, string $outcome, ?string $summary = null): string
    {
        return $this->renderRows([['source' => 'autoimporter', 'state' => $outcome === VIRTUSPHERE_RUN_OUTCOME_OK ? 'ok' : 'warning', 'row' => [
            'last_event' => VIRTUSPHERE_RUN_EVENT_COMPLETED,
            'last_status' => $outcome,
            'last_result_at' => '2026-11-09 13:50:00',
            'last_attempt_at' => '2026-11-09 13:45:00',
            'interval_seconds' => 60,
            'last_duration_ms' => 495000,
            'last_detail' => $detail,
            'last_summary' => $summary,
        ], 'report_overdue' => false]], (int) strtotime('2026-11-09 14:00:00 UTC'));
    }

    /** @param list<array<string,mixed>> $rows */
    private function renderRows(array $rows, int $now): string
    {
        ob_start();
        try {
            system_status_render_run_rows($rows, false, self::ADMIN, $now);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
