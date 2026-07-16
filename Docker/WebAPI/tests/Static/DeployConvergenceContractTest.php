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
        $outcome = $this->source('lib/deploy_worker_outcome.php');

        // The mode predicate is derived from the playbook sequence, never a
        // second hand-kept list; the verdict comes from the DB, never stdout.
        self::assertStringContainsString("ansible_mode_expects_mac_result((string) \$payload['mode'])", $outcome);
        self::assertStringContainsString('deploy_worker_job_mac_result($db, $jobId)', $outcome);
        self::assertStringContainsString('no usable MAC import result was recorded', $outcome);
        self::assertStringContainsString('MAC import failed for every VM of this job.', $outcome);
        self::assertStringContainsString('deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $summary)', $outcome);

        $command = $this->source('lib/ansible_command.php');
        self::assertStringContainsString('function ansible_mode_expects_mac_result', $command);
        self::assertStringContainsString("in_array(VIRTUSPHERE_PLAYBOOKS['export'], ansible_playbooks_for_mode(\$mode), true)", $command);
    }

    public function testWorkerEntrypointDelegatesEveryOutcomeToTheTestableModule(): void
    {
        // The CLI entrypoint executes its main loop on require, so the status
        // decisions must live in the requireable module - a decision inlined
        // back into deploy_worker.php would be unreachable for the integration
        // tests that prove the matrix.
        $worker = $this->source('lib/deploy_worker.php');
        self::assertStringContainsString("require_once __DIR__ . '/deploy_worker_outcome.php'", $worker);
        self::assertStringContainsString('deploy_worker_conclude_sequence($db, $job, $workerId, $vmIds, $priorLifecycles)', $worker);
        // The claim-time marking must feed the restore, or a green create/start
        // job leaves `deploying` VMs for the sweep to falsely fail.
        self::assertStringContainsString('$priorLifecycles = deploy_worker_mark_vms_deploying(', $worker);
        self::assertStringContainsString('deploy_worker_handle_cancelled($db, $job, $vmIds)', $worker);
        self::assertStringContainsString('deploy_worker_handle_failure($db, $job, $workerId, $vmIds, $exception->getMessage())', $worker);
        self::assertStringNotContainsString('VIRTUSPHERE_DEPLOY_STATUS_PARTIAL', $worker);
    }

    public function testFailureMarkingIsSelectiveAndSetsMecmStateWithLifecycle(): void
    {
        $outcome = $this->source('lib/deploy_worker_outcome.php');

        // The blanket "all VMs failed" path is gone; successful_vm_ids from the
        // committed import keep their deployed state on a late follow-up error.
        self::assertStringNotContainsString('deploy_worker_mark_mission_vms', $outcome);
        self::assertStringNotContainsString('deploy_worker_mark_mission_vms', $this->source('lib/deploy_worker.php'));
        self::assertStringContainsString("\$keepVmIds = \$macResult !== null ? \$macResult['successful_vm_ids'] : []", $outcome);
        self::assertStringContainsString('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ?', $outcome);

        // The frozen legacy vm_status must not be rewritten by failure paths.
        self::assertStringNotContainsString('SET lifecycle_state = ?, mecm_sync_state = ?, vm_status', $outcome);
    }

    public function testCancelConvergesOnlyStillDeployingVms(): void
    {
        $outcome = $this->source('lib/deploy_worker_outcome.php');

        self::assertStringContainsString('catch (DeployWorkerCancelled', $this->source('lib/deploy_worker.php'));
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
        $maintenance = $this->source('lib/maintenance_worker.php');
        self::assertStringContainsString("maintenance_worker_due(\$state, 'deploy-vm-sweep', VIRTUSPHERE_DEPLOY_VM_SWEEP_INTERVAL_SECONDS", $maintenance);
        self::assertStringContainsString('repo_sweep_orphaned_deploying_vms($db)', $maintenance);
        self::assertStringContainsString('VIRTUSPHERE_LOG_CATEGORY_DEPLOY', $maintenance);

        $repo = $this->source('lib/repo/deploy_jobs.php');
        self::assertStringContainsString('function repo_sweep_orphaned_deploying_vms', $repo);
        // Only deploying orphans: an active (queued/running) job protects its
        // mission's VMs, and the sweep must not block on concurrent row locks.
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM deploy_jobs j WHERE j.mission_id = v.mission_id AND j.status IN (?, ?))', $repo);
        self::assertMatchesRegularExpression('/lifecycle_state = \?\s+AND NOT EXISTS/s', $repo);
        self::assertMatchesRegularExpression('/repo_sweep_orphaned_deploying_vms.*?FOR UPDATE SKIP LOCKED/s', $repo);
        self::assertStringContainsString('WHERE id = ? AND lifecycle_state = ?', $repo);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
