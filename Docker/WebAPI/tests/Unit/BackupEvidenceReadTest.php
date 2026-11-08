<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/backup_evidence.php';

/**
 * Restore-drill and checksum-scrub evidence. No evidence is `unknown`, never
 * `ok`: a missing file, a broken line, a future timestamp and a status the
 * writers never produce all prove nothing.
 */
final class BackupEvidenceReadTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'vsevidence');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /** @param list<array<string,mixed>|string> $lines */
    private function writeLines(array $lines): void
    {
        $out = '';
        foreach ($lines as $line) {
            $out .= (is_string($line) ? $line : json_encode($line, JSON_THROW_ON_ERROR)) . "\n";
        }
        file_put_contents($this->file, $out);
    }

    /** @return array<string,mixed> */
    private function drillLine(int $ageSeconds, string $stamp = '20261101-060000'): array
    {
        return ['ts' => self::NOW - $ageSeconds, 'status' => 'ok', 'backup_ts' => $stamp, 'root_commit' => 'abc'];
    }

    public function testMissingDrillFileIsUnknown(): void
    {
        unlink($this->file);
        $drill = backup_drill_status_read($this->file, self::NOW);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN, $drill['state']);
        self::assertNull($drill['ts']);
        self::assertNull($drill['age_days']);
    }

    public function testNewestSuccessWinsAndBrokenLinesAreSkipped(): void
    {
        $this->writeLines([
            $this->drillLine(40 * 86400, '20260901-060000'),
            $this->drillLine(3 * 86400 + 60, '20261105-060000'),
            '{"ts":',
            ['ts' => self::NOW - 60, 'status' => 'failed', 'backup_ts' => '20261108-060000'],
        ]);
        $drill = backup_drill_status_read($this->file, self::NOW);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_OK, $drill['state']);
        self::assertSame(3, $drill['age_days']);
        self::assertSame('20261105-060000', $drill['backup_ts']);
    }

    public function testDrillIsDueFromTheThresholdDayOn(): void
    {
        $this->writeLines([$this->drillLine((VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS - 1) * 86400 + 86399)]);
        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_OK, backup_drill_status_read($this->file, self::NOW)['state']);

        $this->writeLines([$this->drillLine(VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS * 86400)]);
        $drill = backup_drill_status_read($this->file, self::NOW);
        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_DUE, $drill['state']);
        self::assertSame(VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS, $drill['age_days']);
    }

    public function testFutureOrMalformedTimestampsProveNothing(): void
    {
        $this->writeLines([
            ['ts' => self::NOW + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS + 1, 'status' => 'ok', 'backup_ts' => '20261108-060000'],
            ['ts' => '1.5e9', 'status' => 'ok'],
            ['ts' => 0, 'status' => 'ok'],
            ['status' => 'ok'],
        ]);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN, backup_drill_status_read($this->file, self::NOW)['state']);
    }

    public function testSmallFutureSkewCountsAsToday(): void
    {
        $this->writeLines([['ts' => self::NOW + 30, 'status' => 'ok', 'backup_ts' => 'not-a-stamp']]);
        $drill = backup_drill_status_read($this->file, self::NOW);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_OK, $drill['state']);
        self::assertSame(0, $drill['age_days']);
        self::assertSame('', $drill['backup_ts'], 'a stamp that is not a backup.sh name is dropped');
    }

    /** @param array<string,mixed> $override */
    private function writeScrub(array $override = []): void
    {
        $this->writeLines([array_merge([
            'ts' => self::NOW - 3600,
            'status' => 'ok',
            'sets_checked' => 14,
            'sets_failed' => 0,
            'failed_sets' => [],
            'duration_s' => 12,
        ], $override)]);
    }

    public function testMissingScrubFileIsUnknown(): void
    {
        unlink($this->file);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN, backup_scrub_status_read($this->file, self::NOW)['state']);
    }

    public function testCleanScrubIsOk(): void
    {
        $this->writeScrub();
        $scrub = backup_scrub_status_read($this->file, self::NOW);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_OK, $scrub['state']);
        self::assertSame(self::NOW - 3600, $scrub['ts']);
        self::assertSame(14, $scrub['sets_checked']);
        self::assertSame([], $scrub['failed_sets']);
    }

    public function testAMismatchFailsWhateverTheStatusWordSays(): void
    {
        $this->writeScrub(['sets_failed' => 2, 'failed_sets' => ['20261101-060000', '../etc', '20261102-060000']]);
        $scrub = backup_scrub_status_read($this->file, self::NOW);

        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_FAILED, $scrub['state']);
        self::assertSame(['20261101-060000', '20261102-060000'], $scrub['failed_sets']);

        $this->writeScrub(['status' => 'failed']);
        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_FAILED, backup_scrub_status_read($this->file, self::NOW)['state']);
    }

    public function testAnImplausibleScrubResultIsUnknown(): void
    {
        foreach ([
            ['sets_checked' => 0],
            ['sets_checked' => '14'],
            ['sets_failed' => 15],
            ['sets_failed' => -1],
            ['status' => 'running'],
            ['ts' => self::NOW + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS + 1],
        ] as $override) {
            $this->writeScrub($override);
            self::assertSame(
                VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN,
                backup_scrub_status_read($this->file, self::NOW)['state'],
                json_encode($override, JSON_THROW_ON_ERROR)
            );
        }

        file_put_contents($this->file, '{"ts":');
        self::assertSame(VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN, backup_scrub_status_read($this->file, self::NOW)['state']);
    }

    public function testEvidenceFilesLiveBesideTheBackupStatusLine(): void
    {
        $dir = dirname(VIRTUSPHERE_BACKUP_STATUS_PATH);

        self::assertSame($dir . '/restore-drill.jsonl', backup_evidence_path(VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE));
        self::assertSame($dir . '/backup-scrub.json', backup_evidence_path(VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE));
        self::assertNotSame(VIRTUSPHERE_BACKUP_STATUS_PATH, backup_evidence_path(VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE));
    }
}
