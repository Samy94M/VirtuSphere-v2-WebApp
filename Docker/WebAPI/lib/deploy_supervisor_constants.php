<?php

declare(strict_types=1);

// The bounds of the deploy supervisor (Etappe 14C).
//
// Every value here is either derived from a bound that already exists or has a
// measured reason next to it. The one thing none of them may be is a second
// copy of a number somebody else owns: the supervisor and the container
// healthcheck have to agree about "the worker stopped answering", and two
// numbers for that is how they would start disagreeing on the day it matters.

require_once __DIR__ . '/constants.php';

// The supervisor's own liveness file, separate from the worker's.
//
// Separate because the two facts are independently true. A supervisor that is
// alive and deliberately holding no child is `cooldown`, not `offline`. A child
// that hangs while the supervisor keeps answering is `degraded`, not `offline`.
// One shared file could not tell those two apart, and both are states an
// operator meets.
const VIRTUSPHERE_SUPERVISOR_HEARTBEAT_FILE = '/tmp/virtusphere-supervisor-heartbeat';

/**
 * Durable supervisor state belongs below the bind-mounted WebAPI tree.
 * `/tmp` is tmpfs in this container and therefore cannot carry a restart
 * budget across a container restart.
 */
function deploy_supervisor_state_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy-supervisor';
}

// How often the supervisor wakes up. Short, because its whole job is watching.
const VIRTUSPHERE_SUPERVISOR_TICK_SECONDS = 5;

// How often it publishes its state to the database. Deliberately much rarer
// than the tick: publishing is a courtesy to the portal, not the supervisor's
// duty, and a write every five seconds would be a write nobody reads.
const VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS = 30;

// How many consecutive stale observations count as a confirmed child failure.
//
// Not one: a single look at a file that is being written at that moment is not
// a finding, and the price of being wrong here is killing a healthy worker in
// the middle of a playbook.
const VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS = 3;

// Seconds between TERM and KILL, and between KILL and giving up.
//
// The TERM grace has to outlast what a worker needs to finish the step it is in
// and release its ownership; the worker's own heartbeat interval is the unit
// that measures that, so the grace is a multiple of it rather than a new
// number. After the KILL grace the supervisor stops trying and says so: a
// process that survives SIGKILL is a kernel-level state (uninterruptible IO, a
// wedged mount), and there is nothing a userspace loop can add.
const VIRTUSPHERE_SUPERVISOR_TERM_GRACE_SECONDS = 30;
const VIRTUSPHERE_SUPERVISOR_KILL_GRACE_SECONDS = 15;

// Quiet time after a confirmed exit, before a new child starts. The cause of a
// hang is often still there (a wedged mount, an exhausted descriptor table), and
// restarting instantly only reaches it again sooner.
const VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS = 30;

// The restart window. More than this many restarts inside it is not an
// accident, it is a pattern, and the supervisor stops trying until
// `next_retry_at`. No hot loop: the wait is a stored timestamp, not a sleep
// that a crash would forget.
const VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_SECONDS = 600;
const VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX = 5;
const VIRTUSPHERE_SUPERVISOR_BACKOFF_MIN_SECONDS = 60;
const VIRTUSPHERE_SUPERVISOR_BACKOFF_MAX_SECONDS = 900;

/**
 * How old the supervisor's own heartbeat file may be before the container
 * healthcheck calls it dead.
 *
 * Four ticks: one slow tick (a loaded host, a slow tmpfs) must not report the
 * container unhealthy, four missed ones must.
 */
function supervisor_heartbeat_max_age_seconds(): int
{
    return 4 * VIRTUSPHERE_SUPERVISOR_TICK_SECONDS;
}

/**
 * When the supervisor considers its child's heartbeat stale.
 *
 * Deliberately the SAME number the worker's own container healthcheck uses.
 * Two answers to "is this worker still answering" would be two answers that
 * drift, and then Docker and the supervisor disagree about whether to restart
 * the same process.
 */
function supervisor_child_stale_seconds(): int
{
    return VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS;
}

/**
 * How long the supervisor waits before its n-th retry once the restart window
 * is exhausted. Doubling, capped, so a briefly broken environment is retried
 * soon and a permanently broken one is not asked every minute for a week.
 */
function supervisor_backoff_seconds(int $restartCount): int
{
    $over = max(1, $restartCount - VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX);
    $delay = VIRTUSPHERE_SUPERVISOR_BACKOFF_MIN_SECONDS * (2 ** ($over - 1));

    return (int) min($delay, VIRTUSPHERE_SUPERVISOR_BACKOFF_MAX_SECONDS);
}
