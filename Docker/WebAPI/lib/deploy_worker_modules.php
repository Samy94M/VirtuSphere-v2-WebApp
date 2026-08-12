<?php

declare(strict_types=1);

/**
 * The owner registry of the deploy worker layer.
 *
 * Same reason as the deploy job repository's registry: a static contract that
 * reads exactly one source file stops guarding the moment that file is split,
 * silently and while still green. `PhaseCContractTest` and
 * `DeployConvergenceContractTest` assert on the worker's phase wiring, reaper
 * SQL, heartbeat tick and stream logging - all of which moved into modules on
 * 2026-08-11. They walk this list instead, and
 * `DeployWorkerModuleContractTest` compares it against the filesystem in both
 * directions so an unregistered new module fails the build.
 *
 * Paths are relative to `Docker/WebAPI`. The two facades come first because
 * they are the only public require paths.
 */
const VIRTUSPHERE_DEPLOY_WORKER_MODULES = [
    'lib/deploy_worker.php',
    'lib/deploy_worker_outcome.php',
    'lib/deploy_worker_loop.php',
    'lib/deploy_worker_mission.php',
    'lib/deploy_worker_inventory.php',
    'lib/deploy_worker_stream.php',
    'lib/deploy_worker_db_channel.php',
    'lib/deploy_worker_db_operations.php',
    'lib/deploy_worker_db_recovery.php',
    'lib/deploy_worker_runtime.php',
    'lib/deploy_worker_vm_state.php',
    'lib/deploy_worker_reaper.php',
    'lib/deploy_worker_finish.php',
];
