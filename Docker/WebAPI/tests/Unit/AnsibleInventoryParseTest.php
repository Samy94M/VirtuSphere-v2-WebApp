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

    /**
     * DVS portgroups keep the id one level down, under `vlan_info`, and say
     * there whether the portgroup is a trunk. Only `show_vlan_info: true` puts
     * that block into the module output at all; without it a DVS portgroup
     * arrives with a name and nothing else, which is a silent ID column, not an
     * error.
     */
    public function testParsesDvsVlanInfoBlock(): void
    {
        $out = $this->markerOutput([
            'datacenters' => [],
            'datastores' => [],
            'networks_standard' => [],
            'networks_dvs' => [
                ['portgroup_name' => 'dvs-prod', 'vlan_info' => ['trunk' => false, 'pvlan' => false, 'vlan_id' => 42]],
                ['portgroup_name' => 'dvs-untagged', 'vlan_info' => ['trunk' => false, 'pvlan' => false, 'vlan_id' => 0]],
                // A trunk reports its range as a list of start/end pairs.
                ['portgroup_name' => 'dvs-range', 'vlan_info' => ['trunk' => true, 'pvlan' => false, 'vlan_id' => [['start' => 1, 'end' => 4094]]]],
                // No show_vlan_info in the run: name only, no id, no trunk claim.
                ['portgroup_name' => 'dvs-quiet'],
            ],
            'hosts' => [],
        ]);

        $byName = [];
        foreach (ansible_parse_inventory_output($out)['networks'] as $item) {
            $byName[$item['name']] = $item['meta_json'];
        }

        self::assertSame(42, $byName['dvs-prod']['vlan_id']);
        self::assertFalse($byName['dvs-prod']['trunk']);
        self::assertSame(0, $byName['dvs-untagged']['vlan_id']);
        // The trunk flag wins over the id beside it: a range is never an id.
        self::assertNull($byName['dvs-range']['vlan_id']);
        self::assertTrue($byName['dvs-range']['trunk']);
        self::assertNull($byName['dvs-quiet']['vlan_id']);
        self::assertFalse($byName['dvs-quiet']['trunk']);
    }

    /**
     * Etappe 9 (decision 6): the pull learns which VMs the host actually holds,
     * because the name-collision gate cannot ask "is there already a VM called
     * X here" without it. `vmware_vm_info` reports `guest_name` and `moid`; it
     * reports the product `uuid` and the durable `instance_uuid`; both the
     * instance UUID and MOID must survive into the credential-scoped cache.
     */
    public function testParsesVirtualMachinesWithTheirHypervisorHandle(): void
    {
        $out = $this->markerOutput([
            'datacenters' => [],
            'datastores' => [],
            'networks_standard' => [],
            'networks_dvs' => [],
            'hosts' => [],
            'vms' => [
                ['guest_name' => 'WS-001', 'moid' => 'vm-24', 'uuid' => '4207072c-edd8-3bd5-64dc-903fd3a0db04', 'instance_uuid' => '503c89f1-5734-4d4d-a930-4d92b97a7289', 'power_state' => 'poweredOff', 'folder' => '/ha-datacenter/vm'],
                // Nameless entry: unusable for a name comparison, dropped, and
                // counted as a normalization loss rather than vanishing.
                ['guest_name' => '', 'moid' => 'vm-25'],
                // Case duplicate: one row per name, the first wins, exactly as
                // the network dedupe does. Two rows would break the unique key.
                ['guest_name' => 'ws-001', 'moid' => 'vm-99'],
                ['guest_name' => 'WS-002', 'moid' => 'vm-30', 'power_state' => 'poweredOn'],
            ],
        ]);

        $parsed = ansible_parse_inventory_output($out);
        self::assertSame(['WS-001', 'WS-002'], array_column($parsed['vms'], 'name'));
        self::assertSame('vm-24', $parsed['vms'][0]['meta_json']['moid']);
        self::assertSame('503c89f1-5734-4d4d-a930-4d92b97a7289', $parsed['vms'][0]['meta_json']['instance_uuid']);
        self::assertSame('poweredOff', $parsed['vms'][0]['meta_json']['power_state']);
        self::assertSame('vm-30', $parsed['vms'][1]['meta_json']['moid']);
        self::assertSame(['raw' => 4, 'kept' => 3], $parsed['normalization']['vms']);
    }

    /**
     * A VM without a MOID is still a name that occupies the host. The gate must
     * see it (and, having nothing to match against, treat it as foreign), so it
     * is kept with a null handle instead of dropped.
     */
    public function testAVirtualMachineWithoutAMoidIsKeptWithoutAHandle(): void
    {
        $out = $this->markerOutput([
            'datacenters' => [],
            'datastores' => [],
            'networks_standard' => [],
            'networks_dvs' => [],
            'hosts' => [],
            'vms' => [['guest_name' => 'WS-003']],
        ]);

        $parsed = ansible_parse_inventory_output($out);
        self::assertSame('WS-003', $parsed['vms'][0]['name']);
        self::assertNull($parsed['vms'][0]['meta_json']['moid']);
    }

    /**
     * The per-query report exists so an empty list stops being ambiguous. A
     * rejected query (the module refused the call) and a skipped one (the
     * playbook had nothing to ask about) both produce the same empty list as a
     * host that genuinely has none.
     */
    public function testParsesPerQueryOutcomes(): void
    {
        $out = $this->markerOutput([
            'datacenters' => ['ha-datacenter'],
            'datastores' => [],
            'networks_standard' => [],
            'networks_dvs' => [],
            'hosts' => [],
            'queries' => [
                'datacenters' => ['failed' => false, 'skipped' => false, 'msg' => ''],
                'networks_standard' => ['failed' => true, 'skipped' => false, 'msg' => 'one of the following is required: cluster_name, esxi_hostname'],
                'networks_dvs' => ['failed' => false, 'skipped' => true, 'msg' => ''],
            ],
        ]);

        $queries = ansible_parse_inventory_output($out)['queries'];
        self::assertSame(VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, $queries['datacenters']['state']);
        self::assertSame(VIRTUSPHERE_INVENTORY_QUERY_REJECTED, $queries['networks_standard']['state']);
        self::assertStringContainsString('cluster_name', $queries['networks_standard']['message']);
        self::assertSame(VIRTUSPHERE_INVENTORY_QUERY_SKIPPED, $queries['networks_dvs']['state']);
    }

    /**
     * A module message can be a traceback. The summary line promises to be one
     * line, so the newlines are collapsed before it is truncated; the verbatim
     * text is in the playbook output above it either way.
     */
    public function testQueryMessageIsCollapsedToOneLine(): void
    {
        $out = $this->markerOutput([
            'datacenters' => [],
            'queries' => ['hosts' => ['failed' => true, 'msg' => "Traceback:\n  File \"x.py\", line 1\r\n\tboom  boom"]],
        ]);

        $message = ansible_parse_inventory_output($out)['queries']['hosts']['message'];
        self::assertSame('Traceback: File "x.py", line 1 boom boom', $message);
        self::assertStringNotContainsString("\n", (string) ansible_inventory_queries_log_line(['hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => $message]]));
    }

    /** A long module message must not push the summary out of a reader's view. */
    public function testQueryMessageIsTruncated(): void
    {
        $out = $this->markerOutput([
            'datacenters' => [],
            'queries' => ['hosts' => ['failed' => true, 'msg' => str_repeat('x', VIRTUSPHERE_INVENTORY_QUERY_MESSAGE_MAX_LENGTH + 50)]],
        ]);

        $queries = ansible_parse_inventory_output($out)['queries'];
        self::assertSame(VIRTUSPHERE_INVENTORY_QUERY_MESSAGE_MAX_LENGTH, mb_strlen($queries['hosts']['message']));
    }

    public function testQueryLogLineNamesEveryQuietQuery(): void
    {
        $line = ansible_inventory_queries_log_line([
            'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
            'networks_standard' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'one of the following is required: cluster_name, esxi_hostname'],
            'networks_dvs' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_SKIPPED, 'message' => ''],
        ]);

        self::assertIsString($line);
        self::assertStringContainsString('1 of 3 answered', $line);
        self::assertStringContainsString('networks_standard rejected (one of the following is required', $line);
        self::assertStringContainsString('networks_dvs skipped', $line);
    }

    /**
     * The failure that actually happens is systematic: one wrong credential or
     * one bad argument list silences several queries with the identical
     * message. Rendered in the job log, repeating that sentence per query
     * produced a wall of text that hid the names. Same failure, one entry.
     */
    public function testQueryLogLineGroupsQueriesThatFailedTheSameWay(): void
    {
        $same = 'one of the following is required: cluster_name, esxi_hostname';
        $line = ansible_inventory_queries_log_line([
            'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
            'datastores' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => $same],
            'networks_standard' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => $same],
            'hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'something else'],
            'about' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_SKIPPED, 'message' => ''],
        ]);

        self::assertIsString($line);
        self::assertStringContainsString('datastores, networks_standard rejected (' . $same . ')', $line);
        self::assertSame(1, substr_count($line, $same), 'A shared message must be named once, not once per query.');
        // A different message stays its own entry, and so does a other state.
        self::assertStringContainsString('hosts rejected (something else)', $line);
        self::assertStringContainsString('about skipped', $line);
        self::assertStringContainsString('1 of 5 answered, 4 without an answer', $line);
    }

    /**
     * The all-good case is logged too. A line that only ever appears when
     * something is wrong never teaches a reader that a pull has parts, and this
     * line is where they find out which part was quiet.
     */
    public function testQueryLogLineAlsoReportsACompletePull(): void
    {
        $line = ansible_inventory_queries_log_line([
            'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
            'hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
        ]);

        self::assertSame('Inventory queries: all 2 answered.', $line);
    }

    /** An older playbook reports nothing; claiming completeness would be a lie. */
    public function testQueryLogLineStaysSilentWithoutTheReport(): void
    {
        self::assertNull(ansible_inventory_queries_log_line([]));
        self::assertSame([], ansible_parse_inventory_queries(null));
    }

    public function testNormalizationCountsRawAgainstKeptEntries(): void
    {
        // ansible_inventory.php used to drop unusable raw entries silently: a
        // module output whose shape stopped matching looked exactly like a host
        // with fewer portgroups, and nothing anywhere said so (B15). The parser
        // now reports raw vs. kept per kind. A case-duplicate is NOT a loss
        // (the dedupe is intentional); only unusable shapes count.
        $out = $this->markerOutput([
            'datacenters' => ['DC1', '   '],
            'datastores' => [['name' => 'ds1'], ['capacity' => 5], 'not-a-dict'],
            'networks_standard' => [['portgroup' => 'VLAN10'], 42, ['no_name_field' => true], ['portgroup' => 'vlan10']],
            'networks_dvs' => [],
            'hosts' => [],
        ]);

        $parsed = ansible_parse_inventory_output($out);
        $normalization = $parsed['normalization'];

        self::assertSame(['raw' => 2, 'kept' => 1], $normalization['datacenters']);
        self::assertSame(['raw' => 3, 'kept' => 1], $normalization['datastores']);
        // 4 raw: one good, one unusable int, one nameless dict, one
        // case-duplicate. The duplicate collapses in the dedupe but still
        // counts as kept here (it WAS parseable), so kept is 2, not 1.
        self::assertSame(['raw' => 4, 'kept' => 2], $normalization['networks']);
    }

    public function testNormalizationLogLineNamesTheDrops(): void
    {
        $line = ansible_inventory_normalization_log_line([
            'datacenters' => ['raw' => 2, 'kept' => 1],
            'datastores' => ['raw' => 3, 'kept' => 1],
            'networks' => ['raw' => 4, 'kept' => 4],
        ]);

        self::assertStringContainsString('datacenters 1 of 2', $line);
        self::assertStringContainsString('datastores 2 of 3', $line);
        self::assertStringNotContainsString('networks', $line);
    }

    public function testNormalizationLogLineSpeaksInTheGoodCaseToo(): void
    {
        // Same rule as the query and datastore-health lines: a line that only
        // ever appears when something is wrong does not teach the reader that
        // the check exists, and silence becomes ambiguous.
        $line = ansible_inventory_normalization_log_line([
            'datacenters' => ['raw' => 1, 'kept' => 1],
            'networks' => ['raw' => 13, 'kept' => 13],
        ]);

        self::assertStringContainsString('all 14 raw entries usable', $line);
    }

    public function testNormalizationLogLineIsNullForAPullWithoutRawEntries(): void
    {
        self::assertNull(ansible_inventory_normalization_log_line([
            'datacenters' => ['raw' => 0, 'kept' => 0],
            'networks' => ['raw' => 0, 'kept' => 0],
        ]));
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

    /**
     * A failure of our own deployment must never be dressed up as an answer
     * from the host. A production pull died on a playbook that was never
     * uploaded and was reported as "the host answered unexpectedly", which sent
     * the operator to check ESXi, the network and the credentials for a file
     * that had not left the portal container.
     */
    public function testOurOwnMissingFilesAreConfigNotAnAnswerFromTheHost(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            ansible_categorize_inventory_error('ERROR! the playbook: inventoryESXi_playbook.yml could not be found', 1)
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            ansible_categorize_inventory_error("ERROR! couldn't resolve module/action 'community.vmware.vmware_datastore_info'", 1)
        );
    }

    public function testTheHostStillOutranksOurConfigWhenItReallyAnswered(): void
    {
        // A permission or login failure names a real answer from ESXi, so it
        // must keep its specific category even though the wording could brush
        // against the config patterns.
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_AUTHZ,
            ansible_categorize_inventory_error('Permission to perform this operation was denied.', 2)
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_AUTH,
            ansible_categorize_inventory_error('Cannot complete login due to an incorrect user name or password.', 2)
        );
    }
}
