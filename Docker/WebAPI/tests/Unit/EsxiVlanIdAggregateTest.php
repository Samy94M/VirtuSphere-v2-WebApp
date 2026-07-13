<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * Pure aggregation behind the catalog's VLAN-ID column (F-slice): per name,
 * which integer IDs are reported by which credentials; trunks stay out of the
 * comparison; rows without meta (old cache, legacy names) contribute nothing.
 */
final class EsxiVlanIdAggregateTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $name, ?array $meta, string $credential): array
    {
        return [
            'name' => $name,
            'meta_json' => $meta !== null ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'credential_name' => $credential,
        ];
    }

    public function testConsistentIdAcrossHosts(): void
    {
        $aggregate = repo_esxi_vlan_id_aggregate([
            $this->row('VLAN_903', ['vlan_id' => 903, 'trunk' => false], 'esxi-01'),
            $this->row('VLAN_903', ['vlan_id' => 903, 'trunk' => false], 'esxi-02'),
        ]);

        self::assertSame([903 => ['esxi-01', 'esxi-02']], $aggregate['ids']['vlan_903']);
        self::assertSame([], $aggregate['trunks']);
    }

    public function testMismatchKeepsBothIdsWithTheirHosts(): void
    {
        $aggregate = repo_esxi_vlan_id_aggregate([
            $this->row('VLAN_903', ['vlan_id' => 903, 'trunk' => false], 'esxi-01'),
            $this->row('vlan_903', ['vlan_id' => 905, 'trunk' => false], 'esxi-07'),
        ]);

        // Case variants aggregate under one name key.
        self::assertSame([903 => ['esxi-01'], 905 => ['esxi-07']], $aggregate['ids']['vlan_903']);
    }

    public function testTrunksNeverEnterTheIdComparison(): void
    {
        $aggregate = repo_esxi_vlan_id_aggregate([
            $this->row('dvs-trunk', ['vlan_id' => null, 'trunk' => true], 'esxi-01'),
            $this->row('dvs-trunk', ['vlan_id' => 42, 'trunk' => false], 'esxi-02'),
        ]);

        self::assertSame([42 => ['esxi-02']], $aggregate['ids']['dvs-trunk']);
        self::assertSame(['esxi-01'], $aggregate['trunks']['dvs-trunk']);
    }

    public function testRowsWithoutMetaContributeNothing(): void
    {
        $aggregate = repo_esxi_vlan_id_aggregate([
            $this->row('VLAN_701', null, 'esxi-01'),
            $this->row('VLAN_701', ['vlan_id' => null, 'trunk' => false], 'esxi-02'),
        ]);

        self::assertSame([], $aggregate['ids']);
        self::assertSame([], $aggregate['trunks']);
    }
}
