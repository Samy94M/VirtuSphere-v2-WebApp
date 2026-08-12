<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/status_events.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_job_worker.php';

/**
 * Unattended maintenance: log and system-job retention, the stale-job reaper and
 * the orphaned-VM convergence sweep.
 *
 * These run from the maintenance worker without an operator, so each one is
 * bounded, uses SKIP LOCKED where it competes with live work, and states in the
 * row it writes what was observed rather than a cause it did not establish.
 */

/**
 * Drops the streamed output of jobs that finished more than $retentionDays ago.
 *
 * The window is measured on the JOB, not on the log row: a job that streams for
 * an hour must not lose its opening lines while it is still running, and a live
 * tail must never race a purge. Only terminal jobs qualify, so `queued` and
 * `running` are untouchable by construction.
 *
 * The job row survives with its status and last_error, so the deploy list keeps
 * its history; deploy_log.php tells the reader that the output was pruned.
 */
function repo_purge_deploy_job_logs(mysqli $db, int $retentionDays = VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS): int
{
    $terminal = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $stmt = $db->prepare(
        'DELETE l FROM deploy_job_logs l
         JOIN deploy_jobs j ON j.id = l.job_id
         WHERE j.status IN (' . $placeholders . ')
           AND j.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $params = array_merge($terminal, [$retentionDays]);
    $stmt->bind_param(str_repeat('s', count($terminal)) . 'i', ...$params);
    $stmt->execute();

    return $stmt->affected_rows;
}

/**
 * Removes finished mission-less system jobs (the ESXi inventory pulls). No page
 * lists them once they are terminal, and their one durable result lives in
 * deploy_esxi_inventory_state, so the rows are pure growth: one every interval
 * per credential. Their log rows cascade with the FK.
 *
 * Mission jobs are deliberately kept: the deploy page shows their history.
 */
function repo_purge_finished_system_jobs(mysqli $db, int $retentionDays = VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS): int
{
    $terminal = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $stmt = $db->prepare(
        'DELETE FROM deploy_jobs
         WHERE mission_id IS NULL
           AND status IN (' . $placeholders . ')
           AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $params = array_merge($terminal, [$retentionDays]);
    $stmt->bind_param(str_repeat('s', count($terminal)) . 'i', ...$params);
    $stmt->execute();

    return $stmt->affected_rows;
}

/**
 * The one sentence of observable fact a reap writes, as a pure function so the
 * wording can be pinned without a database.
 *
 * Four facts, all of them readable from the row being written: which job, who
 * held the lock, how stale the heartbeat is against the limit that made it
 * stale, and the transition. No claim about why, because nothing in this
 * transaction can see why.
 */
function deploy_job_reap_observation(
    int $jobId,
    string $lockedBy,
    ?int $heartbeatAgeSeconds,
    int $staleAfterSeconds,
    string $fromStatus,
    string $toStatus
): string {
    $age = $heartbeatAgeSeconds === null
        ? 'no heartbeat was ever written'
        : 'last heartbeat ' . max(0, $heartbeatAgeSeconds) . ' s ago';

    return 'Job ' . $jobId . ': ' . $age . ', limit ' . $staleAfterSeconds . ' s; lock held by '
        . (trim($lockedBy) === '' ? 'nobody' : trim($lockedBy)) . '; ' . $fromStatus . ' -> ' . $toStatus . '.';
}

/**
 * Concludes jobs whose heartbeat has gone stale.
 *
 * The message this writes is the operator's whole account of what happened, so
 * it says only what this transaction can see: which job, who held its lock, how
 * old its last heartbeat is against the limit, and the transition that follows.
 * Everything else is a guess. "No heartbeat for N seconds" alone named the
 * mechanism that noticed rather than the event, and the sentence that used to
 * follow it went further and asserted a cause - that the worker had died, or
 * that it had not - from a singleton status row that proves neither. A restart
 * writes a fresh row, so a reporting service does not establish that the
 * process holding THIS job survived, and a silent one does not establish that
 * it died.
 *
 * @param string $cause An additional observation the caller established, if it
 *        has one. It is appended verbatim, so it must be phrased as an
 *        observation and not as a cause; the deploy worker passes the current
 *        state of the service's own status row, explicitly labelled as separate.
 */
function repo_reap_stale_deploy_jobs(mysqli $db, int $staleAfterSeconds = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, string $cause = ''): array
{
    $staleAfterSeconds = max(60, $staleAfterSeconds);
    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
    $failed = VIRTUSPHERE_DEPLOY_STATUS_FAILED;
    $cancelled = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
    $suffix = $cause === '' ? '' : ' ' . $cause;

    return repo_transaction($db, static function () use ($db, $staleAfterSeconds, $running, $cancelling, $failed, $cancelled, $suffix): array {
        $stmt = $db->prepare('SELECT id, mission_id, status, payload_json, credential_esxi_id, locked_by, heartbeat_at, TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) AS heartbeat_age_seconds FROM deploy_jobs WHERE status IN (?, ?) AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)) ORDER BY heartbeat_at ASC, id ASC FOR UPDATE SKIP LOCKED');
        $stmt->bind_param('ssi', $running, $cancelling, $staleAfterSeconds);
        $stmt->execute();
        $jobs = repo_fetch_all($stmt->get_result());

        foreach ($jobs as &$job) {
            $jobId = (int) $job['id'];
            // A stale cancelling job converges to what the operator asked for,
            // never to failed: the wish was recorded, only the confirmation is
            // missing, and a dead worker cannot deliver it (ADR-0033). The
            // reaped-from status travels with the row so the caller's VM
            // convergence can phrase its note truthfully.
            $wasCancelling = (string) $job['status'] === $cancelling;
            $job['reaped_to'] = $wasCancelling ? $cancelled : $failed;
            $observation = deploy_job_reap_observation(
                $jobId,
                (string) ($job['locked_by'] ?? ''),
                $job['heartbeat_age_seconds'] === null ? null : (int) $job['heartbeat_age_seconds'],
                $staleAfterSeconds,
                $wasCancelling ? $cancelling : $running,
                (string) $job['reaped_to']
            );
            $message = ($wasCancelling ? 'Cancellation converged by the reaper. ' : 'Reaped stale deploy job. ') . $observation . $suffix;
            if ($wasCancelling) {
                $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND status = ?');
                $stmt->bind_param('ssis', $cancelled, $message, $jobId, $cancelling);
            } else {
                $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND status = ?');
                $stmt->bind_param('ssis', $failed, $message, $jobId, $running);
            }
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);
                $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
                if (!$wasCancelling
                    && ($job['mission_id'] ?? null) === null
                    && (int) ($job['credential_esxi_id'] ?? 0) > 0
                    && is_array($payload)
                    && (string) ($payload['mode'] ?? '') === VIRTUSPHERE_DEPLOY_MODE_INVENTORY) {
                    // A reaped inventory pull is a real failed attempt. Without
                    // this state transition the active-job link vanished while
                    // the ESXi card kept its previous (possibly green) result,
                    // and no page listed the now-terminal system job. A
                    // CANCELLING pull is exempt on purpose: the operator asked
                    // for the stop, which is not a fetch failure.
                    repo_esxi_inventory_record_failure(
                        $db,
                        (int) $job['credential_esxi_id'],
                        VIRTUSPHERE_INVENTORY_ERROR_WORKER,
                        $jobId
                    );
                }
            }
        }
        unset($job);

        return $jobs;
    });
}

/**
 * Convergence sweep for orphaned deploying VMs. A VM left in `deploying` whose
 * mission has no queued/running job any more can never be finished by a
 * worker: the job that owned it is terminal, and the worker died (or was
 * cancelled and then died) before its own failure path could mark the VM. The
 * heartbeat reaper cannot help either - it only touches jobs still `running`.
 *
 * Convergence means failed/failed: lifecycle_state and mecm_sync_state
 * together, so no orphan advertises a MECM pickup that can never happen.
 * Stored MACs are untouched and the frozen legacy vm_status is not rewritten.
 * VMs of missions with an active job are never touched, however long they
 * have been deploying - the running worker owns them.
 *
 * SKIP LOCKED for the same reason as the reaper: a concurrent import callback
 * holds row locks on its mission's VMs, and the sweep must neither block on it
 * nor deadlock; skipped rows converge on the next interval.
 *
 * @return array<int, array{vm_id:int, mission_id:int, vm_name:string}>
 */
function repo_sweep_orphaned_deploying_vms(mysqli $db): array
{
    $deploying = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
    $failedLifecycle = VIRTUSPHERE_LIFECYCLE_FAILED;
    $failedMecm = VIRTUSPHERE_MECM_SYNC_FAILED;
    $note = 'convergence sweep: stuck in deploying without an active deploy job';

    return repo_transaction($db, static function () use ($db, $deploying, $failedLifecycle, $failedMecm, $note): array {
        // The active SSoT again: a cancelling job's VMs belong to that job's
        // own convergence (worker confirm or reaper), never to this sweep.
        $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
        $placeholders = implode(', ', array_fill(0, count($active), '?'));
        $stmt = $db->prepare(
            'SELECT v.id, v.mission_id, v.vm_name, v.vm_status FROM deploy_vms v
             WHERE v.lifecycle_state = ?
               AND NOT EXISTS (SELECT 1 FROM deploy_jobs j WHERE j.mission_id = v.mission_id AND j.status IN (' . $placeholders . '))
             ORDER BY v.id
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->bind_param('s' . str_repeat('s', count($active)), $deploying, ...$active);
        $stmt->execute();
        $vms = repo_fetch_all($stmt->get_result());

        $swept = [];
        foreach ($vms as $vm) {
            $vmId = (int) $vm['id'];
            $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ?');
            $stmt->bind_param('ssis', $failedLifecycle, $failedMecm, $vmId, $deploying);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                continue;
            }
            repo_record_vm_status_event($db, $vmId, $failedLifecycle, $failedMecm, (string) ($vm['vm_status'] ?? ''), $note);
            $swept[] = ['vm_id' => $vmId, 'mission_id' => (int) $vm['mission_id'], 'vm_name' => (string) $vm['vm_name']];
        }

        return $swept;
    });
}
