<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/log_rotation.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/client_events.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/catalog.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/package_run_maintenance.php';

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
        maintenance_worker_retention($db, $failures);
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
        fwrite(STDERR, '[maintenance-worker] job ' . $name . ' failed: ' . virtusphere_redact_log_text($exception->getMessage()) . "\n");
    }
}

/**
 * LG-18 / LG-19: the retention purges. Each purge is a job of its own, so a failing
 * one is named and the others still run, as the docblock of this module promises.
 * Their counts go into ONE `system` audit row per run, written only when something
 * was deleted, so the purge of the security window is on the record and not only
 * on STDOUT.
 *
 * @param list<string> $failures
 */
function maintenance_worker_retention(mysqli $db, array &$failures): void
{
    $purged = [];
    // Portal audit log, pruned per category (ADR-0026): security categories keep a
    // year, everything else a quarter. The two windows are counted apart.
    maintenance_worker_job('retention:portal_logs', $failures, static function () use ($db, &$purged): void {
        $windows = [];
        removeLog($db, VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS, VIRTUSPHERE_LOG_RETENTION_DAYS, $windows);
        $purged['portal_logs_security'] = $windows['security'];
        $purged['portal_logs_general'] = $windows['general'];
    });

    // Independent of each other and of the audit log above. The login attempts are a
    // 15-minute lockout counter, not an archive (ADR-0026); the job log and system job
    // purges cascade what they own; the VM status history and package diagnostics
    // expire on their own windows.
    $purges = [
        'client_events' => static fn (): int => (int) repo_purge_client_events($db),
        'login_attempts' => static fn (): int => (int) repo_purge_login_attempts($db),
        'retired_packages' => static fn (): int => (int) repo_purge_retired_packages($db),
        'retired_os' => static fn (): int => (int) repo_purge_retired_os($db),
        'job_log_lines' => static fn (): int => (int) repo_purge_deploy_job_logs($db),
        'finished_system_jobs' => static fn (): int => (int) repo_purge_finished_system_jobs($db),
        'status_events' => static fn (): int => (int) repo_purge_vm_status_events($db),
        'package_report_runs' => static fn (): int => (int) repo_purge_expired_package_runs($db),
    ];
    foreach ($purges as $name => $purge) {
        maintenance_worker_job('retention:' . $name, $failures, static function () use ($name, $purge, &$purged): void {
            $purged[$name] = $purge();
        });
    }

    $items = [];
    foreach ($purged as $name => $count) {
        if ($count > 0) {
            $items[] = $name . ': ' . $count;
        }
    }
    if ($items === []) {
        return;
    }
    fwrite(STDOUT, '[maintenance-worker] retention purged ' . implode(', ', $items) . "\n");
    // The deletes are committed already, so an audit row that cannot be stored cannot
    // undo them. It is reported as a failed job instead, which the traffic light shows.
    maintenance_worker_job('retention:audit', $failures, static function () use ($db, $items): void {
        if (!audit_event($db, VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED, 'maintenance', 'retention', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
            'items' => audit_fit_string_list($items),
            'item_count' => count($items),
        ], null, 'cli')) {
            throw new RuntimeException('retention audit row was not stored');
        }
    });
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
            audit_event($db, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_WARNING, [
                'vm_count' => count($vmIds),
                'vm_ids' => array_slice($vmIds, 0, VIRTUSPHERE_AUDIT_ID_LIST_MAX),
                'reason' => 'stuck in deploying without an active deploy job',
            ], null, 'cli');
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] sweep audit failed: ' . virtusphere_redact_log_text($exception->getMessage()) . "\n");
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
    maintenance_worker_record_transitions($db, $state, repo_integration_status_rows($db));
}

/**
 * The transition decision of one pass, over the rows it was given. Split from the
 * repo read so a test can drive the failure branch with a row it chooses.
 *
 * LG-06: a state is remembered only after its transition row is stored. The old
 * order remembered it first, so a failed insert left a STDERR line and the next
 * pass compared against the new state, and the transition was never logged. A row
 * that is not stored keeps the old state, so the next pass sees the same transition
 * again, and the pass counts as failed until then.
 *
 * @param list<array<string,mixed>> $rows
 */
function maintenance_worker_record_transitions(mysqli $db, array &$state, array $rows): void
{
    $benign = ['ok', 'legacy'];
    $unrecorded = [];
    foreach ($rows as $entry) {
        $source = (string) $entry['source'];
        $newState = (string) $entry['state'];
        $oldState = $state['states'][$source] ?? null;

        // Both benign (ok/legacy) means a script-rollout wobble, not a health
        // change; a problem state on either side is a real transition to log.
        $logged = $oldState !== null
            && $oldState !== $newState
            && !(in_array($oldState, $benign, true) && in_array($newState, $benign, true));
        if (!$logged) {
            $state['states'][$source] = $newState;
            continue;
        }

        $stored = false;
        try {
            // The category comes from the registry's closed source map, not
            // from "maintenance or else MECM". That fallback filed every future
            // non-MECM source under `mecm`, so a deploy-worker outage would
            // have arrived in the tab an operator opens to read MECM sync.
            $stored = audit_event($db, VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE, 'integration_source', $source, in_array($newState, $benign, true)
                ? VIRTUSPHERE_AUDIT_RESULT_RECOVERED
                : VIRTUSPHERE_AUDIT_RESULT_WARNING, [
                    'old_state' => $oldState,
                    'new_state' => $newState,
                ], null, 'cli');
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] transition audit failed: ' . virtusphere_redact_log_text($exception->getMessage()) . "\n");
        }
        if ($stored) {
            $state['states'][$source] = $newState;
        } else {
            $unrecorded[] = $source;
        }
    }

    if ($unrecorded !== []) {
        throw new RuntimeException('transition not recorded for ' . implode(', ', $unrecorded) . '; it is retried on the next pass');
    }
}
