// Fixtures for the pages of the health/a11y matrix that only render against a
// concrete object: the two editor pages and the deploy page *with* a mission.
//
// Without a mission, deploy.php renders neither the storage table nor the host
// warning boxes, so the whole block of markup this slice added was never
// scanned. The seed therefore builds the smallest shape that makes all of it
// appear:
//
//  - two ESXi credentials, both with a recorded successful pull, because free
//    space and the presence buckets are derived only from credentials that
//    actually pulled,
//  - overlapping and disjoint datastores, so the picker renders more than one
//    presence bucket instead of a flat list,
//  - a mission datacenter that exists on only one of the two, which is what
//    makes the per-host warning box render at all,
//  - one datastore in maintenance, so its "no usable free space" path is on the
//    page too.
//
// Everything is prefixed and removed again in cleanup; the dev database is
// shared and the suite runs with workers: 1.

const { runPhp, phpJson } = require('./php');

/**
 * @param {string} mark unique prefix for this spec's rows
 */
function seedMatrixFixtures(mark) {
  return phpJson(
    `
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);

$esxiA = repo_create_credential($db, ['type' => 'esxi', 'name' => '${mark}-esxi-a', 'host' => '127.0.0.1', 'port' => 443, 'username' => 'root'], 'secret123', $admin);
$esxiB = repo_create_credential($db, ['type' => 'esxi', 'name' => '${mark}-esxi-b', 'host' => '127.0.0.2', 'port' => 443, 'username' => 'root'], 'secret123', $admin);
$ansible = repo_create_credential($db, ['type' => 'ansible', 'name' => '${mark}-ans', 'host' => '127.0.0.1', 'port' => 22, 'username' => 'ans'], 'secret123', $admin);

// Host A: the mission's datacenter and datastore, plus one shared datastore.
// Host B: the shared one only, and its copy is in maintenance. The mission
// datacenter is missing on B, which is the per-host warning.
$rows = [
    [$esxiA, 'datacenter', '${mark}-DC', null, null, null],
    [$esxiA, 'datastore', '${mark}-ds-a', 1099511627776, 549755813888, null],
    [$esxiA, 'datastore', '${mark}-ds-shared', 2199023255552, 1099511627776, null],
    [$esxiA, 'host', '${mark}-host-a', null, null, null],
    // A has networks, just not the one the VMs use: a credential that reports
    // no network at all is silent about a missing one on purpose (it cannot
    // prove absence), so without this row the warning box never fills.
    [$esxiA, 'network', '${mark}-vlan-other', null, null, null],
    [$esxiB, 'datacenter', '${mark}-DC-other', null, null, null],
    [$esxiB, 'datastore', '${mark}-ds-shared', 2199023255552, 274877906944, '{"accessible":true,"maintenance":true}'],
    [$esxiB, 'host', '${mark}-host-b', null, null, null],
    // The VMs' VLAN exists on B only, so selecting A renders the per-host
    // warning box *filled* rather than empty, while A still resolves the
    // datacenter and the datastore and the storage table keeps its numbers. A
    // value missing on every credential would be subtracted into the union
    // warning instead and this box would stay hidden.
    [$esxiB, 'network', '${mark}-vlan', null, null, null],
];
$stmt = $db->prepare('INSERT INTO deploy_esxi_inventory (credential_id, kind, name, capacity_bytes, free_bytes, meta_json) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($rows as [$cid, $kind, $name, $cap, $free, $meta]) {
    $stmt->bind_param('isssss', $cid, $kind, $name, $cap, $free, $meta);
    $stmt->execute();
}

// A recorded successful pull on both: the presence buckets count credentials
// that pulled, not credentials that exist.
$stmt = $db->prepare('INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, failure_streak) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), \\'ok\\', 0) ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), last_attempt_at = VALUES(last_attempt_at), last_status = VALUES(last_status)');
foreach ([$esxiA, $esxiB] as $cid) {
    $stmt->bind_param('i', $cid);
    $stmt->execute();
}

$missionId = repo_create_mission($db, [
    'mission_name' => '${mark}-mission',
    'hypervisor_datastorage' => '${mark}-ds-shared',
    'hypervisor_datacenter' => '${mark}-DC',
    'domain' => 'seed.example.local',
], false, $admin);

$vmIds = [];
foreach (['${mark}VM1', '${mark}VM2'] as $vmName) {
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, ?, ?, 'Win11')");
    $stmt->bind_param('iss', $missionId, $vmName, $vmName);
    $stmt->execute();
    $vmId = (int) $db->insert_id;
    $vmIds[] = $vmId;
    $stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', '${mark}-vlan', ?, 'dhcp')");
    $mac = sprintf('00:50:56:AB:CD:%02X', $vmId % 256);
    $stmt->bind_param('is', $vmId, $mac);
    $stmt->execute();
}

echo 'JSON' . json_encode([
    'missionId' => $missionId,
    'vmId' => $vmIds[0],
    'esxiId' => $esxiA,
    'esxiOtherId' => $esxiB,
    'ansibleId' => $ansible,
]) . 'JSON';
`,
    ['lib/repo/credentials.php', 'lib/repo/missions.php'],
  );
}

/** @param {string} mark the same prefix seedMatrixFixtures() was called with */
function cleanupMatrixFixtures(mark) {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_interfaces WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${mark}%'))");
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${mark}%')");
$db->query("DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${mark}%')");
$db->query("DELETE FROM deploy_missions WHERE mission_name LIKE '${mark}%'");
$db->query("DELETE FROM deploy_esxi_inventory WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${mark}%')");
$db->query("DELETE FROM deploy_esxi_inventory_state WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${mark}%')");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${mark}%'");
echo 'CLEANED';
`);
}

module.exports = { seedMatrixFixtures, cleanupMatrixFixtures };
