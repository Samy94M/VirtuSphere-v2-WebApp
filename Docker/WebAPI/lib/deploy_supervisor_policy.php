<?php

declare(strict_types=1);

// What the supervisor does this tick, as a pure function over a state struct
// (Etappe 14C, plan section 21.1).
//
// Pure for the same reason the deploy service snapshot is: a state machine you
// can only reach through real processes is a state machine whose corners are
// never tested, and every corner here is one an operator meets on a bad day.
// "The child has hung for the third time in ten minutes while a playbook is
// still changing ESXi" is not something anybody arranges in a container twice.
//
// The one invariant the whole etappe exists for: this function returns `start`
// ONLY when the caller has confirmed, through waitpid, that the previous child
// is gone. It never infers that from a signal it sent. A process that survives
// TERM and KILL therefore ends in `manual`, never in a second child, because a
// second child would run the same job again and create the same VM again.

require_once __DIR__ . '/deploy_supervisor_constants.php';

const VIRTUSPHERE_SUPERVISOR_ACTION_START = 'start';
const VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE = 'observe';
const VIRTUSPHERE_SUPERVISOR_ACTION_TERM = 'term';
const VIRTUSPHERE_SUPERVISOR_ACTION_KILL = 'kill';
const VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT = 'await_exit';
const VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN = 'cooldown';
const VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY = 'wait_retry';
const VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL = 'manual';
const VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN = 'shutdown';

const VIRTUSPHERE_SUPERVISOR_ACTIONS = [
    VIRTUSPHERE_SUPERVISOR_ACTION_START,
    VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE,
    VIRTUSPHERE_SUPERVISOR_ACTION_TERM,
    VIRTUSPHERE_SUPERVISOR_ACTION_KILL,
    VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT,
    VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN,
    VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY,
    VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL,
    VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN,
];

// The phases the supervisor's own state can be in. `stopping` covers both the
// deliberate shutdown and the reaction to a confirmed child failure, because
// the sequence is identical: TERM, wait, KILL, wait, give up. What differs is
// only what happens after the child is gone, and that is decided by
// `shutdown_requested`, not by a second phase vocabulary.
const VIRTUSPHERE_SUPERVISOR_PHASE_IDLE = 'idle';
const VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING = 'running';
const VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING = 'stopping';
const VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN = 'cooldown';
const VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY = 'wait_retry';
const VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL = 'manual';
const VIRTUSPHERE_SUPERVISOR_PHASE_STOPPED = 'stopped';

const VIRTUSPHERE_SUPERVISOR_PHASES = [
    VIRTUSPHERE_SUPERVISOR_PHASE_IDLE,
    VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
    VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
    VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN,
    VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY,
    VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL,
    VIRTUSPHERE_SUPERVISOR_PHASE_STOPPED,
];

/**
 * The supervisor's state at rest, before its first tick.
 *
 * @return array<string, mixed>
 */
function deploy_supervisor_initial_state(): array
{
    return [
        'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_IDLE,
        'stale_confirmations' => 0,
        'term_sent_at' => null,
        'kill_sent_at' => null,
        'cooldown_until' => null,
        'restart_window_started_at' => null,
        'restart_count' => 0,
        'next_retry_at' => null,
        'child_started_at' => null,
    ];
}

/**
 * One tick.
 *
 * `$facts` is what the caller observed just now and nothing else:
 *  - `now`                 int, seconds
 *  - `child_running`       bool, from waitpid/proc_get_status, NEVER from a
 *                          signal this process sent
 *  - `child_heartbeat_age` int|null, seconds since the child last touched its
 *                          liveness file; null means the file is not there
 *  - `shutdown_requested`  bool, a TERM/INT this process received
 *
 * Deliberately absent from that list: anything from the database. A worker
 * sitting out a database outage is HEALTHY - that is the whole point of the
 * worker's db channel - so a supervisor that asked the database would answer a
 * database outage by killing the one process that is correctly surviving it.
 *
 * @param array<string, mixed> $state
 * @param array{now:int,child_running:bool,child_heartbeat_age:?int,shutdown_requested:bool} $facts
 * @return array{action:string,state:array<string,mixed>,reason:string}
 */
function deploy_supervisor_decide(array $state, array $facts): array
{
    $now = (int) $facts['now'];
    $running = (bool) $facts['child_running'];

    // Manual is a durable ownership latch. Even a requested supervisor stop
    // must not rewrite it to `stopped`: after a later container start that
    // would look like permission to start a replacement although the unknown
    // old child was never reaped by this process.
    if ($state['phase'] === VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL) {
        if ($facts['shutdown_requested'] && !$running) {
            return deploy_supervisor_result(
                VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN,
                $state,
                'manual ownership latch preserved while supervisor exits'
            );
        }

        return deploy_supervisor_result(
            VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL,
            $state,
            'manual intervention required before another child may start'
        );
    }

    // Shutdown outranks everything, including a cooldown that has not expired:
    // once PID 1 has been asked to stop, starting another child would leave a
    // process behind that nobody is going to reap.
    if ($facts['shutdown_requested']) {
        if (!$running) {
            return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN, [
                'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPED,
            ] + $state, 'child confirmed gone, supervisor exits');
        }

        return deploy_supervisor_stop_sequence($state, $now, 'shutdown requested');
    }

    if ($state['phase'] === VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING) {
        if ($running) {
            return deploy_supervisor_stop_sequence($state, $now, 'child failure confirmed');
        }

        // Confirmed gone. Only NOW may a restart be considered, and it still
        // goes through the window and the cooldown.
        return deploy_supervisor_after_exit($state, $now);
    }

    if (!$running) {
        // No child. Either we never had one, or it exited on its own.
        if ($state['phase'] === VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING) {
            return deploy_supervisor_after_exit($state, $now);
        }

        return deploy_supervisor_start_or_wait($state, $now);
    }

    // A live child. The only question left is whether it still answers.
    $age = $facts['child_heartbeat_age'];
    $fresh = $age !== null && $age <= supervisor_child_stale_seconds();
    if ($fresh) {
        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, [
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
            'stale_confirmations' => 0,
        ] + $state, 'child heartbeat fresh');
    }

    $confirmations = (int) $state['stale_confirmations'] + 1;
    if ($confirmations < VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS) {
        // One stale look is not a finding. The file may be mid-write, and the
        // price of being wrong is a healthy worker killed inside a playbook.
        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, [
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
            'stale_confirmations' => $confirmations,
        ] + $state, 'stale observation ' . $confirmations . ' of ' . VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS);
    }

    return deploy_supervisor_stop_sequence([
        'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
        'stale_confirmations' => $confirmations,
        'term_sent_at' => null,
        'kill_sent_at' => null,
    ] + $state, $now, 'child failure confirmed');
}

/**
 * TERM, wait, KILL, wait, give up. One signal per call, never a repeat, and
 * never a `start` anywhere inside it.
 *
 * @param array<string, mixed> $state
 * @return array{action:string,state:array<string,mixed>,reason:string}
 */
function deploy_supervisor_stop_sequence(array $state, int $now, string $reason): array
{
    $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING;

    if ($state['term_sent_at'] === null) {
        $state['term_sent_at'] = $now;

        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_TERM, $state, $reason . ', asking it to stop');
    }

    if ($state['kill_sent_at'] === null) {
        if ($now - (int) $state['term_sent_at'] < VIRTUSPHERE_SUPERVISOR_TERM_GRACE_SECONDS) {
            return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT, $state, 'waiting out the term grace');
        }
        $state['kill_sent_at'] = $now;

        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_KILL, $state, 'term grace expired');
    }

    if ($now - (int) $state['kill_sent_at'] < VIRTUSPHERE_SUPERVISOR_KILL_GRACE_SECONDS) {
        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT, $state, 'waiting out the kill grace');
    }

    // Survived SIGKILL. Nothing in userspace fixes that, and a replacement
    // process would be a second executor of the same work.
    $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL;

    return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL, $state, 'child survived kill');
}

/**
 * What happens once the child's exit is CONFIRMED: count the restart against
 * the window, then either cool down or wait out the backoff.
 *
 * @param array<string, mixed> $state
 * @return array{action:string,state:array<string,mixed>,reason:string}
 */
function deploy_supervisor_after_exit(array $state, int $now): array
{
    $windowStart = $state['restart_window_started_at'];
    if ($windowStart === null || ($now - (int) $windowStart) > VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_SECONDS) {
        $state['restart_window_started_at'] = $now;
        $state['restart_count'] = 0;
    }
    $state['restart_count'] = (int) $state['restart_count'] + 1;
    $state['stale_confirmations'] = 0;
    $state['term_sent_at'] = null;
    $state['kill_sent_at'] = null;
    $state['child_started_at'] = null;

    if ((int) $state['restart_count'] > VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX) {
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY;
        $state['next_retry_at'] = $now + supervisor_backoff_seconds((int) $state['restart_count']);
        $state['cooldown_until'] = null;

        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY, $state, 'restart window exhausted');
    }

    $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN;
    $state['cooldown_until'] = $now + VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS;
    $state['next_retry_at'] = null;

    return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $state, 'cooling down before restart');
}

/**
 * No child and nothing to stop: start one, unless a cooldown or a retry wait is
 * still running.
 *
 * @param array<string, mixed> $state
 * @return array{action:string,state:array<string,mixed>,reason:string}
 */
function deploy_supervisor_start_or_wait(array $state, int $now): array
{
    if ($state['next_retry_at'] !== null && $now < (int) $state['next_retry_at']) {
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY;

        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY, $state, 'waiting for the next attempt');
    }
    if ($state['cooldown_until'] !== null && $now < (int) $state['cooldown_until']) {
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN;

        return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $state, 'cooling down before restart');
    }

    $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING;
    $state['cooldown_until'] = null;
    $state['next_retry_at'] = null;
    $state['stale_confirmations'] = 0;
    $state['term_sent_at'] = null;
    $state['kill_sent_at'] = null;
    $state['child_started_at'] = $now;

    return deploy_supervisor_result(VIRTUSPHERE_SUPERVISOR_ACTION_START, $state, 'starting the worker process');
}

/**
 * @param array<string, mixed> $state
 * @return array{action:string,state:array<string,mixed>,reason:string}
 */
function deploy_supervisor_result(string $action, array $state, string $reason): array
{
    return ['action' => $action, 'state' => $state, 'reason' => $reason];
}

/**
 * Whether this state means the service is in its restart window without a
 * child. The snapshot reads exactly this, so `cooldown` on the System status
 * card and the supervisor's own idea of cooling down cannot drift apart.
 *
 * @param array<string, mixed> $state
 */
function deploy_supervisor_is_cooling_down(array $state): bool
{
    return in_array(
        (string) $state['phase'],
        [VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN, VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY],
        true
    );
}
