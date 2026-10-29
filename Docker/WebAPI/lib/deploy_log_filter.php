<?php

declare(strict_types=1);

// The validated filter of one job-log view (Etappe 13).
//
// One struct per request, built once and read by everything: the query, the
// visible filter state, the notice above the table and the links back out. The
// log CSV export learned the same lesson one level up - a view and the thing
// that produced it must not be two derivations of the same query string.
//
// The source options are the three DISPLAY sources, not the five stored stream
// values. `stdout` and `stderr` were never two channels (the remote command
// redirects with `2>&1`), so offering to separate them would offer something
// the transport cannot deliver; both legacy values therefore fold into the
// Ansible option, which is what deploy_job_log_source_label() already shows.
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_log_phases.php';
require_once __DIR__ . '/deploy_log_severity.php';

const VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH = 120;

/** The id of the filter panel, the target of every jump into the log. */
const VIRTUSPHERE_DEPLOY_LOG_FILTER_ANCHOR = 'deploy-log-filter';

// The head of an Ansible failure line: `fatal: [host]: FAILED!`, `fatal:
// [host]: UNREACHABLE!` or, for one loop item, `failed: [host] (item=x)`. The
// worker keeps the last such line of a failed step as the terminal reason
// detail (FC2-05), and the job view searches the log for the same head.
const VIRTUSPHERE_ANSIBLE_FAILURE_HEAD_PATTERN = '/(?:fatal|failed): \[[^\]\r\n]+\](?: \(item=[^)\r\n]*\))?(?:: (?:FAILED|UNREACHABLE)!)?/';

/**
 * The log search that finds the failure a terminal reason detail quotes, or
 * null when the detail holds no Ansible failure line.
 */
function deploy_log_failure_needle(?string $detail): ?string
{
    if ($detail === null || preg_match(VIRTUSPHERE_ANSIBLE_FAILURE_HEAD_PATTERN, $detail, $head) !== 1) {
        return null;
    }

    return mb_substr($head[0], 0, VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH, 'UTF-8');
}

/**
 * The display sources a reader may filter by, each mapped to the stored stream
 * values it covers.
 *
 * @return array<string, list<string>>
 */
function deploy_log_filter_sources(): array
{
    return [
        VIRTUSPHERE_DEPLOY_LOG_ANSIBLE => array_merge(
            [VIRTUSPHERE_DEPLOY_LOG_ANSIBLE],
            VIRTUSPHERE_DEPLOY_LOG_LEGACY_STREAMS
        ),
        VIRTUSPHERE_DEPLOY_LOG_SYSTEM => [VIRTUSPHERE_DEPLOY_LOG_SYSTEM],
        VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR => [VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR],
    ];
}

/**
 * Reads the filter out of the query string.
 *
 * Every value is validated against a closed set here, not at the query: an
 * unknown source or a phase this job never entered is DROPPED rather than
 * carried into the SQL, so a hand-edited URL narrows the view to nothing
 * visible instead of quietly answering a different question. The search term is
 * the one free value and is bounded; it reaches the repository as a LIKE
 * parameter and never as SQL.
 *
 * @param array<string,mixed> $query typically $_GET
 * @param list<string> $phaseNames the playbooks this job actually ran
 * `errors` is the "errors only" switch (E1): it keeps the lines
 * deploy_log_line_severity() marks and combines with the other three.
 *
 * @return array{q:string,source:string,phase:string,errors:bool,active:bool}
 */
function deploy_log_filter_from_query(array $query, array $phaseNames): array
{
    $needle = trim((string) ($query['q'] ?? ''));
    if (mb_strlen($needle, 'UTF-8') > VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH) {
        $needle = mb_substr($needle, 0, VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH, 'UTF-8');
    }

    $source = (string) ($query['source'] ?? '');
    if (!array_key_exists($source, deploy_log_filter_sources())) {
        $source = '';
    }

    $phase = (string) ($query['phase'] ?? '');
    if (!in_array($phase, $phaseNames, true)) {
        $phase = '';
    }
    $errors = ($query['errors'] ?? null) === '1';

    return [
        'q' => $needle,
        'source' => $source,
        'phase' => $phase,
        'errors' => $errors,
        'active' => $needle !== '' || $source !== '' || $phase !== '' || $errors,
    ];
}

/**
 * The repository arguments for a filter. One derivation, so the table and any
 * later reader cannot disagree about what was asked.
 *
 * @param array{q:string,source:string,phase:string,errors:bool,active:bool} $filter
 * @param array{phases:list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool,started_at:?string,finished_at:?string}>,current:?string} $timeline
 * @return array{needle:string,streams:list<string>,from_seq:?int,to_seq:?int,any_of:list<string>}
 */
function deploy_log_filter_repo_args(array $filter, array $timeline): array
{
    $streams = $filter['source'] === '' ? [] : deploy_log_filter_sources()[$filter['source']];
    $from = null;
    $to = null;
    if ($filter['phase'] !== '') {
        $range = deploy_log_phase_range($timeline, $filter['phase']);
        if ($range !== null) {
            [$from, $to] = $range;
        }
    }

    $anyOf = $filter['errors'] ? array_values(VIRTUSPHERE_DEPLOY_LOG_SEVERITY_MARKERS) : [];

    return ['needle' => $filter['q'], 'streams' => $streams, 'from_seq' => $from, 'to_seq' => $to, 'any_of' => $anyOf];
}

/**
 * The URL of this job's log with the given filter, built from the same shape
 * the reader validates. A hand-written query string is what lets a filter and
 * its link drift apart.
 *
 * @param array{q:string,source:string,phase:string,errors?:bool,active:bool} $filter
 */
function deploy_log_filter_url(int $jobId, array $filter): string
{
    $params = ['id' => (string) $jobId];
    foreach (['q', 'source', 'phase'] as $key) {
        if ($filter[$key] !== '') {
            $params[$key] = $filter[$key];
        }
    }
    if (!empty($filter['errors'])) {
        $params['errors'] = '1';
    }

    return 'deploy_log.php?' . http_build_query($params);
}

/**
 * The plain link to the page of lines older than $beforeSeq.
 *
 * The "load older" control was a `<button type="button">` and therefore did
 * nothing at all without JavaScript, while the endpoint had accepted
 * `before_seq` as a GET cursor the whole time: the capability existed and was
 * simply not offered. A log is the one page a person opens when something has
 * already gone wrong, which is the worst moment to require a working script.
 *
 * It carries no filter parameters, because a filtered view has no cursor at all
 * (the rows are matches, not the sequence), and the filter form hides this
 * control while it is active.
 */
function deploy_log_older_url(int $jobId, int $beforeSeq): string
{
    // Built into a variable first, like the function above. An inline array
    // literal in the call would put a quoted key and a variable between the
    // parentheses, which is the shape the CSP/SQL scanner treats as string
    // interpolation into a query, and a guard that has to be argued with at
    // every call site stops being a guard.
    $params = ['id' => (string) $jobId, 'before_seq' => (string) $beforeSeq];

    return 'deploy_log.php?' . http_build_query($params);
}

/**
 * The filtered view of one create unit's FAIL (or HOLD) lines, every attempt
 * included (E1, 29.10.2026). A search link, not an anchor into the page: the
 * line may have left the bounded window long ago, and the link works without
 * JavaScript.
 *
 * @param array<string,mixed> $finding one entry of deploy_create_progress_finding()
 */
function deploy_log_create_unit_url(int $jobId, array $finding): string
{
    $filter = [
        'q' => deploy_log_create_unit_needle((int) $finding['position'], (int) $finding['total'], (string) $finding['severity']),
        'source' => VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
        'phase' => '',
        'errors' => false,
        'active' => true,
    ];

    return deploy_log_jump_url($jobId, $filter);
}

/**
 * A link that jumps to a place in the log: the filter URL plus the anchor of
 * the filter panel, so the reader lands on the hit count with the matching
 * lines right below instead of at the top of a long page (browser check
 * 29.10.2026). Plain filter links keep no anchor.
 *
 * @param array{q:string,source:string,phase:string,errors:bool,active:bool} $filter
 */
function deploy_log_jump_url(int $jobId, array $filter): string
{
    return deploy_log_filter_url($jobId, $filter) . '#' . VIRTUSPHERE_DEPLOY_LOG_FILTER_ANCHOR;
}
