<?php

declare(strict_types=1);

/**
 * The owner registry of the deploy job repository.
 *
 * A static contract that reads exactly one source file stops guarding the
 * moment that file is split, silently and while still green: before the
 * 2026-08-11 split, `DeployConvergenceContractTest` and `PhaseCContractTest`
 * both scanned `lib/repo/deploy_jobs.php`, and every SQL, lock and convergence
 * assertion they hold would have moved out from under them into a module
 * nothing reads. This constant is what those scanners walk instead, and
 * `DeployJobRepoFacadeContractTest` compares it against the filesystem in both
 * directions, so a new module that is not registered fails the build rather
 * than quietly leaving the checked surface.
 *
 * Paths are relative to `Docker/WebAPI`. The facade comes first because it is
 * the only public require path; the rest is the load order it uses.
 */
const VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES = [
    'lib/repo/deploy_jobs.php',
    'lib/repo/deploy_job_input.php',
    'lib/repo/deploy_job_queries.php',
    'lib/repo/deploy_job_guards.php',
    'lib/repo/deploy_job_worker.php',
    'lib/repo/deploy_job_queue.php',
    'lib/repo/deploy_job_cancel.php',
    'lib/repo/deploy_job_maintenance.php',
];
