<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/log_rotation.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/client_events.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/catalog.php';
require_once __DIR__ . '/repo/deploy_jobs.php';

/**
 * The maintenance worker's interval jobs. Lives outside lib/maintenance_worker.php
 * because that file is the CLI entrypoint and runs its main loop on require;
 * this module keeps the jobs requireable, so the integration tests can drive a
 * full maintenance pass against a real database (same split as
 * deploy_worker_outcome.php for the deploy worker).
 */

// True at most once per interval per job key ($state carries the bookkeeping).
function maintenance_worker_due(array &$state, string $job, int $intervalSeconds, bool $force): bool
{
    $now = time();
    $last = (int) ($state['last_run'][$job] ?? 0);
    if (!$force && ($now - $last) < $intervalSeconds) {
        return false;
    }
    $state['last_run'][$job] = $now;

    return true;
}

/**
 * One maintenance pass.
 *
 * The self-heartbeat used to be the FIRST thing in here, with a hardcoded
 * 'loop ok'. A pass that threw on every cycle therefore kept its own row fresh
 * and green: the one component whose job is to notice that other things are stuck
 * reported health it had not established, and the operator had no way to see that
 * the retention purges, the job reaper and the VM convergence sweep had all
 * stopped running. It is at the END now (see the finally below) and it carries
 * what actually happened.
 *
 * A failing individual job does not abort the pass - the others are independent
 * and must still run - but it does make the pass's verdict a failure.
 */
function maintenance_worker_run_once(mysqli $db, array &$state, bool $force = false): void
{
    $failures = [];
    $heartbeatDue = maintenance_worker_due($state, 'self-heartbeat', VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS, $force);

    try {
        maintenance_worker_run_jobs($db, $state, $force, $failures);
    } finally {
        if ($heartbeatDue) {
            repo_record_worker_result(
                $db,
                VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
                VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS,
                $failures === [],
                $failures === [] ? 'loop ok' : 'failed jobs: ' . implode(', ', $failures)
            );
        }
    }
}

/**
 * The pass's individual jobs. Each one records its own failure and lets the rest
 * continue: they are independent, and a broken retention purge must not stop the
 * job reaper.
 *
 * @param list<string> $failures
 */
function maintenance_worker_run_jobs(mysqli $db, array &$state, bool $force, array &$failures): void
{
    if (maintenance_worker_due($state, 'retention', VIRTUSPHERE_MAINTENANCE_RETENTION_INTERVAL_SECONDS, $force)) {
        maintenance_worker_job('retention', $failures, static function () use ($db): void {
            $purged = repo_purge_client_events($db);
            // Portal audit log, pruned per category (ADR-0026): security categories
            // keep a year, everything else a quarter.
            $purgedLogs = removeLog($db);
            // The login-attempt table is a 15-minute lockout counter, not an archive;
            // every attempt is also on the auth audit channel. Left unpruned it grew
            // without bound (it had no purge before). Its own short window (ADR-0026),
            // deliberately shorter than the audit windows.
            $purgedAttempts = repo_purge_login_attempts($db);
            $purgedPackages = repo_purge_retired_packages($db);
            $purgedOs = repo_purge_retired_os($db);
            // Streamed playbook output of FINISHED jobs, and the finished inventory
            // jobs themselves. Both grew without bound: the interval pull writes one
            // job per credential every few hours, each with its own output. The
            // system-job delete cascades the log rows it owns.
            $purgedJobLogs = repo_purge_deploy_job_logs($db);
            $purgedSystemJobs = repo_purge_finished_system_jobs($db);
            if ($purged + $purgedLogs + $purgedPackages + $purgedOs + $purgedAttempts + $purgedJobLogs + $purgedSystemJobs > 0) {
                fwrite(STDOUT, '[maintenance-worker] purged ' . $purged . ' client events, ' . $purgedLogs . ' portal log rows, ' . $purgedAttempts . ' login attempts, ' . $purgedPackages . ' retired packages, ' . $purgedOs . ' retired os rows, ' . $purgedJobLogs . ' job log lines, ' . $purgedSystemJobs . " finished system jobs\n");
            }
        });
    }

    if (maintenance_worker_due($state, 'log-rotation', VIRTUSPHERE_MAINTENANCE_LOG_ROTATION_INTERVAL_SECONDS, $force)) {
        maintenance_worker_job('log-rotation', $failures, static function (): void {
            // The two FILE logs the DB retention above cannot cover (ADR-0026
            // amendment). Errors surface through the pass verdict; the System
            // status deliberately shows no extra rotation row.
            $rotated = virtusphere_rotate_logs();
            if ($rotated > 0) {
                fwrite(STDOUT, '[maintenance-worker] rotated ' . $rotated . " log file(s)\n");
            }
        });
    }

    if (maintenance_worker_due($state, 'deploy-job-reap', VIRTUSPHERE_DEPLOY_REAP_INTERVAL_SECONDS, $force)) {
        maintenance_worker_job('deploy-job-reap', $failures, static function () use ($db): void {
            maintenance_worker_reap_deploy_jobs($db);
        });
    }

    if (maintenance_worker_due($state, 'deploy-vm-sweep', VIRTUSPHERE_DEPLOY_VM_SWEEP_INTERVAL_SECONDS, $force)) {
        maintenance_worker_job('deploy-vm-sweep', $failures, static function () use ($db): void {
            maintenance_worker_sweep_deploying_vms($db);
        });
    }

    if (maintenance_worker_due($state, 'esxi-inventory', VIRTUSPHERE_ESXI_INVENTORY_SCHEDULE_CHECK_SECONDS, $force)) {
        maintenance_worker_job('esxi-inventory', $failures, static function () use ($db): void {
            $enqueued = esxi_inventory_enqueue_due($db);
            if ($enqueued > 0) {
                fwrite(STDOUT, '[maintenance-worker] enqueued ' . $enqueued . " ESXi inventory job(s)\n");
            }
        });
    }

    maintenance_worker_job('audit-transitions', $failures, static function () use ($db, &$state): void {
        maintenance_worker_audit_transitions($db, $state);
    });
}

/**
 * Runs one maintenance job and records its name if it throws, without stopping
 * the pass. The jobs are independent, so a broken retention purge must not keep
 * the deploy-job reaper from running; but the pass's verdict is a failure, and
 * that is what reaches the System status row.
 *
 * @param list<string> $failures
 */
function maintenance_worker_job(string $name, array &$failures, callable $work): void
{
    try {
        $work();
    } catch (Throwable $exception) {
        $failures[] = $name;
        fwrite(STDERR, '[maintenance-worker] job ' . $name . ' failed: ' . $exception->getMessage() . "\n");
    }
}

// Second reaper (AP6): the deploy worker reaps only at its own loop start, so
// a worker that dies or hangs inside a job leaves its `running` row unreaped
// until ANOTHER deploy-worker iteration comes along - with a single worker
// container, never while it hangs. The maintenance worker runs the same reap
// (heartbeat check plus MAC-aware VM convergence) on its own interval.
function maintenance_worker_reap_deploy_jobs(mysqli $db): void
{
    // Deliberately no local catch any more: maintenance_worker_job() reports the
    // failure AND makes it the pass's verdict. Swallowing it here logged to stderr
    // and left the System status row green, which is the defect one level up.
    $reaped = deploy_worker_reap_stale_jobs($db);
    if ($reaped > 0) {
        fwrite(STDOUT, '[maintenance-worker] reaped ' . $reaped . " stale deploy job(s)\n");
    }
}

// Convergence sweep: VMs stuck in `deploying` whose mission has no active
// (queued/running) job converge to failed/failed. Covers the fault the deploy
// worker cannot handle itself - it died before its own failure/cancel path ran
// - and the heartbeat reaper misses, because the job is already terminal. One
// audit line per affected mission; the per-VM evidence is in the status events
// the repo sweep records.
function maintenance_worker_sweep_deploying_vms(mysqli $db): void
{
    // Same reasoning as the reaper: the pass's wrapper owns the failure, so it
    // reaches the traffic light instead of only stderr.
    $swept = repo_sweep_orphaned_deploying_vms($db);
    if ($swept === []) {
        return;
    }

    $byMission = [];
    foreach ($swept as $vm) {
        $byMission[(int) $vm['mission_id']][] = (int) $vm['vm_id'];
    }
    foreach ($byMission as $missionId => $vmIds) {
        try {
            audit($db, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, '[maintenance-worker] convergence sweep marked ' . count($vmIds) . ' VM(s) of mission id ' . $missionId . ' as failed: stuck in deploying without an active deploy job', null, 'cli');
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] sweep audit failed: ' . $exception->getMessage() . "\n");
        }
    }
    fwrite(STDOUT, '[maintenance-worker] convergence sweep marked ' . count($swept) . " deploying VM(s) as failed\n");
}

// Sparse audits: log only meaningful health transitions (OK <-> warning/fail/
// missing/unknown, and the recovery back), never individual runs (logging
// matrix, ADR-0018). The ok<->legacy flutter of a script rollout is not logged;
// the one-time legacy->V2 switch is logged once at report time by mecm_report.php.
function maintenance_worker_audit_transitions(mysqli $db, array &$state): void
{
    foreach (repo_integration_status_rows($db) as $entry) {
        $source = (string) $entry['source'];
        $newState = (string) $entry['state'];
        $oldState = $state['states'][$source] ?? null;
        $state['states'][$source] = $newState;

        if ($oldState === null || $oldState === $newState) {
            continue;
        }

        // Both benign (ok/legacy) means a script-rollout wobble, not a health
        // change; a problem state on either side is a real transition to log.
        $benign = ['ok', 'legacy'];
        if (in_array($oldState, $benign, true) && in_array($newState, $benign, true)) {
            continue;
        }

        try {
            $category = $source === VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE
                ? VIRTUSPHERE_LOG_CATEGORY_SYSTEM
                : VIRTUSPHERE_LOG_CATEGORY_MECM;
            audit($db, $category, '[maintenance-worker] integration ' . $source . ' state ' . $oldState . ' -> ' . $newState, null, 'cli');
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] transition audit failed: ' . $exception->getMessage() . "\n");
        }
    }
}
