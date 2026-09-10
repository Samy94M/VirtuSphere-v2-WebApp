<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The deploy supervisor CLI entrypoint: nothing but the shell (Etappe 14C).
 *
 * It runs as PID 1 of the deploy container, but only inside an audited
 * maintenance window: compose still starts lib/deploy_worker.php, and the
 * process shape changes together with the `supervisor_v1` contract, never on
 * its own. The reason is in the snapshot: an observed process shape that does
 * not match the stored contract is reported as degraded rather than guessed, so
 * starting this process while the row still says `worker_v1` would put the
 * service into a permanent fault state that is not a fault.
 *
 * Ownership map:
 * - deploy_supervisor_policy.php    what to do this tick, as a pure function
 * - deploy_supervisor_process.php   proc_open/waitpid/signals behind one seam
 * - deploy_supervisor_local_state.php durable restart budget and lifetime lock
 * - deploy_supervisor_publish.php   best-effort DB side-channel and reconnect
 * - deploy_supervisor_loop.php      the clock, the liveness files and the child
 * - supervisor_heartbeat.php        this process's own liveness file
 */

require_once __DIR__ . '/deploy_supervisor_loop.php';

exit(deploy_supervisor_main($argv));
