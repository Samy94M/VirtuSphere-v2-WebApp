<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vm_history.php';
require_once __DIR__ . '/../deploy_stored_mode.php';
require_once __DIR__ . '/../audit_registry.php';
require_once __DIR__ . '/../log_redaction.php';

/**
 * The SQL readers of the VM chronicle (SP3, F03). One keyset query per stream,
 * each with at most $pageSize + 1 rows, merged by virtusphere_vm_history_merge().
 * The caller passes only sources the reader may see (F2: `audit` needs
 * users.manage), so a refused source is neither queried nor counted. Each source
 * runs in its own try: a failure names the source and leaves the others readable.
 */

/** The DATETIME(6) text a stream sorts by; equal to virtusphere_vm_history_at(). */
function repo_vm_history_sort_at(string $column): string
{
    return "DATE_FORMAT(CAST(" . $column . " AS DATETIME(6)), '%Y-%m-%d %H:%i:%s.%f')";
}

/**
 * The WHERE tail of one stream: high-water mark, day range and the keyset after
 * the cursor, in the three cases of virtusphere_vm_history_is_after().
 *
 * @param array{cursor:?array{at:string,rank:int,id:int}, from:?string, to:?string, mark:int} $window
 * @return array{0:string, 1:string, 2:list<int|string>}
 */
function repo_vm_history_window(string $kind, string $timeColumn, string $idColumn, array $window): array
{
    $time = 'CAST(' . $timeColumn . ' AS DATETIME(6))';
    $sql = ' AND ' . $timeColumn . ' IS NOT NULL AND ' . $idColumn . ' <= ?';
    $types = 'i';
    $params = [$window['mark']];
    if ($window['from'] !== null) {
        $sql .= ' AND ' . $time . ' >= ?';
        $types .= 's';
        $params[] = $window['from'];
    }
    if ($window['to'] !== null) {
        $sql .= ' AND ' . $time . ' < ?';
        $types .= 's';
        $params[] = $window['to'];
    }
    $cursor = $window['cursor'];
    if ($cursor !== null) {
        $rank = virtusphere_vm_history_rank($kind);
        if ($rank === $cursor['rank']) {
            $sql .= ' AND (' . $time . ' < ? OR (' . $time . ' = ? AND ' . $idColumn . ' < ?))';
            $types .= 'ssi';
            array_push($params, $cursor['at'], $cursor['at'], $cursor['id']);
        } else {
            $sql .= ' AND ' . $time . ($rank < $cursor['rank'] ? ' < ?' : ' <= ?');
            $types .= 's';
            $params[] = $cursor['at'];
        }
    }

    return [$sql, $types, $params];
}

/**
 * Runs one stream query: $select ends in its WHERE, the window and the order follow.
 *
 * @param list<int|string> $params
 * @return list<array<string,mixed>>
 */
function repo_vm_history_fetch(mysqli $db, string $kind, string $select, string $types, array $params, string $timeColumn, string $idColumn, array $window, int $limit): array
{
    [$tail, $tailTypes, $tailParams] = repo_vm_history_window($kind, $timeColumn, $idColumn, $window);
    $stmt = $db->prepare($select . $tail . ' ORDER BY sort_at DESC, sort_id DESC LIMIT ?');
    $all = [...$params, ...$tailParams, $limit];
    $stmt->bind_param($types . $tailTypes . 'i', ...$all);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * The direct VM events of the change audit: the registry events whose object is a
 * VM, minus every event that can land in a security category (derived from
 * VIRTUSPHERE_LOG_TABS, never listed). Leading column of the
 * deploy_logs_event_object_time index.
 *
 * @return list<string>
 */
function repo_vm_history_audit_events(): array
{
    $security = VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY];
    $events = [];
    foreach (audit_event_registry() as $code => $definition) {
        $categories = array_filter([$definition['category'] ?? null, ...($definition['categories'] ?? []), ...array_values($definition['categoryById'] ?? [])]);
        if (in_array('vm', $definition['objects'], true) && $categories !== [] && array_intersect($categories, $security) === []) {
            $events[] = (string) $code;
        }
    }

    return $events;
}

/**
 * The stream readers, kind => callable(mysqli, vmId, window, limit). A test may
 * replace one to prove that a failing source leaves the others readable.
 *
 * @return array<string, callable(mysqli,int,array,int):list<array<string,mixed>>>
 */
function repo_vm_history_streams(): array
{
    $jobSelect = 'SELECT j.id AS sort_id, %s AS sort_at, j.id, j.status, j.payload_json, j.created_at, j.started_at, j.updated_at
        FROM deploy_job_vm_states s INNER JOIN deploy_jobs j ON j.id = s.job_id WHERE s.vm_id = ?';
    $packageSelect = 'SELECT r.id AS sort_id, %s AS sort_at, r.id, LOWER(BIN_TO_UUID(r.run_id)) AS run_id,
            r.project_name, r.package_version, r.first_received_at, r.completed_received_at, r.wrapper_result,
            (SELECT COUNT(*) FROM deploy_package_step_results x WHERE x.package_run_id = r.id AND x.result = \'fail\') AS reported_failures
        FROM deploy_package_runs r JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        WHERE r.vm_id = ? AND m.expires_at > UTC_TIMESTAMP(6)';
    $job = static fn (string $kind, string $time, string $extra, array $extraParams) => static function (mysqli $db, int $vmId, array $window, int $limit) use ($kind, $time, $extra, $extraParams, $jobSelect): array {
        $rows = repo_vm_history_fetch($db, $kind, sprintf($jobSelect, repo_vm_history_sort_at($time)) . $extra, 'i' . str_repeat('s', count($extraParams)), [$vmId, ...$extraParams], $time, 'j.id', $window, $limit);

        return array_map(static function (array $row): array {
            $row['mode'] = deploy_job_stored_mode(json_decode((string) ($row['payload_json'] ?? ''), true));
            unset($row['payload_json']);

            return $row;
        }, $rows);
    };
    $package = static fn (string $kind, string $time) => static fn (mysqli $db, int $vmId, array $window, int $limit): array
        => repo_vm_history_fetch($db, $kind, sprintf($packageSelect, repo_vm_history_sort_at($time)), 'i', [$vmId], $time, 'r.id', $window, $limit);
    $terminal = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;

    return [
        VIRTUSPHERE_VM_TIMELINE_STATUS => static fn (mysqli $db, int $vmId, array $window, int $limit): array => repo_vm_history_fetch(
            $db,
            VIRTUSPHERE_VM_TIMELINE_STATUS,
            'SELECT e.id AS sort_id, ' . repo_vm_history_sort_at('e.created_at') . ' AS sort_at, e.id, e.lifecycle_state, e.mecm_sync_state,
                e.legacy_status, e.note, e.created_at, u.name AS actor_name
             FROM deploy_vm_status_events e LEFT JOIN deploy_users u ON u.id = e.created_by WHERE e.vm_id = ?',
            'i',
            [$vmId],
            'e.created_at',
            'e.id',
            $window,
            $limit
        ),
        VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED => $job(
            VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED,
            'j.updated_at',
            ' AND j.status IN (' . implode(', ', array_fill(0, count($terminal), '?')) . ')',
            $terminal
        ),
        VIRTUSPHERE_VM_TIMELINE_JOB_STARTED => $job(VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, 'COALESCE(j.started_at, j.created_at)', '', []),
        VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED => $package(VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED, 'r.completed_received_at'),
        VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED => $package(VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, 'r.first_received_at'),
        VIRTUSPHERE_VM_TIMELINE_CLIENT => static fn (mysqli $db, int $vmId, array $window, int $limit): array => repo_vm_history_fetch(
            $db,
            VIRTUSPHERE_VM_TIMELINE_CLIENT,
            'SELECT c.id AS sort_id, ' . repo_vm_history_sort_at('c.created_at') . ' AS sort_at, c.id, c.phase, c.event, c.detail, c.created_at
             FROM deploy_client_events c WHERE c.vm_id = ?',
            'i',
            [$vmId],
            'c.created_at',
            'c.id',
            $window,
            $limit
        ),
        VIRTUSPHERE_VM_HISTORY_AUDIT => 'repo_vm_history_audit',
    ];
}

/**
 * The change audit of one VM: its direct rows and the rows that name it in
 * `vm_ids` (bulk change, convergence sweep; index of migration 0069). UNION, so a
 * row reached both ways counts once. Never a security category.
 *
 * @return list<array<string,mixed>>
 */
function repo_vm_history_audit(mysqli $db, int $vmId, array $window, int $limit): array
{
    $events = repo_vm_history_audit_events();
    $security = VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY];
    $columns = 'SELECT l.id AS sort_id, ' . repo_vm_history_sort_at('l.created_at') . ' AS sort_at, l.id, l.created_at, l.category,
            l.log_message, l.event_code, l.object_type, l.object_id, l.result, l.context_json, u.name AS actor_name';
    [$tail, $tailTypes, $tailParams] = repo_vm_history_window(VIRTUSPHERE_VM_HISTORY_AUDIT, 'l.created_at', 'l.id', $window);
    $direct = $columns . ' FROM deploy_logs l LEFT JOIN deploy_users u ON u.id = l.user_id
        WHERE l.event_code IN (' . implode(', ', array_fill(0, count($events), '?')) . ') AND l.object_type = \'vm\' AND l.object_id = ?' . $tail;
    $named = $columns . ' FROM deploy_log_vm_refs x INNER JOIN deploy_logs l ON l.id = x.log_id LEFT JOIN deploy_users u ON u.id = l.user_id
        WHERE x.vm_id = ? AND l.category NOT IN (' . implode(', ', array_fill(0, count($security), '?')) . ')' . $tail;
    $stmt = $db->prepare('(' . $direct . ' ORDER BY sort_at DESC, sort_id DESC LIMIT ?) UNION (' . $named
        . ' ORDER BY sort_at DESC, sort_id DESC LIMIT ?) ORDER BY sort_at DESC, sort_id DESC LIMIT ?');
    $params = [...$events, (string) $vmId, ...$tailParams, $limit, $vmId, ...$security, ...$tailParams, $limit, $limit];
    $types = str_repeat('s', count($events)) . 's' . $tailTypes . 'i' . 'i' . str_repeat('s', count($security)) . $tailTypes . 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * The highest id of each source when the chronicle opens (high-water mark). A
 * source that cannot be read is null and reported as an error.
 *
 * @param list<string> $sources
 * @return array<string, ?int>
 */
function repo_vm_history_marks(mysqli $db, int $vmId, array $sources): array
{
    $queries = [
        VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS => 'SELECT COALESCE(MAX(id), 0) FROM deploy_vm_status_events WHERE vm_id = ?',
        VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB => 'SELECT COALESCE(MAX(job_id), 0) FROM deploy_job_vm_states WHERE vm_id = ?',
        VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE => 'SELECT COALESCE(MAX(id), 0) FROM deploy_package_runs WHERE vm_id = ?',
        VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT => 'SELECT COALESCE(MAX(id), 0) FROM deploy_client_events WHERE vm_id = ?',
        VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT => 'SELECT COALESCE(MAX(id), 0) FROM deploy_logs WHERE ? > 0',
    ];
    $marks = array_fill_keys(VIRTUSPHERE_VM_HISTORY_SOURCES, null);
    foreach ($sources as $source) {
        try {
            $marks[$source] = (int) repo_scalar($db, $queries[$source], 'i', [$vmId]);
        } catch (Throwable $exception) {
            repo_vm_history_log_failure($source, $vmId, $exception);
        }
    }

    return $marks;
}

/**
 * One page of the chronicle. $sources are the permitted, selected sources; $cursor
 * continues a page (with its marks), null opens a new snapshot.
 *
 * @param list<string> $sources
 * @param array{position:array{at:string,rank:int,id:int}, marks:array<string,?int>}|null $cursor
 * @param array<string, callable>|null $streams test seam, default repo_vm_history_streams()
 * @return array{entries:list<array<string,mixed>>, more:bool, next:?string, failed:list<string>}
 */
function repo_vm_history_page(mysqli $db, int $vmId, array $sources, ?string $fromUtc, ?string $toUtc, ?array $cursor, int $pageSize = VIRTUSPHERE_VM_HISTORY_PAGE_SIZE, ?array $streams = null): array
{
    $marks = $cursor['marks'] ?? repo_vm_history_marks($db, $vmId, $sources);
    $streams ??= repo_vm_history_streams();
    $rows = [];
    $failed = [];
    foreach (VIRTUSPHERE_VM_HISTORY_STREAMS as $kind => $source) {
        if (!in_array($source, $sources, true)) {
            continue;
        }
        if ($marks[$source] === null) {
            $failed[$source] = $source;
            continue;
        }
        $window = ['cursor' => $cursor['position'] ?? null, 'from' => $fromUtc, 'to' => $toUtc, 'mark' => $marks[$source]];
        try {
            $rows[$kind] = ($streams[$kind])($db, $vmId, $window, $pageSize + 1);
        } catch (Throwable $exception) {
            repo_vm_history_log_failure($kind, $vmId, $exception);
            $failed[$source] = $source;
        }
    }
    $page = virtusphere_vm_history_merge($rows, $pageSize);
    $last = $page['entries'] === [] ? null : $page['entries'][count($page['entries']) - 1];

    return [
        'entries' => $page['entries'],
        'more' => $page['more'],
        'next' => $page['more'] && $last !== null ? virtusphere_vm_history_cursor_encode(virtusphere_vm_history_position($last), $marks) : null,
        'failed' => array_values($failed),
    ];
}

function repo_vm_history_log_failure(string $part, int $vmId, Throwable $exception): void
{
    $refId = function_exists('virtusphere_error_reference') ? virtusphere_error_reference() : 'no-ref';
    error_log(virtusphere_redact_log_text(sprintf('[vm_history] ref=%s source=%s vm_id=%d: %s', $refId, $part, $vmId, $exception->getMessage())));
}
