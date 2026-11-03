<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/cron_schedule.php';

/**
 * ADR-0024: the backup card computes the next run from the cron expression that
 * backup.sh read out of the crontab it runs under. A wrong date here would be
 * worse than no date, so anything the evaluator does not fully understand must
 * come back as null instead of a guess.
 */
final class CronScheduleNextTest extends TestCase
{
    private const TZ = 'Europe/Berlin';

    private function at(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone(self::TZ)))->getTimestamp();
    }

    private function next(string $expression, string $from, string $timezone = self::TZ): ?string
    {
        $ts = cron_schedule_next($expression, $this->at($from), $timezone);
        if ($ts === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $ts))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d H:i');
    }

    public function testDailyScheduleRollsToTheNextDay(): void
    {
        self::assertSame('2026-07-10 06:00', $this->next('0 6 * * *', '2026-07-09 10:08'));
    }

    public function testTheSameDayIsUsedWhenTheTimeHasNotPassed(): void
    {
        self::assertSame('2026-07-09 06:00', $this->next('0 6 * * *', '2026-07-09 05:59'));
    }

    public function testTheCurrentMinuteNeverCountsAsTheNextRun(): void
    {
        // Otherwise a run finishing at exactly 06:00:00 would claim it is due now.
        self::assertSame('2026-07-10 06:00', $this->next('0 6 * * *', '2026-07-09 06:00'));
    }

    public function testStepAndRangeSyntax(): void
    {
        self::assertSame('2026-07-09 12:00', $this->next('0 */6 * * *', '2026-07-09 10:08'));
        self::assertSame('2026-07-09 11:30', $this->next('30 8-17 * * *', '2026-07-09 10:45'));
    }

    public function testWeekdayRangeSkipsTheWeekend(): void
    {
        // Friday 07:00 -> the next weekday run is Monday.
        self::assertSame('2026-07-13 06:00', $this->next('0 6 * * 1-5', '2026-07-10 07:00'));
    }

    public function testWeekdayNamesAndSundayAsSevenAreAccepted(): void
    {
        self::assertSame('2026-07-12 04:00', $this->next('0 4 * * sun', '2026-07-09 12:00'));
        self::assertSame('2026-07-12 04:00', $this->next('0 4 * * 7', '2026-07-09 12:00'));
    }

    public function testRestrictedDayOfMonthAndWeekdayAreOred(): void
    {
        // Cron's documented quirk: with both day fields set, either may match.
        // Friday 2026-07-10 matches "5" (weekday) although the 13th is later.
        self::assertSame('2026-07-10 00:00', $this->next('0 0 13 * 5', '2026-07-09 00:00'));
    }

    public function testMonthlyScheduleCrossesTheMonthBoundary(): void
    {
        self::assertSame('2026-08-01 03:15', $this->next('15 3 1 * *', '2026-07-09 10:08'));
    }

    public function testShorthandsExpand(): void
    {
        self::assertSame('2026-07-10 00:00', $this->next('@daily', '2026-07-09 10:08'));
        self::assertSame('2026-07-09 11:00', $this->next('@hourly', '2026-07-09 10:08'));
    }

    public function testDstEndKeepsTheWallClockTime(): void
    {
        // 2026-10-25 is the CEST -> CET fall-back; 03:30 exists once, in CET.
        self::assertSame('2026-10-25 03:30', $this->next('30 3 * * *', '2026-10-24 12:00'));
    }

    public function testAnUnknownTimezoneFallsBackToUtcRatherThanFailing(): void
    {
        $ts = cron_schedule_next('0 6 * * *', $this->at('2026-07-09 10:08'), 'Nicht/EineZone');
        self::assertNotNull($ts);
        self::assertSame('2026-07-10 06:00', (new DateTimeImmutable('@' . $ts))->format('Y-m-d H:i'));
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedExpressions(): array
    {
        return [
            'reboot has no calendar meaning' => ['@reboot'],
            'four fields' => ['0 6 * *'],
            'six fields (seconds)' => ['0 0 6 * * *'],
            'garbage' => ['kaputt'],
            'empty' => [''],
            'minute out of range' => ['60 6 * * *'],
            'hour out of range' => ['0 24 * * *'],
            'inverted range' => ['0 17-8 * * *'],
            'zero step' => ['*/0 6 * * *'],
            'unknown weekday name' => ['0 6 * * montag'],
            'quartz style' => ['0 6 ? * *'],
            'last-day extension' => ['0 6 L * *'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedExpressions')]
    public function testUnsupportedExpressionsYieldNull(string $expression): void
    {
        self::assertNull(cron_schedule_next($expression, $this->at('2026-07-09 10:08'), self::TZ));
    }

    public function testAScheduleThatNeverFiresYieldsNullInsteadOfLooping(): void
    {
        // February 30th does not exist; the search must give up at the horizon.
        self::assertNull($this->next('0 0 30 2 *', '2026-07-09 10:08'));
    }
}
