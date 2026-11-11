<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The rows of the "Host and backup" section. Each row's badge speaks only
 * for its own facts, missing evidence never renders as OK, and a hint that
 * names another page links it only for a user who may open it.
 */
final class SystemStatusBackupPanelTest extends TestCase
{
    private const ADMIN = ['id' => 1, 'role' => 'admin'];
    private const OPERATOR = ['id' => 2, 'role' => 'user'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_backup_panels.php';
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $user
     */
    private function render(array $data, array $user = self::ADMIN): string
    {
        ob_start();
        system_status_render_host_backup(array_merge(['backup' => null, 'drill' => null, 'scrub' => null, 'https' => null], $data), $user);

        return (string) ob_get_clean();
    }

    private function row(string $html, string $kind): string
    {
        self::assertSame(1, preg_match('/<article class="status-row" data-host-state="' . preg_quote($kind, '/') . '">.*?<\/article>/s', $html, $match), $kind . ' row missing');

        return $match[0];
    }

    public function testNoEvidenceAtAllReadsUnknownInEveryRowAndNamesTheCommands(): void
    {
        $html = $this->render([]);

        foreach (['backup', 'restore-drill', 'backup-scrub'] as $kind) {
            $row = $this->row($html, $kind);
            self::assertStringNotContainsString('badge-success', $row, $kind . ' must not claim OK without evidence');
        }
        self::assertStringContainsString(h(status_tone_word('no_data')), $this->row($html, 'restore-drill'));
        self::assertStringContainsString('<code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code>', $html);
        self::assertStringContainsString('<code>' . h(VIRTUSPHERE_BACKUP_SCRUB_COMMAND) . '</code>', $html);
        self::assertStringNotContainsString('data-host-state="https-certificate"', $html, 'no certificate row without a live HTTPS listener');
    }

    public function testARecentDrillShowsItsAgeAndNoHint(): void
    {
        $row = $this->row($this->render(['drill' => [
            'state' => VIRTUSPHERE_BACKUP_EVIDENCE_OK,
            'ts' => 1_800_000_000,
            'age_days' => 12,
            'backup_ts' => '20261027-060000',
        ]]), 'restore-drill');

        self::assertStringContainsString('badge-success', $row);
        self::assertStringContainsString(h(__t('system_status_backup.age_days', ['days' => 12])), $row);
        self::assertStringContainsString('<code>20261027-060000</code>', $row);
        self::assertStringNotContainsString('status-action', $row);
    }

    public function testADueDrillWarnsWithTheThresholdFromItsConstant(): void
    {
        $row = $this->row($this->render(['drill' => [
            'state' => VIRTUSPHERE_BACKUP_EVIDENCE_DUE,
            'ts' => 1_800_000_000,
            'age_days' => 40,
            'backup_ts' => '',
        ]]), 'restore-drill');

        self::assertStringContainsString('badge-warning', $row);
        self::assertStringContainsString(h(__t('system_status_backup.drill_due_hint', ['days' => VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS])), $row);
        self::assertStringContainsString('&mdash;', $row, 'a drill line without a stamp shows the placeholder');
    }

    public function testAgeLabelsPickTheSentenceByCount(): void
    {
        self::assertSame(__t('system_status_backup.age_today'), system_status_backup_age_label(0));
        self::assertSame(__t('system_status_backup.age_days_one', ['days' => 1]), system_status_backup_age_label(1));
        self::assertSame(__t('system_status_backup.age_days', ['days' => 2]), system_status_backup_age_label(2));
    }

    public function testAScrubMismatchIsDangerAndNamesTheSets(): void
    {
        $row = $this->row($this->render(['scrub' => [
            'state' => VIRTUSPHERE_BACKUP_EVIDENCE_FAILED,
            'ts' => 1_800_000_000,
            'sets_checked' => 14,
            'sets_failed' => 2,
            'failed_sets' => ['20261101-060000', '20261102-060000'],
        ]]), 'backup-scrub');

        self::assertStringContainsString('badge-danger', $row);
        self::assertStringContainsString('20261101-060000, 20261102-060000', $row);
        self::assertStringContainsString(h(__t('system_status_backup.scrub_result', ['checked' => 14, 'failed' => 2])), $row);
    }

    public function testAFailedBackupLinksTheSettingsCardOnlyForConfigUsers(): void
    {
        $backup = ['state' => VIRTUSPHERE_BACKUP_STATE_FAILED, 'last' => ['ts' => 1_800_000_000, 'status' => 'failed']];

        $admin = $this->row($this->render(['backup' => $backup]), 'backup');
        self::assertStringContainsString('badge-danger', $admin);
        self::assertStringContainsString(h(backup_status_message(VIRTUSPHERE_BACKUP_STATE_FAILED)), $admin);
        self::assertStringContainsString('href="' . h(settings_url('backup')) . '"', $admin);

        $operator = $this->row($this->render(['backup' => $backup], self::OPERATOR), 'backup');
        self::assertStringContainsString(h(backup_status_message(VIRTUSPHERE_BACKUP_STATE_FAILED)), $operator);
        self::assertStringNotContainsString('href=', $operator);
    }

    public function testAHealthyBackupHasNoHint(): void
    {
        $row = $this->row($this->render(['backup' => [
            'state' => VIRTUSPHERE_BACKUP_STATE_OK,
            'last' => ['ts' => 1_800_000_000, 'status' => 'ok'],
        ]]), 'backup');

        self::assertStringContainsString('badge-success', $row);
        self::assertStringNotContainsString('status-action', $row);
    }

    public function testCertificateRowFollowsTheSharedExpiryThreshold(): void
    {
        $meta = static fn (int $days): array => ['valid_to' => 1_800_000_000, 'days_remaining' => $days];

        $fine = $this->row($this->render(['https' => $meta(VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS + 1)]), 'https-certificate');
        self::assertStringContainsString('badge-success', $fine);
        self::assertStringNotContainsString('status-action', $fine);

        $soon = $this->row($this->render(['https' => $meta(VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS)]), 'https-certificate');
        self::assertStringContainsString('badge-warning', $soon);
        self::assertStringContainsString(h(__t('settings.https_meta_expires_soon', ['days' => VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS])), $soon);
        self::assertStringContainsString('href="' . h(settings_url(VIRTUSPHERE_SETTINGS_TAB_HTTPS)) . '"', $soon);

        $expired = $this->row($this->render(['https' => $meta(-1)], self::OPERATOR), 'https-certificate');
        self::assertStringContainsString('badge-danger', $expired);
        self::assertStringContainsString(h(__t('system_status_backup.https_expired_hint')), $expired);
        self::assertStringNotContainsString('href=', $expired);
    }
}
