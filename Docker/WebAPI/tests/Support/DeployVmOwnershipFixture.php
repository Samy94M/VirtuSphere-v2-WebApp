<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

/** Shared persisted claims for the ownership and two-session boundary tests. */
trait DeployVmOwnershipFixture
{
    private mysqli $db;
    private int $missionId = 0;
    private int $vmId;
    private string $vmName;
    private const WORKER = 'phpunit:vm-ownership';

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $error) {
            self::markTestSkipped('Database is not reachable: ' . $error->getMessage());
        }
        $this->vmName = 'phpunit_vmfence_' . bin2hex(random_bytes(5));
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status, wds_vlan) VALUES (?, ?, ?)',
            'sss', [$this->vmName, 'active', 'WDS']);
        $this->missionId = (int) $this->db->insert_id;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)',
            'issss', [$this->missionId, $this->vmName, $this->vmName, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY]);
        $this->vmId = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode, type) VALUES (?, '', '', '', ?, '', 'dhcp', 'vmxnet3')",
            'is', [$this->vmId, 'WDS']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->missionId > 0) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        }
    }

    private function claim(string $mode = 'start', string $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING): array
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => [$this->vmId]], JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_by, lock_token, worker_epoch, attempts, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, ?, ?, 1, 1, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1',
            'isssss', [$this->missionId, $status, $payload, self::WORKER, bin2hex(random_bytes(16)), VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY]);
        $job = repo_deploy_job($this->db, (int) $this->db->insert_id);
        self::assertNotNull($job);

        return $job;
    }

    /**
     * An accepted V2 callback transaction using the production planning,
     * ownership and result builders. HTTP routing is covered separately by
     * MacImportCallbackTest; this fixture exposes the before-commit boundary.
     */
    private function commitImport(array $job, ?mysqli $connection = null, ?callable $beforeCommit = null): void
    {
        $connection ??= $this->db;
        repo_transaction($connection, function () use ($job, $connection, $beforeCommit): void {
            repo_fetch_one($connection, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$this->missionId]);
            $current = repo_fetch_one($connection, 'SELECT status, payload_json, attempts, execution_contract, LOWER(HEX(execution_generation_id)) AS execution_generation_id FROM deploy_jobs WHERE id = ? FOR UPDATE',
                'i', [(int) $job['id']]);
            self::assertNotNull($current);
            $payload = mac_import_callback_job_payload($current);
            $runtime = repo_fetch_one($connection, 'SELECT LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
            self::assertNull(mac_import_callback_fence_reason($current, (string) $runtime['generation_id'], null));
            $mac = '02' . substr(hash('sha256', $this->vmName), 0, 10);
            $results = [['instance' => ['hw_name' => $this->vmName, 'hw_eth0' => ['macaddress' => $mac, 'summary' => 'WDS']]]];
            $plan = mac_import_build_plan($connection, $this->missionId, $results, true, $payload['scope_ids'], 'WDS');
            self::assertSame('success', $plan['outcome'], json_encode($plan['errors'], JSON_THROW_ON_ERROR));
            $fingerprint = mac_import_callback_fingerprint($this->missionId, (int) $job['id'], $results, [$this->vmId]);
            $result = json_encode(mac_import_result_contract($plan, $fingerprint), JSON_THROW_ON_ERROR);
            foreach ($plan['vm_plans'][$this->vmId]['updates'] as $update) {
                repo_execute($connection, 'UPDATE deploy_interfaces SET mac = ? WHERE id = ? AND vm_id = ?',
                    'sii', [(string) $update['mac'], (int) $update['id'], $this->vmId]);
            }
            repo_execute($connection, 'UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = 1 WHERE id = ?',
                'sssi', [VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_DEPLOYED, $this->vmId]);
            repo_record_vm_status_event($connection, $this->vmId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_DEPLOYED, 'ansible mac import');
            repo_execute($connection, 'UPDATE deploy_jobs SET result_json = ? WHERE id = ?', 'si', [$result, (int) $job['id']]);
            if ($beforeCommit !== null) {
                $beforeCommit();
            }
        });
    }

    private function state(?mysqli $connection = null): array
    {
        return repo_fetch_one($connection ?? $this->db, 'SELECT lifecycle_state, mecm_sync_state, vm_status, updated, mecm_id, updated_at, (SELECT COUNT(*) FROM deploy_vm_status_events WHERE vm_id = ?) AS events, (SELECT mac FROM deploy_interfaces WHERE vm_id = ? LIMIT 1) AS mac FROM deploy_vms WHERE id = ?',
            'iii', [$this->vmId, $this->vmId, $this->vmId]) ?? [];
    }
}
