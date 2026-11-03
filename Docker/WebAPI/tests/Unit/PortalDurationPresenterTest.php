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
        self::assertSame('3599 Sekunden', portal_format_duration(3599));
        self::assertSame('1 Stunde', portal_format_duration(3600));
    }

    public function testMillisecondPathReusesTheSameBoundaries(): void
    {
        self::assertSame('1 Sekunde', portal_format_duration_ms(1000));
        self::assertSame('1 Minute', portal_format_duration_ms(60000));
        self::assertSame('1 Stunde', portal_format_duration_ms(3600000));
    }
}
