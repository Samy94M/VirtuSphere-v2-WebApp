<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/format.php';

/**
 * virtusphere_human_bytes() is rendered twice for the same cell: by PHP when the
 * deploy queue table is built, and by the humanBytes() mirror in deploy.js when the
 * VM selection changes. Anything locale- or separator-dependent would make the
 * cell disagree with itself, so the format is pinned here.
 */
final class HumanBytesTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    public function testNullAndNegativeRenderAsEmptyString(): void
    {
        self::assertSame('', virtusphere_human_bytes(null));
        self::assertSame('', virtusphere_human_bytes(-1));
    }

    public function testBytesStayIntegral(): void
    {
        self::assertSame('0 B', virtusphere_human_bytes(0));
        self::assertSame('1023 B', virtusphere_human_bytes(1023));
    }

    public function testUnitsStepAtBinaryBoundaries(): void
    {
        self::assertSame('1.0 KB', virtusphere_human_bytes(1024));
        self::assertSame('1.0 MB', virtusphere_human_bytes(1024 ** 2));
        self::assertSame('1.0 GB', virtusphere_human_bytes(1024 ** 3));
        self::assertSame('1.0 TB', virtusphere_human_bytes(1024 ** 4));
    }

    public function testFourDigitValuesCarryNoThousandsSeparator(): void
    {
        // The regression this test exists for: number_format()'s default would
        // print "1,010.0 GB" while app.js prints "1010.0 GB" for the same total.
        self::assertSame('1010.0 GB', virtusphere_human_bytes(1010 * self::GB));
        self::assertSame('1023.9 GB', virtusphere_human_bytes((int) round(1023.9 * self::GB)));
    }

    public function testTerabytesDoNotStepFurther(): void
    {
        // TB is the last unit, so a petabyte-sized datastore keeps counting in TB
        // instead of falling off the table.
        self::assertSame('2048.0 TB', virtusphere_human_bytes(2048 * (1024 ** 4)));
    }
}
