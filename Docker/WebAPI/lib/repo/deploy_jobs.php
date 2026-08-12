<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../mac_import.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/vm_identity.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/status_events.php';

/**
 * Deploy job repository: compatibility facade over the domain modules.
 *
 * This file used to hold all of it on 1220 lines - payload rules, display
 * reads, locking preconditions, four enqueue paths, the cancellation state
 * machine, worker ownership and unattended maintenance. Those are five
 * different transaction domains with five different reasons to change, and the
 * lock order that makes the enqueue race-free was documented in the middle of
 * them (ADR-0006 amendment 2026-08-11).
 *
 * The split is structural only: every public function kept its name, signature,
 * SQL, transaction and lock boundaries, exceptions and log wording. This path
 * stays the single public require, so no caller changed, and the modules load
 * in a deterministic order below. Static scanners must read the whole owner set
 * rather than this file - VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES in
 * lib/repo/deploy_job_modules.php is that registry.
 *
 * Ownership map:
 * - deploy_job_input.php       payload normalization, retry decision, schedule
 * - deploy_job_queries.php     list/detail/log reads, mission VM resolution
 * - deploy_job_guards.php      locking preconditions for every write
 * - deploy_job_queue.php       create, retry, staggered group, system job
 * - deploy_job_cancel.php      the ADR-0033 cancellation transitions
 * - deploy_job_worker.php      claim, heartbeat, finish, log append
 * - deploy_job_maintenance.php retention, reaper, convergence sweep
 */

require_once __DIR__ . '/deploy_job_modules.php';
require_once __DIR__ . '/deploy_job_input.php';
require_once __DIR__ . '/deploy_job_queries.php';
require_once __DIR__ . '/deploy_job_guards.php';
require_once __DIR__ . '/deploy_job_worker.php';
require_once __DIR__ . '/deploy_job_queue.php';
require_once __DIR__ . '/deploy_job_cancel.php';
require_once __DIR__ . '/deploy_job_maintenance.php';
