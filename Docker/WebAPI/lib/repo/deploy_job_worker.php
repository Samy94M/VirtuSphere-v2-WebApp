<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_job_output.php';
require_once __DIR__ . '/../deploy_job_result.php';
require_once __DIR__ . '/../deploy_preflight_bounds.php';
require_once __DIR__ . '/../remote_execution.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_job_service_state.php';
require_once __DIR__ . '/deploy_job_queries.php';

/**
 * Worker ownership: claim, heartbeat, finish and the job log append.
 *
 * Every write here is an ownership CAS on `locked_by`, so a worker can only
 * conclude the job it actually holds; a lost lock makes the update affect zero
 * rows and the caller learns it from the boolean rather than overwriting a
 * foreign terminal state.
 */

function repo_claim_next_deploy_job(mysqli $db, string $workerId): ?array
{
    $workerId = trim($workerId);
    if ($workerId === '') {
        throw new InvalidArgumentException('Worker id is required.');
    }

    return repo_transaction($db, static function () use ($db, $workerId): ?array {
        // The claim gate (Etappe 13R). It sits INSIDE the claim transaction, not
        // in the worker loop, because a check in the caller is a check some
        // future second caller will not make; a paused service that still takes
        // a job through another path is worse than no pause at all.
        //
        // Only NEW work is refused. The worker's own running job keeps its
        // heartbeat, its log and its recovery paths: a pause exists so that work
        // may finish, not so that it is abandoned half-applied on ESXi.
        if (!deploy_claim_state_allows_new_work(repo_deploy_claim_state($db)['state'])) {
            return null;
        }

        $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
        // Scheduled jobs (scheduled_at in the future) are not yet eligible.
        // The DB session is pinned to UTC (db()), so UTC_TIMESTAMP() matches the
        // stored UTC scheduled_at (ADR-0022).
        //
        // Mission deploys claim before mission-less system jobs (inventory
        // pulls): with several ESXi credentials one interval cycle enqueues a
        // burst of pulls that would otherwise delay an operator's deploy by
        // many minutes. Deliberate starvation trade-off: a continuous deploy
        // stream postpones inventory jobs, which is fine because the cache is
        // a warn-only mirror and re-enqueues every interval (ADR-0023). The
        // expression is not index-backed; with LIMIT 1 over the small queued
        // set that is irrelevant.
        $stmt = $db->prepare('SELECT id FROM deploy_jobs WHERE status = ? AND cancelled_at IS NULL AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP()) ORDER BY (mission_id IS NULL) ASC, id ASC LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $queued);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        $jobId = (int) $row['id'];
        $runtime = repo_fetch_one($db, 'SELECT claim_state, LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
        // The earlier read is only a fast rejection. This current locked read
        // serializes the actual claim with a concurrent pause, Job -> Runtime.
        if (!deploy_claim_state_allows_new_work((string) ($runtime['claim_state'] ?? ''))) {
            return null;
        }
        $generation = (string) ($runtime['generation_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $generation) !== 1) {
            throw new RuntimeException('Deploy runtime generation is missing.');
        }
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $legacyContract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        // The ownership fence of this claim (Etappe 14B). A worker id is
        // derived from host and process and survives a restart, so it cannot
        // tell a returning old worker from its successor; a per-claim random
        // token can, and the per-VM create writes compare it on every
        // transition. The lease epoch travels with it so the same comparison
        // keeps working once the remote execution contract owns the lease.
        // Nothing here activates that contract: the job stays `legacy`.
        $lockToken = bin2hex(random_bytes(16));
        $epoch = (int) repo_scalar($db, "SELECT COALESCE(MAX(epoch), 0) FROM deploy_worker_leases WHERE lease_name = 'deploy-worker'");
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, locked_at = NOW(), locked_by = ?, lock_token = ?, worker_epoch = ?, heartbeat_at = NOW(), attempts = attempts + 1, execution_contract = COALESCE(execution_contract, ?), execution_generation_id = COALESCE(execution_generation_id, UNHEX(?)), updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssissi', $running, $workerId, $lockToken, $epoch, $legacyContract, $generation, $jobId);
        $stmt->execute();
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job claimed by ' . $workerId);

        return repo_deploy_job($db, $jobId);
    });
}

function repo_touch_deploy_job_heartbeat(mysqli $db, int $jobId, string $workerId): bool
{
    // running AND cancelling: a cancelling job's current step is still
    // executing under this worker, and without the beat the reaper would fail
    // a perfectly alive worker mid-confirmation (ADR-0033).
    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
    $stmt = $db->prepare('UPDATE deploy_jobs SET heartbeat_at = NOW(), updated_at = NOW() WHERE id = ? AND locked_by = ? AND status IN (?, ?)');
    $stmt->bind_param('isss', $jobId, $workerId, $running, $cancelling);

    return $stmt->execute() && $stmt->affected_rows === 1;
}

function repo_finish_deploy_job(
    mysqli $db,
    int $jobId,
    string $workerId,
    string $status,
    ?string $lastError = null,
    ?string $reasonCode = null,
    ?string $reasonDetail = null,
    ?array $terminalResult = null
): bool
{
    if (!in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        throw new InvalidArgumentException('Deploy job finish status must be terminal.');
    }

    $reasonCode ??= match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_COMPLETED,
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_PARTIAL_RESULT,
        VIRTUSPHERE_DEPLOY_STATUS_FAILED => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_OPERATOR_CANCELLED,
    };
    deploy_terminal_reason_assert($status, $reasonCode);
    $lastError = $status === VIRTUSPHERE_DEPLOY_STATUS_FAILED ? $lastError : null;
    $reasonDetail = deploy_terminal_reason_detail($reasonDetail ?? ($status === VIRTUSPHERE_DEPLOY_STATUS_FAILED ? $lastError : null));
    $terminalResultJson = $terminalResult === null ? null : repo_encode_deploy_preflight_result($terminalResult);

    return repo_transaction($db, static function () use ($db, $jobId, $workerId, $status, $lastError, $reasonCode, $reasonDetail, $terminalResultJson): bool {
        $stmt = $db->prepare('SELECT status, locked_by, result_json FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row
            || (string) $row['status'] !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING
            || (string) ($row['locked_by'] ?? '') !== $workerId
        ) {
            return false;
        }

        $terminalLines = [
            VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => 'Deploy job succeeded.',
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => 'Deploy job finished partially.',
            VIRTUSPHERE_DEPLOY_STATUS_FAILED => 'Deploy job failed.',
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => 'Deploy job cancelled.',
        ];
        // PHPStan derives $status from VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
        // adding a terminal state without extending this map is a static error.
        $terminalLine = $terminalLines[$status];
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $terminalLine);
        if ($terminalResultJson !== null && $row['result_json'] !== null) {
            throw new RuntimeException('Deploy preflight terminal result found an existing final result.');
        }
        $resultJson = $terminalResultJson ?? deploy_job_terminal_result_json(
            $row['result_json'] !== null ? (string) $row['result_json'] : null,
            $status
        );

        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, result_json = ?, terminal_reason_code = ?, terminal_reason_detail = ?, locked_at = NULL, locked_by = NULL, lock_token = NULL, worker_epoch = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND locked_by = ? AND status = ?');
        $stmt->bind_param('sssssiss', $status, $lastError, $resultJson, $reasonCode, $reasonDetail, $jobId, $workerId, $running);
        $stmt->execute();
        $finished = $stmt->affected_rows === 1;

        // The worker's half of a requested pause (Etappe 13R), in the same
        // transaction as the terminal write: the job this pause was waiting for
        // has ended, so `pause_after_current` becomes `paused`. Doing it here
        // rather than in the loop means the pause completes exactly when the
        // work it protected did, even if the process dies immediately after.
        //
        // It is a CAS on the requested state, so a resume issued while the job
        // was still running wins and the service simply keeps working.
        if ($finished) {
            repo_deploy_confirm_claim_pause($db);
        }

        return $finished;
    });
}

function repo_append_deploy_job_log(mysqli $db, int $jobId, string $stream, string $line): int
{
    return repo_transaction($db, static fn (): int => repo_insert_deploy_job_log_unlocked($db, $jobId, $stream, $line));
}

/**
 * Encodes the bounded structured result used by an atomic pre-remote finish.
 *
 * It bounds instead of refusing. A job stopped at the configuration boundary
 * has to end as `configuration_blocked` with a reason the operator can act on;
 * throwing here would send it down the failure path and label it
 * `execution_failed`, which asserts that a playbook ran, which is the one thing
 * that provably did not happen. The verdict is never at risk, only the number
 * of rows illustrating it, and `counts` keeps stating the complete decision.
 */
function repo_encode_deploy_preflight_result(array $result): string
{
    return deploy_preflight_bounded_result($result, VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES)['json'];
}

function repo_insert_deploy_job_log_unlocked(mysqli $db, int $jobId, string $stream, string $line): int
{
    if (!in_array($stream, VIRTUSPHERE_DEPLOY_LOG_STREAMS, true)) {
        throw new InvalidArgumentException('Invalid deploy log stream.');
    }

    // Serialize every append with the parent row. Terminal transitions append
    // their final SYSTEM line while that row is still active and change the
    // status in the same transaction; a later normal writer therefore observes
    // the terminal state and cannot create evidence after the declared end.
    $stmt = $db->prepare('SELECT status FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    if (!$job) {
        throw new RuntimeException('Deploy job not found for log append.');
    }
    if (in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        throw new RuntimeException('Cannot append to a terminal deploy job.');
    }

    // The last thing before the row exists, and therefore the place where
    // "storable" is guaranteed rather than hoped for (Etappe 8). The worker's
    // channel already runs the full output gate, but the outcome and cancel
    // paths write here directly with a `mysqli`, and one of their lines is an
    // exception message that can quote remote output verbatim - colour codes,
    // control characters and all. Redaction and the per-job volume budget need
    // job state and stay in the gate; being displayable is a property of the
    // column and belongs here. Both steps are idempotent, so a line that
    // already passed the gate is unchanged by this.
    $line = deploy_job_output_truncate_line(deploy_job_output_normalize_line($line))['line'];

    $stmt = $db->prepare('SELECT seq FROM deploy_job_logs WHERE job_id = ? ORDER BY seq DESC LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $seq = (int) ($row['seq'] ?? 0) + 1;

    // ADR-0032: the execution's correlation id (the worker adopts the job's
    // stored id on claim, so its lines carry the job's trace).
    $correlationId = virtusphere_correlation_id();
    $stmt = $db->prepare('INSERT INTO deploy_job_logs (job_id, seq, stream, line, correlation_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('iisss', $jobId, $seq, $stream, $line, $correlationId);
    $stmt->execute();

    return $seq;
}
