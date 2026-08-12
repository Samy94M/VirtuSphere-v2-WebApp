<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_job_worker.php';

/**
 * The cancellation state machine (ADR-0033): the operator's two transitions,
 * the group variant, and the worker's confirmation.
 *
 * They live together because they are one contract, not because of who calls
 * them: `queued -> cancelled` ends the job outright, `running -> cancelling`
 * only records the wish and leaves lock, heartbeat and every protective effect
 * of an active job in place until the worker confirms at a step boundary or the
 * reaper converges a dead one. Splitting the wish from its confirmation is how
 * a terminal job ends up with a playbook still creating VMs.
 */

/**
 * Cancels all still-queued jobs of a stagger group. A job that is already
 * running is left to finish; only queued slots are stopped.
 */
function repo_cancel_deploy_group(mysqli $db, string $groupId, int $userId): int
{
    $groupId = trim($groupId);
    if ($groupId === '' || $userId <= 0) {
        throw new InvalidArgumentException('Group and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $groupId, $userId): int {
        $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
        $stmt = $db->prepare('SELECT id FROM deploy_jobs WHERE group_id = ? AND status = ? AND cancelled_at IS NULL FOR UPDATE');
        $stmt->bind_param('ss', $groupId, $queued);
        $stmt->execute();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], repo_fetch_all($stmt->get_result()));

        $cancelled = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
        $message = 'Cancelled with group ' . $groupId . ' by user id ' . $userId;
        foreach ($ids as $jobId) {
            $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $stmt->bind_param('ssis', $cancelled, $message, $jobId, $queued);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);
            }
        }

        return count($ids);
    });
}

/**
 * The two cancel transitions (ADR-0033, decision 4). Returns the status the
 * job holds afterwards, so the caller can phrase its answer honestly:
 *
 *  - queued  -> cancelled: nothing started, the wish IS the end state.
 *  - running -> cancelling: the worker still owns the sequence, so lock,
 *    heartbeat and every protective effect of an active job stay until it
 *    confirms at a step boundary (repo_confirm_deploy_job_cancelled) or the
 *    reaper converges a dead worker. cancelled_at stays NULL: it names the
 *    CONFIRMED end state only; the wish carries its own timestamp and actor.
 *  - cancelling -> cancelling: idempotent. The first wish keeps its timestamp
 *    and actor (the button is hidden for cancelling jobs, but a stale page's
 *    POST must not error or overwrite who asked first).
 *
 * The old behaviour - running straight to cancelled with nulled lock and
 * heartbeat - showed a terminal job whose playbook was still creating VMs for
 * the length of its current step: delete/enqueue guards opened, the
 * sequence's own MAC callback bounced with 409, and a worker that died after
 * the cancel was invisible to the reaper (B4).
 */
function repo_cancel_deploy_job(mysqli $db, int $jobId, int $userId): string
{
    if ($jobId <= 0 || $userId <= 0) {
        throw new InvalidArgumentException('Job and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $userId): string {
        $stmt = $db->prepare('SELECT id, status FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        if (!$job) {
            throw new RuntimeException('Deploy job not found.');
        }
        $current = (string) $job['status'];
        if ($current === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
            return VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        }
        if (!in_array($current, VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true)) {
            throw new RuntimeException('Only active deploy jobs can be cancelled.');
        }

        if ($current === VIRTUSPHERE_DEPLOY_STATUS_QUEUED) {
            $message = 'Cancelled by user id ' . $userId;
            $status = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
            $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), cancel_requested_at = NOW(), cancel_requested_by = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('sisi', $status, $userId, $message, $jobId);
            $stmt->execute();
            repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);

            return $status;
        }

        $message = 'Cancel requested by user id ' . $userId . '; the worker stops at the next step boundary.';
        $status = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancel_requested_at = NOW(), cancel_requested_by = ?, last_error = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sisi', $status, $userId, $message, $jobId);
        $stmt->execute();
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);

        return $status;
    });
}

/**
 * The worker's confirmation of a requested cancel, as an ownership CAS: only
 * the worker that holds the lock may conclude its own job, exactly like the
 * finish path. True when this call performed the transition.
 */
function repo_confirm_deploy_job_cancelled(mysqli $db, int $jobId, string $workerId): bool
{
    $workerId = trim($workerId);
    if ($jobId <= 0 || $workerId === '') {
        throw new InvalidArgumentException('Job and worker are required.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $workerId): bool {
        $cancelled = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
        $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $message = 'Cancelled after operator request; confirmed by the worker at a step boundary.';
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ? AND locked_by = ? AND status = ?');
        $stmt->bind_param('ssiss', $cancelled, $message, $jobId, $workerId, $cancelling);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return false;
        }
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);

        return true;
    });
}
