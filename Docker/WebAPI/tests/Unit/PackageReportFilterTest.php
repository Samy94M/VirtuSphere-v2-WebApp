<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/package_report_filter.php';

final class PackageReportFilterTest extends TestCase
{
    public function testLiteralSearchAndExactPackageInputsRemainDistinct(): void
    {
        $filter = package_report_filter([
            'scope' => 'package', 'project' => 'P%_!', 'version' => '1.0', 'name' => 'VM_%',
        ]);

        self::assertSame([], $filter['errors']);
        self::assertSame('P%_!', $filter['project']);
        self::assertSame('VM_%', $filter['name']);
        self::assertSame('scope=package&project=P%25_%21&version=1.0&name=VM_%25',
            package_report_filter_query($filter));
    }

    public function testMalformedFiltersCannotBecomeABroaderSearch(): void
    {
        self::assertArrayHasKey('name', package_report_filter(['name' => ['x']])['errors']);
        self::assertArrayHasKey('project', package_report_filter(['project' => 'P'])['errors']);
        self::assertArrayHasKey('state', package_report_filter(['state' => 'finished'])['errors']);
        self::assertArrayHasKey('before_at', package_report_filter([
            'before_at' => '2026-02-31 12:00:00.000000', 'before_id' => '4',
        ])['errors']);
        self::assertArrayHasKey('child_exit', package_report_filter(['child_exit' => '1'])['errors']);
        self::assertArrayHasKey('wrapper_exit', package_report_filter(['wrapper_exit' => '2147483648'])['errors']);
        self::assertArrayHasKey('exclude_vm', package_report_filter(['exclude_vm' => '0'])['errors']);
    }

    public function testPagingCursorIsValidAndCanBeClearedWithoutLosingFilters(): void
    {
        $filter = package_report_filter([
            'name' => 'Portal VM', 'before_at' => '2026-09-25 12:00:00.000000', 'before_id' => '4',
        ]);

        self::assertSame([], $filter['errors']);
        self::assertSame(4, $filter['before_id']);
        self::assertSame('name=Portal%20VM', package_report_filter_query($filter, [
            'before_at' => null, 'before_id' => null,
        ]));
    }
}
