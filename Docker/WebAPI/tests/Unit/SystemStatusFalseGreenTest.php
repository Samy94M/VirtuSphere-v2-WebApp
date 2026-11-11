<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SY1: overview cards that read green while the area below them is not OK.
 *
 * - K1: a paused deploy service read "Ready" in green, although it takes no new
 *   job; the card below showed the pause on its claim axis.
 * - K2: backup, restore drill, checksums and certificate had no card at all, so
 *   a failed backup left the whole strip green.
 * - Audit: the deviation card read green "No deviations" over partial or old
 *   evidence, while its own section said that is no release.
 *
 * Each test renders the real overview strip, so the badge an operator reads is
 * what is checked, not a helper in isolation.
 */
final class SystemStatusFalseGreenTest extends TestCase
{
    private const ADMIN = ['id' => 1, 'role' => 'admin'];

    /** One status badge: the variant, the decorative icon span and the word (SY2). */
    private const BADGE = '<span class="badge badge-[a-z]+"><span class="badge-icon badge-icon-[a-z-]+" aria-hidden="true"><\/span>[^<]*<\/span>';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_panels.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_backup_panels.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    /** @return array<string,mixed> */
    private function integrationSnapshot(): array
    {
        return [
            'mecm_sync' => ['state' => 'ok'],
            'mecm_site' => ['state' => 'ok', 'site_state' => VIRTUSPHERE_MECM_SITE_VERDICT_OK],
            'ansible' => ['state' => 'ok'],
            'esxi' => ['state' => 'ok'],
            'internal' => ['state' => 'ok'],
        ];
    }

    /** @return array<string,mixed> the fields of deploy_service_health_snapshot() the panels read */
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
     * @param array<string,mixed>|null $service
     * @param array<string,mixed>|null $host
     * @param array<string,mixed>|null $deviationReport
     */
    private function overview(?array $service = null, ?array $host = null, ?int $deviationCount = 0, ?array $deviationReport = ['fully_current' => true]): string
    {
        ob_start();
        system_status_render_overview(
            $this->integrationSnapshot(),
            $deviationCount,
            [],
            $service ?? $this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING),
            null,
            $host,
            $deviationReport
        );

        return (string) ob_get_clean();
    }

    /** The badge of the card that jumps to $anchor. */
    private function card(string $html, string $anchor): string
    {
        self::assertSame(
            1,
            preg_match('/<a class="status-overview-card" href="system_status\.php#' . preg_quote($anchor, '/') . '"[^>]*>.*?(' . self::BADGE . ').*?<\/a>/s', $html, $match),
            $anchor . ' card missing'
        );

        return $match[1];
    }

    /** @param array<string,mixed> $overrides */
    private function hostData(array $overrides): array
    {
        $okBackup = ['state' => VIRTUSPHERE_BACKUP_STATE_OK, 'last' => ['ts' => 1_800_000_000, 'status' => 'ok']];
        $okDrill = ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_OK, 'ts' => 1_800_000_000, 'age_days' => 3, 'backup_ts' => '20261101-060000'];
        $okScrub = ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_OK, 'ts' => 1_800_000_000, 'sets_checked' => 4, 'sets_failed' => 0];

        return array_merge(['backup' => $okBackup, 'drill' => $okDrill, 'scrub' => $okScrub, 'https' => null], $overrides);
    }

    public function testAPausedDeployServiceIsYellowInTheOverviewWithTheCardsOwnWords(): void
    {
        foreach ([VIRTUSPHERE_DEPLOY_CLAIM_PAUSED, VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT] as $claim) {
            $badge = $this->card($this->overview($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, $claim)), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE);
            self::assertStringContainsString('badge-warning', $badge, $claim . ' must not read green');
            self::assertStringContainsString(h(deploy_service_claim_label($claim)), $badge, 'the overview names the pause as the claim badge does');
            self::assertStringNotContainsString(h(__t('system_status.service_ready')), $badge);
        }

        $accepting = $this->card($this->overview(), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE);
        self::assertStringContainsString('badge-success', $accepting);
        self::assertStringContainsString(h(__t('system_status.service_ready')), $accepting);
    }

    public function testAFaultStillOutranksThePauseInTheOverview(): void
    {
        $badge = $this->card(
            $this->overview($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE, VIRTUSPHERE_DEPLOY_CLAIM_PAUSED)),
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE
        );
        self::assertStringContainsString('badge-danger', $badge);
        self::assertStringContainsString(h(__t('system_status.service_offline')), $badge);
    }

    public function testThePausedServiceCardSaysWhatThePauseCostsAndHowItEnds(): void
    {
        foreach ([
            VIRTUSPHERE_DEPLOY_CLAIM_PAUSED => 'system_status.service_paused_hint',
            VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT => 'system_status.service_pause_after_current_hint',
        ] as $claim => $key) {
            ob_start();
            system_status_render_deploy_service($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, $claim), ['id' => 2, 'role' => 'user']);
            $html = (string) ob_get_clean();
            self::assertStringContainsString('<p class="status-action" data-claim-hint>' . h(__t($key)) . '</p>', $html, $claim);
        }

        ob_start();
        system_status_render_deploy_service($this->serviceSnapshot(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING), self::ADMIN);
        self::assertStringNotContainsString('data-claim-hint', (string) ob_get_clean());
    }

    public function testAFailedBackupTurnsTheHostCardRed(): void
    {
        $failed = ['state' => VIRTUSPHERE_BACKUP_STATE_FAILED, 'last' => ['ts' => 1_800_000_000, 'status' => 'failed']];
        $badge = $this->card($this->overview(null, $this->hostData(['backup' => $failed])), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);

        self::assertStringContainsString('badge-danger', $badge);
        self::assertStringContainsString(h(status_tone_word('critical')), $badge);
    }

    public function testTheHostCardShowsTheWorstRowAndUnknownIsNeverOk(): void
    {
        $healthy = $this->card($this->overview(null, $this->hostData([])), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);
        self::assertStringContainsString('badge-success', $healthy);

        // One row without evidence keeps the card from reading OK.
        $unknown = $this->card($this->overview(null, $this->hostData(['drill' => null])), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);
        self::assertStringContainsString('badge-neutral', $unknown);
        self::assertStringContainsString(h(status_tone_word('no_data')), $unknown);

        // Yellow outranks grey, red outranks yellow.
        $due = ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_DUE, 'ts' => 1_800_000_000, 'age_days' => 90, 'backup_ts' => ''];
        $mismatch = ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_FAILED, 'ts' => 1_800_000_000, 'sets_checked' => 4, 'sets_failed' => 1, 'failed_sets' => ['x']];
        $warning = $this->card($this->overview(null, $this->hostData(['backup' => null, 'drill' => $due])), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);
        self::assertStringContainsString('badge-warning', $warning);
        self::assertStringContainsString(h(status_tone_word('warning')), $warning);
        $danger = $this->card($this->overview(null, $this->hostData(['drill' => $due, 'scrub' => $mismatch])), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);
        self::assertStringContainsString('badge-danger', $danger);

        // The certificate counts while nginx serves HTTPS.
        $expired = $this->card(
            $this->overview(null, $this->hostData(['https' => ['valid_to' => 1_800_000_000, 'days_remaining' => -2]])),
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP
        );
        self::assertStringContainsString('badge-danger', $expired);
        self::assertStringContainsString(h(status_tone_word('critical')), $expired);

        // A caller without host data gets unknown, never OK.
        $none = $this->card($this->overview(), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);
        self::assertStringContainsString('badge-neutral', $none);
    }

    public function testTheHostCardAndItsSectionShowTheSameBadge(): void
    {
        $data = $this->hostData(['scrub' => null]);
        $card = $this->card($this->overview(null, $data), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP);

        ob_start();
        system_status_render_host_backup($data, self::ADMIN);
        $section = (string) ob_get_clean();

        self::assertStringContainsString('<section class="panel status-section" id="' . VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP . '">', $section);
        self::assertSame(1, preg_match('/data-host-state="backup-scrub">.*?(' . self::BADGE . ')/s', $section, $row));
        self::assertSame($card, $row[1], 'the worst row and the card are the same badge');
    }

    public function testAZeroOverIncompleteEvidenceIsNotGreen(): void
    {
        foreach ([['fully_current' => false, 'fully_evaluable' => false], ['fully_current' => false, 'fully_evaluable' => true], null] as $report) {
            $badge = $this->card($this->overview(null, null, 0, $report), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS);
            self::assertStringContainsString('badge-neutral', $badge);
            self::assertStringContainsString(h(status_tone_word('evidence_incomplete')), $badge);
        }

        $clean = $this->card($this->overview(null, null, 0, ['fully_current' => true]), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS);
        self::assertStringContainsString('badge-success', $clean);
        // A count above zero stays a count, whatever the evidence.
        $found = $this->card($this->overview(null, null, 3, ['fully_current' => false]), VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS);
        self::assertStringContainsString('badge-warning', $found);
    }
}
