<?php

declare(strict_types=1);

/** Compatibility surface for the legacy aggregate VM API. Wire behavior is unchanged. */

// Bound each prepared IN-list independently of the number of VMs in a mission.
// This remains request-local: callers receive a fresh aggregate on every read.
const REPO_VM_RELATION_BATCH_SIZE = 500;

function getVMs($connection, $missionId)
{
    $missionId = repo_id($missionId);
    $stmt = $connection->prepare('SELECT * FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());

    $relations = [];
    $vmIds = [];
    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $vmIds[] = $vmId;
        $relations[$vmId] = ['packages' => [], 'interfaces' => [], 'disks' => []];
    }

    foreach (array_chunk($vmIds, REPO_VM_RELATION_BATCH_SIZE) as $batchIds) {
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $types = str_repeat('i', count($batchIds));

        $packageStmt = $connection->prepare(
            'SELECT dvp.vm_id AS relation_vm_id, dp.*'
            . ' FROM deploy_packages dp INNER JOIN deploy_vm_packages dvp ON dp.id = dvp.package_id'
            . ' WHERE dvp.vm_id IN (' . $placeholders . ')'
            . ' ORDER BY dvp.vm_id, dp.package_name'
        );
        $packageStmt->bind_param($types, ...$batchIds);
        $packageStmt->execute();
        foreach (repo_fetch_all($packageStmt->get_result()) as $package) {
            $vmId = (int) $package['relation_vm_id'];
            unset($package['relation_vm_id']);
            $relations[$vmId]['packages'][] = $package;
        }

        $interfaceStmt = $connection->prepare(
            'SELECT * FROM deploy_interfaces WHERE vm_id IN (' . $placeholders . ') ORDER BY vm_id, id'
        );
        $interfaceStmt->bind_param($types, ...$batchIds);
        $interfaceStmt->execute();
        foreach (repo_fetch_all($interfaceStmt->get_result()) as $interface) {
            $relations[(int) $interface['vm_id']]['interfaces'][] = $interface;
        }

        $diskStmt = $connection->prepare(
            'SELECT * FROM deploy_disks WHERE vm_id IN (' . $placeholders . ') ORDER BY vm_id, id'
        );
        $diskStmt->bind_param($types, ...$batchIds);
        $diskStmt->execute();
        foreach (repo_fetch_all($diskStmt->get_result()) as $disk) {
            $relations[(int) $disk['vm_id']]['disks'][] = $disk;
        }
    }

    foreach ($vms as &$vm) {
        $vmId = (int) $vm['id'];
        $vm['packages'] = $relations[$vmId]['packages'];
        $vm['interfaces'] = $relations[$vmId]['interfaces'];
        $vm['disks'] = $relations[$vmId]['disks'];
        $vm['progress_watch_kind'] = virtusphere_vm_progress_watch_kind($vm);
        $vm['progress_attention'] = virtusphere_vm_progress_attention($vm);
    }
    unset($vm);

    return $vms;
}

function repo_fetch_related(mysqli $connection, string $sql, int $vmId): array
{
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function deleteVM($vmList, $connection)
{
    return vmListToDelete($vmList, $connection);
}

function vmListToDelete($vmList, $connection)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return false;
    }

    // Legacy wire contract: batch-or-nothing, failures answer false (see create).
    try {
        return repo_transaction($connection, static function () use ($connection, $vmList): bool {
            foreach ($vmList as $vm) {
                $id = repo_id(repo_object_get($vm, 'Id', repo_object_get($vm, 'id')));
                if ($id > 0) {
                    $missionId = (int) (repo_scalar($connection, 'SELECT mission_id FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$id]) ?? 0);
                    if ($missionId <= 0 || repo_deploy_lock_mission($connection, $missionId) === null) {
                        throw new RuntimeException('VM delete skipped: VM or mission not found.');
                    }
                    repo_vm_network_assert_scope_idle($connection, $missionId, [$id]);
                    repo_execute($connection, 'DELETE FROM deploy_vms WHERE id = ? AND mission_id = ?', 'ii', [$id, $missionId]);
                }
            }

            return true;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToDelete rollback: ' . $exception->getMessage());
        return false;
    }
}
