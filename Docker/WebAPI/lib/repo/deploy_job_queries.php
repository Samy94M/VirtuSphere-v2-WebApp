<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Deploy job reads: the list and detail rows the portal renders, the streamed
 * log tail and the payload summary a log line carries. Read-only; the VM scope
 * of a job lives in deploy_job_scope.php.
 */

function deploy_job_payload_summary(?string $payloadJson): string
{
    if ($payloadJson === null || trim($payloadJson) === '') {
        return VIRTUSPHERE_DEPLOY_MODE_FULL;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return 'invalid payload';
    }

    $mode = (string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL);
    // K4b: the resumed start of a full pipeline names its autostart follow-up.
    $mode .= $mode === 'start' && !empty($payload['then_autostart']) ? ' then autostart' : '';
    $verbose = !empty($payload['verbose']) ? ' -vvv' : '';
    $vmIds = is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
    $scope = $vmIds === [] ? '' : ' (' . count($vmIds) . ' VMs)';

    return $mode . $verbose . $scope;
}

function repo_deploy_jobs(mysqli $db, int $limit = 100, ?int $missionId = null): array
{
    $limit = max(1, min(500, $limit));
    if ($missionId !== null && $missionId > 0) {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.started_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_users cu ON cu.id = j.cancel_requested_by
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             WHERE j.mission_id = ?
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $missionId, $limit);
    } else {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.started_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_users cu ON cu.id = j.cancel_requested_by
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * The deploy jobs one portal request enqueued, newest first.
 *
 * Read beside an EXACT correlation search on logs.php, so an operator following
 * a trace sees the jobs it started without going to the deploy page and
 * matching timestamps by eye. The mission is LEFT JOINed because a system job
 * (an ESXi inventory pull, ADR-0023) legitimately has none, and dropping those
 * rows would make a trace look like it enqueued nothing.
 *
 * Ordered by id alone: `created_at` has second resolution and a staggered batch
 * is written inside one second, so a tie there would order the same trace
 * differently on two renders. One row over the limit is fetched so the caller
 * can SAY that it capped rather than silently showing a prefix.
 *
 * @return array{jobs:list<array<string,mixed>>,truncated:bool}
 */
function repo_deploy_jobs_by_correlation(mysqli $db, string $correlationId, int $limit = VIRTUSPHERE_LOG_CORRELATION_JOB_LIMIT): array
{
    $limit = max(1, $limit);
    if (trim($correlationId) === '') {
        return ['jobs' => [], 'truncated' => false];
    }

    $queryLimit = $limit + 1;
    $stmt = $db->prepare(
        'SELECT j.id, j.mission_id, m.mission_name, j.status, j.created_at
         FROM deploy_jobs j
         LEFT JOIN deploy_missions m ON m.id = j.mission_id
         WHERE j.correlation_id = ?
         ORDER BY j.id DESC
         LIMIT ?'
    );
    $stmt->bind_param('si', $correlationId, $queryLimit);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());
    $truncated = count($rows) > $limit;

    return ['jobs' => $truncated ? array_slice($rows, 0, $limit) : $rows, 'truncated' => $truncated];
}

function repo_deploy_job(mysqli $db, int $jobId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.lock_token, j.worker_epoch, LOWER(HEX(j.execution_generation_id)) AS execution_generation_id, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.started_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
         FROM deploy_jobs j
         LEFT JOIN deploy_missions m ON m.id = j.mission_id
         LEFT JOIN deploy_users u ON u.id = j.user_id
         LEFT JOIN deploy_users cu ON cu.id = j.cancel_requested_by
         LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
         LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
         WHERE j.id = ?
         LIMIT 1',
        'i',
        [$jobId]
    );
}

/**
 * The one definition of an open mission-less system job (the ESXi inventory
 * pull, ADR-0023): queued, running or cancelling and not cancelled. A cancelling
 * pull still owns its credential until the worker confirms (ADR-0033). The
 * enqueue dedupe of repo_create_system_job() and the portal's pending view
 * repo_esxi_inventory_pending_jobs() both read it, so the greyed-out button and
 * the dedupe cannot disagree.
 *
 * @return array{sql:string,types:string,params:list<string>} a WHERE fragment over deploy_jobs and its bindings
 */
function repo_open_system_job_condition(): array
{
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(', ', array_fill(0, count($active), '?'));

    return [
        'sql' => 'mission_id IS NULL AND status IN (' . $placeholders . ') AND cancelled_at IS NULL',
        'types' => str_repeat('s', count($active)),
        'params' => $active,
    ];
}

/** @return array{logs:array<int,array>,oldest_seq:?int,newest_seq:?int,has_older:bool,has_more:bool,caught_up:bool} */
function repo_deploy_job_log_initial_tail(mysqli $db, int $jobId, int $limit = VIRTUSPHERE_DEPLOY_LOG_INITIAL_TAIL_LIMIT): array
{
    $limit = deploy_job_log_read_limit($limit);

    return repo_transaction($db, static function () use ($db, $jobId, $limit): array {
        $queryLimit = $limit + 1;
        $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM (SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? ORDER BY seq DESC LIMIT ?) AS recent ORDER BY seq ASC');
        $stmt->bind_param('ii', $jobId, $queryLimit);
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());
        $hasOlder = count($rows) > $limit;
        $logs = $hasOlder ? array_slice($rows, 1) : $rows;

        return deploy_job_log_page($logs, $hasOlder, false, true);
    });
}

/** @return array{logs:array<int,array>,oldest_seq:?int,newest_seq:?int,has_older:bool,has_more:bool,caught_up:bool} */
function repo_deploy_job_log_forward(mysqli $db, int $jobId, int $afterSeq, int $limit = VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT): array
{
    $limit = deploy_job_log_read_limit($limit);
    if ($afterSeq < 0) {
        throw new InvalidArgumentException('after_seq must be zero or positive.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $afterSeq, $limit): array {
        $queryLimit = $limit + 1;
        $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? AND seq > ? ORDER BY seq ASC LIMIT ?');
        $stmt->bind_param('iii', $jobId, $afterSeq, $queryLimit);
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());
        $hasMore = count($rows) > $limit;
        $logs = array_slice($rows, 0, $limit);
        $bounds = deploy_job_log_bounds($db, $jobId);
        $newestCursor = $logs === [] ? $afterSeq : (int) end($logs)['seq'];

        return deploy_job_log_page(
            $logs,
            $bounds['oldest'] !== null && $bounds['oldest'] <= $afterSeq,
            $hasMore,
            !$hasMore && ($bounds['newest'] === null || $bounds['newest'] <= $newestCursor)
        );
    });
}

/** @return array{logs:array<int,array>,oldest_seq:?int,newest_seq:?int,has_older:bool,has_more:bool,caught_up:bool} */
function repo_deploy_job_log_older(mysqli $db, int $jobId, int $beforeSeq, int $limit = VIRTUSPHERE_DEPLOY_LOG_OLDER_LIMIT): array
{
    $limit = deploy_job_log_read_limit($limit);
    if ($beforeSeq <= 0) {
        throw new InvalidArgumentException('before_seq must be positive.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $beforeSeq, $limit): array {
        $queryLimit = $limit + 1;
        $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM (SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? AND seq < ? ORDER BY seq DESC LIMIT ?) AS older ORDER BY seq ASC');
        $stmt->bind_param('iii', $jobId, $beforeSeq, $queryLimit);
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());
        $hasOlder = count($rows) > $limit;
        $logs = $hasOlder ? array_slice($rows, 1) : $rows;
        $bounds = deploy_job_log_bounds($db, $jobId);
        $newestCursor = $logs === [] ? $beforeSeq - 1 : (int) end($logs)['seq'];
        $hasMore = $bounds['newest'] !== null && $bounds['newest'] > $newestCursor;

        return deploy_job_log_page($logs, $hasOlder, $hasMore, !$hasMore);
    });
}

/**
 * Streams a fixed read snapshot in bounded batches. The maximum sequence is
 * captured inside the same transaction, so an active job cannot turn one
 * download into an endless response while it continues to append.
 *
 * @return Generator<int,array<int,array>>
 */
function repo_deploy_job_log_raw_batches(mysqli $db, int $jobId, int $batchSize = VIRTUSPHERE_DEPLOY_LOG_RAW_BATCH_SIZE): Generator
{
    $batchSize = deploy_job_log_read_limit($batchSize);
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $closed = false;
    try {
        $snapshotMax = (int) (repo_scalar($db, 'SELECT COALESCE(MAX(seq), 0) FROM deploy_job_logs WHERE job_id = ?', 'i', [$jobId]) ?? 0);
        $afterSeq = 0;
        while ($afterSeq < $snapshotMax) {
            $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? AND seq > ? AND seq <= ? ORDER BY seq ASC LIMIT ?');
            $stmt->bind_param('iiii', $jobId, $afterSeq, $snapshotMax, $batchSize);
            $stmt->execute();
            $rows = repo_fetch_all($stmt->get_result());
            if ($rows === []) {
                break;
            }
            $afterSeq = (int) end($rows)['seq'];
            yield $rows;
        }
        $db->commit();
        $closed = true;
    } catch (Throwable $exception) {
        $db->rollback();
        $closed = true;
        throw $exception;
    } finally {
        // A disconnected client may destroy the generator before its final
        // batch. Never leave that read snapshot open on a reused connection.
        if (!$closed) {
            try {
                $db->rollback();
            } catch (Throwable) {
                // Connection loss already discards the transaction.
            }
        }
    }
}

function deploy_job_log_read_limit(int $limit): int
{
    if ($limit <= 0) {
        throw new InvalidArgumentException('Deploy job log limit must be positive.');
    }

    return min(VIRTUSPHERE_DEPLOY_LOG_QUERY_LIMIT_MAX, $limit);
}

/** @return array{oldest:?int,newest:?int} */
function deploy_job_log_bounds(mysqli $db, int $jobId): array
{
    $stmt = $db->prepare('SELECT MIN(seq) AS oldest_seq, MAX(seq) AS newest_seq FROM deploy_job_logs WHERE job_id = ?');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    return [
        'oldest' => isset($row['oldest_seq']) ? (int) $row['oldest_seq'] : null,
        'newest' => isset($row['newest_seq']) ? (int) $row['newest_seq'] : null,
    ];
}

/** @param array<int,array> $logs */
function deploy_job_log_page(array $logs, bool $hasOlder, bool $hasMore, bool $caughtUp): array
{
    return [
        'logs' => $logs,
        'oldest_seq' => $logs === [] ? null : (int) $logs[0]['seq'],
        'newest_seq' => $logs === [] ? null : (int) $logs[count($logs) - 1]['seq'],
        'has_older' => $hasOlder,
        'has_more' => $hasMore,
        'caught_up' => $caughtUp,
    ];
}

/**
 * Compatibility wrapper for internal callers/tests that still ask for the old
 * forward-only shape. New portal paths use the named cursor functions above.
 */
function repo_deploy_job_logs(mysqli $db, int $jobId, int $afterSeq = 0, int $limit = VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT): array
{
    return repo_deploy_job_log_forward($db, $jobId, $afterSeq, $limit)['logs'];
}
