<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_test_config.php';

/** Diagnostics get their own DB connection and cannot block worker liveness. */
function ansible_test_process_start(): mixed
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__ . '/ansible_test_worker.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start scheduled Ansible full test.');
    }
    return $process;
}

/** Reap before replacement; a signal sent to a process is not proof of exit. */
function ansible_test_process_reap(mixed &$process): void
{
    if (!is_resource($process)) {
        $process = null;
        return;
    }
    $status = proc_get_status($process);
    if (!$status['running']) {
        $exitCode = (int) $status['exitcode'];
        proc_close($process);
        $process = null;
        if ($exitCode !== 0) {
            fwrite(STDERR, '[deploy-worker] scheduled Ansible test process exit=' . $exitCode . "\n");
        }
    }
}

/** Parent shutdown must not leave a diagnostic child contacting the host. */
function ansible_test_process_stop(mixed &$process): void
{
    if (!is_resource($process)) {
        return;
    }
    $status = proc_get_status($process);
    if ($status['running']) {
        // This disposable read-only diagnostic has no deploy effect to reconcile.
        proc_terminate($process, SIGKILL);
    }
    proc_close($process);
    $process = null;
}
