<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the AP1.6/AP1.7 status machine: the worker trusts only the durable
 * result_json of an export sequence, failure marking is selective and sets
 * lifecycle plus MECM state together, a cancel converges still-deploying VMs,
 * and the maintenance worker sweeps the crash case the deploy worker cannot.
 */
final class DeployConvergenceContractTest extends TestCase
{
    public function testPartialIsTerminalAndKnownToTheStatusConstants(): void
    {
        self::assertContains(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES);
        self::assertNotContains(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES);
    }

    public function testWorkerDemandsTheDbResultForExportSequencesOnly(): void
    {
        $outcome = $this->workerSource();

        // The mode predicate is derived from the playbook sequence, never a
        // second hand-kept list; the verdict comes from the DB, never stdout.
        self::assertStringContainsString("ansible_mode_expects_mac_result((string) \$payload['mode'])", $outcome);
        self::assertStringContainsString('deploy_worker_job_mac_result($db, $jobId)', $outcome);
        self::assertStringContainsString('no usable MAC import result was recorded', $outcome);
        self::assertStringContainsString('MAC import failed for every VM of this job.', $outcome);
        self::assertStringContainsString('deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $summary)', $outcome);

        $command = $this->commandSource();
        self::assertStringContainsString('function ansible_mode_expects_mac_result', $command);
        self::assertStringContainsString("in_array(VIRTUSPHERE_PLAYBOOKS['export'], ansible_playbooks_for_mode(\$mode), true)", $command);
    }

    public function testWorkerEntrypointDelegatesEveryOutcomeToTheTestableModule(): void
    {
        // The CLI entrypoint executes its main loop on require, so the status
        // decisions must live in the requireable module - a decision inlined
        // back into deploy_worker.php would be unreachable for the integration
        // tests that prove the matrix.
        $worker = $this->workerSource();
        self::assertStringContainsString("require_once __DIR__ . '/deploy_worker_outcome.php'", $worker);
        // Since the DB channel (Masterplan Etappe 2) the handle is asked for at
        // the call, never carried: a `$db` captured before a long remote step is
        // a dead mysqli object after a reconnect, and handing it to the outcome
        // layer would throw exactly where the job's only result gets written.
        self::assertStringContainsString('deploy_worker_conclude_sequence($channel->connection(), $job, $workerId, $vmIds, $priorLifecycles)', $worker);
        // The claim-time marking must feed the restore, or a green create/start
        // job leaves `deploying` VMs for the sweep to falsely fail.
        self::assertStringContainsString('$priorLifecycles = deploy_worker_mark_vms_deploying(', $worker);
        // The stop reason travels with it: "cancelled", "the row is gone" and
        // "somebody else concluded it" are the same stop but not the same finding,
        // and the log line is the only place that difference can survive.
        self::assertStringContainsString('deploy_worker_handle_cancelled($channel->connection(), $job, $vmIds, $cancelled->getMessage())', $worker);
        // The ownership check itself lives in the requireable module too, or the
        // four states it distinguishes would again be unreachable for a test.
        self::assertStringContainsString('function deploy_worker_assert_job_is_ours', $this->workerSource());
        self::assertStringNotContainsString('function deploy_worker_assert_job_is_ours', $this->source('lib/deploy_worker.php'));
        // Since B6 the message leaves through the secret redactor first; the
        // handler call itself stays in the entrypoint's catch.
        self::assertStringContainsString('deploy_worker_handle_failure($channel->connection(), $job, $workerId, $vmIds, deploy_worker_redact_secrets($exception->getMessage(), [$esxiSecret, $ansibleSecret]))', $worker);
        // The partial verdict belongs to the outcome layer; neither the CLI
        // shell nor either job processor may decide it on its own.
        foreach (['lib/deploy_worker.php', 'lib/deploy_worker_loop.php', 'lib/deploy_worker_mission.php', 'lib/deploy_worker_inventory.php'] as $entry) {
            self::assertStringNotContainsString('VIRTUSPHERE_DEPLOY_STATUS_PARTIAL', $this->source($entry), $entry);
        }
    }

    public function testFailureMarkingIsSelectiveAndSetsMecmStateWithLifecycle(): void
    {
        $outcome = $this->workerSource();

        // The blanket "all VMs failed" path is gone; successful_vm_ids from the
        // committed import keep their deployed state on a late follow-up error.
        self::assertStringNotContainsString('deploy_worker_mark_mission_vms', $outcome);
        self::assertStringContainsString("\$keepVmIds = \$macResult !== null ? \$macResult['successful_vm_ids'] : []", $outcome);
        self::assertStringContainsString('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ?', $outcome);

        // The frozen legacy vm_status must not be rewritten by failure paths.
        self::assertStringNotContainsString('SET lifecycle_state = ?, mecm_sync_state = ?, vm_status', $outcome);
    }

    public function testCancelConvergesOnlyStillDeployingVms(): void
    {
        $outcome = $this->workerSource();

        self::assertStringContainsString('catch (DeployWorkerCancelled', $this->workerSource());
        self::assertStringContainsString("cancelled while deploying', \$vmIds, [], true)", $outcome);
        self::assertStringContainsString("\$lifecycle !== VIRTUSPHERE_LIFECYCLE_DEPLOYING", $outcome);
        // The reaper keeps VMs whose import already committed.
        self::assertMatchesRegularExpression(
            '/reaped after stale heartbeat.*successful_vm_ids/s',
            $outcome
        );
    }

    public function testMaintenanceWorkerRunsTheConvergenceSweep(): void
    {
        // The jobs live in the requireable module; the CLI entrypoint only
        // delegates (AP6 split, same reason as deploy_worker_outcome.php).
        self::assertStringContainsString("require_once __DIR__ . '/maintenance_tasks.php'", $this->source('lib/maintenance_worker.php'));
        $maintenance = $this->source('lib/maintenance_tasks.php');
        self::assertStringContainsString("maintenance_worker_due(\$state, 'deploy-vm-sweep', VIRTUSPHERE_DEPLOY_VM_SWEEP_INTERVAL_SECONDS", $maintenance);
        self::assertStringContainsString('repo_sweep_orphaned_deploying_vms($db)', $maintenance);
        self::assertStringContainsString('VIRTUSPHERE_LOG_CATEGORY_DEPLOY', $maintenance);

        $repo = $this->deployJobRepoSource();
        self::assertStringContainsString('function repo_sweep_orphaned_deploying_vms', $repo);
        // Only deploying orphans: an ACTIVE job (the SSoT set, cancelling
        // included since ADR-0033) protects its mission's VMs, and the sweep
        // must not block on concurrent row locks.
        self::assertStringContainsString("NOT EXISTS (SELECT 1 FROM deploy_jobs j WHERE j.mission_id = v.mission_id AND j.status IN (' . \$placeholders . '))", $repo);
        self::assertMatchesRegularExpression('/lifecycle_state = \?\s+AND NOT EXISTS/s', $repo);
        self::assertMatchesRegularExpression('/repo_sweep_orphaned_deploying_vms.*?FOR UPDATE SKIP LOCKED/s', $repo);
        self::assertStringContainsString('WHERE id = ? AND lifecycle_state = ?', $repo);
    }

    public function testMaintenanceWorkerRunsTheSecondReaper(): void
    {
        // AP6: the deploy worker reaps only at its own loop start; a worker
        // stuck inside a blocking transport call is reaped by the maintenance
        // worker instead. Both callers must go through the outcome module's
        // reap so the MAC-aware VM convergence cannot be bypassed.
        $maintenance = $this->source('lib/maintenance_tasks.php');
        self::assertStringContainsString("maintenance_worker_due(\$state, 'deploy-job-reap', VIRTUSPHERE_DEPLOY_REAP_INTERVAL_SECONDS", $maintenance);
        self::assertStringContainsString('deploy_worker_reap_stale_jobs($db)', $maintenance);
        self::assertStringNotContainsString('repo_reap_stale_deploy_jobs', $maintenance);
        self::assertStringContainsString('deploy_worker_reap_stale_jobs($db)', $this->workerSource());
    }

    public function testPlaybookExecIsBoundedAndHeartbeatIsTimeBased(): void
    {
        // AP6: no unbounded remote command, and the DB heartbeat must tick on
        // silent read slices, not only on output - otherwise a quiet clone
        // task looks like a dead worker and gets reaped mid-run.
        $ssh = $this->source('lib/ssh.php');
        self::assertStringContainsString('setKeepAlive(VIRTUSPHERE_SSH_KEEPALIVE_INTERVAL_SECONDS)', $ssh);
        self::assertStringContainsString('function ssh_stream_command_output', $ssh);
        self::assertStringContainsString('idle timeout', $ssh);
        self::assertStringContainsString('total time limit', $ssh);

        $worker = $this->workerSource();
        self::assertStringContainsString('$heartbeatOnSilence', $worker);
        self::assertStringContainsString('ansible_step_failure_suffix($currentStep)', $worker);

        $command = $this->commandSource();
        self::assertStringContainsString('function ansible_step_marker_line', $command);
        self::assertStringContainsString('function ansible_step_marker_parse', $command);
    }

    /**
     * The active predicate is ONE constant (ADR-0033). A hand-written
     * queued/running literal is exactly how five sites silently disagreed
     * about what "active" means when cancelling arrived: a job in that state
     * was invisible to the guards that exist to protect its mission. Only the
     * migration history may carry the historical literal.
     */
    public function testNoHandWrittenActiveStatusListSurvivesOutsideTheMigrationHistory(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/repo/*.php') ?: [],
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/*.php') ?: []
        );
        self::assertNotEmpty($files, 'zero-match: the scan must see the codebase');

        $offenders = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '/lib/migrate.php')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match("/IN\s*\(\s*'queued'\s*,\s*'running'\s*\)/i", $source) === 1
                || preg_match('/VIRTUSPHERE_DEPLOY_STATUS_QUEUED\s*,\s*VIRTUSPHERE_DEPLOY_STATUS_RUNNING\s*\]/', $source) === 1) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }

        // deploy_constants.php itself declares the cancellable set
        // (queued/running), which is a DIFFERENT question than active.
        $offenders = array_values(array_diff($offenders, ['lib/deploy_constants.php']));
        self::assertSame([], $offenders, 'active-status lists must come from VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES');
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The deploy worker is a facade plus domain modules (ADR-0006 amendment
     * 2026-08-11): CLI shell, the two job processors, stream logging, runtime,
     * VM state, reaper and outcome. Every positive assertion about "the worker
     * does X" therefore reads the whole owner set, because a contract pinned to
     * one filename stops guarding the moment its subject moves. A negative
     * assertion of the form "this must NOT be inlined into the entrypoint"
     * deliberately keeps naming that one file, since that is what it means.
     */
    /**
     * The deploy-mode and preflight command layer is a facade over domain
     * modules since Etappe 8. Reading its historic filename would now read a
     * fifteen-line require list: every assertion below would pass vacuously and
     * this guard would be permanently, silently green.
     */
    private function commandSource(): string
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_command_modules.php';

        $source = '';
        foreach (VIRTUSPHERE_ANSIBLE_COMMAND_MODULES as $module) {
            $source .= $this->source($module) . "\n";
        }
        self::assertNotSame('', $source, 'the Ansible command module registry produced no source.');

        return $source;
    }

    private function workerSource(): string
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy_worker_modules.php';

        $source = '';
        foreach (VIRTUSPHERE_DEPLOY_WORKER_MODULES as $module) {
            $source .= $this->source($module) . "\n";
        }
        self::assertNotSame('', $source, 'the deploy worker module registry produced no source.');

        return $source;
    }

    /**
     * The deploy job repository is a facade over domain modules (ADR-0006
     * amendment 2026-08-11). Reading the facade alone would leave every
     * assertion below silently unchecked after the split, so this walks the
     * owner registry; DeployJobRepoFacadeContractTest keeps that registry and
     * the filesystem in agreement.
     */
    private function deployJobRepoSource(): string
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_modules.php';

        $source = '';
        foreach (VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES as $module) {
            $source .= $this->source($module) . "\n";
        }
        self::assertNotSame('', $source, 'the deploy job repo module registry produced no source.');

        return $source;
    }
}
