<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

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
