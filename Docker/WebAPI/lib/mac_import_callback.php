<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/mac.php';
require_once __DIR__ . '/mac_import_constants.php';
require_once __DIR__ . '/remote_execution.php';
require_once __DIR__ . '/remote_step_policy.php';

/** @return mixed */
function mac_import_canonical_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('mac_import_canonical_value', $value);
    }
    $keys = array_map('strval', array_keys($value));
    sort($keys, SORT_STRING);
    $canonical = [];
    foreach ($keys as $key) {
        $canonical[$key] = mac_import_canonical_value($value[$key]);
    }
    return $canonical;
}

/** @return array<string,mixed> */
function mac_import_callback_semantic_entry(mixed $rawEntry): array
{
    if (is_string($rawEntry)) {
        $decoded = json_decode($rawEntry, true);
        $rawEntry = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($rawEntry)) {
        return ['shape' => 'malformed'];
    }

    $instance = is_array($rawEntry['instance'] ?? null) ? $rawEntry['instance'] : null;
    $item = is_array($rawEntry['item'] ?? null) ? $rawEntry['item'] : [];
    $instanceName = is_array($instance) ? (string) ($instance['hw_name'] ?? '') : '';
    $itemName = (string) ($item['vm_name'] ?? $item['name'] ?? '');
    $vmName = $instanceName !== '' ? $instanceName : $itemName;
    $failed = !empty($rawEntry['failed']);
    $semantic = [
        'failed' => $failed,
        'instance_present' => $instance !== null,
        'vm_name' => $vmName,
    ];
    // A failed or nameless row returns before identity/NIC evaluation. Module
    // messages, invocation data and other Ansible diagnostics are deliberately
    // absent from this closed semantic shape.
    if ($failed || $vmName === '' || $instance === null) {
        return $semantic;
    }

    $semantic['identity'] = [
        'moid' => trim((string) ($instance['moid'] ?? '')),
        'instance_uuid' => trim((string) ($instance['instance_uuid'] ?? '')),
    ];
    $nics = [];
    foreach ($instance as $key => $value) {
        if (!str_starts_with((string) $key, 'hw_eth')) {
            continue;
        }
        if (!is_array($value)) {
            $nics[] = ['shape' => 'malformed'];
            continue;
        }
        $rawMac = trim((string) ($value['macaddress'] ?? ''));
        $nics[] = [
            'summary' => (string) ($value['summary'] ?? ''),
            'mac' => virtusphere_normalize_mac($rawMac) ?? $rawMac,
        ];
    }
    usort($nics, static fn (array $left, array $right): int => strcmp(
        json_encode(mac_import_canonical_value($left), JSON_THROW_ON_ERROR),
        json_encode(mac_import_canonical_value($right), JSON_THROW_ON_ERROR)
    ));
    $semantic['nics'] = $nics;
    return $semantic;
}

/**
 * Semantic request fingerprint. Expected VM ids and list ordering are
 * canonicalized; duplicate semantic rows remain duplicates.
 *
 * @param list<int> $expectedVmIds
 */
function mac_import_callback_fingerprint(int $missionId, int $jobId, array $results, array $expectedVmIds = []): string
{
    $expectedVmIds = array_values(array_unique(array_filter(array_map('intval', $expectedVmIds), static fn (int $id): bool => $id > 0)));
    sort($expectedVmIds, SORT_NUMERIC);
    $entries = array_map('mac_import_callback_semantic_entry', $results);
    usort($entries, static fn (array $left, array $right): int => strcmp(
        json_encode(mac_import_canonical_value($left), JSON_THROW_ON_ERROR),
        json_encode(mac_import_canonical_value($right), JSON_THROW_ON_ERROR)
    ));
    $json = json_encode(
        [
            'version' => 2,
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'expected_vm_ids' => $expectedVmIds,
            'results' => $entries,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
    return hash('sha256', $json);
}

/** @return array{mode:string,scope_ids:?list<int>} */
function mac_import_callback_job_payload(array $job): array
{
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new UnexpectedValueException('Deploy job payload is not an object.');
    }
    $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
    if (!in_array($mode, virtusphere_deploy_modes(), true)
        || $mode === VIRTUSPHERE_DEPLOY_MODE_INVENTORY
        || !ansible_mode_expects_mac_result($mode)
        || remote_step_callback_expectation($mode, 'export') !== 'db_import_mac') {
        throw new UnexpectedValueException('Deploy mode does not expect a MAC callback.');
    }
    return ['mode' => $mode, 'scope_ids' => mac_import_job_scope_ids($job)];
}

/**
 * Checks the immutable job/runtime/remote-handle contract after the caller has
 * acquired locks in Mission -> Job -> Runtime -> Remote-handle order.
 */
function mac_import_callback_fence_reason(array $job, string $runtimeGeneration, ?array $remoteHandle): ?string
{
    $contract = (string) ($job['execution_contract'] ?? '');
    $jobGeneration = (string) ($job['execution_generation_id'] ?? '');
    if (!in_array($contract, [VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY, VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE], true)) {
        return 'callback_execution_contract_missing';
    }
    if (preg_match('/^[a-f0-9]{32}$/', $runtimeGeneration) !== 1
        || preg_match('/^[a-f0-9]{32}$/', $jobGeneration) !== 1
        || !hash_equals($runtimeGeneration, $jobGeneration)) {
        return 'callback_generation_mismatch';
    }
    if ($contract === VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY) {
        return null;
    }
    if ($remoteHandle === null
        || (int) ($remoteHandle['job_attempt'] ?? 0) !== (int) ($job['attempts'] ?? 0)
        || (string) ($remoteHandle['step_key'] ?? '') !== 'export'
        || (string) ($remoteHandle['callback_expectation'] ?? '') !== 'db_import_mac'
        || !hash_equals($runtimeGeneration, (string) ($remoteHandle['generation_id'] ?? ''))) {
        return 'callback_remote_handle_mismatch';
    }
    return null;
}

/** @return array<string,mixed> */
function mac_import_response(array $plan, int $jobId, bool $legacyPayload, ?string $correlationId): array
{
    $diagnostics = mac_import_legacy_diagnostics($plan['errors']);
    $response = [
        'success' => $plan['outcome'] === 'success',
        'legacy_payload' => $legacyPayload,
        'updated_interfaces' => $plan['counts']['updated_interfaces'],
        'updated_vms' => $plan['counts']['successful_vms'],
        'missing_vms' => $diagnostics['missing_vms'],
        'unmatched_interfaces' => $diagnostics['unmatched_interfaces'],
        'duplicate_macs' => $diagnostics['duplicate_macs'],
        'result_version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
        'outcome' => $plan['outcome'],
        'job_id' => $jobId,
        'correlation_id' => $correlationId,
        'vm_results' => $plan['vm_results'],
        'counts' => $plan['counts'],
        'errors' => $plan['errors'],
    ];
    if ($plan['outcome'] !== 'success') {
        $response['error'] = 'MAC import completed with unmatched entries';
    }
    return $response;
}

function mac_import_contract_bound_reason(
    string $resultJson,
    string $responseJson,
    int $resultMax = VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES,
    int $responseMax = VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES
): ?string {
    if (strlen($resultJson) > $resultMax) {
        return 'result_contract_too_large';
    }
    if (strlen($responseJson) > $responseMax) {
        return 'response_contract_too_large';
    }
    return null;
}
