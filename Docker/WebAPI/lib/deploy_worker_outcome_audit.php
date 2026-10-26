<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/deploy_worker_runtime.php';

/**
 * The deploy.outcome audit row of a mission job and the bounded log line for a
 * write this worker no longer owns.
 *
 * Split out of lib/deploy_worker_finish.php at its 400-line budget (ADR-0006),
 * with no behaviour change: names, SQL, transaction placement and log wording
 * are the ones the conclusion, failure, cancel, reaper and create paths already
 * used. Every end of a mission job writes its row through
 * deploy_worker_write_outcome_audit(), so this module stays the single writer.
 */

/**
 * WK-08: a write this worker no longer owns is dropped by design, but its message
 * is the only account of what the worker saw. One bounded, redacted line to the
 * error log; never a database write, because the row belongs to another claim.
 */
function deploy_worker_log_discarded_write(int $jobId, string $what, ?string $message): void
{
    $text = mb_substr(trim((string) preg_replace('/\s+/', ' ', virtusphere_redact_log_text((string) $message))), 0, 500, 'UTF-8');
    error_log('[deploy-worker] job ' . $jobId . ' is no longer owned by this worker; discarded ' . $what . ($text === '' ? '' : ': ' . $text));
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
    // A confirmed cancel wrote its row inside its own transaction (LG-08).
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED) {
        return;
    }
    try {
        $reason = in_array($status, [VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL], true) ? $error : null;
        deploy_worker_write_outcome_audit($db, $job, $status, $reason);
    } catch (Throwable $exception) {
        error_log('[deploy-worker] outcome audit failed for job ' . (int) ($job['id'] ?? 0) . ' with status ' . $status . ': ' . virtusphere_redact_log_text($exception->getMessage()));
    }
}

/**
 * The one writer of a mission job's deploy.outcome row (LG-07, LG-08). Conclusions
 * call it fail-soft after publication; a confirmed cancel and the reaper call it
 * inside their own transaction, so the row exists exactly when the state does.
 * Inventory jobs have no mission and no row. Returns false only when the row
 * was not stored.
 */
function deploy_worker_write_outcome_audit(mysqli $db, array $job, string $status, ?string $reason = null): bool
{
    if (($job['mission_id'] ?? null) === null) {
        return true;
    }
    $userId = isset($job['user_id']) ? (int) $job['user_id'] : null;
    $mode = deploy_worker_payload($job)['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL;
    $context = [
        'mission_id' => (int) $job['mission_id'],
        'mode' => audit_snippet($mode, 24),
        'status' => $status,
    ];
    if ($reason !== null && $reason !== '') {
        $snippet = audit_snippet($reason, 120);
        if ($snippet !== '') {
            $context['reason'] = $snippet;
        }
    }

    return audit_event(
        $db,
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME,
        'deploy_job',
        (int) $job['id'],
        deploy_worker_outcome_audit_result($status),
        $context,
        $userId,
        'cli'
    );
}

/**
 * The closed audit result of a terminal deploy status. A partial run is a
 * warning, not a failure: the mission did change, just not completely, and
 * flattening the two would hide that difference from whoever reads the log.
 * An outcome_unknown job (the reaper saw only silence) is a warning too: it is
 * not a known failure, and success would be a guess (WK-04/WK-07 A).
 * Unknown statuses cannot slip through as "success": the default is failure,
 * and the registry refuses a result it does not know.
 */
function deploy_worker_outcome_audit_result(string $status): string
{
    return match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN => VIRTUSPHERE_AUDIT_RESULT_WARNING,
        default => VIRTUSPHERE_AUDIT_RESULT_FAILURE,
    };
}
