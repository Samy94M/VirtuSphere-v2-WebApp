<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';

/**
 * Paket E chunk 2: parsing the inventory playbook's base64-JSON marker and
 * classifying fetch errors. Pure functions, no DB / no ESXi.
 */
final class AnsibleInventoryParseTest extends TestCase
{
    private function markerOutput(array $data): string
    {
        $b64 = base64_encode(json_encode($data, JSON_THROW_ON_ERROR));

        return "TASK [debug] noise\nok: [localhost] => {\n    \"msg\": \"VIRTUSPHERE_INVENTORY_B64_BEGIN{$b64}VIRTUSPHERE_INVENTORY_B64_END\"\n}\nPLAY RECAP\n";
    }

    public function testParsesAllKinds(): void
    {
        $out = $this->markerOutput([
            'fetched_epoch' => '1783570000',
            'datacenters' => ['DC1', 'ha-datacenter'],
            'datastores' => [['name' => 'ds-fast', 'capacity' => 2_000_000_000_000, 'freeSpace' => 500_000_000_000]],
            'networks_standard' => ['VLAN10', 'VLAN20'],
            'networks_dvs' => ['dvs-VLAN30'],
            'hosts' => ['ansible_hostname' => 'esxi-01', 'ansible_memtotal_mb' => 262144, 'ansible_processor_cores' => 40, 'hw_processor_model' => 'Xeon Gold'],
        ]);

        $parsed = ansible_parse_inventory_output($out);
        self::assertSame(['DC1', 'ha-datacenter'], $parsed['datacenters']);
        self::assertSame('ds-fast', $parsed['datastores'][0]['name']);
        self::assertSame(500_000_000_000, $parsed['datastores'][0]['free_bytes']);
        // Legacy plain-name entries stay valid and carry no meta.
        self::assertSame(['VLAN10', 'VLAN20', 'dvs-VLAN30'], array_column($parsed['networks'], 'name'));
        self::assertNull($parsed['networks'][0]['meta_json']);
        self::assertSame('esxi-01', $parsed['hosts'][0]['name']);
        self::assertSame(262144, $parsed['hosts'][0]['meta_json']['ram_mb']);
        self::assertSame(40, $parsed['hosts'][0]['meta_json']['cpu_cores']);
    }

    public function testParsesNetworkObjectsWithVlanIdsAndTrunks(): void
    {
        // Raw module objects (F-slice): standard portgroups name the field
        // 'portgroup', DVS ones 'portgroup_name'; the playbook no longer
        // projects, the parser extracts.
        $out = $this->markerOutput([
            'datacenters' => [],
            'datastores' => [],
            'networks_standard' => [
                ['portgroup' => 'VLAN_903', 'vswitch' => 'vSwitch0', 'vlan_id' => 903],
                ['portgroup' => 'Management Network', 'vlan_id' => 0],
                ['portgroup' => '', 'vlan_id' => 5],
            ],
            'networks_dvs' => [
                ['portgroup_name' => 'dvs-trunk', 'vlan_id' => '100-200'],
                ['portgroup_name' => 'vlan_903', 'vlan_id' => 905],
            ],
            'hosts' => [],
        ]);

        $parsed = ansible_parse_inventory_output($out);
        $byName = [];
        foreach ($parsed['networks'] as $item) {
            $byName[$item['name']] = $item['meta_json'];
        }

        self::assertSame(903, $byName['VLAN_903']['vlan_id']);
        self::assertFalse($byName['VLAN_903']['trunk']);
        // VLAN 0 (untagged) is a valid integer id.
        self::assertSame(0, $byName['Management Network']['vlan_id']);
        // A range is a trunk, never an integer id.
        self::assertNull($byName['dvs-trunk']['vlan_id']);
        self::assertTrue($byName['dvs-trunk']['trunk']);
        // Case-insensitive dedupe across both sources: the first item wins,
        // so the DVS case variant with a different id does not appear.
        self::assertArrayNotHasKey('vlan_903', $byName);
        // The empty-named object was dropped entirely.
        self::assertCount(3, $parsed['networks']);
    }

    public function testMissingMarkerThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ansible_parse_inventory_output("PLAY RECAP\nok=1 failed=0\n");
    }

    public function testErrorCategories(): void
    {
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTH, ansible_categorize_inventory_error('Cannot complete login due to an incorrect user name or password.', 2));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE, ansible_categorize_inventory_error('Unable to connect to the host: timed out', 2));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTHZ, ansible_categorize_inventory_error('Permission to perform this operation was denied.', 2));
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_PARSE, ansible_categorize_inventory_error('some other unexpected failure', 2));
    }
}
