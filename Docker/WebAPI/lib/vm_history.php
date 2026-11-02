<?php

declare(strict_types=1);

/**
 * The VM chronicle (SP3, admin plan F03): the pure owner of its order, its cursor
 * and its filter. The SQL readers live in lib/repo/vm_history.php and apply the
 * same keyset as virtusphere_vm_history_is_after(); the entries keep the shape of
 * virtusphere_vm_timeline() so the editor and the chronicle word them alike.
 * No database and no clock of its own.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/package_run_report_constants.php';
require_once __DIR__ . '/vm_timeline.php';
require_once __DIR__ . '/portal_work_context.php';

const VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT = 'audit';
const VIRTUSPHERE_VM_HISTORY_AUDIT = 'audit';
const VIRTUSPHERE_VM_HISTORY_PAGE_SIZE = 50;

/** The sources of the chronicle, in filter order. `audit` needs users.manage (F2). */
const VIRTUSPHERE_VM_HISTORY_SOURCES = [
    VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT,
    VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT,
];

/**
 * Stream kind => source. The key order is the rank: at an equal time the earlier
 * stream comes first, within a stream the higher id. A finished job or package
 * run comes before its start, because the list reads newest first.
 */
const VIRTUSPHERE_VM_HISTORY_STREAMS = [
    VIRTUSPHERE_VM_TIMELINE_STATUS => VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS,
    VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED => VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
    VIRTUSPHERE_VM_TIMELINE_JOB_STARTED => VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
    VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED => VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
    VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED => VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
    VIRTUSPHERE_VM_TIMELINE_CLIENT => VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT,
    VIRTUSPHERE_VM_HISTORY_AUDIT => VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT,
];

/** A stored time as `Y-m-d H:i:s.uuuuuu`, or null. This format sorts chronologically as text. */
function virtusphere_vm_history_at(string $value): ?string
{
    if (preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/', trim($value), $match) !== 1) {
        return null;
    }
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1], new DateTimeZone('UTC'));
    if ($time === false || $time->format('Y-m-d H:i:s') !== $match[1]) {
        return null;
    }

    return $match[1] . '.' . str_pad($match[2] ?? '', 6, '0');
}

function virtusphere_vm_history_rank(string $kind): int
{
    $rank = array_search($kind, array_keys(VIRTUSPHERE_VM_HISTORY_STREAMS), true);
    if ($rank === false) {
        throw new InvalidArgumentException('Unknown chronicle stream: ' . $kind);
    }

    return $rank;
}

/**
 * Whether an entry of stream $kind lies strictly after $cursor in the global
 * order (time descending, rank ascending, id descending). The SQL readers apply
 * the same three cases (repo_vm_history_window()).
 *
 * @param array{at:string,rank:int,id:int} $cursor
 */
function virtusphere_vm_history_is_after(string $kind, string $at, int $id, array $cursor): bool
{
    $rank = virtusphere_vm_history_rank($kind);
    if ($rank < $cursor['rank']) {
        return $at < $cursor['at'];
    }
    if ($rank > $cursor['rank']) {
        return $at <= $cursor['at'];
    }

    return $at < $cursor['at'] || ($at === $cursor['at'] && $id < $cursor['id']);
}

/**
 * Merges the rows the readers returned (each stream at most $pageSize + 1, newest
 * first, with `sort_at` and `sort_id`) into one page. `more` is exact: a stream
 * cut by its reader always leaves more than $pageSize candidates.
 *
 * @param array<string, list<array<string,mixed>>> $rowsByKind
 * @return array{entries:list<array{at:string,source:string,kind:string,id:int,row:array<string,mixed>}>, more:bool}
 */
function virtusphere_vm_history_merge(array $rowsByKind, int $pageSize): array
{
    $candidates = [];
    foreach ($rowsByKind as $kind => $rows) {
        $rank = virtusphere_vm_history_rank((string) $kind);
        foreach ($rows as $row) {
            $at = virtusphere_vm_history_at((string) ($row['sort_at'] ?? ''));
            if ($at === null) {
                continue;
            }
            $candidates[] = [
                'at' => $at,
                'source' => VIRTUSPHERE_VM_HISTORY_STREAMS[$kind],
                'kind' => (string) $kind,
                'id' => (int) $row['sort_id'],
                'rank' => $rank,
                'row' => $row,
            ];
        }
    }
    usort($candidates, static fn (array $a, array $b): int => [$b['at'], $a['rank'], $b['id']] <=> [$a['at'], $b['rank'], $a['id']]);

    $entries = [];
    foreach (array_slice($candidates, 0, max(1, $pageSize)) as $candidate) {
        unset($candidate['rank']);
        $entries[] = $candidate;
    }

    return ['entries' => $entries, 'more' => count($candidates) > $pageSize];
}

/**
 * The cursor position of an entry from virtusphere_vm_history_merge().
 *
 * @param array{at:string,kind:string,id:int} $entry
 * @return array{at:string,rank:int,id:int}
 */
function virtusphere_vm_history_position(array $entry): array
{
    return ['at' => $entry['at'], 'rank' => virtusphere_vm_history_rank($entry['kind']), 'id' => $entry['id']];
}

/**
 * `<YmdHisu>.<rank>.<id>.<one mark per source>`; a mark is the highest id of its
 * source when the chronicle was opened, `n` when it was not read. Numbers only.
 *
 * @param array{at:string,rank:int,id:int} $position
 * @param array<string, ?int> $marks
 */
function virtusphere_vm_history_cursor_encode(array $position, array $marks): string
{
    $parts = [str_replace(['-', ' ', ':', '.'], '', $position['at']), (string) $position['rank'], (string) $position['id']];
    foreach (VIRTUSPHERE_VM_HISTORY_SOURCES as $source) {
        $mark = $marks[$source] ?? null;
        $parts[] = $mark === null ? 'n' : (string) max(0, $mark);
    }

    return implode('.', $parts);
}

/**
 * The validated cursor, or null for anything this owner did not write.
 *
 * @return array{position:array{at:string,rank:int,id:int}, marks:array<string, ?int>}|null
 */
function virtusphere_vm_history_cursor_decode(string $cursor): ?array
{
    $parts = explode('.', $cursor);
    if (count($parts) !== 3 + count(VIRTUSPHERE_VM_HISTORY_SOURCES)
        || preg_match('/\A\d{20}\z/', $parts[0]) !== 1
        || preg_match('/\A\d{1,2}\z/', $parts[1]) !== 1
        || preg_match('/\A[1-9]\d{0,17}\z/', $parts[2]) !== 1
    ) {
        return null;
    }
    $at = virtusphere_vm_history_at(sprintf(
        '%s-%s-%s %s:%s:%s.%s',
        substr($parts[0], 0, 4), substr($parts[0], 4, 2), substr($parts[0], 6, 2),
        substr($parts[0], 8, 2), substr($parts[0], 10, 2), substr($parts[0], 12, 2), substr($parts[0], 14, 6)
    ));
    $rank = (int) $parts[1];
    if ($at === null || $rank >= count(VIRTUSPHERE_VM_HISTORY_STREAMS)) {
        return null;
    }
    $marks = [];
    foreach (VIRTUSPHERE_VM_HISTORY_SOURCES as $index => $source) {
        $mark = $parts[3 + $index];
        if ($mark !== 'n' && preg_match('/\A(0|[1-9]\d{0,17})\z/', $mark) !== 1) {
            return null;
        }
        $marks[$source] = $mark === 'n' ? null : (int) $mark;
    }

    return ['position' => ['at' => $at, 'rank' => $rank, 'id' => (int) $parts[2]], 'marks' => $marks];
}

/**
 * The filter of one request: the permitted sources (none selected = all permitted)
 * and an optional day range in the portal timezone, as an inclusive UTC lower and
 * an exclusive UTC upper bound. An invalid or reversed range is reported by field
 * and not applied; the typed text stays in the form.
 *
 * @param array<string,mixed> $query
 * @return array{sources:list<string>, from:string, to:string, from_utc:?string, to_utc:?string, errors:list<string>}
 */
function virtusphere_vm_history_filter(array $query, bool $canAudit, string $timezone): array
{
    $permitted = $canAudit
        ? VIRTUSPHERE_VM_HISTORY_SOURCES
        : array_values(array_diff(VIRTUSPHERE_VM_HISTORY_SOURCES, [VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT]));
    $requested = is_array($query['sources'] ?? null) ? $query['sources'] : [];
    $sources = array_values(array_filter($permitted, static fn (string $source): bool => in_array($source, $requested, true)));

    $from = is_string($query['from'] ?? null) ? trim($query['from']) : '';
    $to = is_string($query['to'] ?? null) ? trim($query['to']) : '';
    $errors = [];
    $fromDay = virtusphere_vm_history_day($from, $timezone);
    $toDay = virtusphere_vm_history_day($to, $timezone);
    if ($from !== '' && $fromDay === null) {
        $errors[] = 'from';
    }
    if ($to !== '' && $toDay === null) {
        $errors[] = 'to';
    }
    if ($fromDay !== null && $toDay !== null && $fromDay > $toDay) {
        $errors[] = 'range';
        $fromDay = $toDay = null;
    }
    $utc = static fn (?DateTimeImmutable $day): ?string => $day?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

    return [
        'sources' => $sources === [] ? $permitted : $sources,
        'from' => $from,
        'to' => $to,
        'from_utc' => $utc($fromDay),
        'to_utc' => $utc($toDay?->modify('+1 day')),
        'errors' => $errors,
    ];
}

/** Midnight of a `Y-m-d` day in $timezone, or null. */
function virtusphere_vm_history_day(string $value, string $timezone): ?DateTimeImmutable
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
        return null;
    }
    try {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone));
    } catch (Throwable) {
        return null;
    }

    return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
}

/** How many days a source keeps its rows, null when it has no age limit (jobs live with their mission). */
function virtusphere_vm_history_retention_days(string $source): ?int
{
    return match ($source) {
        VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS => VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS,
        VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE => VIRTUSPHERE_PACKAGE_REPORT_RETENTION_DAYS,
        VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT => VIRTUSPHERE_CLIENT_EVENT_RETENTION_DAYS,
        VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT => VIRTUSPHERE_LOG_RETENTION_DAYS,
        default => null,
    };
}

/**
 * The chronicle of one VM with the editor's work context. $filter carries the
 * query fields of virtusphere_vm_history_filter() as typed; $cursor continues a page.
 *
 * @param array<string,mixed> $workContext
 * @param array{sources?:list<string>, from?:string, to?:string} $filter
 */
function vm_history_url(int $missionId, int $vmId, array $workContext = [], array $filter = [], ?string $cursor = null): string
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM ids are required.');
    }
    $query = ['mission_id' => $missionId, 'vm_id' => $vmId];
    if (($filter['sources'] ?? []) !== []) {
        $query['sources'] = $filter['sources'];
    }
    foreach (['from', 'to'] as $field) {
        if (($filter[$field] ?? '') !== '') {
            $query[$field] = $filter[$field];
        }
    }
    if ($cursor !== null) {
        $query['cursor'] = $cursor;
    }

    return portal_work_context_append_url('vm_history.php?' . http_build_query($query), $workContext);
}
