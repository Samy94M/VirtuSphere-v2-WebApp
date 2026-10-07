<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
$vmJournalRepo = dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';
if (is_file($vmJournalRepo)) {
    require_once $vmJournalRepo;
}

/**
 * The convergence sweep the maintenance worker runs: VMs stuck in `deploying`
 * whose mission has no queued/running job converge to failed/failed, while an
 * active job protects its mission's VMs and non-deploying states stay whatever
 * they are. Stored MACs must survive the sweep.
 */
final class DeployVmConvergenceSweepTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_sweep_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
    }

    public function testOrphanedDeployingVmConvergesToFailedFailedAndKeepsItsMac(): void
    {
        $missionId = $this->insertMission('orphan');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $mac = '02:AA:BB:CC:DD:01';
        $this->insertInterface($vmId, 'WDS', $mac);
        $jobId = $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, [$vmId]);
        $this->markAndTouch($jobId, [$vmId], [$vmId]);
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        $sweptVmIds = array_column($swept, 'vm_id');
        self::assertContains($vmId, $sweptVmIds);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->vmState($vmId));
        self::assertSame($mac, $this->interfaceMac($vmId), 'a sweep must never touch stored MACs');
        self::assertSame(1, $this->sweepEventCount($vmId), 'the convergence must leave a status event');
    }

    public function testActiveJobsProtectTheirMissionsVms(): void
    {
        $runningMission = $this->insertMission('running');
        $runningVm = $this->insertVm($runningMission, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->insertJob($runningMission, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, [$runningVm]);

        $queuedMission = $this->insertMission('queued');
        $queuedVm = $this->insertVm($queuedMission, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->insertJob($queuedMission, VIRTUSPHERE_DEPLOY_STATUS_QUEUED, [$queuedVm]);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        $sweptVmIds = array_column($swept, 'vm_id');
        self::assertNotContains($runningVm, $sweptVmIds);
        self::assertNotContains($queuedVm, $sweptVmIds);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($runningVm));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($queuedVm));
    }

    public function testOnlyDeployingVmsAreSwept(): void
    {
        $missionId = $this->insertMission('deployed');
        $deployedVm = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, [$deployedVm]);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        self::assertNotContains($deployedVm, array_column($swept, 'vm_id'));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->vmState($deployedVm), 'a committed import result outlives its terminal job');
    }

    public function testHistoricalDeployingVmWithoutJournalIsLeftUntouched(): void
    {
        $missionId = $this->insertMission('idempotent');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, [$vmId]);

        $firstSweptIds = array_column(repo_sweep_orphaned_deploying_vms($this->db), 'vm_id');
        $secondSweptIds = array_column(repo_sweep_orphaned_deploying_vms($this->db), 'vm_id');

        self::assertNotContains($vmId, $firstSweptIds, 'historical state has no journal proving who may repair it');
        self::assertNotContains($vmId, $secondSweptIds);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($vmId));
        self::assertSame(0, $this->sweepEventCount($vmId));
    }

    public function testJournaledUnmarkedBracketIsRestoredBySweep(): void
    {
        if (!function_exists('repo_deploy_job_vm_states')) {
            self::markTestSkipped('K15 journal storage is not installed on this baseline.');
        }
        $missionId = $this->insertMission('restore');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, [$vmId]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'sweep bracket capture', [$vmId]);
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_FAILED);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        self::assertContains($vmId, array_column($swept, 'vm_id'));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($vmId));
    }

    public function testJournalFromAnOlderExecutionGenerationCannotRepairVm(): void
    {
        if (!function_exists('repo_deploy_job_vm_states')) {
            self::markTestSkipped('K15 journal storage is not installed on this baseline.');
        }
        $missionId = $this->insertMission('old-generation');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, [$vmId]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'old generation capture', [$vmId]);
        $staleGeneration = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ?, execution_generation_id = UNHEX(?) WHERE id = ?');
        $failed = VIRTUSPHERE_DEPLOY_STATUS_FAILED;
        $stmt->bind_param('ssi', $failed, $staleGeneration, $jobId);
        $stmt->execute();

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        self::assertNotContains($vmId, array_column($swept, 'vm_id'));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($vmId));
    }

    public function testSweepKeepsImportedSuccessFailsTouchedVmAndRestoresUntouchedVm(): void
    {
        if (!function_exists('repo_deploy_job_vm_admit_touch')) {
            self::markTestSkipped('K15 per-VM touch admission is not installed on this baseline.');
        }
        $missionId = $this->insertMission('per-vm');
        $imported = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $touched = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $untouched = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, [$imported, $touched, $untouched], $this->resultJson([$imported], [$touched]));
        $claim = $this->job($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'sweep per-VM setup', [$imported, $touched, $untouched]);
        self::assertTrue(repo_deploy_job_vm_admit_touch($this->db, $jobId, $touched,
            (int) $claim['attempts'], (string) $claim['execution_generation_id'], 'export'));
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_FAILED);

        repo_sweep_orphaned_deploying_vms($this->db);

        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->vmState($imported));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->vmState($touched));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->vmState($untouched));
        self::assertSame([$imported], json_decode((string) $this->job($jobId)['result_json'], true)['successful_vm_ids']);
    }

    private function insertMission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;

        return $id;
    }

    private function insertVm(int $missionId, string $lifecycleState, string $mecmSyncState): int
    {
        $name = strtoupper($this->prefix . '_' . $lifecycleState . '_' . count($this->missionIds));
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $missionId, $name, $name, $lifecycleState, $mecmSyncState);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertInterface(int $vmId, string $vlan, string $mac): void
    {
        $empty = '';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $mac);
        $stmt->execute();
    }

    /** @param list<int> $vmIds */
    private function insertJob(int $missionId, string $status, array $vmIds, ?string $resultJson = null): int
    {
        $payload = json_encode(['mode' => 'export', 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        $worker = 'phpunit:sweep-worker';
        if (in_array($status, [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)) {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, result_json, locked_at, locked_by, lock_token, worker_epoch, attempts, heartbeat_at, execution_contract, execution_generation_id) '
                . 'SELECT ?, ?, ?, ?, NOW(), ?, REPEAT(CHAR(98), 32), 1, 1, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1');
            $stmt->bind_param('isssss', $missionId, $status, $payload, $resultJson, $worker, $contract);
        } else {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, result_json, heartbeat_at, execution_contract, execution_generation_id) '
                . 'SELECT ?, ?, ?, ?, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1');
            $stmt->bind_param('issss', $missionId, $status, $payload, $resultJson, $contract);
        }
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param list<int> $vmIds @param list<int> $touchedVmIds */
    private function markAndTouch(int $jobId, array $vmIds, array $touchedVmIds): void
    {
        if (!function_exists('repo_deploy_job_vm_admit_touch')) {
            self::markTestSkipped('K15 per-VM touch admission is not installed on this baseline.');
        }
        $job = $this->job($jobId);
        deploy_worker_mark_vms_deploying($this->db, $job, 'K15 sweep setup', $vmIds);
        foreach ($touchedVmIds as $vmId) {
            self::assertTrue(repo_deploy_job_vm_admit_touch($this->db, $jobId, $vmId,
                (int) $job['attempts'], (string) $job['execution_generation_id'], 'export'));
        }
    }

    private function setJobStatus(int $jobId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $jobId);
        $stmt->execute();
    }

    /** @return array<string,mixed> */
    private function job(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        return $job;
    }

    /** @param list<int> $successful @param list<int> $failed */
    private function resultJson(array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => $failed === [] ? 'success' : ($successful === [] ? 'failed' : 'partial'),
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'errors' => [],
            'counts' => ['expected_vms' => count($successful) + count($failed), 'successful_vms' => count($successful), 'failed_vms' => count($failed), 'updated_interfaces' => count($successful)],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{0:string,1:string} */
    private function vmState(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }

    private function interfaceMac(int $vmId): string
    {
        $stmt = $this->db->prepare('SELECT mac FROM deploy_interfaces WHERE vm_id = ? LIMIT 1');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();

        return (string) ($stmt->get_result()->fetch_assoc()['mac'] ?? '');
    }

    private function sweepEventCount(int $vmId): int
    {
        $note = 'convergence sweep: stuck in deploying without an active deploy job';
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_vm_status_events WHERE vm_id = ? AND note = ?');
        $stmt->bind_param('is', $vmId, $note);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }
}
