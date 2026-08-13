<?php

declare(strict_types=1);

/**
 * Lower-cased set of inventory names of one kind across all credentials.
 *
 * @return array<string, true>
 */
function esxi_inventory_name_set(mysqli $db, string $kind): array
{
    $set = [];
    foreach (repo_esxi_inventory_names_by_kind($db, $kind) as $name) {
        $set[esxi_inventory_name_key($name)] = true;
    }

    return $set;
}
/** True when a non-empty value is absent from a non-empty inventory name set. */
function esxi_inventory_value_unknown(string $value, array $nameSet): bool
{
    return $value !== '' && $nameSet !== [] && !isset($nameSet[esxi_inventory_name_key($value)]);
}

/**
 * Values missing from per-kind name sets. A kind with an empty set is skipped
 * (empty-guard: an empty inventory of that kind proves nothing). Comparison
 * runs through esxi_inventory_name_key; the output keeps the original
 * spelling, de-duplicated case-insensitively and sorted.
 *
 * @param array<string, array<int, string>> $valuesByKind kind => stored values
 * @param array<string, array<string, true>> $nameSetsByKind kind => lower-cased name set
 * @return array<int, string>
 */
function esxi_inventory_missing_values(array $valuesByKind, array $nameSetsByKind): array
{
    $missing = [];
    foreach ($valuesByKind as $kind => $values) {
        $nameSet = $nameSetsByKind[$kind] ?? [];
        if ($nameSet === []) {
            continue;
        }
        foreach ($values as $value) {
            $value = trim((string) $value);
            if (esxi_inventory_value_unknown($value, $nameSet)) {
                $key = esxi_inventory_name_key($value);
                if (!isset($missing[$key])) {
                    $missing[$key] = $value; // first spelling wins, like every other dedupe here
                }
            }
        }
    }
    $missing = array_values($missing);
    sort($missing, SORT_NATURAL | SORT_FLAG_CASE);

    return $missing;
}

/**
 * Per ESXi credential: which of a mission's stored values (WDS VLAN, interface
 * VLANs, mission datacenter/datastore plus the per-VM overrides) are missing
 * from THAT credential's cached inventory. Values already missing from the
 * union of all credentials are excluded here: they belong to the mission-level
 * deviation warning, so the two deploy warnings stay disjoint and never name
 * the same value twice. Warn-only input for the deploy page island; never a
 * block.
 *
 * @return array<int, array<int, string>> credential_id => missing values
 */
function esxi_inventory_mission_missing_by_credential(mysqli $db, int $missionId): array
{
    $mission = repo_fetch_one($db, 'SELECT hypervisor_datacenter, hypervisor_datastorage, wds_vlan FROM deploy_missions WHERE id = ? LIMIT 1', 'i', [$missionId]);
    if ($mission === null) {
        return [];
    }

    $valuesByKind = [
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER => [trim((string) ($mission['hypervisor_datacenter'] ?? ''))],
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE => [trim((string) ($mission['hypervisor_datastorage'] ?? ''))],
        VIRTUSPHERE_INVENTORY_KIND_NETWORK => [trim((string) ($mission['wds_vlan'] ?? ''))],
    ];
    $stmt = $db->prepare('SELECT vm_datacenter, vm_datastore FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_DATACENTER][] = trim((string) ($row['vm_datacenter'] ?? ''));
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_DATASTORE][] = trim((string) ($row['vm_datastore'] ?? ''));
    }
    $stmt = $db->prepare('SELECT i.vlan FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id WHERE v.mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $valuesByKind[VIRTUSPHERE_INVENTORY_KIND_NETWORK][] = trim((string) ($row['vlan'] ?? ''));
    }

    // One query for every credential and every kind this function compares, and
    // the union is folded out of the same rows. The previous shape read a whole
    // per-credential inventory inside the credential loop plus one union query
    // per kind, so the cost of two warning boxes grew with the host count.
    $kinds = array_keys($valuesByKind);
    $setsByCredential = repo_esxi_inventory_name_sets_by_credential($db, $kinds);

    // Values unknown to the whole union are subtracted from every per-credential
    // list below (disjoint-warnings rule).
    $unionSets = array_fill_keys($kinds, []);
    foreach ($setsByCredential as $credentialSets) {
        foreach ($credentialSets as $kind => $set) {
            $unionSets[$kind] += $set;
        }
    }
    $unionMissing = [];
    foreach (esxi_inventory_missing_values($valuesByKind, $unionSets) as $value) {
        $unionMissing[esxi_inventory_name_key($value)] = true;
    }

    $out = [];
    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) as $credential) {
        $credentialId = (int) $credential['id'];
        $credentialSets = ($setsByCredential[$credentialId] ?? []) + array_fill_keys($kinds, []);
        $missing = array_values(array_filter(
            esxi_inventory_missing_values($valuesByKind, $credentialSets),
            static fn (string $value): bool => !isset($unionMissing[esxi_inventory_name_key($value)])
        ));
        if ($missing !== []) {
            $out[$credentialId] = $missing;
        }
    }

    return $out;
}

/**
 * Missions and VMs whose datacenter / datastore / VLAN reference a value that is
 * not in the current inventory (E4.2). A kind is only evaluated when the
 * inventory has at least one entry of it (otherwise we cannot prove absence).
 * Returns an empty list when the inventory is empty.
 *
 * Mission entries carry no vm_id. VM entries additionally carry vm_id/vm_name
 * and cover the per-VM location overrides (which the deploy honours) plus the
 * interface VLANs (which the mass reassignment below can repair).
 *
 * $includeTemplates adds '_'-prefixed template missions, flagged is_template:
 * a stale VLAN living only in a template would otherwise stay invisible and
 * propagate into every mission created from it. The mission-list badge and the
 * deploy hint keep excluding templates (they cannot deploy).
 *
 * @return array<int, array{mission_id:int, mission_name:string, is_template:bool, vm_id?:int, vm_name?:string, issues:array<int,array{field:string,value:string}>}>
 */
function esxi_inventory_mission_deviations(mysqli $db, bool $includeTemplates = false): array
{
    $datacenters = esxi_inventory_name_set($db, VIRTUSPHERE_INVENTORY_KIND_DATACENTER);
    $datastores = esxi_inventory_name_set($db, VIRTUSPHERE_INVENTORY_KIND_DATASTORE);
    $networks = esxi_inventory_name_set($db, VIRTUSPHERE_INVENTORY_KIND_NETWORK);
    if ($datacenters === [] && $datastores === [] && $networks === []) {
        return [];
    }

    $result = [];
    // Two static query variants, no splicing (the '_' literal mirrors
    // VIRTUSPHERE_TEMPLATE_PREFIX).
    $rows = $includeTemplates
        ? $db->query('SELECT id, mission_name, hypervisor_datacenter, hypervisor_datastorage, wds_vlan FROM deploy_missions ORDER BY mission_name')
        : $db->query("SELECT id, mission_name, hypervisor_datacenter, hypervisor_datastorage, wds_vlan FROM deploy_missions WHERE LEFT(mission_name, 1) <> '_' ORDER BY mission_name");
    foreach (repo_fetch_all($rows) as $mission) {
        $issues = [];
        $dc = trim((string) ($mission['hypervisor_datacenter'] ?? ''));
        if (esxi_inventory_value_unknown($dc, $datacenters)) {
            $issues[] = ['field' => 'datacenter', 'value' => $dc];
        }
        $ds = trim((string) ($mission['hypervisor_datastorage'] ?? ''));
        if (esxi_inventory_value_unknown($ds, $datastores)) {
            $issues[] = ['field' => 'datastore', 'value' => $ds];
        }
        $vlan = trim((string) ($mission['wds_vlan'] ?? ''));
        if (esxi_inventory_value_unknown($vlan, $networks)) {
            $issues[] = ['field' => 'vlan', 'value' => $vlan];
        }
        if ($issues !== []) {
            $missionName = (string) $mission['mission_name'];
            $result[] = [
                'mission_id' => (int) $mission['id'],
                'mission_name' => $missionName,
                'is_template' => mission_name_is_template($missionName),
                'issues' => $issues,
            ];
        }
    }

    foreach (esxi_inventory_vm_deviations($db, $datacenters, $datastores, $networks, $includeTemplates) as $entry) {
        $result[] = $entry;
    }

    return $result;
}

/** Appends one de-duplicated issue to the VM entry, creating the entry on demand. */
function esxi_inventory_add_vm_issue(array &$entries, array $row, string $field, string $value): void
{
    $vmId = (int) $row['vm_id'];
    if (!isset($entries[$vmId])) {
        $entries[$vmId] = [
            'mission_id' => (int) $row['mission_id'],
            'mission_name' => (string) $row['mission_name'],
            'is_template' => mission_name_is_template((string) $row['mission_name']),
            'vm_id' => $vmId,
            'vm_name' => (string) $row['vm_name'],
            'issues' => [],
        ];
    }
    foreach ($entries[$vmId]['issues'] as $issue) {
        if ($issue['field'] === $field && $issue['value'] === $value) {
            return;
        }
    }
    $entries[$vmId]['issues'][] = ['field' => $field, 'value' => $value];
}

/**
 * Per-VM deviations, one entry per VM: the location overrides the deploy resolves
 * ahead of the mission value, and the interface VLANs. Two NICs on the same stale
 * VLAN report it once. Templates follow the $includeTemplates flag like on the
 * mission level (two static query variants each, no splicing).
 *
 * @return array<int, array{mission_id:int, mission_name:string, is_template:bool, vm_id:int, vm_name:string, issues:array<int,array{field:string,value:string}>}>
 */
function esxi_inventory_vm_deviations(mysqli $db, array $datacenters, array $datastores, array $networks, bool $includeTemplates = false): array
{
    $entries = [];

    if ($datacenters !== [] || $datastores !== []) {
        $rows = $includeTemplates
            ? $db->query(
                "SELECT v.id AS vm_id, v.vm_name, v.mission_id, m.mission_name, v.vm_datacenter, v.vm_datastore
                 FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
                 WHERE COALESCE(v.vm_datacenter, '') <> '' OR COALESCE(v.vm_datastore, '') <> ''"
            )
            : $db->query(
                "SELECT v.id AS vm_id, v.vm_name, v.mission_id, m.mission_name, v.vm_datacenter, v.vm_datastore
                 FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
                 WHERE LEFT(m.mission_name, 1) <> '_'
                   AND (COALESCE(v.vm_datacenter, '') <> '' OR COALESCE(v.vm_datastore, '') <> '')"
            );
        foreach (repo_fetch_all($rows) as $row) {
            $dc = trim((string) ($row['vm_datacenter'] ?? ''));
            if (esxi_inventory_value_unknown($dc, $datacenters)) {
                esxi_inventory_add_vm_issue($entries, $row, 'vm_datacenter', $dc);
            }
            $ds = trim((string) ($row['vm_datastore'] ?? ''));
            if (esxi_inventory_value_unknown($ds, $datastores)) {
                esxi_inventory_add_vm_issue($entries, $row, 'vm_datastore', $ds);
            }
        }
    }

    if ($networks !== []) {
        $rows = $includeTemplates
            ? $db->query(
                "SELECT v.id AS vm_id, v.vm_name, v.mission_id, m.mission_name, i.vlan
                 FROM deploy_interfaces i
                 INNER JOIN deploy_vms v ON v.id = i.vm_id
                 INNER JOIN deploy_missions m ON m.id = v.mission_id
                 WHERE COALESCE(i.vlan, '') <> ''"
            )
            : $db->query(
                "SELECT v.id AS vm_id, v.vm_name, v.mission_id, m.mission_name, i.vlan
                 FROM deploy_interfaces i
                 INNER JOIN deploy_vms v ON v.id = i.vm_id
                 INNER JOIN deploy_missions m ON m.id = v.mission_id
                 WHERE LEFT(m.mission_name, 1) <> '_' AND COALESCE(i.vlan, '') <> ''"
            );
        foreach (repo_fetch_all($rows) as $row) {
            $vlan = trim((string) $row['vlan']);
            if (esxi_inventory_value_unknown($vlan, $networks)) {
                esxi_inventory_add_vm_issue($entries, $row, 'vlan', $vlan);
            }
        }
    }

    $entries = array_values($entries);
    usort($entries, static function (array $a, array $b): int {
        return [$a['mission_name'], $a['vm_name']] <=> [$b['mission_name'], $b['vm_name']];
    });

    return $entries;
}

/** Mission ids that currently have an inventory deviation (for a list badge). */
function esxi_inventory_deviating_mission_ids(mysqli $db): array
{
    $ids = [];
    foreach (esxi_inventory_mission_deviations($db) as $entry) {
        $ids[$entry['mission_id']] = true;
    }

    return $ids;
}

/**
 * Guided mass reassignment of a VLAN name (E4b.8): rewrites every mission
 * WDS-VLAN and interface VLAN from $from to $to in one transaction. Returns the
 * counts. Name-string assignments only; nothing on ESXi is touched.
 *
 * @return array{missions:int, interfaces:int}
 */
function repo_reassign_vlan(mysqli $db, string $from, string $to): array
{
    $from = trim($from);
    $to = trim($to);
    if ($from === '' || $to === '' || $from === $to) {
        throw new InvalidArgumentException('Source and target VLAN are required and must differ.');
    }

    return repo_transaction($db, static function () use ($db, $from, $to): array {
        $stmt = $db->prepare('UPDATE deploy_missions SET wds_vlan = ? WHERE wds_vlan = ?');
        $stmt->bind_param('ss', $to, $from);
        $stmt->execute();
        $missions = $stmt->affected_rows;

        $stmt = $db->prepare('UPDATE deploy_interfaces SET vlan = ? WHERE vlan = ?');
        $stmt->bind_param('ss', $to, $from);
        $stmt->execute();
        $interfaces = $stmt->affected_rows;

        return ['missions' => max(0, $missions), 'interfaces' => max(0, $interfaces)];
    });
}
