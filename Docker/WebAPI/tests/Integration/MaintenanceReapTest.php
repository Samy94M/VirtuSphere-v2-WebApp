<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';

/**
 * The maintenance worker's second reaper (AP6): a running job whose worker
 * hangs inside a blocking call is reaped on the maintenance interval, with the
 * same MAC-aware VM convergence as the deploy worker's own loop-start reap.
 * Complements DeployJobReaperTest (repo level) by driving the maintenance
 * entry function the interval wiring calls.
 */
final class MaintenanceReapTest extends TestCase
{
    private mysqli $db;
    private string $prefix;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_maintreap_' . bin2hex(random_bytes(4));
        $this->deleteTestMissions();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        $this->deleteTestMissions();
    }

    public function testMaintenanceReapFailsStaleJobAndConvergesVmsKeepingImportedOnes(): void
    {
        $this->skipWhenForeignRunningJobsExist();

        $missionId = $this->insertMission($this->prefix . '_stale');
        $importedVmId = $this->insertVm($missionId, 'vm-imported', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $stuckVmId = $this->insertVm($missionId, 'vm-stuck', VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);

        $resultJson = json_encode([
            'version' => 1,
            'kind' => 'mac_import',
            'outcome' => 'partial',
            'successful_vm_ids' => [$importedVmId],
            'failed_vm_ids' => [$stuckVmId],
            'errors' => [],
            'counts' => ['expected_vms' => 2, 'successful_vms' => 1, 'failed_vms' => 1, 'updated_interfaces' => 1],
            'retry' => ['mode' => 'export', 'vm_ids' => [$stuckVmId]],
        ], JSON_THROW_ON_ERROR);
        $jobId = $this->insertStaleRunningJob($missionId, [$importedVmId, $stuckVmId], $resultJson);

        maintenance_worker_reap_deploy_jobs($this->db);

        $job = $this->row('SELECT status, locked_by FROM deploy_jobs WHERE id = ' . $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertNull($job['locked_by']);

        // The committed import is the truth and survives the reap; only the
        // VM without an import converges to failed/failed.
        $imported = $this->row('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ' . $importedVmId);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYED, $imported['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_PENDING, $imported['mecm_sync_state']);

        $stuck = $this->row('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ' . $stuckVmId);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $stuck['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_FAILED, $stuck['mecm_sync_state']);
    }

    public function testMaintenanceReapLeavesFreshJobsAlone(): void
    {
        $this->skipWhenForeignRunningJobsExist();

        $missionId = $this->insertMission($this->prefix . '_fresh');
        $vmId = $this->insertVm($missionId, 'vm-live', VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertStaleRunningJob($missionId, [$vmId], null, 0);

        maintenance_worker_reap_deploy_jobs($this->db);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $this->row('SELECT status FROM deploy_jobs WHERE id = ' . $jobId)['status']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->row('SELECT lifecycle_state FROM deploy_vms WHERE id = ' . $vmId)['lifecycle_state']);
    }

    private function insertMission(string $name): int
    {
        $status = 'Aktiv';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertVm(int $missionId, string $name, string $lifecycle, string $mecm): int
    {
        $vmName = $this->prefix . '_' . $name;
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $missionId, $vmName, $vmName, $lifecycle, $mecm);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * @param int[] $vmIds
     */
    private function insertStaleRunningJob(int $missionId, array $vmIds, ?string $resultJson, int $heartbeatAgeSeconds = 700): int
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $worker = 'phpunit:maintreap-worker';
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'verbose' => false, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, locked_at, locked_by, heartbeat_at, payload_json, result_json) VALUES (?, ?, NOW(), ?, NOW(), ?, ?)');
        $stmt->bind_param('issss', $missionId, $running, $worker, $payload, $resultJson);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;

        if ($heartbeatAgeSeconds > 0) {
            $stmt = $this->db->prepare('UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?');
            $stmt->bind_param('ii', $heartbeatAgeSeconds, $jobId);
            $stmt->execute();
        }

        return $jobId;
    }

    private function skipWhenForeignRunningJobsExist(): void
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $like = 'phpunit_maintreap_%';
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_jobs j INNER JOIN deploy_missions m ON m.id = j.mission_id WHERE j.status = ? AND m.mission_name NOT LIKE ?');
        $stmt->bind_param('ss', $running, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ((int) ($row['c'] ?? 0) > 0) {
            self::markTestSkipped('Foreign running deploy jobs exist; global reaper test would mutate live state.');
        }
    }

    private function deleteTestMissions(): void
    {
        $like = 'phpunit_maintreap_%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sql): array
    {
        $result = $this->db->query($sql);
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        self::assertIsArray($row);

        return $row;
    }
}
