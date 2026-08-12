<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';
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
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, locked_at = NOW(), locked_by = ?, heartbeat_at = NOW(), attempts = attempts + 1, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $running, $workerId, $jobId);
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

function repo_finish_deploy_job(mysqli $db, int $jobId, string $workerId, string $status, ?string $lastError = null): bool
{
    if (!in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        throw new InvalidArgumentException('Deploy job finish status must be terminal.');
    }

    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND locked_by = ? AND status = ?');
    $stmt->bind_param('ssiss', $status, $lastError, $jobId, $workerId, $running);

    return $stmt->execute() && $stmt->affected_rows === 1;
}

function repo_append_deploy_job_log(mysqli $db, int $jobId, string $stream, string $line): int
{
    return repo_transaction($db, static fn (): int => repo_insert_deploy_job_log_unlocked($db, $jobId, $stream, $line));
}

function repo_insert_deploy_job_log_unlocked(mysqli $db, int $jobId, string $stream, string $line): int
{
    if (!in_array($stream, VIRTUSPHERE_DEPLOY_LOG_STREAMS, true)) {
        throw new InvalidArgumentException('Invalid deploy log stream.');
    }

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
