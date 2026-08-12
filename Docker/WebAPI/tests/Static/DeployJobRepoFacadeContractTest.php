<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_modules.php';

/**
 * The deploy job repository was one 1220-line file holding five transaction
 * domains (ADR-0006 amendment 2026-08-11). Splitting it is only safe while
 * three things stay true, and each of them is quiet when it breaks:
 *
 * 1. `lib/repo/deploy_jobs.php` remains the single public require path and
 *    still defines the whole surface. Every caller in the repository requires
 *    that one file; a function left behind in the move would only fail at the
 *    call site, at runtime, on the path that uses it.
 * 2. The owner registry matches the filesystem in both directions. A static
 *    contract that reads one filename keeps passing after a split while the
 *    code it asserted about has moved somewhere nothing reads, which is the
 *    exact failure mode this registry exists to prevent.
 * 3. No name is defined twice. Two modules defining the same function is a
 *    fatal error only in the load order that reaches both, so it can hide in
 *    the branch nobody exercised.
 */
final class DeployJobRepoFacadeContractTest extends TestCase
{
    /**
     * The public surface as it stood before the split. This list is the
     * contract: a rename or removal is a decision, and it fails here first
     * rather than at one call site under load.
     */
    private const PUBLIC_SURFACE = [
        'deploy_job_normalize_mode',
        'deploy_job_normalize_mission_mode',
        'deploy_job_bool',
        'deploy_job_normalize_vm_ids',
        'deploy_job_normalize_wait',
        'deploy_job_normalize_start_wait',
        'deploy_job_payload',
        'deploy_job_is_retryable',
        'deploy_job_retry_plan',
        'deploy_schedule_error',
        'deploy_parse_schedule',
        'deploy_preview_rows',
        'deploy_job_payload_summary',
        'repo_deploy_jobs',
        'repo_deploy_job',
        'repo_deploy_job_logs',
        'repo_deploy_filter_mission_vm_ids',
        'repo_deploy_group_vm_list',
        'repo_purge_deploy_job_logs',
        'repo_purge_finished_system_jobs',
        // Added in Etappe 2: the reap message as a pure function, so the wording
        // that is an operator's whole account of a reap can be pinned without a
        // database (see DeployJobReapObservationTest).
        'deploy_job_reap_observation',
        'repo_deploy_active_job_exists',
        'repo_deploy_lock_mission',
        'repo_deploy_assert_mission_idle',
        'repo_create_deploy_job',
        'repo_retry_deploy_job',
        'repo_enqueue_deploy_group',
        'repo_cancel_deploy_group',
        'repo_create_system_job',
        'repo_cancel_deploy_job',
        'repo_confirm_deploy_job_cancelled',
        'repo_claim_next_deploy_job',
        'repo_touch_deploy_job_heartbeat',
        'repo_reap_stale_deploy_jobs',
        'repo_sweep_orphaned_deploying_vms',
        'repo_finish_deploy_job',
        'repo_append_deploy_job_log',
        'repo_deploy_assert_user_exists',
        'repo_deploy_assert_mission_ready',
        'repo_deploy_assert_credential_type',
        'repo_insert_deploy_job_log_unlocked',
    ];

    public function testTheRegistryAndTheFilesystemAgreeInBothDirections(): void
    {
        $registered = VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES;
        self::assertNotSame([], $registered, 'the module registry is empty; every scanner walking it would check nothing.');

        $onDisk = array_map(
            static fn (string $path): string => 'lib/repo/' . basename($path),
            glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/repo/deploy_job*.php') ?: []
        );
        // deploy_job_modules.php is the registry itself, not a scanned owner.
        $onDisk = array_values(array_diff($onDisk, ['lib/repo/deploy_job_modules.php']));
        self::assertNotSame([], $onDisk, 'the glob found no deploy job module; this contract would pass on anything.');

        sort($registered);
        sort($onDisk);
        self::assertSame(
            $onDisk,
            $registered,
            'VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES and lib/repo/deploy_job*.php disagree. A module missing from the registry '
                . 'drops out of every static contract that walks it, without anything turning red.'
        );
    }

    public function testTheFacadeLoadsEveryRegisteredModule(): void
    {
        $facade = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php');
        foreach (VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES as $module) {
            if ($module === 'lib/repo/deploy_jobs.php') {
                continue;
            }
            self::assertStringContainsString(
                "require_once __DIR__ . '/" . basename($module) . "'",
                $facade,
                basename($module) . ' is registered but the facade never loads it, so the public require path is incomplete.'
            );
        }
    }

    /**
     * Loaded in a separate process with nothing but the facade: the same thing
     * a portal page or a worker does. An implicit dependency on some other
     * test's require would make this pass while production fatals.
     *
     * @runInSeparateProcess
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testThePublicSurfaceIsCompleteAfterRequiringOnlyTheFacade(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

        $missing = array_values(array_filter(
            self::PUBLIC_SURFACE,
            static fn (string $function): bool => !function_exists($function)
        ));
        self::assertSame([], $missing, 'requiring lib/repo/deploy_jobs.php no longer defines: ' . implode(', ', $missing));
    }

    public function testNoFunctionIsDefinedByTwoModules(): void
    {
        $owners = [];
        foreach (VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES as $module) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $name) {
                $owners[$name][] = $module;
            }
        }
        self::assertNotSame([], $owners, 'no function declaration was found in any module; this contract would pass on anything.');

        $duplicates = [];
        foreach ($owners as $name => $files) {
            if (count($files) > 1) {
                $duplicates[] = $name . ' (' . implode(', ', $files) . ')';
            }
        }
        sort($duplicates);
        self::assertSame([], $duplicates, 'a function is declared by more than one module: ' . implode('; ', $duplicates));
    }

    /** Every registered function is owned by exactly one module, and vice versa. */
    public function testTheModulesOwnExactlyThePublicSurface(): void
    {
        $declared = [];
        foreach (VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES as $module) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) !== 0) {
                foreach ($matches[1] as $name) {
                    $declared[$name] = true;
                }
            }
        }

        $expected = self::PUBLIC_SURFACE;
        sort($expected);
        $found = array_keys($declared);
        sort($found);
        self::assertSame(
            $expected,
            $found,
            'The deploy job modules no longer declare exactly the surface the facade used to. Adding a function here is a '
                . 'decision: extend PUBLIC_SURFACE in the same change, so a later split cannot lose it unnoticed.'
        );
    }
}
