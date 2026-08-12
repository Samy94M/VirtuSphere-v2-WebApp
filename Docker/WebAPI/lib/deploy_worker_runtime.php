<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/integration_health.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/worker_heartbeat.php';

/**
 * Worker runtime: the vocabulary and the per-tick bookkeeping every job path
 * shares - the cancellation signal, the phase names a failure is classified by,
 * secret redaction, the service's own status row, the job heartbeat and the
 * ownership assertion.
 *
 * These are the pieces both the mission and the inventory processor call on
 * every step, which is exactly why they must not live inside either of them.
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
