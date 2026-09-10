<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VmRelationBatchingContractTest extends TestCase
{
    public function testGetVmsUsesBoundedRequestLocalRelationBatches(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/vms_legacy.php');

        self::assertMatchesRegularExpression('/const REPO_VM_RELATION_BATCH_SIZE = ([1-9][0-9]{0,3});/', $source);
        preg_match('/const REPO_VM_RELATION_BATCH_SIZE = ([1-9][0-9]{0,3});/', $source, $match);
        self::assertLessThanOrEqual(1000, (int) $match[1], 'prepared IN lists need a deliberate upper bound');
        self::assertStringContainsString('array_chunk($vmIds, REPO_VM_RELATION_BATCH_SIZE)', $source);
        self::assertSame(3, substr_count($source, "vm_id IN (' . \$placeholders . ')"));
        self::assertStringContainsString('ORDER BY dvp.vm_id, dp.package_name', $source);
        self::assertStringContainsString('ORDER BY vm_id, id', $source);
        self::assertStringNotContainsString('static $', $source, 'the aggregate must not cache rows across requests');
    }
}
