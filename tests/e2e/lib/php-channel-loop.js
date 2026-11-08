'use strict';

// The loop that runs inside the PHP container at the far end of the runPhp
// channel (C4). It reads framed requests on stdin and runs each snippet in a
// fresh `php` process with the snippet on that process's stdin, exactly what
// `docker exec -i <php> php` did per call: same binary, user, environment and
// working directory, a new bootstrap and no state carried from one snippet to
// the next. The loop itself loads nothing from the application.
//
// It answers with the snippet's exit code, stdout and stderr (frame protocol in
// exec-channel-thread.js). A snippet that overruns its deadline is killed and
// answered as "timeout". The loop's own warnings go to stderr, so they can never
// be read as an answer, and a malformed request ends the loop, which the client
// reports as a failed call.
//
// The code travels base64-encoded inside `php -r`, so no quoting rule of the
// Windows command line or of a shell touches it.

const LOOP = String.raw`
ini_set('display_errors', 'stderr');
$writeAll = static function ($fh, string $data): bool {
    $done = 0;
    $len = strlen($data);
    while ($done < $len) {
        $n = fwrite($fh, substr($data, $done));
        if ($n === false || $n === 0) {
            return false;
        }
        $done += $n;
    }
    return fflush($fh);
};
$readExact = static function ($fh, int $n): ?string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($fh, $n - strlen($buf));
        if ($chunk === false || ($chunk === '' && feof($fh))) {
            return null;
        }
        $buf .= $chunk;
    }
    return $buf;
};
if (!$writeAll(STDOUT, 'READY 1 ' . getmypid() . "\n")) {
    exit(3);
}
while (($line = fgets(STDIN)) !== false) {
    if (preg_match('/^REQ (\d+) (\d+)\n\z/', $line, $m) !== 1) {
        fwrite(STDERR, 'exec channel loop: protocol error, got ' . json_encode(substr($line, 0, 200)) . "\n");
        exit(3);
    }
    $script = $readExact(STDIN, (int) $m[1]);
    if ($script === null) {
        fwrite(STDERR, "exec channel loop: input ended inside a request\n");
        exit(3);
    }
    $deadline = microtime(true) + ((int) $m[2]) / 1000;
    $pipes = [];
    $proc = proc_open(['php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        $msg = "exec channel loop: proc_open php failed\n";
        if (!$writeAll(STDOUT, 'RES 127 0 ' . strlen($msg) . " spawn\n" . $msg)) {
            exit(3);
        }
        continue;
    }
    // php reads its whole script before it runs it, so writing all of it
    // before reading any output cannot deadlock.
    $writeAll($pipes[0], $script);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    while ($open !== []) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            $timedOut = true;
            break;
        }
        $read = array_values($open);
        $write = null;
        $except = null;
        $sec = (int) floor($left);
        if (@stream_select($read, $write, $except, $sec, (int) (($left - $sec) * 1000000)) === false) {
            continue;
        }
        foreach ($open as $fd => $pipe) {
            if (!in_array($pipe, $read, true)) {
                continue;
            }
            $chunk = fread($pipe, 65536);
            if ($chunk === false || ($chunk === '' && feof($pipe))) {
                fclose($pipe);
                unset($open[$fd]);
                continue;
            }
            if ($fd === 1) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }
    $exit = -1;
    while (!$timedOut) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = $st['signaled'] ? 128 + (int) $st['termsig'] : (int) $st['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }
        usleep(2000);
    }
    if ($timedOut) {
        proc_terminate($proc, 9);
    }
    foreach ($open as $pipe) {
        fclose($pipe);
    }
    proc_close($proc);
    $frame = sprintf("RES %d %d %d %s\n", $exit, strlen($stdout), strlen($stderr), $timedOut ? 'timeout' : 'done');
    if (!$writeAll(STDOUT, $frame . $stdout . $stderr)) {
        exit(3);
    }
}
exit(0);
`;

/** The `php -r` argument that runs the loop. */
function loopArgument() {
  return `eval(base64_decode('${Buffer.from(LOOP, 'utf8').toString('base64')}'));`;
}

module.exports = { LOOP, loopArgument };
