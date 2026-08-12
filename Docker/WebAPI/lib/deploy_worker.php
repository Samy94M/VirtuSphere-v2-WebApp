<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ansible_inventory.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/worker_heartbeat.php';

/**
 * The deploy worker CLI entrypoint: nothing but the shell.
 *
 * This file runs its loop on require, which is why every decision it makes has
 * to live in a requireable module - a test that wants the claim loop or a job
 * processor cannot load this file without starting a worker. It used to hold
 * the loop, both job processors, the autostart gate and the stream splitter on
 * 521 lines; those are four things that change for four different reasons
 * (ADR-0006 amendment 2026-08-11).
 *
 * The split is structural only: `--once`/`--loop`, every public helper, the
 * require order above, the STDERR and job-log wording and the worker exit codes
 * are unchanged. lib/deploy_worker_modules.php is the owner registry static
 * scanners walk instead of this filename.
 *
 * Ownership map:
 * - deploy_worker_loop.php      options, DB connect/reconnect, claim loop, id
 * - deploy_worker_mission.php   the mission deploy processor and its dispatch
 * - deploy_worker_inventory.php the mission-less ESXi inventory processor
 * - deploy_worker_stream.php    streamed output, flush, credential lookup
 */

require_once __DIR__ . '/deploy_worker_loop.php';

exit(deploy_worker_main($argv));
