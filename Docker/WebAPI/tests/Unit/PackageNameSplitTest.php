<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

final class PackageNameSplitTest extends TestCase
{
    public function testSplitsAtLastHyphen(): void
    {
        self::assertSame(['basename' => 'Firefox', 'version' => '115.0'], repo_package_split_name('Firefox-115.0'));
        self::assertSame(['basename' => '7-Zip', 'version' => '23.01'], repo_package_split_name('7-Zip-23.01'));
        self::assertSame(['basename' => 'Name-Sub', 'version' => '1.2.3'], repo_package_split_name('Name-Sub-1.2.3'));
    }

    public function testNoHyphenKeepsFullNameAsBasename(): void
    {
        self::assertSame(['basename' => 'Notepad', 'version' => ''], repo_package_split_name('Notepad'));
    }

    public function testEdgeHyphensDoNotSplit(): void
    {
        self::assertSame(['basename' => 'Trailing-', 'version' => ''], repo_package_split_name('Trailing-'));
        self::assertSame(['basename' => '-Leading', 'version' => ''], repo_package_split_name('-Leading'));
    }
}
