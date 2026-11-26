<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/portal_time.php';

/**
 * E4 (i18n R15, user decision 26.11.2026): the English portal shows ISO dates,
 * the German portal keeps "d.m.Y H:i:s". The format follows the display
 * language only (R6); the three display formatters share it.
 */
final class PortalDateFormatTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testEnglishPortalShowsIsoDates(): void
    {
        Lang::load('en');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', portal_format_datetime('2026-09-10 17:06:34'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', portal_format_epoch(1_800_000_000));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \(/', portal_now_label());
    }

    public function testGermanPortalKeepsDayMonthYear(): void
    {
        Lang::load('de');

        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/', portal_format_datetime('2026-09-10 17:06:34'));
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/', portal_format_epoch(1_800_000_000));
        self::assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2} \(/', portal_now_label());
    }

    public function testBothLanguagesNameTheSameInstant(): void
    {
        Lang::load('en');
        $en = portal_format_datetime('2026-09-10 17:06:34');
        Lang::load('de');
        $de = portal_format_datetime('2026-09-10 17:06:34');

        $parsedEn = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $en);
        $parsedDe = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $de);
        self::assertNotFalse($parsedEn);
        self::assertNotFalse($parsedDe);
        self::assertSame($parsedDe->format('c'), $parsedEn->format('c'), 'only the notation differs, never the time');
    }

    public function testUnparsableInputStaysVerbatimInBothLanguages(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            self::assertSame('not a date', portal_format_datetime('not a date'));
            self::assertSame('', portal_format_datetime(''));
        }
    }
}
