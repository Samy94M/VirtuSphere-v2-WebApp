<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/worker_heartbeat.php';
require_once __DIR__ . '/deploy_worker_runtime.php';

/**
 * The four database operations the channel performs, behind one seam.
 *
 * Not indirection for its own sake: the channel's whole contract is what it does
 * when these throw, and there is no honest way to make a real mysqli fail on
 * demand in a unit test. Injecting the operations is what lets the outage,
 * backoff, spool, replay and lost-lock paths be proven deterministically,
 * without a database and without waiting out a real backoff. The default
 * implementation is the production one and adds nothing of its own.
 *
 * Own module rather than a class next to the channel (ADR-0006): this is the
 * adapter to the repository layer, and it is the only part of the channel a test
 * ever replaces. The channel itself knows nothing about SQL.
 *
 * Every method here talks to the database, so every one of them can throw
 * `mysqli_sql_exception` - that is the event the channel exists to survive, and
 * saying so is what keeps its catch blocks from reading as dead code.
 */
class DeployWorkerDbOperations
{
    /** @throws mysqli_sql_exception when the database is unreachable */
    public function appendLog(mysqli $db, int $jobId, string $stream, string $line): void
    {
        repo_append_deploy_job_log($db, $jobId, $stream, $line);
    }

    /** @throws mysqli_sql_exception when the database is unreachable */
    public function touchJobHeartbeat(mysqli $db, int $jobId, string $workerId): void
    {
        repo_touch_deploy_job_heartbeat($db, $jobId, $workerId);
    }

    /** @throws mysqli_sql_exception when the database is unreachable */
    public function heartbeatTick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds): void
    {
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, $intervalSeconds);
    }

    /**
     * @throws DeployWorkerCancelled when the job is no longer ours to finish
     * @throws mysqli_sql_exception when the database is unreachable
     */
    public function assertJobIsOurs(mysqli $db, int $jobId, string $workerId): void
    {
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);
    }

    /** The container-level liveness file; never touches the database. */
    public function touchProcessHeartbeat(): void
    {
        worker_heartbeat_touch();
    }
}
