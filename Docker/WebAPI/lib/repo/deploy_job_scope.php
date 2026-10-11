<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../ansible_command_modes.php';
require_once __DIR__ . '/helpers.php';

/**
 * Which VMs a deploy job covers: the mission-scoped selection, the ordered
 * stagger list, the mode-dependent K16 eligibility and the schedule preview
 * built from it. Enqueue and preview share these, so both name the same VMs.
 *
 * Read-only by construction. The one deliberate throw is in
 * repo_deploy_filter_mission_vm_ids(): a selection that filters down to nothing
 * must not read as "whole mission".
 */

/**
 * Computes the per-VM start times for the schedule preview without creating any
 * jobs. Returns Unix epochs so the page formats them in the portal timezone.
 *
 * @return array{rows:list<array{vm_name:string,epoch:int}>,skipped_vms:list<array{id:int,vm_name:string}>,eligible_vm_ids:list<int>}
 */
function deploy_preview_rows(mysqli $db, int $missionId, array $payloadData, array $schedule): array
{
    $scope = repo_deploy_mode_vm_selection(
        $db,
        $missionId,
        is_array($payloadData['vm_ids'] ?? null) ? $payloadData['vm_ids'] : [],
        (string) ($payloadData['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL)
    );
    $vms = $scope['eligible_vms'];
    $baseEpoch = (int) $schedule['base_epoch'];
    $stagger = $schedule['stagger'];

    $rows = [];
    foreach ($vms as $i => $vm) {
        $epoch = $baseEpoch + ($stagger !== null ? $i * $stagger * 60 : 0);
        $rows[] = ['vm_name' => (string) $vm['vm_name'], 'epoch' => $epoch];
    }

    return [
        'rows' => $rows,
        'skipped_vms' => $scope['skipped_vms'],
        'eligible_vm_ids' => array_map(static fn (array $vm): int => (int) $vm['id'], $vms),
    ];
}

/**
 * @param int[] $vmIds
 * @return int[] VM ids that belong to the mission (order preserved)
 *
 * An empty input stays empty and is read as "whole mission" one level up. But a
 * non-empty input that filters down to nothing is a selection whose VMs have all
 * been deleted (or never belonged here) since the form was rendered: silently
 * returning [] would widen that job to the entire mission. Throw instead, with
 * the exact wording of the worker-side gate (ansible_prepare_job_artifacts), so
 * the same condition reads the same one stage earlier.
 */
function repo_deploy_filter_mission_vm_ids(mysqli $db, int $missionId, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }

    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $owned = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $owned[(int) $row['id']] = true;
    }

    $filtered = array_values(array_filter($vmIds, static fn (int $id): bool => isset($owned[$id])));
    if ($filtered === []) {
        throw new RuntimeException('None of the selected VMs belong to this mission.');
    }

    return $filtered;
}

/**
 * Ordered VM list for a stagger group / its preview. An explicit selection
 * (filtered to the mission) keeps its order; an empty selection means the whole
 * mission ordered by vm_name.
 *
 * @param int[] $selectedVmIds
 * @return array<int, array{id:int, vm_name:string}>
 */
function repo_deploy_group_vm_list(mysqli $db, int $missionId, array $selectedVmIds): array
{
    $selected = repo_deploy_filter_mission_vm_ids($db, $missionId, $selectedVmIds);
    if ($selected === []) {
        $stmt = $db->prepare('SELECT id, vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'vm_name' => (string) $r['vm_name']],
            repo_fetch_all($stmt->get_result())
        );
    }

    $vms = [];
    foreach ($selected as $vmId) {
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        $vms[] = ['id' => $vmId, 'vm_name' => $name];
    }

    return $vms;
}

/**
 * Resolve an operator selection to a frozen queue-time VM scope. Empty user
 * selection means the entire current mission; only after that expansion do
 * UUID-addressed modes omit VMs that this portal has never bound.
 *
 * @param int[] $selectedVmIds
 * @return array{eligible_vms:list<array{id:int,vm_name:string}>,skipped_vms:list<array{id:int,vm_name:string}>}
 */
function repo_deploy_mode_vm_selection(mysqli $db, int $missionId, array $selectedVmIds, string $mode): array
{
    $selected = repo_deploy_filter_mission_vm_ids($db, $missionId, $selectedVmIds);
    $sql = 'SELECT id, vm_name, vm_instance_uuid FROM deploy_vms WHERE mission_id = ?';
    $types = 'i';
    $values = [$missionId];
    if ($selected !== []) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($selected), '?')) . ')';
        $types .= str_repeat('i', count($selected));
        $values = array_merge($values, $selected);
    } else {
        $sql .= ' ORDER BY vm_name';
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $byId = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $byId[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'vm_name' => (string) $row['vm_name'],
            'vm_instance_uuid' => trim((string) ($row['vm_instance_uuid'] ?? '')),
        ];
    }
    $ordered = [];
    if ($selected === []) {
        $ordered = array_values($byId);
    } else {
        foreach ($selected as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
    }

    $eligible = [];
    $skipped = [];
    $requiresBinding = ansible_mode_requires_bound_instance_uuid($mode);
    foreach ($ordered as $vm) {
        $entry = ['id' => $vm['id'], 'vm_name' => $vm['vm_name']];
        if ($requiresBinding && $vm['vm_instance_uuid'] === '') {
            $skipped[] = $entry;
        } else {
            $eligible[] = $entry;
        }
    }

    return ['eligible_vms' => $eligible, 'skipped_vms' => $skipped];
}
