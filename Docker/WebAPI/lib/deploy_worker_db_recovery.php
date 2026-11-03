<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_redaction.php';
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
 * The outcome of a finished remote command that could not be written, because the
 * database stayed unreachable for the whole wait. Its exit code exists only in
 * this process, so no verdict is written for it afterwards: a failure written now
 * would be a guess, and a run that exited 0 would read as failed. The reaper records
 * the job's remote outcome as unknown (see deploy_worker_failure_connection()).
 *
 * It cannot extend mysqli_sql_exception, which is final, so every place that
 * treats a database outage names it beside that class: the loop worker's
 * reconnect handler and the inventory failure classification.
 */
final class DeployWorkerOutcomeUnpersisted extends RuntimeException
{
}

/**
 * Opens the channel a job runs under. One factory for both job processors, so
 * there cannot be a second reconnect policy that behaves differently for
 * inventory pulls than for mission deploys.
 */
function deploy_worker_open_db_channel(mysqli $db, int $jobId, string $workerId, ?array $claim = null): DeployWorkerDbChannel
{
    return new DeployWorkerDbChannel($db, static fn (): mysqli => db(true), $jobId, $workerId,
        ops: new DeployWorkerDbOperations($claim));
}

/**
 * How many attempts the database wait of this run gets: a loop worker is a
 * service and may wait for the outcome of work that already ran, `--once` is
 * tooling and stays bounded.
 *
 * @param array<string, mixed> $options
 */
function deploy_worker_db_recover_attempts(array $options): int
{
    return !empty($options['once'])
        ? VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_ONCE
        : VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_LOOP;
}

/**
 * Seconds that a recovery of $attempts failed attempts sleeps in total: the
 * backoff after each failed attempt, with the doubling and the cap that
 * DeployWorkerDbChannel applies. A lower bound, because a channel that has been
 * failing since the outage started already holds a larger backoff.
 *
 * The loop budget is chosen against this number: the wait must not be shorter
 * than VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS (WK-04). No heartbeat can be written
 * to a dead database, so the alternative, a heartbeat kept alive during the wait,
 * would only touch the process file, which the reaper does not read.
 */
function deploy_worker_db_recover_wait_seconds(int $attempts): int
{
    $total = 0;
    $backoff = VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $backoff = min(VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MAX_SECONDS, $backoff * 2);
        $total += $backoff;
    }

    return $total;
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
 * @param (callable(int): void)|null $sleeper injected by tests; null sleeps for real
 */
function deploy_worker_settle_db_channel(DeployWorkerDbChannel $channel, array $options, ?int $remoteExitCode, ?callable $sleeper = null): void
{
    if ($channel->isConnected()) {
        return;
    }

    if ($channel->recover(deploy_worker_db_recover_attempts($options), $sleeper)) {
        return;
    }

    fwrite(STDERR, '[deploy-worker] deploy job ' . $channel->jobId() . ' finished remotely with exit code '
        . ($remoteExitCode === null ? 'unknown' : (string) $remoteExitCode)
        . ', but the database is still unreachable: this outcome could not be persisted. The job stays claimed until '
        . "this worker reaches the database again or the reaper converges it.\n");

    throw new DeployWorkerOutcomeUnpersisted('Database unreachable while persisting the outcome of deploy job ' . $channel->jobId() . '.');
}

/**
 * The connection a failure is written through, or null when no failure may be
 * written, because the database is still gone or the outcome was already given
 * up on.
 *
 * A live channel answers with its own handle. A channel that is down is
 * reconnected through the recovery budget. The dead handle is never returned: a
 * write through it throws again, which is how a failure used to escape its own
 * handler and leave the job to the reaper without its reason (WK-04).
 *
 * An outcome settle() could not persist returns null at once, even if the
 * database answers now. Its wait is spent, and a verdict written from here would
 * be a guess about a remote run whose exit code is gone.
 *
 * @param array<string, mixed> $options
 * @param (callable(int): void)|null $sleeper injected by tests; null sleeps for real
 */
function deploy_worker_failure_connection(DeployWorkerDbChannel $channel, array $options, Throwable $cause, ?callable $sleeper = null): ?mysqli
{
    if ($cause instanceof DeployWorkerOutcomeUnpersisted) {
        return null;
    }
    if ($channel->isConnected()) {
        return $channel->connection();
    }

    return $channel->recover(deploy_worker_db_recover_attempts($options), $sleeper) ? $channel->connection() : null;
}

/**
 * WK-04: a failure that cannot be written, because the database is still gone
 * after the wait. One redacted line on STDERR. The job stays claimed, and the
 * reaper converges it with its remote outcome recorded as unknown.
 */
function deploy_worker_log_unpersisted_failure(int $jobId, string $message): void
{
    fwrite(STDERR, '[deploy-worker] deploy job ' . $jobId . ' failed, but the database is still unreachable, so this failure '
        . 'could not be persisted. The job stays claimed until the reaper converges it, with its remote outcome unknown: '
        . virtusphere_redact_log_text($message) . "\n");
}
