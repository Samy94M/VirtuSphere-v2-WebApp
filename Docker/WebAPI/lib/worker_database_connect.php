<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/worker_heartbeat.php';
require_once __DIR__ . '/worker_stop_signal.php';

/**
 * Connect or reconnect a loop worker without making its idle wait deaf to a
 * stop signal. One-shot callers retain their bounded three attempts.
 *
 * @param array{once:bool} $options
 * @param callable(mysqli):void $onConnected
 * @param null|callable():mysqli $connector
 */
function worker_database_connect(
    array $options,
    string $component,
    callable $onConnected,
    ?callable $connector = null
): ?mysqli {
    $once = (bool) $options['once'];
    $maxAttempts = $once ? 3 : 0;
    $attempt = 0;
    $connector ??= static fn (): mysqli => db(true);

    while (true) {
        if (!$once && worker_stop_requested()) {
            return null;
        }
        $attempt++;
        try {
            $db = $connector();
            // A signal can arrive while mysqli is blocked in connect. Check
            // again before the connection is passed to any database user.
            if (!$once && worker_stop_requested()) {
                return null;
            }
            $onConnected($db);
            if (!$once && worker_stop_requested()) {
                return null;
            }

            return $db;
        } catch (mysqli_sql_exception $exception) {
            if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
                throw $exception;
            }
            fwrite(STDERR, '[' . $component . '] Database not reachable (attempt ' . $attempt . '): '
                . virtusphere_redact_log_text($exception->getMessage()) . "\n");
            // Waiting out a DB restart is a healthy process state. The wait is
            // sliced by the shared signal owner, so loop mode can stop here.
            worker_heartbeat_touch();
            worker_idle_wait(min(30, 2 * $attempt));
        }
    }
}
