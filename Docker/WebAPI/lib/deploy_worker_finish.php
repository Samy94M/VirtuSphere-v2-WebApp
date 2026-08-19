<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_vm_state.php';

/**
 * How a job ends: the success conclusion including its per-VM verdict, the
 * cancellation and failure paths, the terminal write itself, its audit line and
 * the inventory refresh a finished deploy triggers.
 *
 * One module because a job must reach exactly one of these, and because the
 * order inside them (VM convergence before the terminal status, audit after)
 * is the contract that keeps a failed job from advertising a MECM pickup.
 */
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
 * Also the landing place for the two other ways a job stops being this worker's
 * (deploy_worker_assert_job_is_ours): the row is gone, or somebody else
 * concluded it. Both are why the job log is written only while the row still
 * exists: deploy_logs references it, so a log line about a deleted job fails on
 * a foreign key and turns a clean stop into an unexplained crash. The VM
 * convergence still runs in that case, from the mission id of the job the worker
 * holds in memory, because those VMs are the ones actually left in `deploying`.
 *
 * @param int[] $vmIds
 */
function deploy_worker_handle_cancelled(mysqli $db, array $job, array $vmIds, string $reason = 'Deploy job was cancelled.'): void
{
    $jobId = (int) $job['id'];
    deploy_worker_log_if_job_exists($db, $jobId, 'Worker stopped processing. Reason: ' . $reason);

    $swept = deploy_worker_mark_vms_failed($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' cancelled while deploying', $vmIds, [], true);
    if ($swept > 0) {
        deploy_worker_log_if_job_exists($db, $jobId, 'Marked ' . $swept . ' still-deploying VM(s) of this job as failed; already imported MACs are kept.');
    }
}

/**
 * A job log line on a stop path, where "the row is already gone" is one of the
 * reasons we got here. deploy_logs references deploy_jobs, so writing about a
 * deleted job fails on a foreign key and turns a clean stop into a crash the
 * operator reads as an ESXi problem. Returns false when the line went to
 * error_log instead, which is the only place left that can still hold it.
 */
function deploy_worker_log_if_job_exists(mysqli $db, int $jobId, string $line): bool
{
    if (repo_deploy_job($db, $jobId) === null) {
        error_log('[deploy-worker] job ' . $jobId . ' is gone; ' . $line);

        return false;
    }

    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);

    return true;
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
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR, $message);
    $macResult = deploy_worker_job_mac_result($db, $jobId);
    $keepVmIds = $macResult !== null ? $macResult['successful_vm_ids'] : [];
    deploy_worker_mark_vms_failed($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' failed', $vmIds, $keepVmIds);
    if ($keepVmIds !== []) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, count($keepVmIds) . ' VM(s) with a committed MAC import keep their deployed state.');
    }
    deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
    deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
}

/**
 * Writes the job's terminal status through a compare-and-swap, and resolves the
 * one race that a previously read status cannot (Etappe 8).
 *
 * The swap only fires from `running` under this worker's lock, so the outcome
 * and a cancel request cannot both win. When it hits zero rows, exactly one
 * thing is established: this job was not `running` under our lock at that
 * instant. WHICH of the reasons it was decides what has to happen next, so the
 * row is read instead of guessed - the old message named a lost lock even when
 * the lock was still ours and only the status had moved on.
 *
 * The reason that must not be left alone is a cancel that committed while the
 * last step ran: nobody else is going to conclude that job, because the worker
 * holding it is this one, so it would sit in `cancelling` until the reaper
 * eventually noticed a heartbeat that stopped. It is confirmed here instead,
 * and the log says what the operator otherwise could not know: the work of the
 * step that was already running did happen.
 */
function deploy_worker_finish_job(mysqli $db, int $jobId, string $workerId, string $status, ?string $lastError = null): void
{
    if (repo_finish_deploy_job($db, $jobId, $workerId, $status, $lastError)) {
        return;
    }

    $job = repo_deploy_job($db, $jobId);
    if ($job === null) {
        // Through the guarded helper, never a direct append: deploy_job_logs
        // references deploy_jobs, so writing about a row that is gone fails on
        // a foreign key and turns a clean stop into an unexplained crash.
        deploy_worker_log_if_job_exists($db, $jobId, 'Terminal status ' . $status . ' was not written: the job row no longer exists.');

        return;
    }

    $observed = (string) $job['status'];
    $lockedBy = (string) ($job['locked_by'] ?? '');
    if ($observed === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING
        && $lockedBy === $workerId
        && repo_confirm_deploy_job_cancelled($db, $jobId, $workerId)
    ) {
        repo_append_deploy_job_log(
            $db,
            $jobId,
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Terminal status ' . $status . ' was not written: a cancel request had already been accepted for this job. '
            . 'The remote step that was running at that moment ran to its end, so its changes on ESXi are in place; no further step was started.'
        );

        return;
    }

    repo_append_deploy_job_log(
        $db,
        $jobId,
        VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
        'Terminal status ' . $status . ' was not written: the job is no longer running under this worker (status ' . $observed
        . ', locked by ' . ($lockedBy !== '' ? $lockedBy : 'nobody') . ').'
    );
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
