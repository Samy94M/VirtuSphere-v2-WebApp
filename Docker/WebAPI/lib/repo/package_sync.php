<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Materializes the assignment owners that this request may relink, then locks
 * those VM parents in numeric order before any package row is changed. The VM
 * editor takes VM before package assignment/FK locks too, so the sync must not
 * introduce the inverse Package -> VM order. A package assigned concurrently
 * after this snapshot is deliberately left on the selectable retired row as
 * the concurrent writer's choice; an operator can move it explicitly later.
 *
 * @param list<array<string,mixed>> $retiredRows
 * @return array<int,list<int>> VM ids keyed by retired package id
 */
function packages_lock_relink_vm_scope(mysqli $db, array $retiredRows): array
{
    $packageIds = array_values(array_unique(array_filter(array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $retiredRows
    ), static fn (int $id): bool => $id > 0)));
    sort($packageIds, SORT_NUMERIC);
    if ($packageIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
    $stmt = $db->prepare(
        'SELECT vm_id, package_id FROM deploy_vm_packages WHERE package_id IN (' . $placeholders . ') ORDER BY vm_id, package_id'
    );
    $stmt->bind_param(str_repeat('i', count($packageIds)), ...$packageIds);
    $stmt->execute();
    $scope = [];
    $vmIds = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $vmId = (int) $row['vm_id'];
        $packageId = (int) $row['package_id'];
        $scope[$packageId][] = $vmId;
        $vmIds[$vmId] = true;
    }

    $vmIds = array_map('intval', array_keys($vmIds));
    sort($vmIds, SORT_NUMERIC);
    if ($vmIds !== []) {
        $placeholders = implode(',', array_fill(0, count($vmIds), '?'));
        $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
        $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
        $stmt->execute();
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    return $scope;
}
