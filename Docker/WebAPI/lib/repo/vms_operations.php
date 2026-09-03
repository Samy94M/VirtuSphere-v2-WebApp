<?php

declare(strict_types=1);

/** Operator, identity, bulk and recovery operations for portal VMs. */


/**
 * Bulk delete of VM DB records (never the hypervisor). VMs are skipped while
 * their mission has an active deploy job. Returns counts + skip reasons.
 *
 * @param int[] $vmIds
 * @return array{deleted:int, skipped:array<int,array{vm_name:string,reason:string}>}
 */
function repo_bulk_delete_vms(mysqli $db, int $missionId, array $vmIds): array
{
    // One transaction for the whole batch, so the mission lock is taken once and
    // held: no job can be queued between two VMs of the same batch, and the
    // active-job verdict below is therefore true for every VM in it, not only
    // for the first. The per-VM guard inside repo_delete_vm_by_id then finds the
    // lock already held and answers from the same reading.
    return repo_transaction($db, static function () use ($db, $missionId, $vmIds): array {
        $result = ['deleted' => 0, 'skipped' => []];
        if (repo_deploy_lock_mission($db, $missionId) === null) {
            return $result;
        }
        $missionHasActiveJob = repo_deploy_active_job_exists($db, $missionId);

        foreach ($vmIds as $vmId) {
            $vmId = (int) $vmId;
            $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
            if ($name === '') {
                continue; // not in this mission; silently ignore
            }
            if ($missionHasActiveJob) {
                $result['skipped'][] = ['vm_name' => $name, 'reason' => 'active_job'];
                continue;
            }
            if (repo_delete_vm_by_id($db, $missionId, $vmId)) {
                $result['deleted']++;
            }
        }

        return $result;
    });
}


/**
 * Deletes one VM row of a mission, never the VM on the hypervisor.
 *
 * The active-job guard lives HERE and not only in the bulk caller, because the
 * single-row path of vms.php is the same click one row over and had no guard at
 * all: it removed a VM the running worker was about to receive MACs for, and
 * the MAC upload then reported an unknown VM while the deploy looked healthy.
 * Both callers are now behind one predicate.
 */
function repo_delete_vm_by_id(mysqli $db, int $missionId, int $vmId): bool
{
    if ($missionId <= 0 || $vmId <= 0) {
        return false;
    }

    return repo_transaction($db, static function () use ($db, $missionId, $vmId): bool {
        if (repo_deploy_lock_mission($db, $missionId) === null) {
            return false;
        }
        repo_vm_network_assert_scope_idle($db, $missionId, [$vmId]);

        return repo_execute($db, 'DELETE FROM deploy_vms WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
    });
}


/**
 * Explicitly starts or restarts the observation clock that is valid for the
 * VM's current progress state. The clock is operational metadata: updated_at
 * and all lifecycle/MECM fields deliberately remain untouched.
 */
function repo_restart_vm_progress_watch(mysqli $db, int $missionId, int $vmId, ?int $userId = null): string
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM are required.');
    }

    return repo_transaction($db, static function () use ($db, $missionId, $vmId, $userId): string {
        $current = repo_fetch_one(
            $db,
            'SELECT lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE',
            'ii',
            [$vmId, $missionId]
        );
        if ($current === null) {
            throw new RuntimeException('VM not found.');
        }

        $kind = virtusphere_vm_progress_watch_kind($current);
        if ($kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING) {
            repo_execute($db, 'UPDATE deploy_vms SET mecm_pending_since = NOW(), updated_at = updated_at WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
            $note = 'MECM pending observation restarted';
        } elseif ($kind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING) {
            repo_execute($db, 'UPDATE deploy_vms SET os_install_watch_started_at = NOW(), updated_at = updated_at WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
            $note = 'OS installation observation restarted';
        } else {
            throw new RuntimeException('The VM is not in an observable progress state.');
        }

        repo_record_vm_status_event(
            $db,
            $vmId,
            (string) $current['lifecycle_state'],
            (string) $current['mecm_sync_state'],
            (string) $current['vm_status'],
            $note,
            $userId
        );

        return $kind;
    });
}

/** Count overdue display-only observations, optionally scoped to one mission. */
function repo_vm_progress_attention_count(mysqli $db, ?int $missionId = null): int
{
    $sql = "SELECT COUNT(*) FROM deploy_vms WHERE ((lifecycle_state = ? AND mecm_sync_state = ? AND mecm_pending_since IS NOT NULL AND mecm_pending_since < DATE_SUB(NOW(), INTERVAL ? SECOND)) OR (lifecycle_state = ? AND mecm_sync_state = ? AND os_install_watch_started_at IS NOT NULL AND os_install_watch_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)))";
    $params = [
        VIRTUSPHERE_LIFECYCLE_DEPLOYED,
        VIRTUSPHERE_MECM_SYNC_PENDING,
        VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS,
        VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
        VIRTUSPHERE_MECM_SYNC_REGISTERED,
        VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS,
    ];
    $types = 'ssissi';
    if ($missionId !== null) {
        if ($missionId <= 0) {
            return 0;
        }
        $sql .= ' AND mission_id = ?';
        $types .= 'i';
        $params[] = $missionId;
    }

    return (int) repo_scalar($db, $sql, $types, $params);
}

/** @return array<int,int> Overdue observation count keyed by mission id. */
function repo_vm_progress_attention_counts_by_mission(mysqli $db): array
{
    $stmt = $db->prepare("SELECT mission_id, COUNT(*) AS attention_count FROM deploy_vms WHERE ((lifecycle_state = ? AND mecm_sync_state = ? AND mecm_pending_since IS NOT NULL AND mecm_pending_since < DATE_SUB(NOW(), INTERVAL ? SECOND)) OR (lifecycle_state = ? AND mecm_sync_state = ? AND os_install_watch_started_at IS NOT NULL AND os_install_watch_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND))) GROUP BY mission_id");
    $lifecycleDeployed = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
    $mecmPending = VIRTUSPHERE_MECM_SYNC_PENDING;
    $pendingSeconds = VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS;
    $lifecycleInstalling = VIRTUSPHERE_LIFECYCLE_OS_INSTALLING;
    $mecmRegistered = VIRTUSPHERE_MECM_SYNC_REGISTERED;
    $installSeconds = VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS;
    $stmt->bind_param('ssissi', $lifecycleDeployed, $mecmPending, $pendingSeconds, $lifecycleInstalling, $mecmRegistered, $installSeconds);
    $stmt->execute();

    $counts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $counts[(int) $row['mission_id']] = (int) $row['attention_count'];
    }

    return $counts;
}

/**
 * Re-queues ONE already-registered VM for the device-sync, so a package or OS
 * change made in the portal after the registration reaches MECM.
 *
 * Why this is an explicit action and not a side effect of saving: the portal is
 * the intent before the rollout, MECM is the truth after it (ADR-0020 amendment /
 * WP-06). Ticking a package on a registered VM used to change the portal row and
 * nothing else, and nothing said so; the operator believed the VM would get the
 * package. Silently setting `updated = 1` on save would be the opposite mistake:
 * it re-runs the whole assignment pass for a machine that may be mid-installation,
 * from an edit that could have been a typo. So the save says the change has not
 * reached MECM, and this action carries it over when the operator decides.
 *
 * Only the queue flag changes. The lifecycle stays exactly where it is: the VM is
 * installed or installing, and this does not undo that. device-sync only ever
 * ADDS collection memberships (Add-CMDeviceCollectionDirectMembershipRule, never
 * Remove-), which is why running it again on a registered device is safe and why
 * assignments made directly in the MECM console survive it.
 */
function repo_mark_vm_for_mecm_resync(mysqli $db, int $missionId, int $vmId, ?int $userId = null): void
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM are required.');
    }

    repo_transaction($db, static function () use ($db, $missionId, $vmId, $userId): void {
        $current = repo_fetch_one($db, 'SELECT lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]);
        if ($current === null) {
            throw new RuntimeException('VM not found.');
        }
        if ((string) $current['mecm_sync_state'] !== VIRTUSPHERE_MECM_SYNC_REGISTERED) {
            // Before the registration the portal selection already travels with
            // the first sync, so the action would promise work it does not do.
            throw new RuntimeException('VM is not registered with MECM yet; its assignments travel with the next sync anyway.');
        }

        repo_execute($db, 'UPDATE deploy_vms SET updated = 1, updated_at = NOW() WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
        repo_record_vm_status_event(
            $db,
            $vmId,
            (string) $current['lifecycle_state'],
            (string) $current['mecm_sync_state'],
            (string) $current['vm_status'],
            'queued for MECM assignment transfer from portal',
            $userId
        );
    });
}
