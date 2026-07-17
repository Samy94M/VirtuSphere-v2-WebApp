<?php

declare(strict_types=1);

// Maintenance worker (ADR-0018): second loop container next to deploy_worker.
// This file is only the CLI shell (option parsing, DB reconnect loop); the
// interval jobs themselves live in lib/maintenance_tasks.php so the tests can
// require and drive them without starting a loop (AP6, same split as
// deploy_worker_outcome.php).

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/maintenance_tasks.php';
require_once __DIR__ . '/worker_heartbeat.php';

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
        worker_heartbeat_touch();
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
            // Waiting out a DB restart is a healthy worker state (AP8).
            worker_heartbeat_touch();
            sleep(min(30, 2 * $attempt));
        }
    }
}

exit(maintenance_worker_main($argv));
