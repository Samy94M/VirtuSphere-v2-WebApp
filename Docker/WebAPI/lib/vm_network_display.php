<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_preflight_bounds.php';
require_once __DIR__ . '/vm_network_contract.php';

function vm_network_finding_message(array $finding): string
{
    $code = (string) ($finding['code'] ?? '');
    $params = [
        'name' => (string) ($finding['vm_name'] ?? ''),
        'vlan' => (string) ($finding['vlan'] ?? $finding['configured_portgroup'] ?? ''),
        'count' => (int) ($finding['occurrences'] ?? count((array) ($finding['row_indexes'] ?? []))),
        'actual' => implode(', ', (array) ($finding['candidates'] ?? [])),
    ];
    $key = match ($code) {
        VIRTUSPHERE_VM_NETWORK_EMPTY => 'deploy.network_empty',
        VIRTUSPHERE_VM_NETWORK_AMBIGUOUS => 'deploy.network_ambiguous',
        VIRTUSPHERE_WDS_MISSION_MISSING => 'deploy.wds_mission_missing',
        VIRTUSPHERE_WDS_PORTAL_MISSING => 'deploy.wds_interface_missing',
        VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH => 'deploy.wds_interface_case_mismatch',
        VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS => 'deploy.wds_interface_ambiguous',
        default => 'deploy.network_unknown',
    };

    return __t($key, $params);
}

/**
 * Bounded operator summary for all-or-nothing bulk writers.
 *
 * Grouped per VM first, then bounded through the one owner in
 * `deploy_preflight_bounds.php`; the caller never picks its own number, or the
 * flash message and the queue block would disagree about how much was left out.
 * `total` and `omitted` describe the complete finding set.
 */
function vm_network_finding_summary(array $findings, int $limit = VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT): array
{
    $sorted = array_values($findings);
    vm_network_sort_issues($sorted);
    $byVm = [];
    foreach ($sorted as $finding) {
        $vmId = (int) ($finding['vm_id'] ?? 0);
        $byVm[$vmId > 0 ? $vmId : count($byVm) * -1 - 1] ??= [
            'name' => (string) ($finding['vm_name'] ?? ''),
            'messages' => [],
        ];
        $byVm[$vmId > 0 ? $vmId : array_key_last($byVm)]['messages'][] = vm_network_finding_message($finding);
    }
    $bounded = deploy_preflight_bounded_findings(array_values($byVm), $limit);
    $rows = [];
    foreach ($bounded['items'] as $row) {
        $rows[] = (string) $row['name'] . ': ' . implode(' ', array_values(array_unique($row['messages'])));
    }

    return ['rows' => $rows, 'omitted' => $bounded['omitted_count'], 'total' => $bounded['total']];
}
