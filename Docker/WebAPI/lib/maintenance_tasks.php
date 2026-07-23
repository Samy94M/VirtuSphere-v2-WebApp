<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/mecm_probe.php';
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

function maintenance_worker_run_once(mysqli $db, array &$state, bool $force = false): void
{
    if (maintenance_worker_due($state, 'self-heartbeat', VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS, $force)) {
        repo_touch_integration_heartbeat($db, VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'cli', VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS, 'loop ok');
    }

    if (maintenance_worker_due($state, 'mecm-probe', VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS, $force)) {
        maintenance_worker_probe($db);
    }

    if (maintenance_worker_due($state, 'retention', VIRTUSPHERE_MAINTENANCE_RETENTION_INTERVAL_SECONDS, $force)) {
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
    }

    if (maintenance_worker_due($state, 'deploy-job-reap', VIRTUSPHERE_DEPLOY_REAP_INTERVAL_SECONDS, $force)) {
        maintenance_worker_reap_deploy_jobs($db);
    }

    if (maintenance_worker_due($state, 'deploy-vm-sweep', VIRTUSPHERE_DEPLOY_VM_SWEEP_INTERVAL_SECONDS, $force)) {
        maintenance_worker_sweep_deploying_vms($db);
    }

    if (maintenance_worker_due($state, 'esxi-inventory', VIRTUSPHERE_ESXI_INVENTORY_SCHEDULE_CHECK_SECONDS, $force)) {
        try {
            $enqueued = esxi_inventory_enqueue_due($db);
            if ($enqueued > 0) {
                fwrite(STDOUT, '[maintenance-worker] enqueued ' . $enqueued . " ESXi inventory job(s)\n");
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] ESXi inventory scheduling failed: ' . $exception->getMessage() . "\n");
        }
    }

    maintenance_worker_audit_transitions($db, $state);
}

// Second reaper (AP6): the deploy worker reaps only at its own loop start, so
// a worker that dies or hangs inside a job leaves its `running` row unreaped
// until ANOTHER deploy-worker iteration comes along - with a single worker
// container, never while it hangs. The maintenance worker runs the same reap
// (heartbeat check plus MAC-aware VM convergence) on its own interval.
function maintenance_worker_reap_deploy_jobs(mysqli $db): void
{
    try {
        $reaped = deploy_worker_reap_stale_jobs($db);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[maintenance-worker] deploy job reaping failed: ' . $exception->getMessage() . "\n");

        return;
    }
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
    try {
        $swept = repo_sweep_orphaned_deploying_vms($db);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[maintenance-worker] deploy VM convergence sweep failed: ' . $exception->getMessage() . "\n");

        return;
    }
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

// Active reachability check of the MECM server. Target host: explicit setting
// override, else the source IP of the last device-sync heartbeat (zero-config).
function maintenance_worker_probe(mysqli $db): void
{
    mecm_probe_run($db);
}

/**
 * @return array{0: string, 1: int}|null
 */
function maintenance_worker_probe_target(mysqli $db): ?array
{
    $target = mecm_probe_target($db);
    if ($target['host'] === null) {
        return null;
    }

    return [$target['host'], $target['port']];
}

/**
 * @return array{0: bool, 1: string}
 */
function maintenance_worker_tcp_check(string $host, int $port, int $timeoutSeconds): array
{
    $result = mecm_probe_tcp_check($host, $port, $timeoutSeconds);

    return [$result['ok'], $result['detail']];
}

// Sparse audits: log only state transitions involving danger/missing (and the
// recovery back) - never individual heartbeats (logging matrix, ADR-0018).
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

        $involvedBad = in_array($oldState, ['danger', 'missing'], true) || in_array($newState, ['danger', 'missing'], true);
        if (!$involvedBad) {
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
