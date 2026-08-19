<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

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
