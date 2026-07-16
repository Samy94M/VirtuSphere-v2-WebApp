<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/status_events.php';

/**
 * The deploy worker's job-outcome state machine: everything that turns a
 * finished (or failed, cancelled, reaped) playbook sequence into durable job
 * and VM states. Lives outside lib/deploy_worker.php because that file is the
 * CLI entrypoint and runs its main loop on require; this module keeps the
 * status decisions requireable, so the integration tests can drive them
 * against a real database without an SSH transport.
 */

function deploy_worker_payload(array $job): array
{
    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        return ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'verbose' => false];
    }

    return deploy_job_payload($payload);
}

/**
 * @return int Number of stale running jobs reaped. Called from the deploy
 *         worker's loop start and, since AP6, from the maintenance worker's
 *         interval as well - both go through this function so the MAC-aware
 *         VM convergence below can never be bypassed by one of the callers.
 */
function deploy_worker_reap_stale_jobs(mysqli $db): int
{
    $reaped = 0;
    foreach (repo_reap_stale_deploy_jobs($db) as $job) {
        $payload = deploy_worker_payload($job);
        $vmIds = $payload['vm_ids'] ?? [];
        $jobId = (int) $job['id'];
        // A reaped job may already carry a committed MAC import: those VMs are
        // really deployed and keep their state; only the rest converges to
        // failed/failed.
        $result = deploy_worker_job_mac_result($db, $jobId);
        deploy_worker_mark_vms_failed(
            $db,
            (int) $job['mission_id'],
            'deploy job ' . $jobId . ' reaped after stale heartbeat',
            $vmIds,
            $result !== null ? $result['successful_vm_ids'] : []
        );
        $reaped++;
    }

    return $reaped;
}

/**
 * Concludes a mission job whose playbook sequence exited 0.
 *
 * A sequence with an export step must prove its MAC import through the
 * durable DB result: the upload's partial exit code (rc=20) does not survive
 * the && chain, so stdout can never carry the verdict. Modes without an
 * export step have no result to demand and finish as before. A missing or
 * wholly failed import throws, which routes the job through
 * deploy_worker_handle_failure() like any other sequence error.
 *
 * @param int[] $vmIds
 * @param array<int,string> $priorLifecycles vmId => lifecycle before the job,
 *        as returned by deploy_worker_mark_vms_deploying().
 */
function deploy_worker_conclude_sequence(mysqli $db, array $job, string $workerId, array $vmIds, array $priorLifecycles = []): void
{
    $jobId = (int) $job['id'];
    $payload = deploy_worker_payload($job);

    $macResult = null;
    if (ansible_mode_expects_mac_result((string) $payload['mode'])) {
        $macResult = deploy_worker_job_mac_result($db, $jobId);
        if ($macResult === null) {
            throw new RuntimeException('Playbook sequence finished, but no usable MAC import result was recorded for this job.');
        }
        if ($macResult['outcome'] === 'failed') {
            throw new RuntimeException('MAC import failed for every VM of this job.');
        }
    } else {
        // A sequence without an export step produces no per-VM verdict, so the
        // `deploying` painted at claim time must be taken back by the worker
        // itself. Left alone, the convergence sweep would later mark the VMs
        // of this GREEN job failed/failed - a false failure after a success.
        deploy_worker_restore_deploying_vms($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' succeeded without export step; lifecycle restored', $vmIds, $priorLifecycles);
    }

    if ($macResult !== null && $macResult['outcome'] === 'partial') {
        $failedCount = count($macResult['failed_vm_ids']);
        $summary = 'MAC import partial: ' . $failedCount . ' of ' . ($failedCount + count($macResult['successful_vm_ids'])) . ' VMs failed.';
        deploy_worker_mark_vms_failed($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' mac import partial', $vmIds, $macResult['successful_vm_ids']);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job finished partially. ' . $summary);
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $summary);
        deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $summary);
    } else {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job succeeded.');
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
    }
    // A create/full deploy changed ESXi resource usage (new VMs, datastore
    // allocation): enqueue an inventory refresh for this credential (E3.4b).
    // Fail-soft and after the job is finalized, so it can never taint the
    // deploy result; the double-enqueue guard prevents pile-up. A partial
    // job created VMs too, so it refreshes as well.
    deploy_worker_refresh_inventory_after_deploy($db, $job);
}

/**
 * The worker-side cancel convergence. A cancel must not leave VMs behind in
 * `deploying`: whatever this job put there converges to failed/failed. VMs
 * the import endpoint already finished stay deployed/pending, and stored
 * MACs are never touched. The job status itself stays `cancelled`.
 *
 * @param int[] $vmIds
 */
function deploy_worker_handle_cancelled(mysqli $db, array $job, array $vmIds): void
{
    $jobId = (int) $job['id'];
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Worker stopped processing because the job was cancelled.');
    $swept = deploy_worker_mark_vms_failed($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' cancelled while deploying', $vmIds, [], true);
    if ($swept > 0) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Marked ' . $swept . ' still-deploying VM(s) of this job as failed; already imported MACs are kept.');
    }
}

/**
 * Fails a mission job with selective VM marking (E1): VMs whose MAC import
 * already committed are really deployed (their MACs are in, MECM may pick
 * them up) and keep deployed/pending even though the job fails. Everything
 * else in the job scope becomes failed/failed - lifecycle and mecm_sync_state
 * together, so no failed VM advertises a sync that can never happen.
 *
 * @param int[] $vmIds
 */
function deploy_worker_handle_failure(mysqli $db, array $job, string $workerId, array $vmIds, string $message): void
{
    $jobId = (int) $job['id'];
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_STDERR, $message);
    $macResult = deploy_worker_job_mac_result($db, $jobId);
    $keepVmIds = $macResult !== null ? $macResult['successful_vm_ids'] : [];
    deploy_worker_mark_vms_failed($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' failed', $vmIds, $keepVmIds);
    if ($keepVmIds !== []) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, count($keepVmIds) . ' VM(s) with a committed MAC import keep their deployed state.');
    }
    deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
    deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
}

function deploy_worker_finish_job(mysqli $db, int $jobId, string $workerId, string $status, ?string $lastError = null): void
{
    if (!repo_finish_deploy_job($db, $jobId, $workerId, $status, $lastError)) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Terminal status ' . $status . ' skipped because the job is no longer locked by this worker.');
    }
}

/**
 * Records a finished MISSION deploy in the audit trail, so the logs page shows a
 * job ending, not just being queued. Attributed to the operator who queued it
 * (the job row carries user_id) even though the worker runs headless.
 *
 * Deliberately only for mission deploys, not the mission-less inventory system
 * jobs: those run on the interval and a "succeeded" line per credential every few
 * hours would bury real events. An inventory failure is already recorded in the
 * fetch-state row, and its one durable consequence, the auth pause, is audited
 * where it is set/cleared. Called after finish_job and never lets an audit hiccup
 * escape into the job's finally block.
 */
function deploy_worker_audit_outcome(mysqli $db, array $job, string $status, ?string $error = null): void
{
    if (($job['mission_id'] ?? null) === null) {
        return;
    }
    try {
        $userId = isset($job['user_id']) ? (int) $job['user_id'] : null;
        $mode = deploy_worker_payload($job)['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL;
        $message = 'deploy job id ' . (int) $job['id'] . ' (mission id ' . (int) $job['mission_id']
            . ', mode ' . audit_snippet($mode, 24) . ') ' . $status;
        if (in_array($status, [VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL], true) && $error !== null && $error !== '') {
            $message .= ': ' . audit_snippet($error, 120);
        }
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, $message, $userId, 'cli');
    } catch (Throwable $exception) {
        error_log('[deploy-worker] outcome audit failed: ' . $exception->getMessage());
    }
}

/**
 * After a successful resource-changing deploy (create/full), enqueue an ESXi
 * inventory refresh for the job's credential so datastore usage etc. catch up
 * without waiting for the interval (ADR-0023, E3.4b). Fail-soft: a scheduling
 * hiccup must never taint the already-finished deploy job. The double-enqueue
 * guard in repo_create_system_job prevents pile-up with the interval automation.
 */
function deploy_worker_refresh_inventory_after_deploy(mysqli $db, array $job): void
{
    $mode = deploy_worker_payload($job)['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL;
    $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
    if ($credentialId <= 0 || !in_array($mode, VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES, true)) {
        return;
    }
    try {
        esxi_inventory_enqueue_for_credential($db, $credentialId);
    } catch (Throwable $exception) {
        error_log('[deploy-worker] post-deploy inventory refresh enqueue failed: ' . $exception->getMessage());
    }
}

/**
 * Marks the job's scope VMs as `deploying` at claim time. Lifecycle only: the
 * MECM sync state and the frozen legacy vm_status are not changed by starting
 * a job.
 *
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @return array<int,string> vmId => lifecycle the VM had before the job, for
 *         deploy_worker_restore_deploying_vms() on a resultless success.
 */
function deploy_worker_mark_vms_deploying(mysqli $db, int $missionId, string $note, array $vmIds = []): array
{
    $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
    $priorLifecycles = [];
    foreach (deploy_worker_scope_vms($db, $missionId, $vmIds) as $vm) {
        $vmId = (int) $vm['id'];
        $priorLifecycles[$vmId] = (string) ($vm['lifecycle_state'] ?? '');
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $lifecycle, $vmId);
        $stmt->execute();
        repo_record_vm_status_event($db, $vmId, $lifecycle, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
    }

    return $priorLifecycles;
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
function deploy_worker_restore_deploying_vms(mysqli $db, int $missionId, string $note, array $vmIds, array $priorLifecycles): int
{
    $restored = 0;
    foreach (deploy_worker_scope_vms($db, $missionId, $vmIds) as $vm) {
        $vmId = (int) $vm['id'];
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
        repo_record_vm_status_event($db, $vmId, $prior, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
        $restored++;
    }

    return $restored;
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
function deploy_worker_mark_vms_failed(mysqli $db, int $missionId, string $note, array $vmIds = [], array $keepVmIds = [], bool $onlyDeploying = false): int
{
    $keep = array_flip(array_map('intval', $keepVmIds));
    $failedLifecycle = VIRTUSPHERE_LIFECYCLE_FAILED;
    $failedMecm = VIRTUSPHERE_MECM_FAILED;
    $marked = 0;

    foreach (deploy_worker_scope_vms($db, $missionId, $vmIds) as $vm) {
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
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $failedLifecycle, $failedMecm, $vmId);
        $stmt->execute();
        repo_record_vm_status_event($db, $vmId, $failedLifecycle, $failedMecm, (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
        $marked++;
    }

    return $marked;
}

/**
 * @param int[] $vmIds Empty means "all VMs of the mission".
 * @return array<int, array<string, mixed>>
 */
function deploy_worker_scope_vms(mysqli $db, int $missionId, array $vmIds): array
{
    if ($missionId <= 0) {
        return [];
    }

    $vmIds = array_values(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0));
    if ($vmIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($vmIds), '?'));
        $stmt = $db->prepare('SELECT id, lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? AND id IN (' . $placeholders . ') ORDER BY id');
        $stmt->bind_param('i' . str_repeat('i', count($vmIds)), $missionId, ...$vmIds);
    } else {
        $stmt = $db->prepare('SELECT id, lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? ORDER BY id');
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
 * @return array{outcome:string, successful_vm_ids:list<int>, failed_vm_ids:list<int>, counts:array<string,int>}|null
 */
function deploy_worker_job_mac_result(mysqli $db, int $jobId): ?array
{
    $stmt = $db->prepare('SELECT result_json FROM deploy_jobs WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $json = is_array($row) && $row['result_json'] !== null ? (string) $row['result_json'] : null;

    return mac_import_decode_result($json);
}
