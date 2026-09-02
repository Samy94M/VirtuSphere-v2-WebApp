<?php

declare(strict_types=1);

// A loop worker's answer to "please stop" (Etappe 14C).
//
// Its own module because it is a process-lifecycle concern that both loop
// workers share, and because the measurement behind it applies to any process
// this product makes PID 1 of a container.
//
// The measurement: `docker stop` on the deploy worker took the full grace
// period and ended in exit 137 (SIGKILL), on every restart, every stack update
// and every host reboot. Two facts together produce that, and each of them
// looks harmless on its own:
//
//  1. A process that is PID 1 IGNORES every signal for which it has installed
//     no handler. The kernel does not apply default actions there, so a worker
//     with no pcntl code at all does not die on the stop signal; it waits to be
//     killed.
//  2. The `php:*-fpm` base image declares `STOPSIGNAL SIGQUIT`, because that is
//     how php-fpm shuts down gracefully, and this container inherits it. So the
//     signal that actually arrives is SIGQUIT, not SIGTERM. Handling only TERM
//     and INT reads correctly and changes nothing, which is why
//     `docker kill -s TERM` worked the whole time and `docker stop` did not.
//
// The semantics are deliberately modest. An IDLE worker exits at once and
// cleanly, which is the overwhelming majority of stops. A BUSY worker records
// the request and keeps going: its playbook is changing ESXi on another host
// and no signal to this process can stop that, so ending here would only make
// the outcome unknown. It gets one STDERR line instead, which is the diagnosis
// nobody had before.

/** The signals that mean "stop". SIGQUIT is the one this image actually sends. */
function worker_stop_signals(): array
{
    return [SIGTERM, SIGINT, SIGQUIT];
}

function worker_install_stop_handler(string $component): void
{
    if (!function_exists('pcntl_async_signals')) {
        // A build without pcntl keeps the old behaviour rather than pretending:
        // there is no way to receive a signal, and a silent no-op that claimed
        // otherwise would be worse than the SIGKILL.
        return;
    }
    pcntl_async_signals(true);
    $handler = static function (int $signal) use ($component): void {
        $GLOBALS['virtusphere_worker_stop_requested'] = true;
        fwrite(STDERR, '[' . $component . '] signal ' . $signal . " received, stopping after the current unit of work\n");
    };
    foreach (worker_stop_signals() as $signal) {
        pcntl_signal($signal, $handler);
    }
}

function worker_stop_requested(): bool
{
    return (bool) ($GLOBALS['virtusphere_worker_stop_requested'] ?? false);
}

/**
 * The idle wait between two rounds, interruptible by a stop.
 *
 * A plain `sleep()` would hold the process for its whole duration after the
 * signal arrived, and the stop grace is not ours to spend: `docker stop` allows
 * ten seconds by default. Slicing it keeps an idle worker's shutdown inside a
 * fraction of a second without busy-waiting.
 */
function worker_idle_wait(int $seconds): void
{
    if (!function_exists('pcntl_signal_dispatch')) {
        sleep($seconds);

        return;
    }
    for ($i = 0; $i < $seconds * 4; $i++) {
        if (worker_stop_requested()) {
            return;
        }
        usleep(250_000);
        pcntl_signal_dispatch();
    }
}
