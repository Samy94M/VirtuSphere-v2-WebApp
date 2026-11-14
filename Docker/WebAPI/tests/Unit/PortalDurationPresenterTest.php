<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

final class PortalDurationPresenterTest extends TestCase
{
    public function testSecondMinuteAndHourBoundariesUseRealSingularForms(): void
    {
        self::assertSame('0 Sekunden', portal_format_duration(0));
        self::assertSame('1 Sekunde', portal_format_duration(1));
        self::assertSame('59 Sekunden', portal_format_duration(59));
        self::assertSame('1 Minute', portal_format_duration(60));
        self::assertSame('59 Minuten 59 Sekunden', portal_format_duration(3599));
        self::assertSame('1 Stunde', portal_format_duration(3600));
    }

    public function testAtMostTwoUnitsTheLargestFirst(): void
    {
        // SY3, operator decision 09.11.2026: never "495 Sekunden".
        self::assertSame('8 Minuten 15 Sekunden', portal_format_duration(495));
        self::assertSame('1 Minute 1 Sekunde', portal_format_duration(61));
        self::assertSame('1 Stunde 30 Minuten', portal_format_duration(5400));
        self::assertSame('1 Stunde 2 Minuten', portal_format_duration(3725), 'the third unit drops out');
        self::assertSame('2 Stunden', portal_format_duration(7259), 'a zero second unit is left out');
        self::assertSame('25 Stunden', portal_format_duration(90000));
        self::assertSame('0 Sekunden', portal_format_duration(-5));
    }

    public function testRelativeTimeNamesOneUnitAndTheDirection(): void
    {
        self::assertSame('gerade eben', portal_format_relative(59));
        self::assertSame('vor 1 Minute', portal_format_relative(60));
        self::assertSame('vor 3 Minuten', portal_format_relative(239));
        self::assertSame('vor 2 Stunden', portal_format_relative(7300));
        self::assertSame('vor 3 Tagen', portal_format_relative(3 * 86400 + 5));
        self::assertSame('in 5 Minuten', portal_format_relative(-300));
        self::assertSame('in weniger als einer Minute', portal_format_relative(-10));
    }

    public function testTimeHtmlShowsRelativeAndAbsoluteWithAMachineReadableDatetime(): void
    {
        $now = (int) strtotime('2026-11-09 13:05:00 UTC');
        $html = portal_time_html('2026-11-09 13:02:00', $now);
        self::assertSame(
            '<time datetime="' . h(portal_time_iso('2026-11-09 13:02:00')) . '">vor 3 Minuten (' . h(portal_format_timestamp('2026-11-09 13:02:00')) . ')</time>',
            $html
        );
        self::assertMatchesRegularExpression('/\A2026-11-09T\d{2}:02:00[+-]\d{2}:\d{2}\z/', portal_time_iso('2026-11-09 13:02:00'), 'ISO 8601 with the display offset');
        // Older than a week: the date alone, still a <time> element.
        self::assertSame(
            '<time datetime="' . h(portal_time_iso('2026-10-01 08:00:00')) . '">' . h(portal_format_timestamp('2026-10-01 08:00:00')) . '</time>',
            portal_time_html('2026-10-01 08:00:00', $now)
        );
        self::assertSame('', portal_time_html('', $now));
        self::assertSame('', portal_time_html(null, $now));
        self::assertSame('not &lt;a&gt; date', portal_time_html('not <a> date', $now), 'an unreadable value is shown escaped, never as a wrong time');
    }

    public function testMillisecondPathReusesTheSameBoundaries(): void
    {
        self::assertSame('1 Sekunde', portal_format_duration_ms(1000));
        self::assertSame('1 Minute', portal_format_duration_ms(60000));
        self::assertSame('1 Stunde', portal_format_duration_ms(3600000));
    }
}
