<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function mac_import_error(string $code, ?int $vmId, string $vmName, string $vlan = '', string $mac = '', ?int $otherVmId = null): array
{
    $error = ['code' => $code];
    if ($vmId !== null && $vmId > 0) {
        $error['vm_id'] = $vmId;
    }
    if ($vmName !== '') {
        $error['vm_name'] = mac_import_bounded_identifier($vmName, 191);
    }
    if ($vlan !== '') {
        $error['vlan'] = mac_import_bounded_identifier($vlan, 255);
    }
    if ($mac !== '') {
        $error['mac'] = mac_import_bounded_identifier($mac, 64);
    }
    if ($otherVmId !== null && $otherVmId > 0) {
        $error['other_vm_id'] = $otherVmId;
    }

    return $error;
}

/**
 * Identity fields of a vmware_guest_info result (Entscheidung 6). Additive on
 * the wire: both keys are optional, and an absent field comes back as '' so a
 * pre-identity playbook result keeps importing without touching what is stored.
 *
 * @return array{moid:string, instance_uuid:string}
 */
function mac_import_extract_identity(array $instance): array
{
    return [
        'moid' => mac_import_bounded_identifier(trim((string) ($instance['moid'] ?? '')), 64),
        'instance_uuid' => mac_import_bounded_identifier(trim((string) ($instance['instance_uuid'] ?? '')), 64),
    ];
}

/** @return array<string,mixed> */
function mac_import_finalize_plan(array $expected, array $vmPlans, array $rows, array $unscopedErrors): array
{
    $successful = [];
    $failed = [];
    $errors = $unscopedErrors;
    $updatedInterfaces = 0;
    foreach ($expected as $vmId => $_vm) {
        $vmPlan = $vmPlans[$vmId];
        $vmErrors = array_values($vmPlan['errors']);
        if ($vmErrors === [] && $vmPlan['updates'] !== []) {
            $successful[] = (int) $vmId;
            $updatedInterfaces += count($vmPlan['updates']);
        } else {
            $failed[] = (int) $vmId;
            array_push($errors, ...$vmErrors);
        }
    }

    sort($successful, SORT_NUMERIC);
    sort($failed, SORT_NUMERIC);
    $outcome = $successful === [] ? 'failed' : (($failed === [] && $errors === []) ? 'success' : 'partial');
    $vmResults = [];
    foreach ($rows as $row) {
        $vmId = is_int($row['vm_id']) ? $row['vm_id'] : null;
        $plan = $vmId !== null && isset($vmPlans[$vmId]) ? $vmPlans[$vmId] : null;
        $codes = is_array($plan) ? array_column(array_values($plan['errors']), 'code') : $row['error_codes'];
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $vmResults[] = [
            'vm_name' => (string) $row['vm_name'],
            'outcome' => $codes === [] ? 'success' : 'failed',
            'updated_interfaces' => $codes === [] && is_array($plan) ? count($plan['updates']) : 0,
            'error_codes' => $codes,
        ];
    }

    return [
        'outcome' => $outcome,
        'successful_vm_ids' => $successful,
        'failed_vm_ids' => $failed,
        'errors' => $errors,
        'counts' => [
            'expected_vms' => count($expected),
            'successful_vms' => count($successful),
            'failed_vms' => count($failed),
            'updated_interfaces' => $updatedInterfaces,
        ],
        'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        'vm_results' => $vmResults,
        'vm_plans' => $vmPlans,
    ];
}

/** @return array<string,mixed> */
function mac_import_result_contract(array $plan): array
{
    return [
        'version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
        'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
        'outcome' => $plan['outcome'],
        'successful_vm_ids' => $plan['successful_vm_ids'],
        'failed_vm_ids' => $plan['failed_vm_ids'],
        'errors' => $plan['errors'],
        'counts' => $plan['counts'],
        'retry' => $plan['retry'],
    ];
}

/**
 * Read side of the result_json contract: what the deploy worker trusts after a
 * sequence with an export step. Anything that is not a well-formed version-1
 * mac_import result with a known outcome comes back as NULL, and NULL means
 * "no usable result", which the worker must treat as a failed export (L3) -
 * a malformed result must never pass as a green one.
 *
 * @return array{outcome:string, successful_vm_ids:list<int>, failed_vm_ids:list<int>, counts:array<string,int>}|null
 */
function mac_import_decode_result(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)
        || (int) ($decoded['version'] ?? 0) !== VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION
        || (string) ($decoded['kind'] ?? '') !== VIRTUSPHERE_MAC_IMPORT_RESULT_KIND) {
        return null;
    }

    $outcome = (string) ($decoded['outcome'] ?? '');
    if (!in_array($outcome, ['success', 'partial', 'failed'], true)) {
        return null;
    }

    $counts = [];
    foreach (is_array($decoded['counts'] ?? null) ? $decoded['counts'] : [] as $key => $value) {
        $counts[(string) $key] = (int) $value;
    }

    return [
        'outcome' => $outcome,
        'successful_vm_ids' => mac_import_decode_vm_ids($decoded['successful_vm_ids'] ?? null),
        'failed_vm_ids' => mac_import_decode_vm_ids($decoded['failed_vm_ids'] ?? null),
        'counts' => $counts,
    ];
}

/** @return list<int> */
function mac_import_decode_vm_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $id) {
        if ((is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1)) && (int) $id > 0) {
            $ids[(int) $id] = true;
        }
    }
    $result = array_map('intval', array_keys($ids));
    sort($result, SORT_NUMERIC);

    return $result;
}

/** @return array{missing_vms:list<string>,unmatched_interfaces:list<array<string,mixed>>,duplicate_macs:list<array<string,mixed>>} */
function mac_import_legacy_diagnostics(array $errors): array
{
    $missing = [];
    $unmatched = [];
    $duplicates = [];
    $messages = [
        VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND => 'No interface row matched vm_id and vlan',
        VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN => 'Multiple interfaces share this VLAN on the VM; MAC not written',
        VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC => 'Invalid MAC address format',
        VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA => 'Missing or empty NIC data',
    ];
    foreach ($errors as $error) {
        $code = (string) ($error['code'] ?? '');
        $vmName = (string) ($error['vm_name'] ?? '');
        if ($code === VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION && $vmName !== '') {
            $missing[$vmName] = true;
        }
        if (isset($messages[$code])) {
            $unmatched[] = [
                'vm_name' => $vmName,
                'vlan' => (string) ($error['vlan'] ?? ''),
                'status' => 'error',
                'message' => $messages[$code],
            ];
        }
        if ($code === VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC) {
            $duplicate = ['vm_name' => $vmName, 'mac' => (string) ($error['mac'] ?? '')];
            if (isset($error['other_vm_id'])) {
                $duplicate['other_vm_id'] = (int) $error['other_vm_id'];
            }
            $duplicates[] = $duplicate;
        }
    }

    return [
        'missing_vms' => array_keys($missing),
        'unmatched_interfaces' => $unmatched,
        'duplicate_macs' => $duplicates,
    ];
}
