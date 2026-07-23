<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

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
        $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED);

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
        $this->insertJob($runningMission, VIRTUSPHERE_DEPLOY_STATUS_RUNNING);

        $queuedMission = $this->insertMission('queued');
        $queuedVm = $this->insertVm($queuedMission, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->insertJob($queuedMission, VIRTUSPHERE_DEPLOY_STATUS_QUEUED);

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
        $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        self::assertNotContains($deployedVm, array_column($swept, 'vm_id'));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->vmState($deployedVm), 'a committed import result outlives its terminal job');
    }

    public function testSweepIsIdempotent(): void
    {
        $missionId = $this->insertMission('idempotent');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED);

        $firstSweptIds = array_column(repo_sweep_orphaned_deploying_vms($this->db), 'vm_id');
        $secondSweptIds = array_column(repo_sweep_orphaned_deploying_vms($this->db), 'vm_id');

        self::assertContains($vmId, $firstSweptIds);
        self::assertNotContains($vmId, $secondSweptIds);
        self::assertSame(1, $this->sweepEventCount($vmId), 'a second sweep must not duplicate the status event');
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

    private function insertJob(int $missionId, string $status): int
    {
        $payload = json_encode(['mode' => 'export', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $missionId, $status, $payload);
        $stmt->execute();

        return (int) $this->db->insert_id;
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
