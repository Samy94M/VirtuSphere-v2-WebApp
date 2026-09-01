<?php
declare(strict_types=1);
/** @return array<string,mixed> */
function mac_import_error(string $code, ?int $vmId, string $vmName, string $vlan = '', string $mac = '', ?int $otherVmId = null, ?string $ambiguitySource = null): array
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
    if ($code === VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN
        && in_array($ambiguitySource, ['portal', 'esxi', 'both'], true)) {
        $error['ambiguity_source'] = $ambiguitySource;
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
        $wds = $vmPlan['wds'] ?? null;
        $wdsVerified = $wds === null || (bool) ($wds['verified'] ?? false);
        if ($vmErrors === [] && $vmPlan['updates'] !== [] && $wdsVerified) {
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
    foreach ($expected as $vmId => $vm) {
        $plan = $vmPlans[$vmId];
        $codes = array_column(array_values($plan['errors']), 'code');
        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes, SORT_STRING);
        $success = in_array((int) $vmId, $successful, true);
        $vmResult = [
            'vm_id' => (int) $vmId,
            'vm_name' => (string) ($vm['vm_name'] ?? ''),
            'outcome' => $success ? 'success' : 'failed',
            'updated_interfaces' => $success ? count($plan['updates']) : 0,
            'error_codes' => $codes,
        ];
        if (is_array($plan['wds'] ?? null)) {
            $vmResult['wds'] = [
                'configured_portgroup' => (string) ($plan['wds']['configured_portgroup'] ?? ''),
                'portal_interface_id' => isset($plan['wds']['portal_interface_id']) ? (int) $plan['wds']['portal_interface_id'] : null,
                'verified' => $success && (bool) ($plan['wds']['verified'] ?? false),
            ];
        }
        $vmResults[] = $vmResult;
    }

    usort($errors, static fn (array $left, array $right): int => strcmp(
        mac_import_error_sort_key($left),
        mac_import_error_sort_key($right)
    ));

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
function mac_import_result_contract(array $plan, string $callbackFingerprint = ''): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $callbackFingerprint) !== 1) {
        throw new InvalidArgumentException('A V2 MAC import result requires its callback fingerprint.');
    }
    $contract = [
        'version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
        'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
        'outcome' => $plan['outcome'],
        'successful_vm_ids' => $plan['successful_vm_ids'],
        'failed_vm_ids' => $plan['failed_vm_ids'],
        'errors' => $plan['errors'],
        'counts' => $plan['counts'],
        'retry' => $plan['retry'],
        'vm_results' => $plan['vm_results'],
    ];
    $contract['callback_fingerprint'] = $callbackFingerprint;

    return $contract;
}

function mac_import_error_sort_key(array $error): string
{
    return sprintf(
        '%010d\0%s\0%s\0%s',
        (int) ($error['vm_id'] ?? 0),
        (string) ($error['code'] ?? ''),
        (string) ($error['vlan'] ?? ''),
        (string) ($error['mac'] ?? '')
    );
}
/**
 * Read side of the result_json contract: what the deploy worker trusts after a
 * sequence with an export step. Anything that is not a well-formed version-1
 * mac_import result with a known outcome comes back as NULL, and NULL means
 * "no usable result", which the worker must treat as a failed export (L3) -
 * a malformed result must never pass as a green one.
 *
 * @return array{version:int,outcome:string,successful_vm_ids:list<int>,failed_vm_ids:list<int>,counts:array<string,int>,errors:list<mixed>,retry:array<mixed>,vm_results:list<mixed>,callback_fingerprint:string}|null
 */
function mac_import_decode_result(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || (string) ($decoded['kind'] ?? '') !== VIRTUSPHERE_MAC_IMPORT_RESULT_KIND) {
        return null;
    }

    $outcome = (string) ($decoded['outcome'] ?? '');
    if (!in_array($outcome, ['success', 'partial', 'failed'], true)) {
        return null;
    }

    $version = (int) ($decoded['version'] ?? 0);
    if (!in_array($version, [VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION, VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION], true)) {
        return null;
    }

    $counts = [];
    foreach (is_array($decoded['counts'] ?? null) ? $decoded['counts'] : [] as $key => $value) {
        $counts[(string) $key] = (int) $value;
    }

    $result = [
        'version' => $version,
        'outcome' => $outcome,
        'successful_vm_ids' => mac_import_decode_vm_ids($decoded['successful_vm_ids'] ?? null),
        'failed_vm_ids' => mac_import_decode_vm_ids($decoded['failed_vm_ids'] ?? null),
        'counts' => $counts,
        'errors' => is_array($decoded['errors'] ?? null) ? array_values($decoded['errors']) : [],
        'retry' => is_array($decoded['retry'] ?? null) ? $decoded['retry'] : [],
        'vm_results' => is_array($decoded['vm_results'] ?? null) ? array_values($decoded['vm_results']) : [],
        'callback_fingerprint' => is_string($decoded['callback_fingerprint'] ?? null) ? $decoded['callback_fingerprint'] : '',
    ];

    if ($version === VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION) {
        return $result;
    }

    return mac_import_validate_v2_result($decoded, $result) ? $result : null;
}

/** @param array<string,mixed> $decoded @param array<string,mixed> $result */
function mac_import_validate_v2_result(array $decoded, array $result): bool
{
    foreach (['successful_vm_ids', 'failed_vm_ids', 'errors', 'counts', 'retry', 'vm_results', 'callback_fingerprint'] as $field) {
        if (!array_key_exists($field, $decoded)) {
            return false;
        }
    }
    if (!mac_import_v2_ids_are_canonical($decoded['successful_vm_ids'])
        || !mac_import_v2_ids_are_canonical($decoded['failed_vm_ids'])
        || preg_match('/^[a-f0-9]{64}$/', (string) $result['callback_fingerprint']) !== 1
        || !is_array($decoded['errors']) || !array_is_list($decoded['errors'])
        || !is_array($decoded['vm_results']) || !array_is_list($decoded['vm_results'])
        || !is_array($decoded['counts']) || !is_array($decoded['retry'])) {
        return false;
    }

    $successful = $result['successful_vm_ids'];
    $failed = $result['failed_vm_ids'];
    if (array_intersect($successful, $failed) !== []) {
        return false;
    }
    $expectedIds = array_merge($successful, $failed);
    sort($expectedIds, SORT_NUMERIC);
    $vmResultIds = [];
    $vmResultCodes = [];
    $updatedInterfaces = 0;
    $previousVmId = 0;
    foreach ($decoded['vm_results'] as $vmResult) {
        if (!is_array($vmResult)) {
            return false;
        }
        $vmId = $vmResult['vm_id'] ?? null;
        $codes = $vmResult['error_codes'] ?? null;
        $wds = $vmResult['wds'] ?? null;
        $updated = $vmResult['updated_interfaces'] ?? null;
        if (!is_int($vmId) || $vmId <= $previousVmId || isset($vmResultIds[$vmId])
            || !is_string($vmResult['vm_name'] ?? null)
            || !in_array((string) ($vmResult['outcome'] ?? ''), ['success', 'failed'], true)
            || !is_int($updated) || $updated < 0
            || !is_array($codes) || !array_is_list($codes)
            || $codes !== array_values(array_unique($codes))) {
            return false;
        }
        $sortedCodes = $codes;
        sort($sortedCodes, SORT_STRING);
        if ($codes !== $sortedCodes || array_diff($codes, VIRTUSPHERE_MAC_IMPORT_ERROR_CODES) !== []) {
            return false;
        }
        if (!is_array($wds)
            || !array_key_exists('configured_portgroup', $wds)
            || !array_key_exists('portal_interface_id', $wds)
            || !array_key_exists('verified', $wds)
            || !is_string($wds['configured_portgroup'] ?? null)
            || (!is_null($wds['portal_interface_id'] ?? null) && (!is_int($wds['portal_interface_id']) || $wds['portal_interface_id'] <= 0))
            || !is_bool($wds['verified'] ?? null)) {
            return false;
        }
        $isSuccess = in_array($vmId, $successful, true);
        if (($vmResult['outcome'] === 'success') !== $isSuccess
            || ($isSuccess && ($codes !== [] || $updated <= 0 || $wds['verified'] !== true || !is_int($wds['portal_interface_id'])))
            || (!$isSuccess && ($codes === [] || $updated !== 0 || $wds['verified'] !== false))) {
            return false;
        }
        $vmResultIds[$vmId] = true;
        $vmResultCodes[$vmId] = $codes;
        $updatedInterfaces += $updated;
        $previousVmId = $vmId;
    }
    $actualIds = array_map('intval', array_keys($vmResultIds));
    sort($actualIds, SORT_NUMERIC);
    if ($actualIds !== $expectedIds) {
        return false;
    }
    $topLevelCodes = [];
    $previousErrorKey = null;
    foreach ($decoded['errors'] as $error) {
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $errorVmId = is_array($error) && array_key_exists('vm_id', $error) ? $error['vm_id'] : null;
        if (!is_array($error) || !is_string($code) || !in_array($code, VIRTUSPHERE_MAC_IMPORT_ERROR_CODES, true)
            || ($errorVmId !== null && (!is_int($errorVmId) || $errorVmId <= 0))
            || (array_key_exists('vm_name', $error) && !is_string($error['vm_name']))
            || (array_key_exists('vlan', $error) && !is_string($error['vlan']))
            || (array_key_exists('mac', $error) && !is_string($error['mac']))
            || (array_key_exists('other_vm_id', $error) && (!is_int($error['other_vm_id']) || $error['other_vm_id'] <= 0))
            || (array_key_exists('ambiguity_source', $error) && !in_array($error['ambiguity_source'], ['portal', 'esxi', 'both'], true))) {
            return false;
        }
        $errorKey = mac_import_error_sort_key($error);
        if ($previousErrorKey !== null && strcmp($previousErrorKey, $errorKey) > 0) {
            return false;
        }
        $previousErrorKey = $errorKey;
        if (is_int($errorVmId) && isset($vmResultIds[$errorVmId])) {
            $topLevelCodes[$errorVmId][$code] = true;
        }
    }
    foreach ($vmResultCodes as $vmId => $codes) {
        $reported = array_keys($topLevelCodes[$vmId] ?? []);
        sort($reported, SORT_STRING);
        if ($reported !== $codes) {
            return false;
        }
    }
    $counts = $decoded['counts'];
    foreach (['expected_vms', 'successful_vms', 'failed_vms', 'updated_interfaces'] as $key) {
        if (!is_int($counts[$key] ?? null) || $counts[$key] < 0) {
            return false;
        }
    }
    if ($counts['expected_vms'] !== count($expectedIds)
        || $counts['successful_vms'] !== count($successful)
        || $counts['failed_vms'] !== count($failed)
        || $counts['updated_interfaces'] !== $updatedInterfaces
        || (string) ($decoded['retry']['mode'] ?? '') !== 'export'
        || !mac_import_v2_ids_are_canonical($decoded['retry']['vm_ids'] ?? null)
        || $decoded['retry']['vm_ids'] !== $failed) {
        return false;
    }
    $derivedOutcome = $successful === []
        ? 'failed'
        : ($failed === [] && $decoded['errors'] === [] ? 'success' : 'partial');
    return $result['outcome'] === $derivedOutcome;
}

function mac_import_v2_ids_are_canonical(mixed $ids): bool
{
    if (!is_array($ids) || !array_is_list($ids)) {
        return false;
    }
    foreach ($ids as $id) {
        if (!is_int($id) || $id <= 0) {
            return false;
        }
    }
    $canonical = array_values(array_unique($ids));
    sort($canonical, SORT_NUMERIC);
    return $ids === $canonical;
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
