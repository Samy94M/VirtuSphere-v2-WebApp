<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The "Last restore test" row of the Backup card in the settings (N1b). A passed
 * drill shows its time and age, a due one adds a warning and the command, and
 * missing evidence never renders as a date or without the command.
 */
final class SettingsBackupDrillCellTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/settings_backup_drill.php';
    }

    /** @return array{state:string,ts:int|null,age_days:int|null,backup_ts:string} */
    private function drill(string $state, ?int $ts, ?int $ageDays): array
    {
        return ['state' => $state, 'ts' => $ts, 'age_days' => $ageDays, 'backup_ts' => '20261027-060000'];
    }

    public function testARecentDrillShowsTimeAndAgeWithoutHint(): void
    {
        $html = settings_backup_drill_cell($this->drill(VIRTUSPHERE_BACKUP_EVIDENCE_OK, 1_800_000_000, 12));

        self::assertStringContainsString(h(portal_format_epoch(1_800_000_000)), $html);
        self::assertStringContainsString(h(__t('settings.backup_drill_age_days', ['days' => 12])), $html);
        self::assertStringNotContainsString('badge-warning', $html);
        self::assertStringNotContainsString('data-backup-drill-hint', $html);
        self::assertStringNotContainsString(h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND), $html);
    }

    public function testADueDrillWarnsWithTheThresholdFromItsConstantAndNamesTheCommand(): void
    {
        $html = settings_backup_drill_cell($this->drill(VIRTUSPHERE_BACKUP_EVIDENCE_DUE, 1_800_000_000, 40));

        self::assertStringContainsString(h(portal_format_epoch(1_800_000_000)), $html);
        self::assertStringContainsString('badge-warning', $html);
        self::assertStringContainsString(h(__t('settings.backup_drill_due_hint', ['days' => VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS])), $html);
        self::assertStringContainsString('<code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code>', $html);
    }

    /** @return iterable<string, array{array{state:string,ts:int|null,age_days:int|null,backup_ts:string}}> */
    public static function unknownEvidence(): iterable
    {
        yield 'no evidence' => [['state' => 'unknown', 'ts' => null, 'age_days' => null, 'backup_ts' => '']];
        yield 'ok state without a time' => [['state' => 'ok', 'ts' => null, 'age_days' => null, 'backup_ts' => '']];
        yield 'unknown state with a stray time' => [['state' => 'unknown', 'ts' => 1_800_000_000, 'age_days' => 3, 'backup_ts' => '']];
    }

    /**
     * Evidence that proves no passed drill.
     * @param array{state:string,ts:int|null,age_days:int|null,backup_ts:string} $drill
     */
    #[DataProvider('unknownEvidence')]
    public function testMissingEvidenceReadsNotOnRecordAndNamesTheCommand(array $drill): void
    {
        $html = settings_backup_drill_cell($drill);

        self::assertStringStartsWith(h(__t('settings.backup_drill_unknown')), $html);
        self::assertStringNotContainsString(h(portal_format_epoch(1_800_000_000)), $html, 'no date without evidence');
        self::assertStringContainsString(h(__t('settings.backup_drill_unknown_hint')), $html);
        self::assertStringContainsString('<code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code>', $html);
    }

    public function testAgeLabelsCoverTodayOneAndManyDays(): void
    {
        self::assertSame(__t('settings.backup_drill_age_today'), settings_backup_drill_age_label(0));
        self::assertSame(__t('settings.backup_drill_age_one'), settings_backup_drill_age_label(1));
        self::assertSame(__t('settings.backup_drill_age_days', ['days' => 2]), settings_backup_drill_age_label(2));
    }

    public function testTheCommandIsTheDrillThatWritesTheRecord(): void
    {
        self::assertSame('sudo sh scripts/restore_test.sh', VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND);
    }
}
