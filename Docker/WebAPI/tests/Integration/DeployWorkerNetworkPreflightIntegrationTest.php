<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php';

final class DeployWorkerNetworkPreflightIntegrationTest extends TestCase
{
    private mysqli $db;
    private mysqli $second;
    private string $prefix;
    private int $missionId;
    private int $vmId;
    private int $userId;
    private int $esxiId;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->second = new mysqli(
                envboot_required('DB_HOST'),
                envboot_required('DB_USER'),
                envboot_required('DB_PASS'),
                envboot_required('DB_NAME'),
                (int) envboot_optional('DB_PORT', '3306')
            );
            $this->second->set_charset('utf8mb4');
            $this->second->query("SET time_zone = '+00:00'");
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_worker14a_' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);

        $name = $this->prefix . '_mission';
        $status = 'active';
        $dc = 'DC1';
        $ds = 'DS1';
        $wds = 'WDS';
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssss', $name, $status, $dc, $ds, $wds);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper($this->prefix . '_vm');
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
        repo_execute(
            $this->db,
            "INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, '', '', '', 'WDS', '')",
            'i',
            [$this->vmId]
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ([$this->esxiId, $this->ansibleId] as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
        $this->second->close();
    }

    public function testActualWorkerPersistsNetworkBlockAndStartsNoRemoteWork(): void
    {
        $jobId = $this->queueFullJob();
        repo_execute($this->db, "UPDATE deploy_interfaces SET vlan = '' WHERE vm_id = ?", 'i', [$this->vmId]);

        $this->processClaimed($jobId);

        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED, $job['terminal_reason_code']);
        $result = vm_network_preflight_decode_result((string) $job['result_json']);
        self::assertNotNull($result);
        self::assertContains(VIRTUSPHERE_VM_NETWORK_EMPTY, array_column($result['vm_results'][0]['issues'], 'code'));
        $this->assertNoRemoteWork($jobId);
    }

    public function testDeletedExplicitSelectionIsBlockedWithStructuredProgress(): void
    {
        $jobId = $this->queueFullJob();
        self::assertTrue(repo_delete_vm_by_id($this->db, $this->missionId, $this->vmId));

        $this->processClaimed($jobId);

        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED, $job['terminal_reason_code']);
        $result = vm_network_preflight_decode_result((string) $job['result_json']);
        self::assertNotNull($result);
        self::assertSame([$this->vmId], $result['missing_vm_ids']);
        self::assertSame(1, $result['counts']['expected_vms']);
        self::assertSame(1, $result['counts']['blocked_vms']);
        self::assertSame(1, $result['counts']['missing_vms']);
        self::assertSame(1, $this->logCount($jobId, '[1/1] RUN network/WDS preflight VM #' . $this->vmId));
        self::assertSame(1, $this->logCount($jobId, '[1/1] FAIL network/WDS preflight VM #' . $this->vmId . ': selected VM no longer exists'));
        $this->assertNoRemoteWork($jobId);
    }

    public function testInterfacesGrowingAfterQueueAreBlockedBeforeRemoteWork(): void
    {
        $jobId = $this->queueFullJob();
        for ($i = 0; $i < VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM; $i++) {
            repo_execute($this->db, 'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)', 'isssss', [$this->vmId, '', '', '', 'extra-' . $i, '']);
        }
        $this->processClaimed($jobId);
        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED, $job['terminal_reason_code']);
        $this->assertNoRemoteWork($jobId);
    }

    public function testCancelWinsBeforeAtomicPreflightTerminalWrite(): void
    {
        $jobId = $this->queueFullJob();
        repo_execute($this->db, "UPDATE deploy_interfaces SET vlan = '' WHERE vm_id = ?", 'i', [$this->vmId]);

        $this->processClaimed($jobId, function () use ($jobId): void {
            self::assertSame(
                VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
                repo_cancel_deploy_job($this->second, $jobId, $this->userId),
                'the second connection must win immediately before the combined result/terminal CAS'
            );
        });

        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_OPERATOR_CANCELLED, $job['terminal_reason_code']);
        self::assertNull($job['result_json'], 'a cancelled job must not retain a network_preflight result');
        self::assertStringContainsString('no remote step was started', (string) $job['terminal_reason_detail']);
        self::assertStringNotContainsString('already running ran to its end', (string) $job['terminal_reason_detail']);
        $this->assertNoRemoteWork($jobId);
    }

    private function queueFullJob(): int
    {
        return repo_create_deploy_job(
            $this->db,
            $this->missionId,
            $this->userId,
            $this->esxiId,
            $this->ansibleId,
            ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'vm_ids' => [$this->vmId]]
        );
    }

    private function processClaimed(int $jobId, ?callable $observer = null): void
    {
        $workerId = 'phpunit:network-preflight-' . $jobId;
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, locked_at = NOW(), heartbeat_at = NOW() WHERE id = ?',
            'ssi',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $workerId, $jobId]
        );
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        deploy_worker_process_job($this->db, $job, $workerId, [
            'once' => true,
            'cleanup' => true,
            'sleep' => 1,
            'network_preflight_block_observer' => $observer,
        ]);
    }

    private function assertNoRemoteWork(int $jobId): void
    {
        self::assertSame(0, (int) repo_scalar(
            $this->db,
            "SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND (line LIKE 'Running Ansible host preflight.%' OR line LIKE 'Deploy files prepared:%' OR line LIKE 'Running Ansible playbook sequence:%')",
            'i',
            [$jobId]
        ));
    }

    private function logCount(int $jobId, string $line): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line = ?', 'is', [$jobId, $line]);
    }

    private function insertCredential(string $type, int $port): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.example.invalid';
        $user = 'svc';
        $secret = 'ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();
        return (int) $this->db->insert_id;
    }
}
