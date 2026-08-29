<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../ansible_command_modes.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * The two filtered reads of a job log (Etappe 13).
 *
 * They are separate from the three cursor reads in deploy_job_queries.php on
 * purpose. Those answer "what comes next in this job" and are what the live
 * view drains; these answer "where in this job does X appear" and are a search
 * over everything still retained. Mixing them would give the poller a cursor
 * whose meaning depends on a filter, which is exactly the bug the 10A contract
 * exists to prevent: a follow that silently marks unseen full-log lines as read.
 *
 * Both stay read-only and bounded. A search is a diagnostic, never an
 * authorization boundary; `deploy.run` already decided this reader may see the
 * whole log, and the raw download remains the complete retained output.
 */

/**
 * Every step-marker line of one job, oldest first.
 *
 * Deliberately its own query rather than a scan of the display window: the
 * phases of a job are a property of the WHOLE job, and deriving them from the
 * bounded tail would make the timeline shrink as the log grows. Markers are a
 * handful of rows even for a long run, because the worker writes exactly one
 * pair per playbook step.
 *
 * @return list<array{seq:int,line:string}>
 */
function repo_deploy_job_log_step_markers(mysqli $db, int $jobId): array
{
    $prefix = VIRTUSPHERE_ANSIBLE_STEP_MARKER_PREFIX . '%';
    $stmt = $db->prepare('SELECT seq, line FROM deploy_job_logs WHERE job_id = ? AND line LIKE ? ORDER BY seq ASC LIMIT ?');
    $limit = VIRTUSPHERE_DEPLOY_LOG_MARKER_LIMIT;
    $stmt->bind_param('isi', $jobId, $prefix, $limit);
    $stmt->execute();

    return array_map(
        static fn (array $row): array => ['seq' => (int) $row['seq'], 'line' => (string) $row['line']],
        repo_fetch_all($stmt->get_result())
    );
}

/**
 * A bounded page of matching lines, oldest first.
 *
 * `$streams` is an already-validated list of stored stream values; an empty
 * list means every stream. `$fromSeq`/`$toSeq` are the inclusive bounds of a
 * phase, both optional, because an open phase has no end yet.
 *
 * The needle is matched with LIKE over an escaped pattern. `%` and `_` in an
 * operator's search term are escaped rather than passed through: a person
 * pasting a path with an underscore is searching for that path, not asking for
 * a single-character wildcard, and the difference is invisible in the result.
 *
 * @param list<string> $streams
 * @return array{logs:list<array<string,mixed>>,has_more:bool,match_count:int}
 */
function repo_deploy_job_log_search(
    mysqli $db,
    int $jobId,
    string $needle,
    array $streams,
    ?int $fromSeq = null,
    ?int $toSeq = null,
    int $limit = VIRTUSPHERE_DEPLOY_LOG_SEARCH_LIMIT
): array {
    $limit = max(1, min(VIRTUSPHERE_DEPLOY_LOG_QUERY_LIMIT_MAX, $limit));
    $where = ['job_id = ?'];
    $types = 'i';
    $params = [$jobId];

    $needle = trim($needle);
    if ($needle !== '') {
        $where[] = 'line LIKE ? ESCAPE \'\\\\\'';
        $types .= 's';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
    }
    if ($streams !== []) {
        $where[] = 'stream IN (' . implode(',', array_fill(0, count($streams), '?')) . ')';
        $types .= str_repeat('s', count($streams));
        foreach ($streams as $stream) {
            $params[] = $stream;
        }
    }
    if ($fromSeq !== null) {
        $where[] = 'seq >= ?';
        $types .= 'i';
        $params[] = $fromSeq;
    }
    if ($toSeq !== null) {
        $where[] = 'seq <= ?';
        $types .= 'i';
        $params[] = $toSeq;
    }

    $sql = 'SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE ' . implode(' AND ', $where)
        . ' ORDER BY seq ASC LIMIT ?';
    $queryLimit = $limit + 1;

    return repo_transaction($db, static function () use ($db, $sql, $types, $params, $queryLimit, $limit): array {
        $stmt = $db->prepare($sql);
        $bind = array_merge($params, [$queryLimit]);
        $stmt->bind_param($types . 'i', ...$bind);
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());
        $hasMore = count($rows) > $limit;
        $logs = array_slice($rows, 0, $limit);

        // The count is what is ON SCREEN, not an estimate of the whole match
        // set: announcing a total this query did not establish would be the
        // same defect the log CSV export was fixed for.
        return ['logs' => $logs, 'has_more' => $hasMore, 'match_count' => count($logs)];
    });
}
