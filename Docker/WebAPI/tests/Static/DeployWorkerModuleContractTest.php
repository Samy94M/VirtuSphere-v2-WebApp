<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_modules.php';

/**
 * The deploy worker was two files holding seven responsibilities: the CLI
 * shell, two job processors, stream logging, per-tick runtime, the reaper, VM
 * convergence and the job outcome (ADR-0006 amendment 2026-08-11). Splitting
 * them is only safe while these stay true, and each is quiet when it breaks:
 *
 * 1. `lib/deploy_worker_outcome.php` remains the single public require of the
 *    outcome layer and still defines all of it. Every integration test and the
 *    maintenance worker require that one file.
 * 2. `lib/deploy_worker.php` remains a shell that runs the loop on require and
 *    decides nothing itself, so a decision stays reachable for a test.
 * 3. The owner registry matches the filesystem in both directions, or a static
 *    contract keeps passing while its subject moved into an unread module.
 */
final class DeployWorkerModuleContractTest extends TestCase
{
    /** What requiring lib/deploy_worker_outcome.php has to define. */
    private const OUTCOME_SURFACE = [
        'deploy_worker_classify_inventory_failure',
        'deploy_worker_redact_secrets',
        'deploy_worker_report_alive',
        'deploy_worker_queue_detail',
        'deploy_worker_heartbeat_tick',
        'deploy_worker_assert_job_is_ours',
        'deploy_worker_payload',
        'deploy_reap_observer_since',
        'deploy_reap_observer_is_blind',
        'deploy_worker_reap_stale_jobs',
        'deploy_worker_conclude_sequence',
        'deploy_worker_handle_cancelled',
        'deploy_worker_log_if_job_exists',
        'deploy_worker_handle_failure',
        'deploy_worker_finish_job',
        'deploy_worker_audit_outcome',
        'deploy_worker_refresh_inventory_after_deploy',
        'deploy_worker_mark_vms_deploying',
        'deploy_worker_restore_deploying_vms',
        'deploy_worker_mark_vms_failed',
        'deploy_worker_scope_vms',
        'deploy_worker_job_mac_result',
    ];

    /** What the CLI shell contributes, reachable without starting a loop. */
    private const ENTRY_SURFACE = [
        'deploy_worker_options',
        'deploy_worker_main',
        'deploy_worker_connect_db',
        'deploy_worker_run_once',
        'deploy_worker_id',
        'deploy_worker_process_job',
        'deploy_worker_autostart_preflight',
        'deploy_worker_process_inventory_job',
        'deploy_worker_credential',
        'deploy_worker_log_stream_chunk',
        'deploy_worker_log_stream_flush',
        'deploy_worker_open_db_channel',
        'deploy_worker_settle_db_channel',
    ];

    public function testTheRegistryAndTheFilesystemAgreeInBothDirections(): void
    {
        $registered = VIRTUSPHERE_DEPLOY_WORKER_MODULES;
        self::assertNotSame([], $registered, 'the worker module registry is empty; every scanner walking it would check nothing.');

        $onDisk = array_map(
            static fn (string $path): string => 'lib/' . basename($path),
            glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/deploy_worker*.php') ?: []
        );
        // The registry file itself is not one of the scanned owners.
        $onDisk = array_values(array_diff($onDisk, ['lib/deploy_worker_modules.php']));
        self::assertNotSame([], $onDisk, 'the glob found no worker module; this contract would pass on anything.');

        sort($registered);
        sort($onDisk);
        self::assertSame(
            $onDisk,
            $registered,
            'VIRTUSPHERE_DEPLOY_WORKER_MODULES and lib/deploy_worker*.php disagree. A module missing from the registry drops '
                . 'out of every static contract that walks it, without anything turning red.'
        );
    }

    /**
     * The outcome facade, loaded exactly as the maintenance worker and the
     * integration tests load it: nothing else required first.
     */
    #[RunInSeparateProcess]
    public function testTheOutcomeFacadeStillDefinesItsWholeSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

        $missing = array_values(array_filter(
            self::OUTCOME_SURFACE,
            static fn (string $function): bool => !function_exists($function)
        ));
        self::assertSame([], $missing, 'requiring lib/deploy_worker_outcome.php no longer defines: ' . implode(', ', $missing));

        self::assertTrue(class_exists('DeployWorkerCancelled', false), 'the cancellation signal must come with the outcome facade.');
        foreach (['CONFIG', 'SSH', 'TRANSPORT', 'MARKER', 'DB'] as $phase) {
            self::assertTrue(defined('VIRTUSPHERE_DEPLOY_PHASE_' . $phase), $phase . ' phase constant is not defined by the facade.');
        }
    }

    /**
     * The CLI shell's own helpers have to be reachable without executing the
     * loop; lib/deploy_worker.php exits on require, so lib/deploy_worker_loop.php
     * is the requireable head of that chain.
     */
    #[RunInSeparateProcess]
    public function testTheLoopModuleIsRequireableWithoutStartingAWorker(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy_worker_loop.php';

        $missing = array_values(array_filter(
            self::ENTRY_SURFACE,
            static fn (string $function): bool => !function_exists($function)
        ));
        self::assertSame([], $missing, 'requiring lib/deploy_worker_loop.php no longer defines: ' . implode(', ', $missing));
    }

    /** The entrypoint stays a shell: it may wire, never decide. */
    public function testTheEntrypointDeclaresNothingItself(): void
    {
        $entry = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker.php');
        self::assertSame(
            0,
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $entry, $found),
            'lib/deploy_worker.php declares ' . implode(', ', $found[1]) . '; the file exits on require, so anything '
                . 'declared here is unreachable for a test.'
        );
        self::assertStringContainsString('exit(deploy_worker_main($argv));', $entry, 'the entrypoint no longer starts the worker.');
        self::assertStringContainsString("if (PHP_SAPI !== 'cli')", $entry, 'the entrypoint lost its CLI guard.');
    }

    public function testNoFunctionIsDefinedByTwoModules(): void
    {
        $owners = [];
        foreach (VIRTUSPHERE_DEPLOY_WORKER_MODULES as $module) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $name) {
                $owners[$name][] = $module;
            }
        }
        self::assertNotSame([], $owners, 'no function declaration was found in any worker module; this contract would pass on anything.');

        $duplicates = [];
        foreach ($owners as $name => $files) {
            if (count($files) > 1) {
                $duplicates[] = $name . ' (' . implode(', ', $files) . ')';
            }
        }
        sort($duplicates);
        self::assertSame([], $duplicates, 'a function is declared by more than one worker module: ' . implode('; ', $duplicates));

        $expected = array_merge(self::OUTCOME_SURFACE, self::ENTRY_SURFACE);
        sort($expected);
        $found = array_keys($owners);
        sort($found);
        self::assertSame(
            $expected,
            $found,
            'The worker modules no longer declare exactly the surface the two former files did. Adding a function here is a '
                . 'decision: extend the surface constant in the same change.'
        );
    }
}
