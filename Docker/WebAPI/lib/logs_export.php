<?php

declare(strict_types=1);

require_once __DIR__ . '/log_filter.php';
require_once __DIR__ . '/portal_export.php';
require_once __DIR__ . '/repo/log.php';

/**
 * The audit-log CSV download.
 *
 * Three properties are the whole point of this module and none of them can be
 * left to the caller:
 *
 *  - the export and the table run the SAME query. Both are handed the one
 *    validated filter struct and both order by `l.id DESC`, so a download can
 *    never quietly cover a different set of rows than the screen it was started
 *    from. Passing the struct itself rather than an unpacked argument list is
 *    what keeps that true as the filter grows: a positional list of same-typed
 *    strings lets a caller swap two and get a working query for the wrong
 *    question.
 *  - the match count is established BEFORE the first row is streamed. Once
 *    output has begun no header can be added and no decision can be revised,
 *    so "was this capped" has to be known while it is still answerable.
 *  - the cap is announced, never silent. A file that stops at ten thousand rows
 *    with no sign of it is the worst outcome of the three: it reads as a
 *    complete answer to the question the operator asked.
 *
 * The cap is a *diagnostic* limit, not an authorization boundary: `users.manage`
 * already decided that this user may read every one of these rows. It exists so
 * one click cannot hold a PHP worker and a MySQL cursor open over an unbounded
 * result set.
 */

/** Rows per repository read while streaming; a page size, not a limit. */
const VIRTUSPHERE_LOG_EXPORT_CHUNK_ROWS = 500;

/**
 * Streams the export and exits. Never returns.
 *
 * @param array<string,mixed> $filter The validated struct (lib/log_filter.php).
 */
function logs_export_send_csv(mysqli $connection, array $filter, int $userId): never
{
    $export = logs_export_prepare($connection, $filter, $userId);
    $bounds = $export['bounds'];
    $rows = $export['rows'];
    $header = [
        __t('logs.th_id'), __t('logs.th_time'), __t('logs.th_category'),
        __t('logs.th_user'), __t('logs.th_ip'), __t('logs.th_message'),
    ];

    portal_send_csv('logs-' . $filter['tab'], $header, $rows, [
        'Total-Rows' => $export['total'],
        'Export-Limit' => $bounds['limit'],
        'Truncated' => $bounds['truncated'] ? 1 : 0,
    ]);
}

/**
 * Reads and audits one export before response streaming begins.
 *
 * @param array<string,mixed> $filter The validated struct (lib/log_filter.php).
 * @return array{rows:list<list<string>>,total:int,bounds:array{limit:int,exported:int,truncated:bool}}
 */
function logs_export_prepare(mysqli $connection, array $filter, ?int $userId): array
{
    $total = repo_count_logs($connection, $filter);
    $bounds = log_filter_export_bounds($total);
    $rows = logs_export_rows($connection, $filter, $bounds['exported']);

    // Exactly one audit row per export, written after the rows were read so the
    // download cannot contain its own audit line, and before the stream starts
    // so a failing insert is still an error page rather than a corrupt file.
    //
    // The context describes the export, never the filter's contents: a search
    // term is what the operator was looking for, and repeating it here would
    // build a second, unretained-by-anyone record of who searched for whom.
    // The fingerprint keys the same filter to the same value without being
    // reversible (log_filter_fingerprint()).
    audit_event(
        $connection,
        VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED,
        'log_view',
        $filter['tab'],
        VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
        [
            'filter_fingerprint' => log_filter_fingerprint($filter),
            'rows_exported' => count($rows),
            'total_rows' => $total,
            'limit' => $bounds['limit'],
            'truncated' => $bounds['truncated'],
        ],
        $userId
    );

    return ['rows' => $rows, 'total' => $total, 'bounds' => $bounds];
}

/**
 * Reads at most $max rows in bounded chunks, newest first.
 *
 * $max of 0 yields no read at all: `repo_recent_logs()` clamps its limit up to
 * 1, so asking it for nothing would return one row, and an export of an empty
 * result set would ship a data line.
 *
 * @param array<string,mixed> $filter The validated struct (lib/log_filter.php).
 * @return list<list<string>>
 */
function logs_export_rows(mysqli $connection, array $filter, int $max): array
{
    $csvRows = [];
    for ($offset = 0; $offset < $max; $offset += VIRTUSPHERE_LOG_EXPORT_CHUNK_ROWS) {
        $chunk = repo_recent_logs(
            $connection,
            $filter,
            min(VIRTUSPHERE_LOG_EXPORT_CHUNK_ROWS, $max - $offset),
            $offset
        );
        foreach ($chunk as $row) {
            $csvRows[] = [
                (string) ($row['id'] ?? ''),
                portal_format_timestamp((string) ($row['created_at'] ?? '')),
                log_category_label((string) ($row['category'] ?? '')),
                (string) ($row['user_name'] ?? ($row['user_id'] ?? '')),
                (string) ($row['ip'] ?? ''),
                (string) ($row['log_message'] ?? ''),
            ];
        }
        if (count($chunk) < VIRTUSPHERE_LOG_EXPORT_CHUNK_ROWS) {
            break;
        }
    }

    return $csvRows;
}
