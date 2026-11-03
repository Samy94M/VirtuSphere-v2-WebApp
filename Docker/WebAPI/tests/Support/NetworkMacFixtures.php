<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/** @param list<string> $kinds */
function test_mark_inventory_kinds_v2(mysqli $db, int $credentialId, array $kinds): void
{
    $normalization = [];
    $keys = ['datacenter' => 'datacenters', 'datastore' => 'datastores', 'network' => 'networks', 'host' => 'hosts', 'vm' => 'vms'];
    foreach ($kinds as $kind) {
        $count = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ?', 'is', [$credentialId, $kind]);
        $normalization[$keys[$kind]] = ['raw' => $count, 'persistable' => $count, 'supported' => $count];
    }
    repo_esxi_inventory_record_kind_evidence($db, $credentialId, $kinds, ['normalization' => $normalization], null);
    repo_esxi_inventory_record_success($db, $credentialId);
}

/** Makes an unrelated queue fixture valid under the Etappe-14A hard gates. */
function test_prepare_network_mac_fixture(mysqli $db, int $missionId, int $credentialId, string $wdsVlan = 'WDS', string $datacenter = 'DC1'): void
{
    repo_execute($db, 'UPDATE deploy_missions SET wds_vlan = ? WHERE id = ?', 'si', [$wdsVlan, $missionId]);
    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ? ORDER BY id');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());
    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $stmt = $db->prepare('SELECT id FROM deploy_interfaces WHERE vm_id = ? ORDER BY id');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $interfaces = repo_fetch_all($stmt->get_result());
        if ($interfaces === []) {
            repo_execute($db, "INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode, type) VALUES (?, '', '', '', ?, '', 'dhcp', 'vmxnet3')", 'is', [$vmId, $wdsVlan]);
            continue;
        }
        foreach ($interfaces as $index => $interface) {
            $vlan = $index === 0 ? $wdsVlan : $wdsVlan . '-secondary-' . ($index + 1);
            repo_execute($db, 'UPDATE deploy_interfaces SET vlan = ? WHERE id = ?', 'si', [$vlan, (int) $interface['id']]);
        }
    }
    repo_esxi_inventory_replace_kind($db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, [['name' => $datacenter]]);
    test_mark_inventory_kinds_v2($db, $credentialId, [VIRTUSPHERE_INVENTORY_KIND_DATACENTER]);
}
