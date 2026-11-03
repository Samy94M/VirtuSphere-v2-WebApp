<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

/**
 * AV-R10: catalog_selected_version_groups() finds the basenames of which a VM
 * ticks two or more versions. The VM editor only warns about them; two versions
 * of one package on one VM stay allowed.
 */
final class SelectedPackageVersionGroupsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(int $id, string $name, string $basename, string $version): array
    {
        return ['id' => $id, 'package_name' => $name, 'package_basename' => $basename, 'package_version' => $version];
    }

    public function testTwoSelectedVersionsOfOneBasenameFormAGroupOrderedByVersion(): void
    {
        $packages = [
            self::row(1, 'Firefox-128.10', 'Firefox', '128.10'),
            self::row(2, 'Firefox-128.9', 'Firefox', '128.9'),
        ];

        // version_compare orders 128.9 before 128.10; a plain text sort would put 128.10 first.
        self::assertSame(
            ['Firefox' => ['Firefox-128.9', 'Firefox-128.10']],
            catalog_selected_version_groups($packages, [1, 2])
        );
    }

    public function testOneSelectedVersionOrAnUnselectedSecondVersionGivesNoGroup(): void
    {
        $packages = [
            self::row(1, 'Firefox-128.9', 'Firefox', '128.9'),
            self::row(2, 'Firefox-128.10', 'Firefox', '128.10'),
        ];

        self::assertSame([], catalog_selected_version_groups($packages, [1]));
        self::assertSame([], catalog_selected_version_groups($packages, []));
    }

    public function testRowsWithoutABasenameAreIgnored(): void
    {
        $packages = [
            self::row(1, 'Notepad', '', ''),
            self::row(2, 'Notepad-1.0', '', '1.0'),
        ];

        self::assertSame([], catalog_selected_version_groups($packages, [1, 2]));
    }

    public function testTwoPackagesWithTwoVersionsEachGiveTwoGroupsOrderedByBasename(): void
    {
        $packages = [
            self::row(1, 'Zed-2.0', 'Zed', '2.0'),
            self::row(2, 'Zed-1.0', 'Zed', '1.0'),
            self::row(3, 'Acrobat-11.0', 'Acrobat', '11.0'),
            self::row(4, 'Acrobat-9.0', 'Acrobat', '9.0'),
        ];

        self::assertSame(
            [
                'Acrobat' => ['Acrobat-9.0', 'Acrobat-11.0'],
                'Zed' => ['Zed-1.0', 'Zed-2.0'],
            ],
            catalog_selected_version_groups($packages, [1, 2, 3, 4])
        );
    }
}
