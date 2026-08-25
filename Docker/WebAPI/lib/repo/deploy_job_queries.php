<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Deploy job reads: the list and detail rows the portal renders, the streamed
 * log tail, the mission-scoped VM resolution both the enqueue and its schedule
 * preview share, and the payload summary a log line carries.
 *
 * Read-only by construction. The one deliberate throw is in
 * repo_deploy_filter_mission_vm_ids(): a selection that filters down to nothing
 * must not read as "whole mission".
 */

/**
 * Computes the per-VM start times for the schedule preview without creating any
 * jobs. Returns Unix epochs so the page formats them in the portal timezone.
 *
 * @return array<int, array{vm_name:string, epoch:int}>
 */
function deploy_preview_rows(mysqli $db, int $missionId, array $payloadData, array $schedule): array
{
    $vmIds = is_array($payloadData['vm_ids'] ?? null) ? $payloadData['vm_ids'] : [];
    $vms = repo_deploy_group_vm_list($db, $missionId, $vmIds);
    $baseEpoch = (int) $schedule['base_epoch'];
    $stagger = $schedule['stagger'];

    $rows = [];
    foreach (array_values($vms) as $i => $vm) {
        $epoch = $baseEpoch + ($stagger !== null ? $i * $stagger * 60 : 0);
        $rows[] = ['vm_name' => (string) $vm['vm_name'], 'epoch' => $epoch];
    }

    return $rows;
}

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
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
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
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
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

function repo_deploy_job(mysqli $db, int $jobId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.terminal_reason_code, j.terminal_reason_detail, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.cancel_requested_at, j.cancel_requested_by, cu.name AS cancel_requested_by_name, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
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

/**
 * @param int[] $vmIds
 * @return int[] VM ids that belong to the mission (order preserved)
 *
 * An empty input stays empty and is read as "whole mission" one level up. But a
 * non-empty input that filters down to nothing is a selection whose VMs have all
 * been deleted (or never belonged here) since the form was rendered: silently
 * returning [] would widen that job to the entire mission. Throw instead, with
 * the exact wording of the worker-side gate (ansible_prepare_job_artifacts), so
 * the same condition reads the same one stage earlier.
 */
function repo_deploy_filter_mission_vm_ids(mysqli $db, int $missionId, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }

    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $owned = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $owned[(int) $row['id']] = true;
    }

    $filtered = array_values(array_filter($vmIds, static fn (int $id): bool => isset($owned[$id])));
    if ($filtered === []) {
        throw new RuntimeException('None of the selected VMs belong to this mission.');
    }

    return $filtered;
}

/**
 * Ordered VM list for a stagger group / its preview. An explicit selection
 * (filtered to the mission) keeps its order; an empty selection means the whole
 * mission ordered by vm_name.
 *
 * @param int[] $selectedVmIds
 * @return array<int, array{id:int, vm_name:string}>
 */
function repo_deploy_group_vm_list(mysqli $db, int $missionId, array $selectedVmIds): array
{
    $selected = repo_deploy_filter_mission_vm_ids($db, $missionId, $selectedVmIds);
    if ($selected === []) {
        $stmt = $db->prepare('SELECT id, vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'vm_name' => (string) $r['vm_name']],
            repo_fetch_all($stmt->get_result())
        );
    }

    $vms = [];
    foreach ($selected as $vmId) {
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        $vms[] = ['id' => $vmId, 'vm_name' => $name];
    }

    return $vms;
}
