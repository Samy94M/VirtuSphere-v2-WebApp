<?php

declare(strict_types=1);

/**
 * The writers of the two VM progress clocks (ADR-0038): the operator's explicit
 * start or restart, and the automatic start of the OS-install clock on
 * power-on evidence (amendment 2). One module because they write the same two
 * columns under the same rule: a clock is operational metadata, so updated_at,
 * lifecycle and MECM state stay untouched and every write leaves a status note.
 *
 * Loaded through the lib/repo/vms.php facade like every other VM-repo module.
 */

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
            $note = VIRTUSPHERE_VM_WATCH_NOTE_OPERATOR;
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

/**
 * Starts the OS-install clock of one VM without a click, when the VM is
 * registered and proof exists that it is switched on (ADR-0038 amendment 2).
 * The decision is virtusphere_vm_install_watch_auto_source() and nothing else;
 * the three triggers below and in mecm_updateid.php only say what is live.
 *
 * It sets an EMPTY clock only and never restarts one: the UPDATE repeats the
 * state and the empty clock in its WHERE, so a second trigger writes nothing
 * and adds no history row. updated_at, lifecycle and MECM state stay as they
 * are. The status note names the source and is its only stored record.
 *
 * $trigger: `fence` (the rollout fence the registration callback read before
 * its state write; without it the fence is derived from the row as it is now),
 * `live_job_id` (the job that just ended), `live_inventory_credential_id` (the
 * access whose `vm` inventory was just applied) and `live_inventory_read_since`
 * (the claim time of that pull: the host was read no earlier).
 *
 * @param array{fence?:array{fenced:bool,since:string}, live_job_id?:int, live_inventory_credential_id?:int, live_inventory_read_since?:string} $trigger
 * @return string|null the source that started the clock, null when nothing was written
 */
function repo_start_vm_install_watch_on_evidence(mysqli $db, int $vmId, array $trigger = []): ?string
{
    if ($vmId <= 0) {
        return null;
    }

    return repo_transaction($db, static function () use ($db, $vmId, $trigger): ?string {
        // OF v: only the VM row is locked. The mission row is read for the
        // template rule and must not enter this short transaction's lock set.
        $vm = repo_fetch_one(
            $db,
            'SELECT v.id, v.lifecycle_state, v.mecm_sync_state, v.vm_status, v.os_install_watch_started_at,
                    v.mecm_rollout_revision, v.mecm_pending_since, v.vm_instance_uuid, v.vm_bound_credential_id, m.mission_name
               FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
              WHERE v.id = ? FOR UPDATE OF v',
            'i',
            [$vmId]
        );
        // Asked before the evidence is read: most VMs a trigger passes are not waiting.
        if ($vm === null || virtusphere_vm_progress_watch_kind($vm) !== VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING || $vm['os_install_watch_started_at'] !== null) {
            return null;
        }
        $isTemplate = mission_name_is_template((string) $vm['mission_name']);

        $job = repo_vm_evidence_jobs($db, [$vmId])[$vmId] ?? null;
        if ($job !== null) {
            $job['live'] = (int) $job['id'] === (int) ($trigger['live_job_id'] ?? 0);
        }
        $credentialId = (int) ($vm['vm_bound_credential_id'] ?? 0);
        $inventory = $credentialId > 0 ? (repo_vm_evidence_inventory($db, [$credentialId], time())[$credentialId] ?? null) : null;
        if ($inventory !== null) {
            $inventory['live'] = $credentialId === (int) ($trigger['live_inventory_credential_id'] ?? 0);
            $inventory['read_since'] = $inventory['live'] ? (string) ($trigger['live_inventory_read_since'] ?? '') : '';
        }
        $source = virtusphere_vm_install_watch_auto_source($vm, [
            'is_template' => $isTemplate,
            'start_job' => $job,
            'inventory' => $inventory,
            'fence' => $trigger['fence'] ?? virtusphere_vm_rollout_fence($vm),
        ]);
        if ($source === null) {
            return null;
        }

        $installing = VIRTUSPHERE_LIFECYCLE_OS_INSTALLING;
        $registered = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        $stmt = $db->prepare('UPDATE deploy_vms SET os_install_watch_started_at = NOW(), updated_at = updated_at WHERE id = ? AND os_install_watch_started_at IS NULL AND lifecycle_state = ? AND mecm_sync_state = ?');
        $stmt->bind_param('iss', $vmId, $installing, $registered);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return null;
        }
        repo_record_vm_status_event(
            $db,
            $vmId,
            (string) $vm['lifecycle_state'],
            (string) $vm['mecm_sync_state'],
            (string) $vm['vm_status'],
            (string) array_search($source, VIRTUSPHERE_VM_WATCH_NOTES, true)
        );

        return $source;
    });
}

/**
 * Trigger 1: the registration callback (mecm_updateid.php), inside its
 * transaction and with the fence it read before its state write.
 *
 * The clock is display metadata and must not cost a registration: a failure is
 * logged and the binding commits without a clock; the next inventory asks
 * again. The one exception is a deadlock or a lock wait timeout. MySQL has then
 * discarded the transaction or left the statement in an unknown state, and
 * committing would publish an empty middle, so it propagates and the callback
 * answers 500 as for any other failed write.
 *
 * @param array{fenced:bool,since:string} $fence
 */
function repo_start_vm_install_watch_at_registration(mysqli $db, int $vmId, array $fence): void
{
    try {
        repo_start_vm_install_watch_on_evidence($db, $vmId, ['fence' => $fence]);
    } catch (Throwable $exception) {
        if ($exception instanceof mysqli_sql_exception && in_array($exception->getCode(), [1213, 1205], true)) {
            throw $exception;
        }
        error_log('[mecm-updateid] vm ' . $vmId . ': automatic observation start failed: ' . virtusphere_redact_log_text($exception->getMessage()));
    }
}

/**
 * Trigger 2: a job that ran its start step has ended. Every VM of its journal
 * that is registered and still without a clock is asked once, each in its own
 * short transaction after the job's terminal state is published.
 *
 * @return int clocks started
 */
function repo_start_install_watches_after_job(mysqli $db, int $jobId): int
{
    $started = 0;
    foreach (repo_vm_install_watch_candidates($db, $jobId, 0) as $vmId) {
        $started += repo_start_vm_install_watch_on_evidence($db, $vmId, ['live_job_id' => $jobId]) === null ? 0 : 1;
    }

    return $started;
}

/**
 * Trigger 3: the `vm` inventory of one ESXi access was applied and answered.
 * Every registered VM bound to that access and still without a clock is asked.
 * $readSince is the claim time of the pull (database clock): a mission job that
 * ended after it may have switched the VM off after the host was read.
 *
 * @return int clocks started
 */
function repo_start_install_watches_after_inventory(mysqli $db, int $credentialId, string $readSince = ''): int
{
    $started = 0;
    $trigger = ['live_inventory_credential_id' => $credentialId, 'live_inventory_read_since' => $readSince];
    foreach (repo_vm_install_watch_candidates($db, 0, $credentialId) as $vmId) {
        $started += repo_start_vm_install_watch_on_evidence($db, $vmId, $trigger) === null ? 0 : 1;
    }

    return $started;
}

/**
 * The VMs a trigger has to ask: registered, installing, clock empty, and either
 * in the journal of $jobId or bound to the access $credentialId. A cheap
 * unlocked pre-selection; the writer decides again under the row lock.
 *
 * @return list<int>
 */
function repo_vm_install_watch_candidates(mysqli $db, int $jobId, int $credentialId): array
{
    if ($jobId <= 0 && $credentialId <= 0) {
        return [];
    }
    $installing = VIRTUSPHERE_LIFECYCLE_OS_INSTALLING;
    $registered = VIRTUSPHERE_MECM_SYNC_REGISTERED;
    $waiting = ' AND v.lifecycle_state = ? AND v.mecm_sync_state = ? AND v.os_install_watch_started_at IS NULL ORDER BY v.id';
    $stmt = $jobId > 0
        ? $db->prepare('SELECT v.id FROM deploy_vms v INNER JOIN deploy_job_vm_states s ON s.vm_id = v.id WHERE s.job_id = ?' . $waiting)
        : $db->prepare('SELECT v.id FROM deploy_vms v WHERE v.vm_bound_credential_id = ?' . $waiting);
    $scopeId = $jobId > 0 ? $jobId : $credentialId;
    $stmt->bind_param('iss', $scopeId, $installing, $registered);
    $stmt->execute();

    return array_map(static fn (array $row): int => (int) $row['id'], repo_fetch_all($stmt->get_result()));
}
