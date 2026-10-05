<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php';

/**
 * K2 / DF-L8 + MR-02: the Ansible host preflight, including the allowlist
 * probe, runs BEFORE the job marks its VMs `deploying`. A job that ends there
 * changed nothing on ESXi, so it must not change a VM either: no `deploying`,
 * no failure convergence, no MECM state, no status event.
 */
final class DeployWorkerHostPreflightIntegrationTest extends TestCase
{
    private mysqli $db;
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
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_hostpf_' . bin2hex(random_bytes(4));
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

        // A registered VM of a rolled-out mission: exactly the state MR-02 says
        // the old failure path repainted to failed/failed.
        $vmName = strtoupper($this->prefix . '_vm');
        $lifecycle = VIRTUSPHERE_LIFECYCLE_OS_INSTALLED;
        $mecm = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $this->missionId, $vmName, $vmName, $lifecycle, $mecm);
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
    }

    public function testDeniedAllowlistBlocksAFullJobBeforeAnyUploadOrVmWrite(): void
    {
        $before = $this->vmState();
        $jobId = $this->queueJob(VIRTUSPHERE_DEPLOY_MODE_FULL);

        $this->processClaimed($jobId, $this->runner(0, [
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST,
            VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' denied 10.20.30.40',
        ]));

        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED, $job['terminal_reason_code']);
        $result = deploy_host_preflight_decode_result((string) $job['result_json']);
        self::assertNotNull($result, 'the block must be stored as a structured result the portal can link from');
        self::assertSame(VIRTUSPHERE_DEPLOY_HOST_PREFLIGHT_ALLOWLIST_DENIED, $result['blocker']);
        self::assertSame('10.20.30.40', $result['ip']);
        self::assertSame(0, $this->logCount($jobId, 'Deploy files prepared:%'), 'no artifact, no upload');
        self::assertSame($before, $this->vmState(), 'a job blocked before the remote work must not touch its VMs');
    }

    public function testFailedHostComponentLeavesVmStatesUnchanged(): void
    {
        $before = $this->vmState();
        $jobId = $this->queueJob(VIRTUSPHERE_DEPLOY_MODE_FULL);

        $this->processClaimed($jobId, $this->runner(1, [
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ansible-playbook',
            'ansible-playbook: command not found',
        ]));

        $job = repo_deploy_job($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED, $job['terminal_reason_code']);
        self::assertStringContainsString('Ansible host preflight failed', (string) $job['last_error']);
        self::assertSame($before, $this->vmState(), 'before K2 every VM of the scope ended failed/failed here');
    }

    public function testUnknownAllowlistVerdictDoesNotBlock(): void
    {
        $jobId = $this->queueJob(VIRTUSPHERE_DEPLOY_MODE_FULL);

        $this->processClaimed($jobId, $this->runner(0, [
            VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' unknown',
        ]));

        // The job goes on into artifact preparation (and fails later on the
        // unreachable fixture host); only a definite `denied` may block.
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CONFIGURATION_BLOCKED, $job['terminal_reason_code']);
        self::assertNull(deploy_host_preflight_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null));
    }

    /** @return array{lifecycle_state:string,mecm_sync_state:string,events:int} */
    private function vmState(): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);
        self::assertNotNull($row);

        return [
            'lifecycle_state' => (string) $row['lifecycle_state'],
            'mecm_sync_state' => (string) $row['mecm_sync_state'],
            'events' => (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vm_status_events WHERE vm_id = ?', 'i', [$this->vmId]),
        ];
    }

    /** @param list<string> $lines */
    private function runner(int $exitCode, array $lines): Closure
    {
        return static function (string $command, callable $onChunk) use ($exitCode, $lines): int {
            $onChunk(implode("\n", $lines) . "\n");

            return $exitCode;
        };
    }

    private function queueJob(string $mode): int
    {
        return repo_create_deploy_job(
            $this->db,
            $this->missionId,
            $this->userId,
            $this->esxiId,
            $this->ansibleId,
            ['mode' => $mode, 'vm_ids' => [$this->vmId]]
        );
    }

    private function processClaimed(int $jobId, Closure $hostPreflightRunner): void
    {
        $workerId = 'phpunit:host-preflight-' . $jobId;
        $lockToken = bin2hex(random_bytes(16));
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = 0, attempts = 1,'
            . ' execution_generation_id = (SELECT current_generation_id FROM deploy_runtime_identity WHERE id = 1),'
            . ' locked_at = NOW(), heartbeat_at = NOW() WHERE id = ?',
            'sssi',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $workerId, $lockToken, $jobId]
        );
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        deploy_worker_process_job($this->db, $job, $workerId, [
            'once' => true,
            'cleanup' => true,
            'sleep' => 1,
            'host_preflight_runner' => $hostPreflightRunner,
        ]);
    }

    private function logCount(int $jobId, string $pattern): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line LIKE ?', 'is', [$jobId, $pattern]);
    }

    private function insertCredential(string $type, int $port): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.example.invalid';
        $user = 'svc';
        $secret = crypto_encrypt_secret('fixture-secret');
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }
}
