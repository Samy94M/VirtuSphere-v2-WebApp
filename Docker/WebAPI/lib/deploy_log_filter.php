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

const VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH = 120;

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
 * @return array{q:string,source:string,phase:string,active:bool}
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

    return [
        'q' => $needle,
        'source' => $source,
        'phase' => $phase,
        'active' => $needle !== '' || $source !== '' || $phase !== '',
    ];
}

/**
 * The repository arguments for a filter. One derivation, so the table and any
 * later reader cannot disagree about what was asked.
 *
 * @param array{q:string,source:string,phase:string,active:bool} $filter
 * @param array{phases:list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>,current:?string} $timeline
 * @return array{needle:string,streams:list<string>,from_seq:?int,to_seq:?int}
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

    return ['needle' => $filter['q'], 'streams' => $streams, 'from_seq' => $from, 'to_seq' => $to];
}

/**
 * The URL of this job's log with the given filter, built from the same shape
 * the reader validates. A hand-written query string is what lets a filter and
 * its link drift apart.
 *
 * @param array{q:string,source:string,phase:string,active:bool} $filter
 */
function deploy_log_filter_url(int $jobId, array $filter): string
{
    $params = ['id' => (string) $jobId];
    foreach (['q', 'source', 'phase'] as $key) {
        if ($filter[$key] !== '') {
            $params[$key] = $filter[$key];
        }
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
