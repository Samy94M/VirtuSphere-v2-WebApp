<?php

declare(strict_types=1);

// Where a package, an operating system, a VLAN or a credential is used (F08,
// "Verwendet in"). This is the one reader for those answers. The OS usage
// counter of lib/repo/catalog.php delegates to usage_evidence_os_vm_counts(), so
// the count on the OS page and the detail list come from the same predicate.
//
// Rules of this reader:
// - It never reads deploy_credentials. The caller names a credential by id and
//   type, and the reader follows the stored references only. No secret, host
//   or username can enter a result, because no such column is selected.
// - A count and its list share one FROM and WHERE fragment. Joins are collapsed
//   per VM, so a VM with two adapters on one VLAN is one reference.
// - Lists are bounded; every group carries the complete total beside its rows.
// - A reference is what the schema stores. The notes name the limits of that
//   evidence, and an absent reference is never reported as a claim of non-use.

require_once __DIR__ . '/../usage_evidence.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param list<string|int> $params
 * @return list<array<string, mixed>>
 */
function usage_evidence_fetch(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{applies: bool, total: int, rows: list<array<string, mixed>>}
 */
function usage_evidence_group(bool $applies, int $total, array $rows): array
{
    return ['applies' => $applies, 'total' => $total, 'rows' => $rows];
}

/** @return array{applies: bool, total: int, rows: list<array<string, mixed>>} */
function usage_evidence_not_applicable(): array
{
    return usage_evidence_group(false, 0, []);
}

/**
 * A group of VMs. The total counts distinct VMs, and the rows are the same VMs
 * in a stable order, cut at $limit.
 *
 * @param list<string|int> $params
 * @return array{applies: bool, total: int, rows: list<array<string, mixed>>}
 */
function usage_evidence_vm_group(mysqli $db, string $from, string $where, string $types, array $params, int $limit): array
{
    $total = (int) repo_scalar($db, 'SELECT COUNT(DISTINCT v.id) FROM ' . $from . ' WHERE ' . $where, $types, $params);
    if ($total === 0) {
        return usage_evidence_group(true, 0, []);
    }
    $rows = usage_evidence_fetch(
        $db,
        'SELECT v.id AS vm_id, v.vm_name, v.mission_id, m.mission_name FROM ' . $from . ' WHERE ' . $where
        . ' GROUP BY v.id, v.vm_name, v.mission_id, m.mission_name'
        . ' ORDER BY m.mission_name, v.vm_name, v.id LIMIT ?',
        $types . 'i',
        [...$params, $limit]
    );

    return usage_evidence_group(true, $total, $rows);
}

/**
 * A group of missions (mission-level settings). Templates are missions too; the
 * renderer tells them apart by name, never the reader.
 *
 * @param list<string|int> $params
 * @return array{applies: bool, total: int, rows: list<array<string, mixed>>}
 */
function usage_evidence_mission_group(mysqli $db, string $where, string $types, array $params, int $limit): array
{
    $total = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_missions m WHERE ' . $where, $types, $params);
    if ($total === 0) {
        return usage_evidence_group(true, 0, []);
    }
    $rows = usage_evidence_fetch(
        $db,
        'SELECT m.id AS mission_id, m.mission_name FROM deploy_missions m WHERE ' . $where
        . ' ORDER BY m.mission_name, m.id LIMIT ?',
        $types . 'i',
        [...$params, $limit]
    );

    return usage_evidence_group(true, $total, $rows);
}

/**
 * Deploy jobs that reference a credential through its foreign key, either as the
 * ESXi or as the Ansible access. The open group uses the fence's own status set.
 * The history keeps mission jobs only: mission-less inventory pulls are pruned
 * after their retention window (repo_purge_finished_system_jobs), so their
 * absence proves nothing, and listing each pull would bury the answer.
 *
 * @return array{applies: bool, total: int, rows: list<array<string, mixed>>}
 */
function usage_evidence_job_group(mysqli $db, int $credentialId, bool $open, int $limit): array
{
    $statuses = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = '(j.credential_esxi_id = ? OR j.credential_ansible_id = ?) AND j.status '
        . ($open ? 'IN' : 'NOT IN') . ' (' . $placeholders . ')'
        . ($open ? '' : ' AND j.mission_id IS NOT NULL');
    $from = 'deploy_jobs j LEFT JOIN deploy_missions m ON m.id = j.mission_id';
    $types = 'ii' . str_repeat('s', count($statuses));
    $params = [$credentialId, $credentialId, ...$statuses];

    $total = (int) repo_scalar($db, 'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $types, $params);
    if ($total === 0) {
        return usage_evidence_group(true, 0, []);
    }
    $rows = usage_evidence_fetch(
        $db,
        'SELECT j.id AS job_id, j.status, j.mission_id, m.mission_name, j.created_at FROM ' . $from
        . ' WHERE ' . $where . ' ORDER BY j.id DESC LIMIT ?',
        $types . 'i',
        [...$params, $limit]
    );

    return usage_evidence_group(true, $total, $rows);
}

/**
 * @param list<string> $sources
 * @param array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}> $groups
 * @param list<string> $notes
 * @return array{object: string, sources: list<string>, notes: list<string>, groups: array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}>}
 */
function usage_evidence_result(string $object, array $sources, array $groups, array $notes = []): array
{
    return [
        'object' => $object,
        // UTC, as every stored timestamp is. The page formats it for display (QL03).
        'sources' => $sources,
        'notes' => $notes,
        'groups' => $groups,
    ];
}

/**
 * Packages are assigned to VMs and nothing else in the schema references them,
 * so only the direct group applies.
 *
 * @return array{object: string, sources: list<string>, notes: list<string>, groups: array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}>}
 */
function usage_evidence_package(mysqli $db, int $packageId, int $limit = VIRTUSPHERE_USAGE_ROW_LIMIT): array
{
    $limit = usage_evidence_clamp_limit($limit);
    $direct = $packageId > 0
        ? usage_evidence_vm_group(
            $db,
            'deploy_vm_packages vp INNER JOIN deploy_vms v ON v.id = vp.vm_id INNER JOIN deploy_missions m ON m.id = v.mission_id',
            'vp.package_id = ?',
            'i',
            [$packageId],
            $limit
        )
        : usage_evidence_group(true, 0, []);

    return usage_evidence_result(VIRTUSPHERE_USAGE_OBJECT_PACKAGE, [VIRTUSPHERE_USAGE_SOURCE_VM_PACKAGES], [
        VIRTUSPHERE_USAGE_GROUP_DIRECT => $direct,
        VIRTUSPHERE_USAGE_GROUP_MISSION => usage_evidence_not_applicable(),
        VIRTUSPHERE_USAGE_GROUP_JOB_OPEN => usage_evidence_not_applicable(),
        VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY => usage_evidence_not_applicable(),
    ]);
}

/**
 * An operating system is the name stored on the VM (vm_os is text, not a key).
 * The comparison uses the column's collation, the same one the purge guard of
 * the catalog reads, so a row this reader calls used is also protected from purge.
 *
 * @return array{object: string, sources: list<string>, notes: list<string>, groups: array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}>}
 */
function usage_evidence_os(mysqli $db, string $osName, int $limit = VIRTUSPHERE_USAGE_ROW_LIMIT): array
{
    $limit = usage_evidence_clamp_limit($limit);
    $direct = $osName !== ''
        ? usage_evidence_vm_group(
            $db,
            'deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id',
            'v.vm_os = ?',
            's',
            [$osName],
            $limit
        )
        : usage_evidence_group(true, 0, []);

    return usage_evidence_result(VIRTUSPHERE_USAGE_OBJECT_OS, [VIRTUSPHERE_USAGE_SOURCE_VM_OS], [
        VIRTUSPHERE_USAGE_GROUP_DIRECT => $direct,
        VIRTUSPHERE_USAGE_GROUP_MISSION => usage_evidence_not_applicable(),
        VIRTUSPHERE_USAGE_GROUP_JOB_OPEN => usage_evidence_not_applicable(),
        VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY => usage_evidence_not_applicable(),
    ]);
}

/**
 * A VLAN is used by the exact stored value of a VM network adapter (binary
 * collation) and separately by the WDS port group of a mission. Neither is
 * matched by name similarity, and an inventory presence proves neither.
 *
 * @return array{object: string, sources: list<string>, notes: list<string>, groups: array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}>}
 */
function usage_evidence_vlan(mysqli $db, string $vlanName, int $limit = VIRTUSPHERE_USAGE_ROW_LIMIT): array
{
    $limit = usage_evidence_clamp_limit($limit);
    $direct = $vlanName !== ''
        ? usage_evidence_vm_group(
            $db,
            'deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id INNER JOIN deploy_missions m ON m.id = v.mission_id',
            'i.vlan = ?',
            's',
            [$vlanName],
            $limit
        )
        : usage_evidence_group(true, 0, []);
    $mission = $vlanName !== ''
        ? usage_evidence_mission_group($db, 'm.wds_vlan = ?', 's', [$vlanName], $limit)
        : usage_evidence_group(true, 0, []);

    return usage_evidence_result(
        VIRTUSPHERE_USAGE_OBJECT_VLAN,
        [VIRTUSPHERE_USAGE_SOURCE_VM_INTERFACES, VIRTUSPHERE_USAGE_SOURCE_MISSION_WDS],
        [
            VIRTUSPHERE_USAGE_GROUP_DIRECT => $direct,
            VIRTUSPHERE_USAGE_GROUP_MISSION => $mission,
            VIRTUSPHERE_USAGE_GROUP_JOB_OPEN => usage_evidence_not_applicable(),
            VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY => usage_evidence_not_applicable(),
        ]
    );
}

/**
 * An ESXi access is bound to a VM only where that VM's identity was proven
 * through it (vm_bound_credential_id). Without that proof a VM is not counted,
 * and the note says so. An Ansible access has no VM binding; only jobs name it.
 *
 * @return array{object: string, sources: list<string>, notes: list<string>, groups: array<string, array{applies: bool, total: int, rows: list<array<string, mixed>>}>}
 */
function usage_evidence_credential(mysqli $db, int $credentialId, string $type, int $limit = VIRTUSPHERE_USAGE_ROW_LIMIT): array
{
    $limit = usage_evidence_clamp_limit($limit);
    $esxi = $type === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
    if ($esxi) {
        $direct = $credentialId > 0
            ? usage_evidence_vm_group(
                $db,
                'deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id',
                'v.vm_bound_credential_id = ?',
                'i',
                [$credentialId],
                $limit
            )
            : usage_evidence_group(true, 0, []);
    } else {
        $direct = usage_evidence_not_applicable();
    }
    $open = $credentialId > 0 ? usage_evidence_job_group($db, $credentialId, true, $limit) : usage_evidence_group(true, 0, []);
    $history = $credentialId > 0 ? usage_evidence_job_group($db, $credentialId, false, $limit) : usage_evidence_group(true, 0, []);

    return usage_evidence_result(
        VIRTUSPHERE_USAGE_OBJECT_CREDENTIAL,
        $esxi
            ? [VIRTUSPHERE_USAGE_SOURCE_VM_IDENTITY, VIRTUSPHERE_USAGE_SOURCE_DEPLOY_JOBS]
            : [VIRTUSPHERE_USAGE_SOURCE_DEPLOY_JOBS],
        [
            VIRTUSPHERE_USAGE_GROUP_DIRECT => $direct,
            VIRTUSPHERE_USAGE_GROUP_MISSION => usage_evidence_not_applicable(),
            VIRTUSPHERE_USAGE_GROUP_JOB_OPEN => $open,
            VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY => $history,
        ],
        $esxi ? [VIRTUSPHERE_USAGE_NOTE_VM_BINDING_UNPROVEN] : [VIRTUSPHERE_USAGE_NOTE_ANSIBLE_JOBS_ONLY]
    );
}

/**
 * VM count per stored operating-system name, for the OS page's count column.
 * This is the query that used to sit in lib/repo/catalog.php. Keys are the stored
 * names; a name with no VM is absent, which the caller reads as zero.
 *
 * @return array<string, int>
 */
function usage_evidence_os_vm_counts(mysqli $db): array
{
    $counts = [];
    foreach (usage_evidence_fetch($db, "SELECT vm_os, COUNT(*) AS c FROM deploy_vms WHERE vm_os IS NOT NULL AND vm_os <> '' GROUP BY vm_os") as $row) {
        $counts[(string) $row['vm_os']] = (int) $row['c'];
    }

    return $counts;
}
