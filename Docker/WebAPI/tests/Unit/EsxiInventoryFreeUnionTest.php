<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * The free-space suffix of the datastore picker. A mission does not store its
 * target ESXi, so a name several hosts report has several free-space numbers and
 * the picker may only promise the smallest. Pure, no database.
 */
final class EsxiInventoryFreeUnionTest extends TestCase
{
    /** @param array<int, array<string, ?int>> $perCredential name => free bytes */
    private function union(array $perCredential): array
    {
        $groups = [];
        foreach ($perCredential as $freeByName) {
            $keyed = [];
            foreach ($freeByName as $name => $free) {
                $keyed[esxi_inventory_name_key((string) $name)] = $free;
            }
            $groups[] = ['free_by_key' => $keyed];
        }

        return esxi_inventory_free_union($groups);
    }

    public function testSmallestReportedFreeSpaceWins(): void
    {
        // The deploy could land on either host; only the tightest number is safe.
        $free = $this->union([['ds1' => 900], ['ds1' => 400], ['ds1' => 700]]);

        self::assertSame(['ds1' => 400], $free);
    }

    public function testNullFromOneCredentialDoesNotHideAnotherCredentialsNumber(): void
    {
        // A never-pulled row proves nothing; it must not win the min().
        self::assertSame(['ds1' => 500], $this->union([['ds1' => null], ['ds1' => 500]]));
        self::assertSame(['ds1' => 500], $this->union([['ds1' => 500], ['ds1' => null]]));
    }

    public function testNullEverywhereStaysNull(): void
    {
        // Datacenters carry no bytes, and so do cache rows written before the
        // column existed. The picker then renders the bare name.
        self::assertSame(['ha-datacenter' => null], $this->union([['ha-datacenter' => null], ['ha-datacenter' => null]]));
    }

    public function testCaseVariantsOfTheSameNameMerge(): void
    {
        // Same key as every other inventory comparison (esxi_inventory_name_key).
        self::assertSame(['ds1' => 100], $this->union([['DS1' => 300], ['ds1' => 100]]));
    }

    public function testDisjointNamesAreAllKept(): void
    {
        self::assertSame(['ds1' => 10, 'ds2' => 20], $this->union([['ds1' => 10], ['ds2' => 20]]));
    }

    public function testEmptyInventoryYieldsAnEmptyMap(): void
    {
        self::assertSame([], esxi_inventory_free_union([]));
        self::assertSame([], esxi_inventory_free_union([['names' => ['ds1']]]));
    }
}
