<?php

declare(strict_types=1);

// Worker liveness heartbeat file (AP8). The deploy and maintenance workers
// call worker_heartbeat_touch() from their loops; the container healthcheck
// (lib/worker_healthcheck.php) calls worker_heartbeat_is_fresh(). Both ends
// share the path and the freshness window through the constants, so the
// compose healthcheck command never spells out either.
//
// This is process liveness only: a worker waiting out a MySQL restart keeps
// touching the file and stays healthy, because the database has its own
// healthcheck and a red worker would only point away from the real cause.

require_once __DIR__ . '/constants.php';

function worker_heartbeat_path(): string
{
    // Env override exists for tests, which must not write into the real /tmp
    // path of whatever container happens to run them.
    $override = getenv('VIRTUSPHERE_WORKER_HEARTBEAT_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return VIRTUSPHERE_WORKER_HEARTBEAT_FILE;
}

function worker_heartbeat_touch(): void
{
    // Liveness must never take the worker down: a failing touch (full tmpfs,
    // exotic permissions) makes the container report unhealthy, which is
    // exactly the signal such a state deserves.
    @touch(worker_heartbeat_path());
}

function worker_heartbeat_is_fresh(?int $now = null): bool
{
    $path = worker_heartbeat_path();
    clearstatcache(true, $path);
    $mtime = @filemtime($path);
    if ($mtime === false) {
        return false;
    }
    return (($now ?? time()) - $mtime) <= VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS;
}
