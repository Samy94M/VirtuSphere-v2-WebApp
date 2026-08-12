<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deploy_worker_db_channel.php';

/**
 * How a running job's channel is opened, and how long the worker is willing to
 * wait for the database once the remote command has ended.
 *
 * Separated from the channel itself (ADR-0006) because these two are policy, not
 * mechanics: the channel knows how to survive an outage, while the decision of
 * how many attempts a service may spend and what a one-shot tool does instead
 * belongs to the worker that parsed the CLI options.
 */

/**
 * Opens the channel a job runs under. One factory for both job processors, so
 * there cannot be a second reconnect policy that behaves differently for
 * inventory pulls than for mission deploys.
 */
function deploy_worker_open_db_channel(mysqli $db, int $jobId, string $workerId): DeployWorkerDbChannel
{
    return new DeployWorkerDbChannel($db, static fn (): mysqli => db(true), $jobId, $workerId);
}

/**
 * Settles the channel after the remote command has ended.
 *
 * This is the moment the plan turns on: the playbook is over, its exit code
 * exists only in this process, and the only remaining question is whether it can
 * be written down. A loop worker waits for that (it is a service and the job
 * stays claimed meanwhile); `--once` stays bounded and says explicitly that the
 * outcome could not be persisted, instead of exiting as if it had concluded.
 *
 * @param array<string, mixed> $options the worker's parsed CLI options
 */
function deploy_worker_settle_db_channel(DeployWorkerDbChannel $channel, array $options, ?int $remoteExitCode): void
{
    if ($channel->isConnected()) {
        return;
    }

    $attempts = !empty($options['once'])
        ? VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_ONCE
        : VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_LOOP;
    if ($channel->recover($attempts)) {
        return;
    }

    fwrite(STDERR, '[deploy-worker] deploy job ' . $channel->jobId() . ' finished remotely with exit code '
        . ($remoteExitCode === null ? 'unknown' : (string) $remoteExitCode)
        . ', but the database is still unreachable: this outcome could not be persisted. The job stays claimed until '
        . "this worker reaches the database again or the reaper converges it.\n");

    throw new mysqli_sql_exception('Database unreachable while persisting the outcome of deploy job ' . $channel->jobId() . '.');
}
