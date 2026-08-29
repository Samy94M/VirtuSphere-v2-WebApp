<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_log_filter.php';
require_once __DIR__ . '/deploy_log_phases.php';
require_once __DIR__ . '/repo/deploy_jobs.php';

/**
 * Everything the HTML view of one job log needs, resolved once (Etappe 13).
 *
 * The two readings of a log are deliberately exclusive. Unfiltered, the page is
 * the live tail of 10A and the poller owns it. Filtered, the page is a bounded
 * search over everything still retained, live updates are off, and it says so:
 * a filtered view that kept following would let a reader watch a subset while
 * believing they were watching the run, and switching back would mark full-log
 * lines they never saw as read.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $query typically $_GET
 * @return array{
 *     timeline: array{phases:list<array{playbook:string,begin_seq:int,end_seq:?int,complete:bool}>,current:?string},
 *     phase_names: list<string>,
 *     filter: array{q:string,source:string,phase:string,active:bool},
 *     page: array<string,mixed>,
 *     logs: list<array<string,mixed>>,
 *     match_capped: bool
 * }
 */
function deploy_log_view_model(mysqli $db, array $job, array $query): array
{
    $jobId = (int) $job['id'];
    $timeline = deploy_log_phase_timeline(repo_deploy_job_log_step_markers($db, $jobId));
    $phaseNames = deploy_log_phase_names($timeline);
    $filter = deploy_log_filter_from_query($query, $phaseNames);

    if (!$filter['active']) {
        $page = repo_deploy_job_log_initial_tail($db, $jobId);

        return [
            'timeline' => $timeline,
            'phase_names' => $phaseNames,
            'filter' => $filter,
            'page' => $page,
            'logs' => $page['logs'],
            'match_capped' => false,
        ];
    }

    $args = deploy_log_filter_repo_args($filter, $timeline);
    $result = repo_deploy_job_log_search($db, $jobId, $args['needle'], $args['streams'], $args['from_seq'], $args['to_seq']);

    // A filtered page carries no cursor. Handing the poller an `after_seq` that
    // came from a filtered read is precisely how a follow ends up treating
    // skipped lines as seen, so the filtered view reports itself as a finished,
    // caught-up window with nothing older to fetch.
    return [
        'timeline' => $timeline,
        'phase_names' => $phaseNames,
        'filter' => $filter,
        'page' => [
            'logs' => $result['logs'],
            'oldest_seq' => 0,
            'newest_seq' => 0,
            'has_older' => false,
            'has_more' => false,
            'caught_up' => true,
        ],
        'logs' => $result['logs'],
        'match_capped' => $result['has_more'],
    ];
}

/** @param array{logs:array<int,array>,oldest_seq:?int,newest_seq:?int,has_older:bool,has_more:bool,caught_up:bool} $page */
function deploy_job_log_format_page(array $page): array
{
    foreach ($page['logs'] as &$entry) {
        $entry['seq'] = (int) $entry['seq'];
        $entry['created_at'] = portal_format_timestamp($entry['created_at'] ?? null);
        $entry['stream_label'] = deploy_job_log_source_label((string) ($entry['stream'] ?? ''));
    }
    unset($entry);

    return $page;
}

function deploy_job_log_cursor(string $name, bool $positive): ?int
{
    if (!array_key_exists($name, $_GET)) {
        return null;
    }
    $raw = $_GET[$name];
    if (!is_string($raw) || preg_match('/^\d+$/D', $raw) !== 1) {
        throw new InvalidArgumentException($name . ' must be an integer cursor.');
    }
    $value = (int) $raw;
    if (($positive && $value <= 0) || (!$positive && $value < 0)) {
        throw new InvalidArgumentException($name . ' is outside the cursor range.');
    }

    return $value;
}

function deploy_job_logs_are_pruned(array $job, array $logs): bool
{
    return $logs === []
        && in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)
        && (int) strtotime((string) ($job['updated_at'] ?? '') . ' UTC') < time() - VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS * 86400;
}

function deploy_job_log_empty_state(array $job, array $logs): string
{
    return deploy_job_logs_are_pruned($job, $logs) ? 'pruned' : 'empty';
}

function deploy_job_log_empty_message(string $state): string
{
    return $state === 'pruned'
        ? __t('deploy.output_pruned', ['days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS])
        : __t('deploy.no_output');
}

function deploy_job_log_raw_utc(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) {
        return $value;
    }
}

/**
 * How a stored job-log line's source is shown (Etappe 8).
 *
 * One helper for both readers - the server-rendered table and the poller's
 * JSON - because the label is a statement about the transport, and two copies
 * of it would eventually disagree about what the transport does.
 *
 * The statement: `stdout` and `stderr` were never two channels. The remote
 * command redirects with `2>&1`, so the streams are merged on the Ansible host
 * before the worker sees a byte, and a line stored as `stdout` may well have
 * been stderr. Showing the raw value invited a filter that cannot exist. Both
 * legacy values and the current `ansible` therefore read as one source, while
 * the worker's own two sources stay separate: `system` is this worker
 * narrating its steps, `worker_error` is its finding about the job.
 */
function deploy_job_log_source_label(string $stream): string
{
    if (in_array($stream, VIRTUSPHERE_DEPLOY_LOG_LEGACY_STREAMS, true)
        || $stream === VIRTUSPHERE_DEPLOY_LOG_ANSIBLE
    ) {
        return __t('deploy.log_source_ansible');
    }

    return match ($stream) {
        VIRTUSPHERE_DEPLOY_LOG_SYSTEM => __t('deploy.log_source_system'),
        VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR => __t('deploy.log_source_worker_error'),
        // A value from a future writer this build does not know. Shown raw
        // rather than hidden or guessed: an unlabelled line is still evidence.
        default => $stream,
    };
}
