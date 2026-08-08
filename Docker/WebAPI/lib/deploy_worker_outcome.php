<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/status_events.php';
require_once __DIR__ . '/worker_heartbeat.php';

/**
 * The deploy worker's job-outcome state machine: everything that turns a
 * finished (or failed, cancelled, reaped) playbook sequence into durable job
 * and VM states. Lives outside lib/deploy_worker.php because that file is the
 * CLI entrypoint and runs its main loop on require; this module keeps the
 * status decisions requireable, so the integration tests can drive them
 * against a real database without an SSH transport.
 */

final class DeployWorkerCancelled extends RuntimeException
{
}

// Where the inventory job was when it threw (B6). Not a wire vocabulary: these
// exist so a thrown failure is classified by the phase that raised it first and
// by text evidence second, instead of every throw falling back to `parse` and
// blaming the host's answer.
const VIRTUSPHERE_DEPLOY_PHASE_CONFIG = 'config';
const VIRTUSPHERE_DEPLOY_PHASE_SSH = 'ssh';
const VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT = 'transport';
const VIRTUSPHERE_DEPLOY_PHASE_MARKER = 'marker';
const VIRTUSPHERE_DEPLOY_PHASE_DB = 'db';

/**
 * Classifies a thrown inventory-job failure into a VIRTUSPHERE_INVENTORY_ERROR_*
 * category: phase first, text second.
 *
 * Only ssh/transport consult the message, because only there can wording
 * distinguish anything (DNS vs. refused vs. auth); their fallback is `ssh`,
 * never `parse`. Config failures never reached the network; marker failures are
 * the one true "the host answered unexpectedly"; db failures are ours. An
 * unknown phase is a coding error in the worker and reads as `worker`.
 */
function deploy_worker_classify_inventory_failure(string $phase, string $message): string
{
    switch ($phase) {
        case VIRTUSPHERE_DEPLOY_PHASE_CONFIG:
            return str_contains(strtolower($message), 'certificate')
                ? VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE
                : VIRTUSPHERE_INVENTORY_ERROR_CONFIG;
        case VIRTUSPHERE_DEPLOY_PHASE_SSH:
        case VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT:
            $category = connection_error_category($message);

            return $category === VIRTUSPHERE_INVENTORY_ERROR_PARSE ? VIRTUSPHERE_INVENTORY_ERROR_SSH : $category;
        case VIRTUSPHERE_DEPLOY_PHASE_MARKER:
            return VIRTUSPHERE_INVENTORY_ERROR_PARSE;
        case VIRTUSPHERE_DEPLOY_PHASE_DB:
        default:
            return VIRTUSPHERE_INVENTORY_ERROR_WORKER;
    }
}

/**
 * Strips credential secrets (and their URL-encoded form) out of a failure
 * message before it reaches the job log. Same minimum length as
 * connection_error_detail(): replacing a 1-3 character secret would shred the
 * message. Deliberately no truncation or whitespace collapse here; the job log
 * is the operator's evidence and the full playbook output sits above it anyway.
 *
 * @param array<int, mixed> $secrets
 */
function deploy_worker_redact_secrets(string $message, array $secrets): string
{
    foreach ($secrets as $secret) {
        if (is_string($secret) && strlen($secret) >= VIRTUSPHERE_CONNECTION_REDACT_MIN) {
            $message = str_replace([$secret, rawurlencode($secret)], '***', $message);
        }
    }

    return $message;
}

/**
 * The deploy worker's own System status row: it reports that it is alive, and how
 * deep its queue is.
 *
 * It had no traffic light at all. Its only liveness signal was a tmpfs file for
 * the container healthcheck, which the PHP container cannot read, so a stopped or
 * crash-looping worker left the page fully green above a queue that had stopped
 * moving. The operator saw "everything ok" and a job sitting at `queued` forever,
 * with nothing anywhere connecting the two.
 *
 * The queue depth and the age of the oldest waiting job are the two numbers that
 * make the row actionable: a live worker with a growing queue and a dead worker
 * with a growing queue look identical without them.
 *
 * Throttled to the reported interval, because the loop wakes every few seconds
 * and the staleness thresholds are multiples of what is reported here.
 */
function deploy_worker_report_alive(mysqli $db, bool $ok = true, ?string $failureDetail = null): void
{
    static $lastReportedAt = 0;

    $now = time();
    if ($ok && $lastReportedAt !== 0 && ($now - $lastReportedAt) < VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS) {
        return;
    }
    $lastReportedAt = $now;

    try {
        $detail = $failureDetail ?? deploy_worker_queue_detail($db);
        repo_record_worker_result($db, VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS, $ok, $detail);
    } catch (Throwable $exception) {
        // The report is never allowed to break the worker: a job in flight matters
        // more than its own status row.
        fwrite(STDERR, '[deploy-worker] status report failed: ' . $exception->getMessage() . "\n");
    }
}

/**
 * "queue: N waiting, oldest 12 min" - the sentence the row needs to be worth
 * reading. Without the age, a queue of one that has been waiting since yesterday
 * looks like a queue of one that arrived a second ago.
 */
function deploy_worker_queue_detail(mysqli $db): string
{
    $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $row = repo_fetch_one(
        $db,
        'SELECT COUNT(*) AS waiting, TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) AS oldest_minutes FROM deploy_jobs WHERE status = ? AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())',
        's',
        [$queued]
    ) ?? [];
    $active = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_jobs WHERE status = ?', 's', [$running]);

    $waiting = (int) ($row['waiting'] ?? 0);
    $detail = 'queue: ' . $waiting . ' waiting, ' . $active . ' running';
    if ($waiting > 0) {
        $detail .= ', oldest ' . (int) ($row['oldest_minutes'] ?? 0) . ' min';
    }

    return $detail;
}

/**
 * The transport-driven heartbeat: fires on every read slice of the bounded SSH
 * transport (AP6), including the silent ones, and carries all three liveness
 * signals of a busy worker. Lives here rather than in the CLI entrypoint so the
 * integration tests can drive it against a real database (the entrypoint runs
 * its main loop on require).
 */
function deploy_worker_heartbeat_tick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds = VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS): void
{
    static $lastHeartbeat = [];

    // Ticks fire on every read slice of the bounded transport (AP6), also the
    // silent ones, so touching here keeps the container healthcheck green
    // through playbook runs far longer than the loop cadence (AP8).
    worker_heartbeat_touch();

    // The System status row rides along, throttled to its own cadence inside
    // deploy_worker_report_alive(). Without this, a healthy job inside one
    // remote step longer than the danger window turned the row red: the report
    // ran only at the top of the main loop, which does not spin while a
    // playbook runs.
    deploy_worker_report_alive($db);

    $key = $jobId . ':' . $workerId;
    $now = time();
    if ($intervalSeconds > 0 && isset($lastHeartbeat[$key]) && ($now - $lastHeartbeat[$key]) < $intervalSeconds) {
        return;
    }

    if (repo_touch_deploy_job_heartbeat($db, $jobId, $workerId)) {
        $lastHeartbeat[$key] = $now;
    }
}

/**
 * Stops the worker as soon as the job in front of it is no longer its own to
 * finish. There are four states and only one was checked:
 *
 *  - the operator cancelled it (the original check),
 *  - the job row is GONE. `$job !== null` let exactly this case through: the
 *    worker carried on against a deleted mission and then failed on a foreign
 *    key while writing its next log line, which the error categorizer reports as
 *    a remote problem, so the operator reads "the host answered unexpectedly"
 *    for a row somebody deleted in the portal,
 *  - it is no longer `running`: the heartbeat reaper concluded it and already
 *    published a verdict plus marked its VMs, which writing on would overwrite,
 *  - it is `running` under ANOTHER worker's lock (adopted after that reap), so
 *    two workers would drive the same playbook sequence over the same VMs.
 *
 * All four raise DeployWorkerCancelled, because the outcome is the same: this
 * worker stops without publishing a result of its own.
 *
 * Cancellation itself is honoured at step boundaries only, never mid-exec (AP6,
 * by design): this check runs between the preflight, the SFTP upload and each
 * playbook, so the sequence stops before the NEXT step. Killing a
 * create/powercycle mid-run would leave the ESXi side in a state no later step
 * could reason about (a half-cloned VM, a power operation of unknown outcome).
 * The bounded SSH transport caps an individual step; cancel is cooperative at
 * the seams, and the convergence sweep (L4) cleans up VMs left `deploying` if
 * the worker dies before its own catch runs.
 */
function deploy_worker_assert_job_is_ours(mysqli $db, int $jobId, string $workerId): void
{
    $job = repo_deploy_job($db, $jobId);
    if ($job === null) {
        throw new DeployWorkerCancelled('Deploy job ' . $jobId . ' no longer exists.');
    }

    $status = (string) $job['status'];
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED) {
        throw new DeployWorkerCancelled('Deploy job was cancelled.');
    }
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
        // The stop IS the confirmation (ADR-0033): this check runs exactly at
        // the step boundaries where a cancel is honoured, so the worker that
        // owns the lock concludes the state machine here via the ownership
        // CAS. Under a foreign lock this worker only stops; the owner (or the
        // reaper, if the owner died) delivers the confirmation.
        if ((string) ($job['locked_by'] ?? '') === $workerId && repo_confirm_deploy_job_cancelled($db, $jobId, $workerId)) {
            throw new DeployWorkerCancelled('Cancel requested by operator; confirmed at this step boundary.');
        }

        throw new DeployWorkerCancelled('Deploy job is cancelling; another party owns its conclusion.');
    }
    if ($status !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING) {
        throw new DeployWorkerCancelled('Deploy job is no longer running (status ' . $status . '); another party concluded it.');
    }
    if ((string) ($job['locked_by'] ?? '') !== $workerId) {
        throw new DeployWorkerCancelled('Deploy job is locked by ' . ((string) ($job['locked_by'] ?? '') ?: 'nobody') . ', not by this worker.');
    }
}

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
        // failed/failed. The note names what actually happened: a converged
        // cancellation is the operator's wish, not a stale-heartbeat failure.
        $result = deploy_worker_job_mac_result($db, $jobId);
        $note = ($job['reaped_to'] ?? '') === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED
            ? 'deploy job ' . $jobId . ' cancelled; the worker died before confirming'
            : 'deploy job ' . $jobId . ' reaped after stale heartbeat';
        deploy_worker_mark_vms_failed(
            $db,
            (int) $job['mission_id'],
            $note,
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
        repo_record_vm_status_event($db, $vmId, $lifecycle, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_SYNC_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
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
        repo_record_vm_status_event($db, $vmId, $prior, (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_SYNC_NOT_READY), (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED), $note);
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
    $failedMecm = VIRTUSPHERE_MECM_SYNC_FAILED;
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
