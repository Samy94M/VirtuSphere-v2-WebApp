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

/**
 * Explicitly binds one portal VM to the namesake currently reported by a
 * selected ESXi credential. Mission lock and active-job gate prevent identity
 * replacement while a worker may still be mutating or exporting that VM.
 *
 * @return array{vm_id:int, vm_name:string, vm_moid:string, vm_instance_uuid:string}
 */
function repo_adopt_vm_identity(mysqli $db, int $missionId, int $vmId, int $credentialId): array
{
    return repo_transaction($db, static function () use ($db, $missionId, $vmId, $credentialId): array {
        if (repo_deploy_lock_mission($db, $missionId) === null) {
            throw new RuntimeException('Mission not found.');
        }
        repo_deploy_assert_mission_idle($db, $missionId);

        return repo_vm_identity_adopt_locked($db, $missionId, $vmId, $credentialId);
    });
}

function repo_fetch_related(mysqli $connection, string $sql, int $vmId): array
{
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_source_to_array(object|array $source): array
{
    return is_array($source) ? $source : get_object_vars($source);
}

function deleteVM($vmList, $connection)
{
    return vmListToDelete($vmList, $connection);
}

function vmListToCreate($missionId, $vmList, $mysqli)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return 0;
    }

    // Legacy wire contract: any failure rolls the whole batch back and answers
    // with 0, never with an exception (the desktop client checks the count).
    try {
        return repo_transaction($mysqli, static function () use ($mysqli, $missionId, $vmList): int {
            $successCount = 0;
            foreach ($vmList as $vm) {
                $vmMissionId = repo_id(repo_object_get($vm, 'mission_id', $missionId));
                if ($vmMissionId === 0) {
                    throw new RuntimeException('VM create skipped: missing mission_id.');
                }

                $values = repo_validate_vm_payload($mysqli, $vmMissionId, repo_source_to_array($vm));
                if (repo_deploy_lock_mission($mysqli, $vmMissionId) === null) {
                    throw new RuntimeException('VM create skipped: mission not found.');
                }
                $values['mission_id'] = $vmMissionId;
                $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
                $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
                $values['mecm_sync_state'] = VIRTUSPHERE_MECM_SYNC_NOT_READY;
                $values['updated'] = 0;

                $vmId = repo_insert_from_values($mysqli, 'deploy_vms', $values);
                repo_replace_interfaces($mysqli, $vmId, repo_object_get($vm, 'interfaces', []), false);
                repo_replace_packages($mysqli, $vmId, repo_object_get($vm, 'packages', []));
                repo_replace_disks($mysqli, $vmId, repo_object_get($vm, 'Disks', repo_object_get($vm, 'disks', [])));
                repo_record_vm_status_event($mysqli, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'created');
                $successCount++;
            }

            return $successCount;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToCreate rollback: ' . $exception->getMessage());
        return 0;
    }
}

function vmListToUpdate($vmList, $connection)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return 0;
    }

    // Legacy wire contract: batch-or-nothing, failures answer 0 (see create).
    try {
        return repo_transaction($connection, static function () use ($connection, $vmList): int {
            $successCount = 0;
            foreach ($vmList as $vm) {
                $vmId = repo_id(repo_object_get($vm, 'Id', repo_object_get($vm, 'id')));
                if ($vmId === 0) {
                    throw new RuntimeException('VM update skipped: missing Id.');
                }

                $values = repo_allowed_columns($vm, REPO_VM_COLUMNS);
                $currentVm = repo_fetch_one($connection, 'SELECT * FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$vmId]);
                if ($currentVm === null) {
                    throw new RuntimeException('VM update skipped: VM not found.');
                }
                $missionId = (int) $currentVm['mission_id'];
                if (repo_deploy_lock_mission($connection, $missionId) === null) {
                    throw new RuntimeException('VM update skipped: mission not found.');
                }
                repo_vm_network_assert_scope_idle($connection, $missionId, [$vmId]);
                $currentVm = repo_fetch_one($connection, 'SELECT * FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]);
                if ($currentVm === null) {
                    throw new RuntimeException('VM update skipped: VM changed mission or was removed.');
                }
                if ($values !== []) {
                    $values = repo_validate_vm_payload($connection, (int) $currentVm['mission_id'], array_merge($currentVm, $values), $vmId);
                    repo_update_from_values($connection, 'deploy_vms', $values, 'id = ?', 'i', [$vmId]);
                }
                if (repo_object_has($vm, 'interfaces')) {
                    repo_replace_interfaces($connection, $vmId, repo_object_get($vm, 'interfaces', []), true);
                }
                if (repo_object_has($vm, 'packages')) {
                    repo_replace_packages($connection, $vmId, repo_object_get($vm, 'packages', []));
                }
                if (repo_object_has($vm, 'Disks') || repo_object_has($vm, 'disks')) {
                    repo_replace_disks($connection, $vmId, repo_object_get($vm, 'Disks', repo_object_get($vm, 'disks', [])));
                }
                // Legacy partial bundles deliberately have no expected version.
                // They still invalidate any portal snapshot, including child-only edits.
                repo_advance_vm_edit_version($connection, $vmId);
                $successCount++;
            }

            return $successCount;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToUpdate rollback: ' . $exception->getMessage());
        return 0;
    }
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
