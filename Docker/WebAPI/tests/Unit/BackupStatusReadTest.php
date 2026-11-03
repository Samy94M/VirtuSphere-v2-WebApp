<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/backup_status.php';

final class BackupStatusReadTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'vsbackup');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function writeLines(array $lines): void
    {
        $out = '';
        foreach ($lines as $line) {
            $out .= json_encode($line, JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($this->file, $out);
    }

    private function okLine(int $ageSeconds = 3600): array
    {
        return [
            'ts' => time() - $ageSeconds,
            'status' => 'ok',
            'db_bytes' => 2_000_000,
            'config_bytes' => 4096,
            'duration_s' => 3,
            'keep' => 14,
            'disk_free_pct' => 62,
            'disk_free_bytes' => 50_000_000_000,
            'error' => '',
        ];
    }

    public function testFreshOkRunIsOk(): void
    {
        $this->writeLines([$this->okLine()]);
        $status = backup_status_read($this->file);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, $status['state']);
        self::assertIsArray($status['last']);
        self::assertSame(14, (int) $status['last']['keep']);
    }

    public function testFailedRunIsFailed(): void
    {
        $line = $this->okLine();
        $line['status'] = 'failed';
        $line['error'] = 'DB-Dump verdaechtig klein';
        $this->writeLines([$this->okLine(7200), $line]);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_FAILED, backup_status_read($this->file)['state']);
    }

    public function testOldRunIsStale(): void
    {
        // Last successful run older than the 26h staleness window.
        $this->writeLines([$this->okLine(30 * 3600)]);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_STALE, backup_status_read($this->file)['state']);
    }

    public function testLowDiskIsFlagged(): void
    {
        $line = $this->okLine();
        $line['disk_free_pct'] = 5;
        $this->writeLines([$line]);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_DISK_LOW, backup_status_read($this->file)['state']);
    }

    public function testFailedBeatsStale(): void
    {
        // Old AND failed -> failed wins (severity failed > stale).
        $line = $this->okLine(30 * 3600);
        $line['status'] = 'failed';
        $this->writeLines([$line]);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_FAILED, backup_status_read($this->file)['state']);
    }

    public function testHalfWrittenTrailingLineIsSkipped(): void
    {
        $good = json_encode($this->okLine(), JSON_THROW_ON_ERROR);
        // Append a truncated/garbage line as a crashed writer would leave behind.
        file_put_contents($this->file, $good . "\n" . '{"ts":123,"status":"ok"' . "\n");
        $status = backup_status_read($this->file);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, $status['state']);
    }

    public function testLastValidLineWins(): void
    {
        $failed = $this->okLine(7200);
        $failed['status'] = 'failed';
        $ok = $this->okLine(1800);
        $this->writeLines([$failed, $ok]);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, backup_status_read($this->file)['state']);
    }

    public function testEmptyFileIsUnknown(): void
    {
        file_put_contents($this->file, '');
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_UNKNOWN, backup_status_read($this->file)['state']);
    }

    public function testMissingFileIsUnknown(): void
    {
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_UNKNOWN, backup_status_read('/does/not/exist/backup.jsonl')['state']);
    }

    // --- Schedule channel (ADR-0024) --------------------------------------

    private function at(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    private function local(?int $ts): ?string
    {
        return $ts === null
            ? null
            : (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');
    }

    /** @param array<string,mixed> $overrides */
    private function readWith(array $overrides, string $now): array
    {
        $this->writeLines([array_merge($this->okLine(), $overrides)]);

        return backup_status_read($this->file, $this->at($now));
    }

    /** @return array<string,mixed> */
    private function cronLine(string $lastRun): array
    {
        return [
            'ts' => $this->at($lastRun),
            'schedule' => '0 6 * * *',
            'schedule_tz' => 'Europe/Berlin',
            'schedule_source' => '/etc/cron.d/virtusphere-backup',
        ];
    }

    public function testAReportedCronScheduleDrivesTheNextRun(): void
    {
        $status = $this->readWith($this->cronLine('2026-07-09 06:00'), '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, $status['state']);
        self::assertSame(VIRTUSPHERE_BACKUP_NEXT_SCHEDULE, $status['next_run_source']);
        self::assertSame('2026-07-10 06:00', $this->local($status['next_run_ts']));
        // Grace window on top of the expected run, not on top of the last one.
        self::assertSame('2026-07-10 08:00', $this->local($status['overdue_at']));
        self::assertSame('/etc/cron.d/virtusphere-backup', $status['schedule_source']);
    }

    public function testAMissedCronRunIsStaleAndStillNamesTheUpcomingRun(): void
    {
        $status = $this->readWith($this->cronLine('2026-07-06 06:00'), '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_STATE_STALE, $status['state']);
        self::assertSame('2026-07-07 08:00', $this->local($status['overdue_at']), 'overdue since the missed run');
        self::assertSame('2026-07-10 06:00', $this->local($status['next_run_ts']), 'the schedule rolls forward');
    }

    public function testAWeeklyScheduleIsNotStaleAfterTwoDays(): void
    {
        // The old fixed 26h window would have flagged this; the schedule knows better.
        $weekly = [
            'ts' => $this->at('2026-07-05 03:00'),
            'schedule' => '0 3 * * 0',
            'schedule_tz' => 'Europe/Berlin',
            'schedule_source' => '/etc/cron.d/virtusphere-backup',
        ];
        $status = $this->readWith($weekly, '2026-07-07 12:00');

        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, $status['state']);
        self::assertSame('2026-07-12 03:00', $this->local($status['next_run_ts']));
    }

    public function testASystemdTimerReportingItsNextElapseWins(): void
    {
        $status = $this->readWith([
            'ts' => $this->at('2026-07-09 06:00'),
            'next_ts' => $this->at('2026-07-09 22:00'),
            'schedule_source' => 'systemd: virtusphere-backup.timer',
        ], '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_NEXT_REPORTED, $status['next_run_source']);
        self::assertSame('2026-07-09 22:00', $this->local($status['next_run_ts']));
    }

    public function testAMissedReportedElapseWithoutAScheduleAdmitsItDoesNotKnow(): void
    {
        // Rolling a one-shot report forward would be fabrication.
        $status = $this->readWith([
            'ts' => $this->at('2026-07-06 06:00'),
            'next_ts' => $this->at('2026-07-07 06:00'),
            'schedule_source' => 'systemd: virtusphere-backup.timer',
        ], '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_STATE_STALE, $status['state']);
        self::assertNull($status['next_run_ts']);
        self::assertNull($status['next_run_source']);
    }

    public function testALineWithoutScheduleFieldsFallsBackToTheInterval(): void
    {
        // Every status line written before ADR-0024 looks like this.
        $status = $this->readWith(['ts' => $this->at('2026-07-09 06:00')], '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_NEXT_ESTIMATED, $status['next_run_source']);
        self::assertSame('2026-07-10 06:00', $this->local($status['next_run_ts']));
        self::assertSame('', $status['schedule']);
    }

    public function testAStaleEstimateReportsNoNextRunRatherThanInventingOne(): void
    {
        $status = $this->readWith(['ts' => $this->at('2026-07-08 06:00')], '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_STATE_STALE, $status['state']);
        self::assertNull($status['next_run_ts']);
    }

    public function testAnUnparsableScheduleDegradesToTheInterval(): void
    {
        $status = $this->readWith([
            'ts' => $this->at('2026-07-09 06:00'),
            'schedule' => '@reboot',
            'schedule_tz' => 'Europe/Berlin',
        ], '2026-07-09 17:00');

        self::assertSame(VIRTUSPHERE_BACKUP_NEXT_ESTIMATED, $status['next_run_source']);
        self::assertSame(VIRTUSPHERE_BACKUP_STATE_OK, $status['state']);
    }

    public function testTheStaleWindowStaysIntervalPlusGrace(): void
    {
        self::assertSame(
            VIRTUSPHERE_BACKUP_INTERVAL_SECONDS + VIRTUSPHERE_BACKUP_GRACE_SECONDS,
            VIRTUSPHERE_BACKUP_STALE_AFTER_SECONDS
        );
    }
}
