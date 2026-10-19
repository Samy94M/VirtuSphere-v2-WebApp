<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/validate.php';

/**
 * Placeholders are filled in one pass. The replacement used to run in insertion
 * order, so ['page' => 2, 'pages' => 3] turned "Seite :page von :pages" into
 * "Seite 2 von 2s" on the deviation list of the system status page.
 */
final class LangReplaceTest extends TestCase
{
    public function testAShorterPlaceholderDoesNotEatALongerOne(): void
    {
        self::assertSame('Seite 2 von 3', Lang::replace('Seite :page von :pages', ['page' => 2, 'pages' => 3]));
        self::assertSame('Seite 2 von 3', Lang::replace('Seite :page von :pages', ['pages' => 3, 'page' => 2]));
    }

    public function testAFilledInValueIsNotSearchedAgain(): void
    {
        self::assertSame('VM :count gelöscht (1)', Lang::replace('VM :name gelöscht (:count)', ['name' => ':count', 'count' => 1]));
    }

    public function testTheValidatorFallbackUsesTheSameRule(): void
    {
        self::assertSame('2 of 3', validator_text('validate.no_such_key_for_this_test', ':page of :pages', ['page' => 2, 'pages' => 3]));
    }

    public function testWithoutPlaceholdersTheTextIsUnchanged(): void
    {
        self::assertSame('Seite :page', Lang::replace('Seite :page', []));
    }
}
