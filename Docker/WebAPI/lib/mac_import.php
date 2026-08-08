<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/mac.php';

const VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION = 1;
const VIRTUSPHERE_MAC_IMPORT_RESULT_KIND = 'mac_import';

const VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND = 'interface_not_found';
const VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC = 'duplicate_mac';
const VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC = 'invalid_mac';
const VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN = 'ambiguous_vlan';
const VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION = 'vm_not_in_mission';
const VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_JOB_SCOPE = 'vm_not_in_job_scope';
const VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NAME = 'missing_name';
const VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA = 'missing_nic_data';
const VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED = 'esxi_query_failed';
const VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_RESULT = 'duplicate_result';
const VIRTUSPHERE_MAC_IMPORT_ERROR_IDENTITY_MISMATCH = 'identity_mismatch';
require_once __DIR__ . '/mac_import_result.php';

/** @return array{0:int,1:?int,2:array<int,mixed>,3:bool} */
function mac_import_normalize_payload(array $payload): array
{
    $legacy = true;
    $missionId = 0;
    $jobId = null;
    $results = $payload;

    if (array_key_exists('mission_id', $payload) && array_key_exists('results', $payload)) {
        $missionId = mac_import_positive_int($payload['mission_id']) ?? 0;
        $jobId = array_key_exists('job_id', $payload) ? mac_import_positive_int($payload['job_id']) : null;
        $results = is_array($payload['results']) ? array_values($payload['results']) : [];
        $legacy = false;
    } elseif (array_key_exists('results', $payload) && is_array($payload['results'])) {
        $results = array_values($payload['results']);
    }

    if (array_key_exists('results', $results) && is_array($results['results'])) {
        $results = array_values($results['results']);
    }

    return [$missionId, $jobId, $results, $legacy];
}

function mac_import_positive_int(mixed $value): ?int
{
    if (is_bool($value) || is_float($value) || is_array($value) || is_object($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '' || preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
        return null;
    }

    $number = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return is_int($number) ? $number : null;
}

/** @return array<string,mixed>|null */
function mac_import_decode_entry(mixed $entry): ?array
{
    if (is_string($entry)) {
        $decoded = json_decode($entry, true);

        return is_array($decoded) ? $decoded : null;
    }

    return is_array($entry) ? $entry : null;
}

/** @return array<string,mixed>|null */
function mac_import_job(mysqli $db, int $jobId): ?array
{
    $stmt = $db->prepare('SELECT id, mission_id, status, payload_json FROM deploy_jobs WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return is_array($row) ? $row : null;
}

/** @return list<int>|null NULL means the whole mission. */
function mac_import_job_scope_ids(array $job): ?array
{
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new UnexpectedValueException('Deploy job payload is not an object.');
    }

    $rawIds = $payload['vm_ids'] ?? [];
    if (!is_array($rawIds) || $rawIds === []) {
        return null;
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = mac_import_positive_int($rawId);
        if ($id === null) {
            throw new UnexpectedValueException('Deploy job payload contains an invalid VM id.');
        }
        $ids[$id] = true;
    }

    $result = array_map('intval', array_keys($ids));
    sort($result, SORT_NUMERIC);

    return $result;
}

/**
 * Resolve every input row and build complete per-VM write plans. This function
 * performs locking reads only; the caller owns the single request transaction.
 *
 * @param list<mixed> $results
 * @param list<int>|null $jobScopeIds NULL is the whole mission for managed jobs.
 * @return array<string,mixed>
 */
function mac_import_build_plan(mysqli $db, int $missionId, array $results, bool $managed, ?array $jobScopeIds): array
{
    [$vmsById, $vmsByName] = mac_import_mission_vms($db, $missionId);
    $interfaceLookup = $db->prepare('SELECT id, vm_id, vlan, mac FROM deploy_interfaces WHERE vm_id = ? AND vlan = ? ORDER BY id FOR UPDATE');

    $expected = [];
    if ($managed) {
        if ($jobScopeIds === null) {
            $expected = $vmsById;
        } else {
            foreach ($jobScopeIds as $vmId) {
                $expected[$vmId] = $vmsById[$vmId] ?? [
                    'id' => $vmId,
                    'vm_name' => '',
                    'lifecycle_state' => '',
                    'mecm_sync_state' => '',
                    'vm_status' => '',
                    'updated' => 0,
                ];
            }
        }
    }

    $vmPlans = [];
    $rows = [];
    $unscopedErrors = [];
    foreach ($results as $rawEntry) {
        $entry = mac_import_decode_entry($rawEntry);
        $row = ['vm_name' => '', 'vm_id' => null, 'error_codes' => [], 'synthetic' => false];
        if ($entry === null) {
            mac_import_add_row_error($row, $unscopedErrors, VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA);
            $rows[] = $row;
            continue;
        }

        $instance = is_array($entry['instance'] ?? null) ? $entry['instance'] : null;
        $item = is_array($entry['item'] ?? null) ? $entry['item'] : [];
        $instanceName = is_array($instance) ? trim((string) ($instance['hw_name'] ?? '')) : '';
        $itemName = trim((string) ($item['vm_name'] ?? $item['name'] ?? ''));
        $vmName = $instanceName !== '' ? $instanceName : $itemName;
        $row['vm_name'] = mac_import_bounded_identifier($vmName, 191);

        if ($vmName === '') {
            mac_import_add_row_error($row, $unscopedErrors, VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NAME);
            if (!empty($entry['failed'])) {
                mac_import_add_row_error($row, $unscopedErrors, VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED);
            }
            $rows[] = $row;
            continue;
        }

        $vm = $vmsByName[$vmName] ?? mac_import_mission_vm_by_name($db, $missionId, $vmName);
        if (is_array($vm)) {
            // Preserve the database collation's legacy case-insensitive match.
            $vm = $vmsById[(int) $vm['id']] ?? $vm;
        }
        if (!is_array($vm)) {
            mac_import_add_row_error($row, $unscopedErrors, VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION);
            $rows[] = $row;
            continue;
        }

        $vmId = (int) $vm['id'];
        $row['vm_id'] = $vmId;
        if ($managed && !array_key_exists($vmId, $expected)) {
            mac_import_add_row_error($row, $unscopedErrors, VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_JOB_SCOPE, $vmId);
            $rows[] = $row;
            continue;
        }

        if (!$managed) {
            $expected[$vmId] = $vm;
        }
        mac_import_ensure_vm_plan($vmPlans, $vm);
        $rowIndex = count($rows);
        if ($vmPlans[$vmId]['input_indexes'] !== []) {
            mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_RESULT);
        }
        $vmPlans[$vmId]['input_indexes'][] = $rowIndex;
        $rows[] = $row;

        if (!empty($entry['failed'])) {
            mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED);
            continue;
        }
        if ($instance === null) {
            mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA);
            continue;
        }

        // Entscheidung 6: a stored instance UUID is the VM's identity. A result
        // naming the same vm_name with a different UUID talks about a foreign
        // VM, so nothing of that row may be written - least of all its identity.
        $identity = mac_import_extract_identity($instance);
        $storedUuid = trim((string) ($vm['vm_instance_uuid'] ?? ''));
        if ($identity['instance_uuid'] !== '' && $storedUuid !== '' && strcasecmp($storedUuid, $identity['instance_uuid']) !== 0) {
            mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_IDENTITY_MISMATCH);
            continue;
        }
        $vmPlans[$vmId]['identity'] = $identity;

        $nicCount = 0;
        foreach ($instance as $key => $value) {
            if (!str_starts_with((string) $key, 'hw_eth')) {
                continue;
            }
            $nicCount++;
            if (!is_array($value)) {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA);
                continue;
            }

            $mac = trim((string) ($value['macaddress'] ?? ''));
            $vlan = trim((string) ($value['summary'] ?? ''));
            if ($mac === '' || $vlan === '') {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA, $vlan);
                continue;
            }
            $normalizedMac = virtusphere_normalize_mac($mac);
            if ($normalizedMac === null) {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC, $vlan, $mac);
                continue;
            }

            $interfaceLookup->bind_param('is', $vmId, $vlan);
            $interfaceLookup->execute();
            $matches = $interfaceLookup->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($matches === []) {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND, $vlan);
                continue;
            }
            if (count($matches) > 1) {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN, $vlan);
                continue;
            }

            $interface = $matches[0];
            $interfaceId = (int) $interface['id'];
            if (isset($vmPlans[$vmId]['updates'][$interfaceId])) {
                mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN, $vlan);
                continue;
            }
            $vmPlans[$vmId]['updates'][$interfaceId] = [
                'id' => $interfaceId,
                'mac' => $normalizedMac,
                'vlan' => $vlan,
            ];
        }
        if ($nicCount === 0) {
            mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA);
        }
    }

    foreach ($expected as $vmId => $vm) {
        mac_import_ensure_vm_plan($vmPlans, $vm);
        if ($vmPlans[$vmId]['input_indexes'] !== []) {
            continue;
        }
        $rows[] = [
            'vm_name' => (string) $vm['vm_name'],
            'vm_id' => (int) $vmId,
            'error_codes' => [VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA],
            'synthetic' => true,
        ];
        $vmPlans[$vmId]['input_indexes'][] = count($rows) - 1;
        mac_import_add_vm_error($vmPlans[$vmId], VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA);
    }

    mac_import_validate_duplicate_macs($db, $vmPlans);

    return mac_import_finalize_plan($expected, $vmPlans, $rows, $unscopedErrors);
}

/** @return array{0:array<int,array<string,mixed>>,1:array<string,array<string,mixed>>} */
function mac_import_mission_vms(mysqli $db, int $missionId): array
{
    $stmt = $db->prepare('SELECT id, vm_name, lifecycle_state, mecm_sync_state, vm_status, updated, vm_instance_uuid FROM deploy_vms WHERE mission_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $byId = [];
    $byName = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $byId[$id] = $row;
        $byName[(string) $row['vm_name']] = $row;
    }

    return [$byId, $byName];
}
/** @return array<string,mixed>|null */
function mac_import_mission_vm_by_name(mysqli $db, int $missionId, string $vmName): ?array
{
    $stmt = $db->prepare('SELECT id, vm_name, lifecycle_state, mecm_sync_state, vm_status, updated, vm_instance_uuid FROM deploy_vms WHERE mission_id = ? AND vm_name = ? LIMIT 1');
    $stmt->bind_param('is', $missionId, $vmName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return is_array($row) ? $row : null;
}


/** @param array<int,array> $vmPlans */
function mac_import_validate_duplicate_macs(mysqli $db, array &$vmPlans): void
{
    $planned = [];
    foreach ($vmPlans as $vmId => $vmPlan) {
        if ($vmPlan['errors'] !== []) {
            continue;
        }
        foreach ($vmPlan['updates'] as $update) {
            $planned[(string) $update['mac']][] = ['vm_id' => (int) $vmId, 'interface_id' => (int) $update['id']];
        }
    }
    ksort($planned, SORT_STRING);

    $lookup = $db->prepare("SELECT id, vm_id FROM deploy_interfaces WHERE mac = ? AND mac <> '' ORDER BY id FOR UPDATE");
    foreach ($planned as $mac => $owners) {
        $lookup->bind_param('s', $mac);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($existing) > 1) {
            foreach ($owners as $owner) {
                mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, (int) $existing[0]['vm_id']);
            }
            continue;
        }
        if ($existing !== []) {
            $existingRow = $existing[0];
            foreach ($owners as $owner) {
                if ((int) $existingRow['id'] !== $owner['interface_id']) {
                    mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, (int) $existingRow['vm_id']);
                }
            }
            continue;
        }
        if (count($owners) > 1) {
            foreach ($owners as $owner) {
                $other = current(array_filter($owners, static fn (array $candidate): bool => $candidate['interface_id'] !== $owner['interface_id']));
                mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, is_array($other) ? (int) $other['vm_id'] : null);
            }
        }
    }
}

/** @param array<int,array<string,mixed>> $vmPlans */
function mac_import_ensure_vm_plan(array &$vmPlans, array $vm): void
{
    $vmId = (int) $vm['id'];
    $vmPlans[$vmId] ??= ['vm' => $vm, 'input_indexes' => [], 'updates' => [], 'errors' => [], 'identity' => null];
}

function mac_import_add_vm_error(array &$vmPlan, string $code, string $vlan = '', string $mac = '', ?int $otherVmId = null): void
{
    $vm = $vmPlan['vm'];
    $error = mac_import_error($code, (int) $vm['id'], (string) $vm['vm_name'], $vlan, $mac, $otherVmId);
    $key = json_encode($error, JSON_THROW_ON_ERROR);
    $vmPlan['errors'][$key] = $error;
}

function mac_import_add_row_error(array &$row, array &$errors, string $code, ?int $vmId = null): void
{
    $row['error_codes'][] = $code;
    $errors[] = mac_import_error($code, $vmId, (string) $row['vm_name']);
}

function mac_import_bounded_identifier(string $value, int $maxBytes): string
{
    return substr($value, 0, $maxBytes);
}

