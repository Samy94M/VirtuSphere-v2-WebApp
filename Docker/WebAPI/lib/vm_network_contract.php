<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/esxi_object_names.php';
require_once __DIR__ . '/mac.php';
require_once __DIR__ . '/network_mac_constants.php';

const VIRTUSPHERE_VM_NETWORK_EMPTY = 'interface_vlan_empty';
const VIRTUSPHERE_VM_NETWORK_AMBIGUOUS = 'interface_vlan_ambiguous';

const VIRTUSPHERE_WDS_READY = 'ready';
const VIRTUSPHERE_WDS_MISSION_MISSING = 'mission_wds_missing';
const VIRTUSPHERE_WDS_PORTAL_MISSING = 'portal_wds_interface_missing';
const VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH = 'portal_wds_interface_case_mismatch';
const VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS = 'portal_wds_interface_ambiguous';

const VIRTUSPHERE_WDS_ERROR_CODES = [
    VIRTUSPHERE_WDS_MISSION_MISSING,
    VIRTUSPHERE_WDS_PORTAL_MISSING,
    VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH,
    VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS,
    'esxi_wds_interface_missing',
    'esxi_wds_interface_case_mismatch',
    'esxi_wds_interface_ambiguous',
    'wds_mac_missing',
];

const VIRTUSPHERE_PREFLIGHT_SOURCE_INTERFACE_VLAN = 'interface_vlan';
const VIRTUSPHERE_PREFLIGHT_SOURCE_WDS = 'wds';

/**
 * Which producer a finding came from. It is a sort key, never a decision: two
 * findings that tie on VM must still order reproducibly, and "which check
 * raised this" is the only remaining stable distinction between them.
 */
const VIRTUSPHERE_PREFLIGHT_SOURCE_KIND_RANK = [
    VIRTUSPHERE_PREFLIGHT_SOURCE_INTERFACE_VLAN => 1,
    VIRTUSPHERE_PREFLIGHT_SOURCE_WDS => 2,
];

/**
 * Closed severity registry, most urgent first. Urgency here is how much a
 * single repair buys: the mission WDS field is one value that blocks every VM
 * of the job, an interface without any VLAN cannot be deployed at all, and a
 * case mismatch is the one finding where the operator can see a plausible
 * value and only has to correct its spelling.
 *
 * It is the first key of the canonical order, so a bounded list always keeps
 * the findings that unblock the most, and dropping entries from the end drops
 * the least useful ones. An unranked code sorts last rather than crashing; the
 * registry is walked against the code constants by VmNetworkContractTest.
 */
const VIRTUSPHERE_PREFLIGHT_SEVERITY_RANK = [
    VIRTUSPHERE_WDS_MISSION_MISSING => 1,
    VIRTUSPHERE_VM_NETWORK_EMPTY => 2,
    VIRTUSPHERE_VM_NETWORK_AMBIGUOUS => 3,
    VIRTUSPHERE_WDS_PORTAL_MISSING => 4,
    VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS => 5,
    VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH => 6,
    'esxi_wds_interface_missing' => 7,
    'esxi_wds_interface_ambiguous' => 8,
    'esxi_wds_interface_case_mismatch' => 9,
    'wds_mac_missing' => 10,
];

function vm_network_finding_severity_rank(array $finding): int
{
    return VIRTUSPHERE_PREFLIGHT_SEVERITY_RANK[(string) ($finding['code'] ?? '')] ?? PHP_INT_MAX;
}

function vm_network_finding_source_rank(array $finding): int
{
    return VIRTUSPHERE_PREFLIGHT_SOURCE_KIND_RANK[(string) ($finding['source_kind'] ?? '')] ?? PHP_INT_MAX;
}

/**
 * @param list<array<string,mixed>|object> $interfaces
 * @return list<array<string,mixed>>
 */
function vm_network_issues_for_interfaces(array $interfaces, int $missionId, int $vmId, string $vmName): array
{
    $byVlan = [];
    $issues = [];
    $emptyRows = [];
    foreach ($interfaces as $index => $interface) {
        $row = is_object($interface) ? get_object_vars($interface) : $interface;
        $vlan = (string) ($row['vlan'] ?? '');
        $interfaceId = (int) ($row['id'] ?? 0);
        if (trim($vlan) === '') {
            $emptyRows[] = ['id' => $interfaceId, 'index' => $index];
            continue;
        }
        // PHP casts numeric-looking array keys. Prefixing the byte length keeps
        // values such as "1" and "01" separate while equality remains the
        // required exact stored-value comparison.
        $key = strlen($vlan) . ':' . $vlan;
        $byVlan[$key]['vlan'] = $vlan;
        $byVlan[$key]['matches'][] = ['id' => $interfaceId, 'index' => $index];
    }

    if ($emptyRows !== []) {
        $ids = vm_network_positive_ids($emptyRows);
        $indexes = array_map(static fn (array $row): int => (int) $row['index'], $emptyRows);
        $issues[] = vm_network_issue(
            VIRTUSPHERE_VM_NETWORK_EMPTY,
            $missionId,
            $vmId,
            $vmName,
            '',
            $ids,
            $indexes,
            count($emptyRows)
        );
    }

    foreach ($byVlan as $group) {
        $vlan = (string) $group['vlan'];
        $matches = (array) $group['matches'];
        if (count($matches) < 2) {
            continue;
        }
        $ids = vm_network_positive_ids($matches);
        $indexes = array_map(static fn (array $row): int => (int) $row['index'], $matches);
        $issues[] = vm_network_issue(VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, $missionId, $vmId, $vmName, (string) $vlan, $ids, $indexes, count($matches));
    }

    vm_network_sort_issues($issues);

    return $issues;
}

/**
 * The canonical total order over preflight findings (correction plan 16.4).
 *
 * A bounded list is only honest while "the first 50" and "entries from the end"
 * name the same rows on every render, so the comparator has to be a TOTAL
 * order: it ends on binary comparisons of the exact stored bytes, because a
 * case tie between two ESXi names is exactly the situation where a natural or
 * folded comparison stops deciding and the browser, the JSON island and the
 * worker result would each keep a different subset.
 *
 * @param list<array<string,mixed>> $issues
 */
function vm_network_sort_issues(array &$issues): void
{
    usort($issues, 'vm_network_compare_findings');
}

function vm_network_compare_findings(array $left, array $right): int
{
    $leftName = (string) ($left['vm_name'] ?? '');
    $rightName = (string) ($right['vm_name'] ?? '');
    foreach ([
        vm_network_finding_severity_rank($left) <=> vm_network_finding_severity_rank($right),
        (int) ($left['mission_id'] ?? 0) <=> (int) ($right['mission_id'] ?? 0),
        strnatcasecmp($leftName, $rightName),
        strcmp($leftName, $rightName),
        (int) ($left['vm_id'] ?? 0) <=> (int) ($right['vm_id'] ?? 0),
        vm_network_finding_source_rank($left) <=> vm_network_finding_source_rank($right),
        (int) (($left['interface_ids'][0] ?? $left['row_indexes'][0] ?? PHP_INT_MAX))
            <=> (int) (($right['interface_ids'][0] ?? $right['row_indexes'][0] ?? PHP_INT_MAX)),
        strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? '')),
        strcmp(
            (string) ($left['vlan'] ?? $left['configured_portgroup'] ?? ''),
            (string) ($right['vlan'] ?? $right['configured_portgroup'] ?? '')
        ),
        strcmp(
            implode("\0", array_map('strval', (array) ($left['candidates'] ?? []))),
            implode("\0", array_map('strval', (array) ($right['candidates'] ?? [])))
        ),
    ] as $comparison) {
        if ($comparison !== 0) {
            return $comparison;
        }
    }

    return 0;
}

/** @return array<string,mixed> */
function vm_network_issue(string $code, int $missionId, int $vmId, string $vmName, string $vlan, array $interfaceIds, array $rowIndexes, int $occurrences): array
{
    return [
        'code' => $code,
        'source_kind' => VIRTUSPHERE_PREFLIGHT_SOURCE_INTERFACE_VLAN,
        'mission_id' => $missionId,
        'vm_id' => $vmId,
        'vm_name' => $vmName,
        'vlan' => $vlan,
        'interface_ids' => $interfaceIds,
        'row_indexes' => $rowIndexes,
        'occurrences' => $occurrences,
    ];
}

/** @param list<array<string,mixed>> $rows @return list<int> */
function vm_network_positive_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @param list<array<string,mixed>|object> $interfaces */
function vm_network_bundle_fingerprint(array $interfaces): string
{
    $rows = [];
    foreach ($interfaces as $ordinal => $interface) {
        $row = is_object($interface) ? get_object_vars($interface) : $interface;
        $rows[] = [
            'ordinal' => $ordinal,
            'id' => max(0, (int) ($row['id'] ?? 0)),
            'vlan' => (string) ($row['vlan'] ?? ''),
            'mode' => trim((string) ($row['mode'] ?? '')),
            'type' => trim((string) ($row['type'] ?? '')),
            'ip' => trim((string) ($row['ip'] ?? '')),
            'subnet' => trim((string) ($row['subnet'] ?? '')),
            'gateway' => trim((string) ($row['gateway'] ?? '')),
            'dns1' => trim((string) ($row['dns1'] ?? '')),
            'dns2' => trim((string) ($row['dns2'] ?? '')),
            'mac' => virtusphere_normalize_mac((string) ($row['mac'] ?? '')) ?? trim((string) ($row['mac'] ?? '')),
        ];
    }
    $json = json_encode(['version' => 1, 'interfaces' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return hash('sha256', $json);
}

/**
 * @param list<array<string,mixed>|object> $interfaces
 * @return array<string,mixed>
 */
function vm_network_wds_verdict(array $interfaces, string $missionWdsVlan, int $missionId, int $vmId, string $vmName): array
{
    $expected = $missionWdsVlan;
    $base = [
        'source_kind' => VIRTUSPHERE_PREFLIGHT_SOURCE_WDS,
        'mission_id' => $missionId,
        'vm_id' => $vmId,
        'vm_name' => $vmName,
        'configured_portgroup' => $expected,
        'interface_ids' => [],
        'row_indexes' => [],
        'candidates' => [],
        'candidate_total' => 0,
        'candidate_omitted_count' => 0,
        'portal_interface_id' => null,
        'wds_mac_present' => false,
    ];
    if (trim($expected) === '') {
        return array_merge($base, ['code' => VIRTUSPHERE_WDS_MISSION_MISSING]);
    }

    $exact = [];
    $similar = [];
    foreach ($interfaces as $index => $interface) {
        $row = is_object($interface) ? get_object_vars($interface) : $interface;
        $vlan = (string) ($row['vlan'] ?? '');
        $entry = ['id' => max(0, (int) ($row['id'] ?? 0)), 'index' => $index, 'vlan' => $vlan, 'mac' => trim((string) ($row['mac'] ?? ''))];
        if ($vlan === $expected) {
            $exact[] = $entry;
        } elseif ($vlan !== '' && esxi_object_name_diagnostic_key($vlan) === esxi_object_name_diagnostic_key($expected)) {
            $similar[] = $entry;
        }
    }
    if (count($exact) === 1) {
        return array_merge($base, [
            'code' => VIRTUSPHERE_WDS_READY,
            'interface_ids' => $exact[0]['id'] > 0 ? [$exact[0]['id']] : [],
            'row_indexes' => [$exact[0]['index']],
            'portal_interface_id' => $exact[0]['id'] > 0 ? $exact[0]['id'] : null,
            'wds_mac_present' => $exact[0]['mac'] !== '',
        ]);
    }
    if (count($exact) > 1) {
        return array_merge($base, [
            'code' => VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS,
            'interface_ids' => vm_network_positive_ids($exact),
            'row_indexes' => array_column($exact, 'index'),
        ]);
    }
    if ($similar !== []) {
        // Candidates are operator-assigned ESXi names, so their number grows
        // with the VM and not with anything this code controls. They are bounded
        // here, at the one place they are produced, and the two counts stay the
        // complete truth about what was found.
        $candidates = array_values(array_unique(array_column($similar, 'vlan')));
        sort($candidates, SORT_STRING);
        $shown = array_slice($candidates, 0, VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT);

        return array_merge($base, [
            'code' => VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH,
            'interface_ids' => array_values(array_map(static fn (array $row): int => $row['id'], array_filter($similar, static fn (array $row): bool => $row['id'] > 0))),
            'row_indexes' => array_column($similar, 'index'),
            'candidates' => $shown,
            'candidate_total' => count($candidates),
            'candidate_omitted_count' => count($candidates) - count($shown),
        ]);
    }

    return array_merge($base, ['code' => VIRTUSPHERE_WDS_PORTAL_MISSING]);
}

function deploy_mode_requires_unique_network_mapping(string $mode): bool
{
    if ($mode === VIRTUSPHERE_DEPLOY_MODE_INVENTORY) {
        return false;
    }
    $playbooks = ansible_playbooks_for_mode($mode);

    return in_array(VIRTUSPHERE_PLAYBOOKS['create'], $playbooks, true)
        || in_array(VIRTUSPHERE_PLAYBOOKS['export'], $playbooks, true);
}

function deploy_mode_requires_wds_ready(string $mode): bool
{
    return $mode !== VIRTUSPHERE_DEPLOY_MODE_INVENTORY && ansible_mode_expects_mac_result($mode);
}

function deploy_mode_has_hard_network_gate(string $mode): bool
{
    return deploy_mode_requires_unique_network_mapping($mode) || deploy_mode_requires_wds_ready($mode);
}

/** @return list<string> */
function deploy_modes_with_hard_network_gate(): array
{
    return array_values(array_filter(
        virtusphere_user_deploy_modes(),
        static fn (string $mode): bool => deploy_mode_has_hard_network_gate($mode)
    ));
}
