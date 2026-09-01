<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_preflight_bounds.php';
require_once __DIR__ . '/vm_network_contract.php';

/**
 * The stored shape of a worker network/WDS preflight verdict: what
 * `deploy_jobs.result_json` carries when a job is blocked before its first
 * remote step, and what the portal and the retry gate may read back from it.
 *
 * Separate from vm_network_contract.php because it is a different data source:
 * the contract file answers "is this VM's network configuration well formed",
 * this one answers "what did a finished job store about that decision", and a
 * stored document has to stay readable across versions long after the producer
 * has changed.
 */

const VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_VERSION = 1;
const VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_KIND = 'network_preflight';

/** @return array<string,mixed> */
function vm_network_preflight_result_contract(string $mode, array $vms, array $blockers, array $missingVmIds = []): array
{
    $missingVmIds = array_values(array_unique(array_filter(array_map('intval', $missingVmIds), static fn (int $id): bool => $id > 0)));
    sort($missingVmIds, SORT_NUMERIC);
    $materializedVmIds = array_map(static fn (array $vm): int => (int) ($vm['id'] ?? 0), $vms);
    if (array_intersect($missingVmIds, $materializedVmIds) !== []) {
        throw new InvalidArgumentException('Missing VM ids must be disjoint from materialized preflight VMs.');
    }
    $byVm = [];
    foreach ($blockers as $finding) {
        $vmId = (int) ($finding['vm_id'] ?? 0);
        if ($vmId <= 0) {
            continue;
        }
        $issue = ['code' => (string) ($finding['code'] ?? '')];
        if ((string) ($finding['vlan'] ?? $finding['configured_portgroup'] ?? '') !== '') {
            $issue['vlan'] = (string) ($finding['vlan'] ?? $finding['configured_portgroup']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($finding['interface_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if ($ids !== []) {
            $issue['interface_ids'] = $ids;
        }
        $byVm[$vmId][] = $issue;
    }

    $rows = [];
    foreach ($vms as $vm) {
        $vmId = (int) ($vm['id'] ?? 0);
        if (!isset($byVm[$vmId])) {
            continue;
        }
        usort($byVm[$vmId], static fn (array $left, array $right): int => strcmp(
            (string) $left['code'] . "\0" . (string) ($left['vlan'] ?? ''),
            (string) $right['code'] . "\0" . (string) ($right['vlan'] ?? '')
        ));
        $rows[] = [
            'vm_id' => $vmId,
            'vm_name' => (string) ($vm['vm_name'] ?? ''),
            'outcome' => 'failed',
            'issues' => $byVm[$vmId],
        ];
    }
    // `counts` is computed from the COMPLETE finding set and stays untouched by
    // the bounding below, so the stored document always says how many VMs were
    // actually blocked even when it can only list some of them.
    $document = [
        'version' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_VERSION,
        'kind' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_KIND,
        'outcome' => 'failed',
        'mode' => $mode,
        'counts' => [
            'expected_vms' => count($vms) + count($missingVmIds),
            'blocked_vms' => count($rows) + count($missingVmIds),
            'missing_vms' => count($missingVmIds),
            'issues' => count($blockers),
        ],
        'missing_vm_ids' => $missingVmIds,
        'vm_results' => $rows,
    ];

    return deploy_preflight_bounded_result($document)['result'];
}

/** @return array<string,mixed>|null */
function vm_network_preflight_decode_result(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)
        || (int) ($decoded['version'] ?? 0) !== VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_VERSION
        || (string) ($decoded['kind'] ?? '') !== VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_KIND
        || (string) ($decoded['outcome'] ?? '') !== 'failed'
        || !in_array((string) ($decoded['mode'] ?? ''), virtusphere_user_deploy_modes(), true)
        || !is_array($decoded['counts'] ?? null)
        || !is_array($decoded['missing_vm_ids'] ?? null)
        || !is_array($decoded['vm_results'] ?? null)) {
        return null;
    }
    $missingVmIds = $decoded['missing_vm_ids'];
    $canonicalMissing = [];
    foreach ($missingVmIds as $id) {
        if (!is_int($id) || $id <= 0 || isset($canonicalMissing[$id])) {
            return null;
        }
        $canonicalMissing[$id] = true;
    }
    $sortedMissing = array_keys($canonicalMissing);
    sort($sortedMissing, SORT_NUMERIC);
    if ($missingVmIds !== $sortedMissing) {
        return null;
    }
    // The bounded fields are additive and optional: a document written before
    // the display bounds existed carries neither, and reads back as a complete
    // list, which is exactly what it is. A present pair must agree with the
    // rows it describes, so a hand-edited or half-written document is still
    // rejected rather than silently believed.
    $listedRows = count($decoded['vm_results']);
    $resultsTotal = array_key_exists('vm_results_total', $decoded) ? $decoded['vm_results_total'] : $listedRows;
    $resultsOmitted = array_key_exists('vm_results_omitted_count', $decoded) ? $decoded['vm_results_omitted_count'] : 0;
    $truncatedByBytes = $decoded['truncated_by_bytes'] ?? false;
    if (!is_int($resultsTotal) || !is_int($resultsOmitted) || !is_bool($truncatedByBytes)
        || $resultsTotal < 0 || $resultsOmitted < 0
        || $listedRows + $resultsOmitted !== $resultsTotal) {
        return null;
    }

    $knownCodes = array_merge([VIRTUSPHERE_VM_NETWORK_EMPTY, VIRTUSPHERE_VM_NETWORK_AMBIGUOUS], VIRTUSPHERE_WDS_ERROR_CODES);
    $seen = [];
    $issueCount = 0;
    foreach ($decoded['vm_results'] as $row) {
        $vmId = (int) ($row['vm_id'] ?? 0);
        if ($vmId <= 0 || isset($seen[$vmId]) || isset($canonicalMissing[$vmId]) || (string) ($row['outcome'] ?? '') !== 'failed'
            || !is_array($row['issues'] ?? null) || $row['issues'] === []) {
            return null;
        }
        $seen[$vmId] = true;
        foreach ($row['issues'] as $issue) {
            if (!is_array($issue) || !in_array((string) ($issue['code'] ?? ''), $knownCodes, true)) {
                return null;
            }
            $issueCount++;
        }
    }
    $counts = $decoded['counts'];
    foreach (['expected_vms', 'blocked_vms', 'missing_vms', 'issues'] as $key) {
        if (!is_int($counts[$key] ?? null) || $counts[$key] < 0) {
            return null;
        }
    }
    // `counts` describes the complete decision, `vm_results` only what fits.
    // Equality is therefore asserted against the DECLARED totals, and the
    // listed rows and issues must not exceed them: a document may say less than
    // it decided, never more.
    if (count($seen) !== $listedRows || $listedRows > $resultsTotal) {
        return null;
    }
    $blockedCount = $resultsTotal + count($missingVmIds);
    if ($counts['expected_vms'] < $blockedCount
        || $counts['blocked_vms'] !== $blockedCount
        || $counts['missing_vms'] !== count($missingVmIds)
        || $counts['issues'] < $issueCount
        || ($resultsOmitted === 0 && $counts['issues'] !== $issueCount)) {
        return null;
    }
    return $decoded;
}
