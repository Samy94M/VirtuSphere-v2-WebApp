<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error. Must come before mysql.php, which
// connects while it loads: a dead database throws there, i.e. before anything
// below runs (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';

header('Content-Type: application/json; charset=utf-8');

function machine_vm_packages(mysqli $db, int $vmId): array
{
    $stmt = $db->prepare('SELECT dp.* FROM deploy_packages dp JOIN deploy_vm_packages dvp ON dp.id = dvp.package_id WHERE dvp.vm_id = ? ORDER BY dp.package_name');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function machine_vm_interfaces(mysqli $db, int $vmId, bool $onlyWithMac): array
{
    $sql = $onlyWithMac
        ? "SELECT * FROM deploy_interfaces WHERE mac != '' AND mac IS NOT NULL AND vm_id = ? ORDER BY id"
        : 'SELECT * FROM deploy_interfaces WHERE vm_id = ? ORDER BY id';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function machine_vm_mission(mysqli $db, int $missionId): ?array
{
    $stmt = $db->prepare('SELECT * FROM deploy_missions WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $mission = $stmt->get_result()->fetch_assoc();

    return $mission ?: null;
}

$clientIp = machine_api_client_ip();
// request_string, not a raw cast: `?action[]=x` would otherwise throw "Array to
// string conversion", and this cast sits ahead of the IP gate, so any host could
// turn a stray bracket into a 500 plus a system audit row - unauthenticated,
// one per request. See lib/request.php.
$action = request_string($_GET, 'action');
$mac = request_string($_GET, 'mac');
$ipAllowed = machine_api_ip_allowed($connection, $clientIp);

// ADR-0032: adopt the PowerShell client's per-run correlation id for this
// request's audit lines. Diagnostic only; an invalid header is ignored.
$headerCorrelation = (string) ($_SERVER['HTTP_X_VIRTUSPHERE_CORRELATION'] ?? '');
if (virtusphere_correlation_id_is_valid($headerCorrelation)) {
    virtusphere_correlation_adopt($headerCorrelation);
}

try {
    if ($action === 'getDeviceInfos') {
        if ($mac === '' || !filter_var($mac, FILTER_VALIDATE_MAC)) {
            machine_api_json(['error' => 'Invalid MAC address'], 400);
        }
        // Canonical lookup (E2): separator/case variants match the stored form.
        $mac = virtusphere_normalize_mac($mac) ?? $mac;
        if (!$ipAllowed && !machine_api_mac_allowed($connection, $mac)) {
            machine_api_forbidden($clientIp);
        }

        $stmt = $connection->prepare('SELECT vm_id FROM deploy_interfaces WHERE mac = ? LIMIT 1');
        $stmt->bind_param('s', $mac);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            machine_api_json([]);
        }

        $vmId = (int) $row['vm_id'];
        repo_set_vm_state($connection, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLED, null, 'mecm client info');

        $stmt = $connection->prepare('SELECT * FROM deploy_vms WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc() ?: [];
        if ($data !== []) {
            $data['vm_status'] = virtusphere_legacy_status_from_states((string) $data['lifecycle_state'], (string) $data['mecm_sync_state']);
            $data['interfaces'] = machine_vm_interfaces($connection, $vmId, false);
            $data['packages'] = machine_vm_packages($connection, $vmId);
            $data['mission'] = machine_vm_mission($connection, (int) $data['mission_id']);
        }

        machine_api_json($data);
    }

    if (!$ipAllowed) {
        machine_api_forbidden($clientIp);
    }

    if ($action === 'getDeviceList') {
        $result = machine_api_prepared_result($connection, 'SELECT * FROM deploy_vms WHERE updated = 1 OR mecm_sync_state IN (?, ?) ORDER BY id', 'ss', [VIRTUSPHERE_MECM_PENDING, VIRTUSPHERE_MECM_SUBMITTED]);
        $data = [];
        while ($vm = $result->fetch_assoc()) {
            $vmId = (int) $vm['id'];
            $vm['interfaces'] = machine_vm_interfaces($connection, $vmId, true);
            if ($vm['interfaces'] === []) {
                continue;
            }

            $mission = machine_vm_mission($connection, (int) $vm['mission_id']);
            if (!$mission || str_starts_with((string) $mission['mission_name'], VIRTUSPHERE_TEMPLATE_PREFIX)) {
                continue;
            }

            $vm['mission'] = $mission;
            $vm['packages'] = machine_vm_packages($connection, $vmId);
            $vm['vm_status'] = virtusphere_legacy_status_from_states((string) $vm['lifecycle_state'], (string) $vm['mecm_sync_state']);
            $vm['updated'] = 1;
            $data[] = $vm;
        }

        machine_api_json($data);
    }

    if ($action === 'getMissionName') {
        $missionId = request_int($_GET, 'mission_id');
        if ($missionId <= 0) {
            machine_api_json(['error' => 'Invalid mission_id'], 400);
        }

        $stmt = $connection->prepare('SELECT mission_name FROM deploy_missions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        machine_api_json($stmt->get_result()->fetch_assoc() ?: []);
    }

    machine_api_json(['message' => 'Invalid action specified'], 400);
} catch (Throwable $exception) {
    machine_api_log_warning('mecm-api', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
