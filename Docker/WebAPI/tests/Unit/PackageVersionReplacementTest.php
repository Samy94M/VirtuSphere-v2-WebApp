<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

/**
 * AV-R14: catalog_names_replaced_by_higher_version() decides which vanishing
 * names of a package sync are a version change, that is a strictly higher
 * version of the same basename in the same payload. Those names do not count
 * as a catalog loss in the retire guard.
 */
final class PackageVersionReplacementTest extends TestCase
{
    public function testAHigherVersionOfTheSameBasenameReplacesTheVanishingName(): void
    {
        self::assertSame(['Firefox-115.0'], catalog_names_replaced_by_higher_version(['Firefox-115.0'], ['Firefox-116.0']));
    }

    public function testAnEqualOrLowerVersionDoesNotReplace(): void
    {
        self::assertSame([], catalog_names_replaced_by_higher_version(['Firefox-116.0'], ['Firefox-116.0']));
        self::assertSame([], catalog_names_replaced_by_higher_version(['Firefox-116.0'], ['Firefox-115.0']));
    }

    public function testADifferentBasenameDoesNotReplace(): void
    {
        self::assertSame([], catalog_names_replaced_by_higher_version(['Firefox-115.0'], ['Chrome-130.0']));
        self::assertSame([], catalog_names_replaced_by_higher_version(['Firefox-115.0'], ['Firefox-ESR-116.0']));
    }

    public function testAVanishingNameWithoutVersionIsNotReplaced(): void
    {
        self::assertSame([], catalog_names_replaced_by_higher_version(['Notepad'], ['Notepad-2.0']));
    }

    public function testAPayloadNameWithoutVersionReplacesNothing(): void
    {
        self::assertSame([], catalog_names_replaced_by_higher_version(['Notepad-1.0'], ['Notepad']));
    }

    public function testAHyphenatedBasenameIsMatchedAtTheLastHyphen(): void
    {
        self::assertSame(['Foo-Bar-1.2'], catalog_names_replaced_by_higher_version(['Foo-Bar-1.2'], ['Foo-Bar-1.3']));
        // Foo-1.3 has the basename Foo, not Foo-Bar.
        self::assertSame([], catalog_names_replaced_by_higher_version(['Foo-Bar-1.2'], ['Foo-1.3']));
    }

    public function testSeveralVanishingVersionsOfOnePackageAreAllReplacedByOneHigherVersion(): void
    {
        self::assertSame(
            ['Tool-1.0', 'Tool-1.1', 'Tool-1.9'],
            catalog_names_replaced_by_higher_version(['Tool-1.0', 'Tool-1.1', 'Tool-1.9'], ['Tool-2.0'])
        );
    }

    public function testTheInputOrderIsKeptAndDuplicatesAreDropped(): void
    {
        self::assertSame(
            ['Zeta-1.0', 'Alpha-1.0', 'Mid-1.0'],
            catalog_names_replaced_by_higher_version(
                ['Zeta-1.0', 'Gone-1.0', 'Alpha-1.0', 'Mid-1.0'],
                ['Zeta-2.0', 'Alpha-2.0', 'Mid-2.0']
            )
        );
        self::assertSame(['Tool-1.0'], catalog_names_replaced_by_higher_version(['Tool-1.0', 'Tool-1.0'], ['Tool-2.0']));
    }

    /**
     * AV-R03: only a version of digits and dots takes part. The name 7-Zip has
     * the version Zip, and 2.0beta is not digits and dots, so neither replaces
     * anything and neither is replaced; both keep counting toward the guard.
     */
    public function testOnlyVersionsOfDigitsAndDotsTakePart(): void
    {
        // A vanishing version of letters is never replaced, not even by a numeric 2.0.
        self::assertSame([], catalog_names_replaced_by_higher_version(['7-Zip'], ['7-2.0']));
        // A payload version with a suffix replaces nothing.
        self::assertSame([], catalog_names_replaced_by_higher_version(['Tool-1.0'], ['Tool-2.0beta']));
    }
}
