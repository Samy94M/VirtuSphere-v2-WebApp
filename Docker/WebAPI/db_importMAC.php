<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';

header('Content-Type: application/json; charset=utf-8');

function mac_normalize_payload(array $payload): array
{
    $missionId = null;
    $legacy = true;
    $results = $payload;

    if (array_key_exists('mission_id', $payload) && array_key_exists('results', $payload)) {
        $missionId = (int) $payload['mission_id'];
        $results = is_array($payload['results']) ? $payload['results'] : [];
        $legacy = false;
    } elseif (array_key_exists('results', $payload) && is_array($payload['results'])) {
        $results = $payload['results'];
    }

    if (array_key_exists('results', $results) && is_array($results['results'])) {
        $results = $results['results'];
    }

    return [$missionId, $results, $legacy];
}

function mac_decode_result(mixed $entry): ?array
{
    if (is_string($entry)) {
        $decoded = json_decode($entry, true);
        return is_array($decoded) ? $decoded : null;
    }

    return is_array($entry) ? $entry : null;
}

function mac_find_vm_id(mysqli $db, ?int $missionId, string $vmName): ?int
{
    if ($missionId !== null && $missionId > 0) {
        $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ? AND vm_name = ? LIMIT 1');
        $stmt->bind_param('is', $missionId, $vmName);
    } else {
        $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE vm_name = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $vmName);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int) $row['id'] : null;
}

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

if (request_string($_GET, 'action') !== 'updateInterface') { // array-safe (lib/request.php)
    machine_api_json(['message' => 'Invalid action specified'], 400);
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || $payload === []) {
        machine_api_json(['error' => 'No data received'], 400);
    }

    [$missionId, $results, $legacyPayload] = mac_normalize_payload($payload);
    if ($missionId === null || $missionId <= 0) {
        machine_api_log_warning('db_importMAC', 'Rejected MAC import without mission_id from ' . $clientIp . '.');
        machine_api_json([
            'error' => 'mission_id is required for MAC import payload',
            'legacy_payload' => $legacyPayload,
        ], 400);
    }
    if (!is_array($results) || $results === []) {
        machine_api_json(['error' => 'No result entries received'], 400);
    }

    $connection->begin_transaction();
    $updatedInterfaces = 0;
    $updatedVmIds = [];
    $missingVms = [];
    $unmatchedInterfaces = [];
    $duplicateMacs = [];

    foreach ($results as $rawEntry) {
        $entry = mac_decode_result($rawEntry);
        $instance = $entry['instance'] ?? null;
        if (!is_array($instance) || empty($instance['hw_name'])) {
            continue;
        }

        $vmName = (string) $instance['hw_name'];
        $vmId = mac_find_vm_id($connection, $missionId, $vmName);
        if ($vmId === null) {
            $missingVms[] = $vmName;
            continue;
        }

        foreach ($instance as $key => $value) {
            if (!str_starts_with((string) $key, 'hw_eth') || !is_array($value)) {
                continue;
            }

            $macAddress = (string) ($value['macaddress'] ?? '');
            $vlanSummary = (string) ($value['summary'] ?? '');
            if ($macAddress === '' || $vlanSummary === '') {
                continue;
            }

            // Canonicalize before writing (E2); reject values that are not
            // parseable as MAC addresses instead of storing them verbatim.
            $normalizedMac = virtusphere_normalize_mac($macAddress);
            if ($normalizedMac === null) {
                $unmatchedInterfaces[] = [
                    'vm_name' => $vmName,
                    'vlan' => $vlanSummary,
                    'status' => 'error',
                    'message' => 'Invalid MAC address format',
                ];
                continue;
            }

            // Duplicate guard (E2): the same MAC on another VM would make the
            // MAC-based device lookups pick an arbitrary VM.
            $stmt = $connection->prepare('SELECT vm_id FROM deploy_interfaces WHERE mac = ? AND vm_id <> ? LIMIT 1');
            $stmt->bind_param('si', $normalizedMac, $vmId);
            $stmt->execute();
            $otherVm = $stmt->get_result()->fetch_assoc();
            if (is_array($otherVm)) {
                $duplicateMacs[] = [
                    'vm_name' => $vmName,
                    'mac' => $normalizedMac,
                    'other_vm_id' => (int) $otherVm['vm_id'],
                ];
                machine_api_log_warning('db_importMAC', 'Rejected duplicate MAC ' . $normalizedMac . ' for VM ' . $vmName . ' (already on vm_id ' . (int) $otherVm['vm_id'] . ').');
                continue;
            }

            // Ambiguity guard (E2): two NICs of the same VM on the same VLAN
            // would both receive this MAC - refuse instead of writing blind.
            $stmt = $connection->prepare('SELECT COUNT(*) AS c FROM deploy_interfaces WHERE vm_id = ? AND vlan = ?');
            $stmt->bind_param('is', $vmId, $vlanSummary);
            $stmt->execute();
            $rowCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            if ($rowCount === 0) {
                $unmatchedInterfaces[] = [
                    'vm_name' => $vmName,
                    'vlan' => $vlanSummary,
                    'status' => 'error',
                    'message' => 'No interface row matched vm_id and vlan',
                ];
                continue;
            }
            if ($rowCount > 1) {
                $unmatchedInterfaces[] = [
                    'vm_name' => $vmName,
                    'vlan' => $vlanSummary,
                    'status' => 'error',
                    'message' => 'Multiple interfaces share this VLAN on the VM; MAC not written',
                ];
                continue;
            }

            $stmt = $connection->prepare('UPDATE deploy_interfaces SET mac = ? WHERE vm_id = ? AND vlan = ?');
            $stmt->bind_param('sis', $normalizedMac, $vmId, $vlanSummary);
            $stmt->execute();

            // Re-runs writing the identical MAC report affected_rows = 0 but
            // are a success, not an unmatched row (the pre-count proved the
            // target exists).
            $updatedInterfaces++;
            $updatedVmIds[$vmId] = true;
        }
    }

    foreach (array_keys($updatedVmIds) as $vmId) {
        repo_set_vm_state($connection, (int) $vmId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING, VIRTUSPHERE_STATUS_DEPLOYED, 1, 'ansible mac import');
    }

    $connection->commit();
    $hasImportErrors = $missingVms !== [] || $unmatchedInterfaces !== [] || $duplicateMacs !== [];
    if ($hasImportErrors) {
        machine_api_log_warning('db_importMAC', sprintf(
            'MAC import for mission_id %d completed with %d missing VMs, %d unmatched interfaces and %d duplicate MACs.',
            $missionId,
            count($missingVms),
            count($unmatchedInterfaces),
            count($duplicateMacs)
        ));
    }

    $response = [
        'success' => !$hasImportErrors,
        'legacy_payload' => $legacyPayload,
        'updated_interfaces' => $updatedInterfaces,
        'updated_vms' => count($updatedVmIds),
        'missing_vms' => array_values(array_unique($missingVms)),
        'unmatched_interfaces' => $unmatchedInterfaces,
        'duplicate_macs' => $duplicateMacs,
    ];
    if ($hasImportErrors) {
        $response['error'] = 'MAC import completed with unmatched entries';
    }

    machine_api_json($response);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    $connection->rollback();
    machine_api_log_warning('db_importMAC', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
