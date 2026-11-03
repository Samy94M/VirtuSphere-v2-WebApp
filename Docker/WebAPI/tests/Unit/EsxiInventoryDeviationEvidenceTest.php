<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory_deviations.php';

final class EsxiInventoryDeviationEvidenceTest extends TestCase
{
    public function testAuthoritativelyEmptyKindCanProveAConfiguredNameMissing(): void
    {
        self::assertTrue(esxi_inventory_value_unknown('VLAN 700', [], true));
        self::assertFalse(esxi_inventory_value_unknown('', [], true));
    }

    public function testEmptyUnqualifiedKindProvesNothing(): void
    {
        self::assertFalse(esxi_inventory_value_unknown('VLAN 700', [], false));
        self::assertFalse(esxi_inventory_value_unknown('VLAN 700', []));
    }

    public function testExactRawNamesStayDistinct(): void
    {
        self::assertFalse(esxi_inventory_value_unknown(' VLAN 700 ', [' VLAN 700 ' => true], true));
        self::assertTrue(esxi_inventory_value_unknown('VLAN 700', [' VLAN 700 ' => true], true));
        self::assertTrue(esxi_inventory_value_unknown('vlan 700', ['VLAN 700' => true], true));
    }
}
