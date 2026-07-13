<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * Pure core of the per-host deploy warning (ADR-0023): which stored values are
 * missing from a credential's per-kind name sets. Shares the predicate family
 * of the deviation report, so the two can never disagree.
 */
final class EsxiInventoryMissingValuesTest extends TestCase
{
    public function testEmptyKindSetProvesNothing(): void
    {
        // The network set is empty (never pulled / kept-empty): its values must
        // not be reported missing; the datastore set is non-empty and does.
        $missing = esxi_inventory_missing_values(
            [
                VIRTUSPHERE_INVENTORY_KIND_NETWORK => ['VLAN_903'],
                VIRTUSPHERE_INVENTORY_KIND_DATASTORE => ['datastore2'],
            ],
            [
                VIRTUSPHERE_INVENTORY_KIND_NETWORK => [],
                VIRTUSPHERE_INVENTORY_KIND_DATASTORE => ['datastore1' => true],
            ]
        );

        self::assertSame(['datastore2'], $missing);
    }

    public function testComparisonIsCaseAndWhitespaceInsensitive(): void
    {
        $missing = esxi_inventory_missing_values(
            [VIRTUSPHERE_INVENTORY_KIND_NETWORK => [' vlan_903 ', 'VLAN_904']],
            [VIRTUSPHERE_INVENTORY_KIND_NETWORK => ['vlan_903' => true]]
        );

        self::assertSame(['VLAN_904'], $missing);
    }

    public function testEmptyValuesAreIgnored(): void
    {
        $missing = esxi_inventory_missing_values(
            [VIRTUSPHERE_INVENTORY_KIND_DATACENTER => ['', '  ']],
            [VIRTUSPHERE_INVENTORY_KIND_DATACENTER => ['dc1' => true]]
        );

        self::assertSame([], $missing);
    }

    public function testAggregatesAcrossKindsAndDeduplicatesCaseInsensitively(): void
    {
        $missing = esxi_inventory_missing_values(
            [
                VIRTUSPHERE_INVENTORY_KIND_NETWORK => ['VLAN_903', 'vlan_903'],
                VIRTUSPHERE_INVENTORY_KIND_DATASTORE => ['fastpool'],
            ],
            [
                VIRTUSPHERE_INVENTORY_KIND_NETWORK => ['vlan_901' => true],
                VIRTUSPHERE_INVENTORY_KIND_DATASTORE => ['datastore1' => true],
            ]
        );

        // First spelling wins the dedupe; output is sorted.
        self::assertSame(['fastpool', 'VLAN_903'], $missing);
    }
}
