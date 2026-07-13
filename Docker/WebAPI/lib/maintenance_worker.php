<?php

declare(strict_types=1);

// Maintenance worker (ADR-0018): second loop container next to deploy_worker.
// Jobs with per-job interval bookkeeping: self heartbeat, active MECM
// reachability probe (TCP connect), retention purges (client events + logs)
// and sparse transition audits (state changes only - never single heartbeats).

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/client_events.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/catalog.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';

function maintenance_worker_options(array $argv): array
{
    $options = [
        'loop' => in_array('--loop', $argv, true),
        'once' => in_array('--once', $argv, true),
        'sleep' => VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS,
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

function maintenance_worker_main(array $argv): int
{
    $options = maintenance_worker_options($argv);
    $db = maintenance_worker_connect_db($options);
    $state = ['last_run' => [], 'states' => []];

    do {
        try {
            maintenance_worker_run_once($db, $state, $options['once']);
        } catch (mysqli_sql_exception $exception) {
            if ($options['once']) {
                throw $exception;
            }
            fwrite(STDERR, '[maintenance-worker] Database error, reconnecting: ' . $exception->getMessage() . "\n");
            $db = maintenance_worker_connect_db($options);
            continue;
        }
        if ($options['once']) {
            return 0;
        }
        sleep((int) $options['sleep']);
    } while (true);
}

function maintenance_worker_connect_db(array $options): mysqli
{
    // Loop mode survives MySQL restarts/slow startups; --once fails fast.
    $maxAttempts = $options['once'] ? 3 : 0;
    $attempt = 0;

    while (true) {
        $attempt++;
        try {
            return db(true);
        } catch (mysqli_sql_exception $exception) {
            if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
                throw $exception;
            }
            fwrite(STDERR, '[maintenance-worker] Database not reachable (attempt ' . $attempt . '): ' . $exception->getMessage() . "\n");
            sleep(min(30, 2 * $attempt));
        }
    }
}

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

// Active reachability check of the MECM server. Target host: explicit setting
// override, else the source IP of the last device-sync heartbeat (zero-config).
function maintenance_worker_probe(mysqli $db): void
{
    $target = maintenance_worker_probe_target($db);
    if ($target === null) {
        // No MECM contact yet and no override - nothing to probe.
        return;
    }

    [$host, $port] = $target;
    [$ok, $error] = maintenance_worker_tcp_check($host, $port, VIRTUSPHERE_MECM_PROBE_TIMEOUT_SECONDS);
    $label = 'tcp://' . $host . ':' . $port;
    if ($ok) {
        repo_touch_integration_heartbeat($db, VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE, '', VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS, $label . ' ok');
    } else {
        repo_mark_integration_failure($db, VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE, $label . ' fail: ' . $error, VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS);
    }
}

/**
 * @return array{0: string, 1: int}|null
 */
function maintenance_worker_probe_target(mysqli $db): ?array
{
    $host = trim(repo_setting_value($db, VIRTUSPHERE_SETTING_MECM_PROBE_HOST, ''));
    if ($host === '') {
        $row = repo_fetch_one($db, 'SELECT last_ip FROM deploy_integration_heartbeats WHERE source = ? LIMIT 1', 's', [VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC]);
        $host = trim((string) ($row['last_ip'] ?? ''));
    }
    if ($host === '') {
        return null;
    }

    $port = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_MECM_PROBE_PORT, (string) VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT);
    if ($port < 1 || $port > 65535) {
        $port = VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT;
    }

    return [$host, $port];
}

/**
 * @return array{0: bool, 1: string}
 */
function maintenance_worker_tcp_check(string $host, int $port, int $timeoutSeconds): array
{
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeoutSeconds);
    if ($socket !== false) {
        fclose($socket);

        return [true, ''];
    }

    return [false, $errstr !== '' ? $errstr : ('errno ' . $errno)];
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
            audit($db, VIRTUSPHERE_LOG_CATEGORY_MECM, '[maintenance-worker] integration ' . $source . ' state ' . $oldState . ' -> ' . $newState, null, 'cli');
        } catch (Throwable $exception) {
            fwrite(STDERR, '[maintenance-worker] transition audit failed: ' . $exception->getMessage() . "\n");
        }
    }
}

exit(maintenance_worker_main($argv));
