<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_supervisor_constants.php';
require_once __DIR__ . '/deploy_supervisor_local_state.php';
require_once __DIR__ . '/deploy_supervisor_policy.php';
require_once __DIR__ . '/deploy_supervisor_process.php';
require_once __DIR__ . '/deploy_supervisor_publish.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/supervisor_heartbeat.php';
require_once __DIR__ . '/worker_heartbeat.php';
require_once __DIR__ . '/worker_stop_signal.php';

/**
 * The supervisor's loop: observe, decide, act, publish (Etappe 14C).
 *
 * It knows nothing about jobs. That is not tidiness, it is the contract: a
 * supervisor that could claim, finish or reap a job would be a second executor,
 * and the whole point of this process is that there is exactly one.
 *
 * The decision itself lives in deploy_supervisor_policy.php as a pure function.
 * What is left here is the part that cannot be pure: reading the clock and two
 * files, driving one child, and telling the database afterwards.
 */

// The stop-signal handling is the SHARED one (lib/worker_stop_signal.php), not
// a second copy. It carries the measurement that made it necessary: PID 1
// ignores every signal it has no handler for, and this image's inherited
// `STOPSIGNAL SIGQUIT` is the one that actually arrives. A second list here
// would be a second chance to forget SIGQUIT.

/**
 * The command the child is started with.
 *
 * Built here rather than taken from the environment: the supervisor exists to
 * run THE deploy worker, and a configurable child would make it a general
 * process runner with a deploy worker's permissions.
 *
 * @return list<string>
 */
function deploy_supervisor_child_command(int $sleepSeconds): array
{
    return [PHP_BINARY, __DIR__ . '/deploy_worker.php', '--loop', '--sleep=' . $sleepSeconds];
}

/**
 * @param list<string> $argv
 */
function deploy_supervisor_main(array $argv): int
{
    $options = deploy_supervisor_options($argv);
    worker_install_stop_handler('deploy-supervisor');

    $process = new DeploySupervisorProcess();
    $publisher = new DeploySupervisorPublisher();
    $store = new DeploySupervisorLocalState(deploy_supervisor_state_directory());
    try {
        $store->acquire();
    } catch (DeploySupervisorLockException $exception) {
        deploy_supervisor_say('cannot acquire the lifetime lock: '
            . virtusphere_redact_log_text($exception->getMessage()));

        return 1;
    }

    $stateReadable = true;
    $stateDirty = false;
    try {
        $state = $store->load();
        $restored = deploy_supervisor_restore_local_state($state, time());
        $stateDirty = $restored !== $state;
        $state = $restored;
    } catch (DeploySupervisorStateException $exception) {
        // Preserve the unreadable file as evidence. This process stays alive
        // and publishable in manual state, but never overwrites or starts.
        $stateReadable = false;
        $state = deploy_supervisor_initial_state();
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL;
        deploy_supervisor_say('durable state is unavailable; replacement starts are blocked: '
            . virtusphere_redact_log_text($exception->getMessage()));
    }

    while (true) {
        $now = time();
        supervisor_heartbeat_touch();

        $decision = deploy_supervisor_decide($state, [
            'now' => $now,
            // waitpid, not a remembered flag. This is the value the whole
            // "never a second child" guarantee rests on.
            'child_running' => $process->isRunning(),
            'child_heartbeat_age' => supervisor_child_heartbeat_age($now),
            'shutdown_requested' => worker_stop_requested(),
        ]);
        $candidate = $decision['state'];
        $changed = $candidate !== $state;
        $persisted = !$stateDirty && !$changed;
        if ($stateReadable && ($stateDirty || $changed)) {
            try {
                // This is the start fence: the policy's running state and its
                // child_started_at reservation reach durable storage before
                // proc_open is allowed below.
                $store->save($candidate);
                $persisted = true;
                $stateDirty = false;
            } catch (DeploySupervisorStateException $exception) {
                $persisted = false;
                deploy_supervisor_say('durable state write failed; replacement starts are blocked: '
                    . virtusphere_redact_log_text($exception->getMessage()));
            }
        }

        if ($decision['action'] === VIRTUSPHERE_SUPERVISOR_ACTION_START && !$persisted) {
            // Do not adopt the reserved-running candidate: without a start its
            // next tick would look like a crashed child and consume another
            // restart. The unchanged prior state lets this exact reservation
            // be retried after storage recovers.
            $decision = deploy_supervisor_result(
                VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY,
                $state,
                'start withheld until durable state can be reserved'
            );
        } else {
            $state = $candidate;
            $stateDirty = !$persisted;
        }

        // Persistence failure never becomes a reason to signal an existing
        // child. TERM/KILL/shutdown/reap decisions still run from local process
        // evidence; only a replacement start requires the durable fence.
        $done = deploy_supervisor_apply($process, $decision, $options);
        $publisher->publish(
            $state,
            getmypid() ?: null,
            $process->pid(),
            $now,
            $decision['action']
        );

        if ($done) {
            return 0;
        }
        if ($options['once']) {
            return 0;
        }
        deploy_supervisor_wait_a_tick();
    }
}

/**
 * Waits one tick, but wakes up on a stop signal.
 *
 * A plain `sleep(5)` would add up to a whole tick to every shutdown, and the
 * shutdown budget is not ours: `docker stop` allows ten seconds by default, and
 * inside that the supervisor still has to ask its child to stop and confirm the
 * exit. Slicing the wait and dispatching explicitly keeps that reaction inside
 * a fraction of a second without busy-waiting.
 */
function deploy_supervisor_wait_a_tick(): void
{
    worker_idle_wait(VIRTUSPHERE_SUPERVISOR_TICK_SECONDS);
}

/**
 * Carries out one decision. Returns true when the supervisor is finished.
 *
 * @param array{action:string,state:array<string,mixed>,reason:string} $decision
 * @param array<string,mixed> $options
 */
function deploy_supervisor_apply(DeploySupervisorProcess $process, array $decision, array $options): bool
{
    switch ($decision['action']) {
        case VIRTUSPHERE_SUPERVISOR_ACTION_START:
            $pid = $process->start(deploy_supervisor_child_command((int) $options['sleep']));
            deploy_supervisor_say('started the worker process (pid ' . $pid . ')');
            break;
        case VIRTUSPHERE_SUPERVISOR_ACTION_TERM:
            deploy_supervisor_say($decision['reason']);
            $process->terminate();
            break;
        case VIRTUSPHERE_SUPERVISOR_ACTION_KILL:
            deploy_supervisor_say($decision['reason']);
            $process->kill();
            break;
        case VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL:
            // Said once per tick on purpose: a state nothing automatic leaves
            // has to keep saying so, or it becomes a supervisor that silently
            // does nothing.
            deploy_supervisor_say($decision['reason'] . '; NOT starting a second worker process');
            break;
        case VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN:
            deploy_supervisor_say($decision['reason'] . ', supervisor exits');

            return true;
        default:
            // observe, await_exit, cooldown, wait_retry: nothing to do but wait.
            break;
    }

    return false;
}

function deploy_supervisor_say(string $message): void
{
    fwrite(STDERR, '[deploy-supervisor] ' . $message . "\n");
}

/** @return array{once:bool,sleep:int} */
function deploy_supervisor_options(array $argv): array
{
    $options = ['once' => in_array('--once', $argv, true), 'sleep' => VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--sleep=')) {
            $options['sleep'] = max(1, min(60, (int) substr($arg, 8)));
        }
    }

    return $options;
}
