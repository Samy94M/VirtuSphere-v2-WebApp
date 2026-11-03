<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/worker_database_connect.php';

$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['retry', 'busy'], true)) { exit(2); }
$heartbeat = $argv[2] ?? '';
if ($heartbeat === '' || !is_file($heartbeat)) { exit(2); }
putenv('VIRTUSPHERE_WORKER_HEARTBEAT_FILE=' . $heartbeat);
$GLOBALS['virtusphere_worker_stop_requested'] = false;
if ($scenario === 'busy') {
    worker_install_stop_handler('busy-control');
    $events = [];
    $unit = static function () use (&$events): void {
        $events[] = 'started';
        if (!posix_kill(getmypid(), SIGQUIT)) { throw new RuntimeException('self signal failed'); }
        $events[] = 'finished';
    };
    $unit();
    echo json_encode(['events' => $events, 'stop_requested' => worker_stop_requested()], JSON_THROW_ON_ERROR);
    exit(0);
}

$results = [];
foreach (['deploy-worker', 'maintenance-worker'] as $component) {
    $GLOBALS['virtusphere_worker_stop_requested'] = false;
    worker_install_stop_handler($component);
    if (!touch($heartbeat, 1)) { throw new RuntimeException('private heartbeat reset failed'); }
    $parent = getmypid();
    $channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($channel === false) { throw new RuntimeException('signal readiness channel failed'); }
    $sender = pcntl_fork();
    if ($sender === -1) { throw new RuntimeException('signal sender fork failed'); }
    if ($sender === 0) {
        fclose($channel[0]);
        stream_set_timeout($channel[1], 3);
        if (fgets($channel[1]) !== "ready\n") { exit(3); }
        $deadline = microtime(true) + 3;
        do {
            clearstatcache(true, $heartbeat);
            if (filemtime($heartbeat) > 1) { break; }
            usleep(1000);
        } while (microtime(true) < $deadline);
        if (filemtime($heartbeat) <= 1) { exit(5); }
        // The product touched liveness immediately before its retry wait.
        usleep(50_000);
        if (fwrite($channel[1], (string) hrtime(true) . "\n") === false) { exit(4); }
        exit(posix_kill($parent, SIGQUIT) ? 0 : 1);
    }
    fclose($channel[1]);
    stream_set_timeout($channel[0], 3);
    $waited = -1;
    $status = 0;
    try {
        $attempts = 0;
        $result = worker_database_connect(['once' => false], $component,
            static function (): void { throw new RuntimeException('unexpected connected callback'); },
            static function () use (&$attempts, $channel): mysqli {
                $attempts++;
                if ($attempts > 3) { throw new RuntimeException('bounded retry exceeded'); }
                if ($attempts === 1 && fwrite($channel[0], "ready\n") === false) {
                    throw new RuntimeException('signal readiness write failed');
                }
                throw new mysqli_sql_exception('synthetic unavailable database');
            });
        $returnedAt = hrtime(true);
        $signalAt = fgets($channel[0]);
        if ($signalAt === false || !ctype_digit(trim($signalAt))) { throw new RuntimeException('signal timestamp missing'); }
        $results[$component] = ['returned_null' => $result === null,
            'stop_requested' => worker_stop_requested(), 'attempts' => $attempts,
            'stop_ms' => ($returnedAt - (int) trim($signalAt)) / 1_000_000];
    } finally {
        $waited = pcntl_waitpid($sender, $status);
        fclose($channel[0]);
    }
    $results[$component]['sender_exited'] = $waited === $sender && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
}
echo json_encode($results, JSON_THROW_ON_ERROR);
