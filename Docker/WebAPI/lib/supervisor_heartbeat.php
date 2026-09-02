<?php

declare(strict_types=1);

// The supervisor's own liveness file (Etappe 14C).
//
// Its own module rather than a second function in worker_heartbeat.php, because
// the two answer different questions and are read by different callers: the
// worker's file says "the process doing the work still answers", this one says
// "the process watching it still answers". A supervisor in a deliberate restart
// cooldown keeps touching THIS file while the worker's is legitimately absent,
// and a container healthcheck that could not tell those apart would report a
// planned restart as a broken container.

require_once __DIR__ . '/deploy_supervisor_constants.php';
// The child's path comes from the worker's own module: two spellings of that
// filename would be two files, and the supervisor would watch the wrong one.
require_once __DIR__ . '/worker_heartbeat.php';

function supervisor_heartbeat_path(): string
{
    // Env override for tests, which must not write into the real /tmp path of
    // whatever container happens to run them.
    $override = getenv('VIRTUSPHERE_SUPERVISOR_HEARTBEAT_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return VIRTUSPHERE_SUPERVISOR_HEARTBEAT_FILE;
}

function supervisor_heartbeat_touch(): void
{
    // Liveness must never take the supervisor down. A failing touch (full
    // tmpfs, exotic permissions) makes the container report unhealthy, which is
    // exactly the signal such a state deserves.
    @touch(supervisor_heartbeat_path());
}

function supervisor_heartbeat_is_fresh(?int $now = null): bool
{
    $path = supervisor_heartbeat_path();
    clearstatcache(true, $path);
    $mtime = @filemtime($path);
    if ($mtime === false) {
        return false;
    }

    return (($now ?? time()) - $mtime) <= supervisor_heartbeat_max_age_seconds();
}

/**
 * How old the CHILD's liveness file is, or null when it is not there.
 *
 * The supervisor's only input about its child's health, and deliberately the
 * only one: a worker that is sitting out a database outage is healthy, and the
 * database is therefore the one source this decision may not consult.
 */
function supervisor_child_heartbeat_age(?int $now = null): ?int
{
    $path = worker_heartbeat_path();
    clearstatcache(true, $path);
    $mtime = @filemtime($path);
    if ($mtime === false) {
        return null;
    }

    return max(0, ($now ?? time()) - $mtime);
}
