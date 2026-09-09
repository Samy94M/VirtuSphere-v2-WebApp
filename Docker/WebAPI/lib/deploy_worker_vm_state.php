<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_worker_ownership.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/status_events.php';

/**
 * VM lifecycle transitions a job owns: marking its scope as deploying,
 * restoring it when nothing was touched, failing what is left, and reading the
 * durable MAC-import verdict that decides which VMs a failure may still claim.
 *
 * Kept apart from the job's own outcome because these writes touch deploy_vms,
 * not deploy_jobs, and because a MAC import that already committed makes a VM
 * off-limits to every convergence path here.
 */
/**
 * Marks the job's scope VMs as `deploying` at claim time. Lifecycle only: the
 * MECM sync state and the frozen legacy vm_status are not changed by starting
 * a job.
 *
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @return array<int,string> vmId => lifecycle the VM had before the job, for
 *         deploy_worker_restore_deploying_vms() on a resultless success.
 */
function deploy_worker_mark_vms_deploying(mysqli $db, array $job, string $note, array $vmIds = []): array
{
    return deploy_worker_owned_transaction($db, $job, static function (array $job) use ($db, $note, $vmIds): array {
        if ((string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING) {
            return [];
        }
        $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
        $priorLifecycles = [];
        $keep = deploy_worker_locked_imported_vm_ids($job);
        foreach (deploy_worker_scope_vms($db, (int) $job['mission_id'], $vmIds, true) as $vm) {
            $vmId = (int) $vm['id'];
            if (in_array($vmId, $keep, true)) {
                continue;
            }
            $priorLifecycles[$vmId] = (string) ($vm['lifecycle_state'] ?? '');
            $prior = $priorLifecycles[$vmId];
            $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ?');
            $stmt->bind_param('sis', $lifecycle, $vmId, $prior);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                continue;
            }
            repo_record_vm_status_event($db, $vmId, $lifecycle, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_SYNC_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
        }

        return $priorLifecycles;
    }) ?? [];
}

/**
 * Returns still-`deploying` scope VMs to the lifecycle they had before the
 * job. Only meaningful for sequences without an export step (a create does
 * not install an OS, a start does not provision), where success leaves no
 * per-VM result behind that could say anything better. VMs without a known
 * prior state are left alone rather than guessed at - for those the
 * convergence sweep stays the authority. MECM state and the frozen legacy
 * vm_status are not touched.
 *
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @param array<int,string> $priorLifecycles vmId => lifecycle before the job
 * @return int Number of VMs actually restored.
 */
function deploy_worker_restore_deploying_vms(mysqli $db, array $job, string $note, array $vmIds, array $priorLifecycles): int
{
    return deploy_worker_owned_transaction($db, $job, static function (array $job) use ($db, $note, $vmIds, $priorLifecycles): int {
        $keep = deploy_worker_locked_imported_vm_ids($job);
        $restored = 0;
        foreach (deploy_worker_scope_vms($db, (int) $job['mission_id'], $vmIds, true) as $vm) {
            $vmId = (int) $vm['id'];
            if (in_array($vmId, $keep, true)) {
                continue;
            }
            if ((string) ($vm['lifecycle_state'] ?? '') !== VIRTUSPHERE_LIFECYCLE_DEPLOYING) {
                continue;
            }
            $prior = $priorLifecycles[$vmId] ?? '';
            if ($prior === '' || $prior === VIRTUSPHERE_LIFECYCLE_DEPLOYING || !in_array($prior, VIRTUSPHERE_LIFECYCLE_STATES, true)) {
                continue;
            }
            $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ?');
            $deploying = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
            $stmt->bind_param('sis', $prior, $vmId, $deploying);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                continue;
            }
            repo_record_vm_status_event($db, $vmId, $prior, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_SYNC_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
            $restored++;
        }

        return $restored;
    }) ?? 0;
}

/**
 * Failure convergence for a job's VMs: lifecycle_state AND mecm_sync_state
 * become `failed` together. A failed lifecycle with a stale `pending` sync
 * state would advertise a MECM pickup that can never happen. The frozen legacy
 * vm_status is never rewritten by a failure (GROK.md legacy status contract);
 * errors live in lifecycle/MECM state only.
 *
 * $keepVmIds are the VMs whose MAC import committed (successful_vm_ids of the
 * job's result_json): their deployed/pending state is the truth and stays.
 * $onlyDeploying additionally restricts the marking to VMs still in
 * `deploying` - the cancel path, which must not repaint states the import
 * endpoint already finished.
 *
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @param int[] $keepVmIds
 * @return int Number of VMs actually marked.
 */
function deploy_worker_mark_vms_failed(mysqli $db, array $job, string $note, array $vmIds = [], array $keepVmIds = [], bool $onlyDeploying = false): int
{
    return deploy_worker_owned_transaction($db, $job, static fn (array $locked): int =>
        deploy_worker_fail_locked_job_vms($db, $locked, $note, $vmIds, $onlyDeploying, $keepVmIds)) ?? 0;
}

/**
 * Reaper/worker shared convergence. The caller holds Mission -> Job -> Runtime
 * in repo_transaction(), and MUST call before terminalizing the locked job.
 * Historical tokenless jobs may be reaped; returning workers never get this
 * capability from an in-memory claim. Current MAC successes are read here.
 *
 * @param int[] $vmIds
 * @param int[] $keepVmIds
 */
function deploy_worker_fail_locked_job_vms(mysqli $db, array $job, string $note, array $vmIds = [], bool $onlyDeploying = false, array $keepVmIds = []): int
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('VM convergence requires the locked job transaction.');
    }
    if (!in_array((string) $job['status'], [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)) {
        return 0;
    }
    $keep = array_flip(array_merge(array_map('intval', $keepVmIds), deploy_worker_locked_imported_vm_ids($job)));
    $failedLifecycle = VIRTUSPHERE_LIFECYCLE_FAILED;
    $failedMecm = VIRTUSPHERE_MECM_SYNC_FAILED;
    $marked = 0;

    foreach (deploy_worker_scope_vms($db, (int) ($job['mission_id'] ?? 0), $vmIds, true) as $vm) {
        $vmId = (int) $vm['id'];
        if (isset($keep[$vmId])) {
            continue;
        }
        $lifecycle = (string) ($vm['lifecycle_state'] ?? '');
        if ($onlyDeploying && $lifecycle !== VIRTUSPHERE_LIFECYCLE_DEPLOYING) {
            continue;
        }
        if ($lifecycle === $failedLifecycle && (string) ($vm['mecm_sync_state'] ?? '') === $failedMecm) {
            // Already converged; keep the status-event stream sparse.
            continue;
        }
        $mecm = (string) $vm['mecm_sync_state'];
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ? AND mecm_sync_state = ?');
        $stmt->bind_param('ssiss', $failedLifecycle, $failedMecm, $vmId, $lifecycle, $mecm);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            continue;
        }
        repo_record_vm_status_event($db, $vmId, $failedLifecycle, $failedMecm, (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
        $marked++;
    }

    return $marked;
}

/** @return int[] The locked job row is newer than any caller's cached result. */
function deploy_worker_locked_imported_vm_ids(array $job): array
{
    $result = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);

    return $result !== null ? $result['successful_vm_ids'] : [];
}

/** The VM convergence and cancel confirmation are one ownership decision. */
function deploy_worker_confirm_owned_cancel(mysqli $db, array $claim, ?string $message = null): bool
{
    return deploy_worker_owned_transaction($db, $claim, static function (array $job) use ($db, $message): bool {
        if ((string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
            return false;
        }
        $jobId = (int) $job['id'];
        $payload = json_decode((string) $job['payload_json'], true);
        $vmIds = is_array($payload) && is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
        deploy_worker_fail_locked_job_vms($db, $job, 'deploy job ' . $jobId . ' cancelled while deploying', $vmIds, true);

        return repo_confirm_deploy_job_cancelled($db, $jobId, (string) $job['locked_by'], $message);
    }) ?? false;
}

/**
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @return array<int, array<string, mixed>>
 */
function deploy_worker_scope_vms(mysqli $db, int $missionId, array $vmIds, bool $lock = false): array
{
    if ($missionId <= 0) {
        return [];
    }

    $vmIds = array_values(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0));
    if ($vmIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($vmIds), '?'));
        $stmt = $db->prepare('SELECT id, lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? AND id IN (' . $placeholders . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i' . str_repeat('i', count($vmIds)), $missionId, ...$vmIds);
    } else {
        $stmt = $db->prepare('SELECT id, lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i', $missionId);
    }
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * The job's durable MAC import verdict. The DB result written by
 * db_importMAC.php is the SSoT for success/partial of an export sequence;
 * stdout never is. NULL means "no usable result".
 *
 * @return array{version:int,outcome:string,successful_vm_ids:list<int>,failed_vm_ids:list<int>,counts:array<string,int>,errors:list<mixed>,retry:array<mixed>,vm_results:list<mixed>,callback_fingerprint:string}|null
 */
function deploy_worker_job_mac_result(mysqli $db, int $jobId): ?array
{
    // A surrounding REPEATABLE READ snapshot may predate a callback that won
    // the mission lock. Finish must consume the current row it owns, not that
    // earlier snapshot, exactly like the lifecycle writer below its fence.
    $stmt = $db->prepare('SELECT result_json FROM deploy_jobs WHERE id = ? LIMIT 1'
        . (repo_transaction_depth($db) > 0 ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $json = is_array($row) && $row['result_json'] !== null ? (string) $row['result_json'] : null;

    return mac_import_decode_result($json);
}
