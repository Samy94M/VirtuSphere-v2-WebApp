<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/worker_heartbeat.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/deploy_worker_mission.php';

/**
 * The deploy worker's CLI shell: option parsing, the database connection with
 * its reconnect policy, the claim loop and the worker identity.
 *
 * Nothing here knows what a job does. That separation is what lets the two job
 * processors change without touching the loop's failure handling, which is the
 * part that decides whether a database outage costs a running playbook.
 */
function deploy_worker_options(array $argv): array
{
    $options = [
        'loop' => in_array('--loop', $argv, true),
        'once' => in_array('--once', $argv, true),
        'sleep' => VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS,
        'cleanup' => !in_array('--keep-local-artifacts', $argv, true),
    ];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--sleep=')) {
            $options['sleep'] = max(1, min(60, (int) substr($arg, 8)));
        }
    }

    if (!$options['loop']) {
        $options['once'] = true;
    }

    return $options;
}

function deploy_worker_main(array $argv): int
{
    $options = deploy_worker_options($argv);
    $workerId = deploy_worker_id();
    $db = deploy_worker_connect_db($options);

    do {
        worker_heartbeat_touch();
        try {
            deploy_worker_report_alive($db);
            $claimed = deploy_worker_run_once($db, $workerId, $options);
        } catch (mysqli_sql_exception $exception) {
            if ($options['once']) {
                throw $exception;
            }
            fwrite(STDERR, '[deploy-worker] Database error, reconnecting: ' . $exception->getMessage() . "\n");
            $db = deploy_worker_connect_db($options);
            // Sleep before retrying. `continue` used to skip it, so a PERMANENT
            // SQL error (a dropped grant, a full disk, a schema mismatch) turned
            // the loop into a hot spin: it reconnected and failed thousands of
            // times a second, filling the log and pinning a core, and nothing in
            // the portal said anything at all. The reconnect helper waits on its
            // own attempts, but a successful reconnect followed by a failing query
            // never reached it.
            sleep((int) $options['sleep']);
            continue;
        }
        if ($options['once']) {
            return $claimed ? 0 : 2;
        }
        if (!$claimed) {
            sleep((int) $options['sleep']);
        }
    } while (true);
}

function deploy_worker_connect_db(array $options): mysqli
{
    // In --loop mode the worker must survive MySQL restarts and slow stack
    // startups instead of exiting; --once keeps failing fast for tooling.
    $maxAttempts = $options['once'] ? 3 : 0;
    $attempt = 0;

    while (true) {
        $attempt++;
        try {
            $db = db(true);
            // Every connect AND every reconnect: the gap in front of this
            // moment was unobserved, so the reaper waits out its grace before
            // it calls anybody else dead (deploy_reap_observer_is_blind).
            deploy_reap_observer_since(time());

            return $db;
        } catch (mysqli_sql_exception $exception) {
            if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
                throw $exception;
            }
            fwrite(STDERR, '[deploy-worker] Database not reachable (attempt ' . $attempt . '): ' . $exception->getMessage() . "\n");
            // Waiting out a DB restart is a healthy worker state (AP8).
            worker_heartbeat_touch();
            sleep(min(30, 2 * $attempt));
        }
    }
}

function deploy_worker_run_once(mysqli $db, string $workerId, array $options): bool
{
    deploy_worker_reap_stale_jobs($db);

    $job = repo_claim_next_deploy_job($db, $workerId);
    if ($job === null) {
        return false;
    }

    // ADR-0032: every log line this job produces carries the job's stored
    // correlation id; a legacy job without one falls back to the worker's
    // process id. Dropped again in finally so the ids of two consecutive
    // jobs cannot bleed into each other.
    virtusphere_correlation_adopt(isset($job['correlation_id']) ? (string) $job['correlation_id'] : null);
    try {
        deploy_worker_process_job($db, $job, $workerId, $options);
    } finally {
        virtusphere_correlation_adopt(null);
    }
    return true;
}

function deploy_worker_id(): string
{
    $host = gethostname() ?: 'worker';
    return $host . ':' . getmypid();
}
