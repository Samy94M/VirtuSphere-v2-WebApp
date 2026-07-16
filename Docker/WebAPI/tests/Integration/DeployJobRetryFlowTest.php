<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * The repo half of the retry matrix: repo_retry_deploy_job() must turn the
 * unit-tested deploy_job_retry_plan() verdict into a real queued job. Matrix 7
 * (a partial job re-queues export for exactly its failed VMs) and Matrix 16
 * (a failed job whose stored outcome says success repeats export for the
 * original selection, never the full deploy).
 */
final class DeployJobRetryFlowTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $userId;
    private int $esxiCredentialId;
    private int $ansibleCredentialId;
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_retry_' . bin2hex(random_bytes(4));
        $name = $this->prefix . '_user';
        $password = password_hash('irrelevant', PASSWORD_DEFAULT);
        $email = $this->prefix . '@example.invalid';
        $stmt = $this->db->prepare("INSERT INTO deploy_users (name, password, email, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param('sss', $name, $password, $email);
        $stmt->execute();
        $this->userId = (int) $this->db->insert_id;

        $this->esxiCredentialId = $this->insertCredential('esxi');
        $this->ansibleCredentialId = $this->insertCredential('ansible');
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
        foreach ([$this->esxiCredentialId, $this->ansibleCredentialId] as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
    }

    /** Matrix 7: retry of a partial job queues export for exactly the failed VMs. */
    public function testPartialJobRetriesExportForOnlyTheFailedVms(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('partial', 3);
        [$okA, $okB, $failed] = $vmIds;
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, $this->resultJson('partial', [$okA, $okB], [$failed]));

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame('export', $payload['mode'], 'a partial retry must never repeat create/powercycle');
        self::assertSame([$failed], $payload['vm_ids']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, $this->jobStatus($newJobId));
        self::assertStringContainsString('export-only: 1 failed VMs', $this->jobLog($newJobId));
    }

    /** Matrix 16: divergence (job failed, stored outcome success) repeats export for the original selection. */
    public function testDivergentFailedJobRetriesExportForTheOriginalSelection(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('diverge', 2);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, $this->resultJson('success', $vmIds, []));

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame('export', $payload['mode'], 'a committed import must never be redeployed from scratch');
        self::assertSame($vmIds, $payload['vm_ids']);
        self::assertStringContainsString('export-only: original selection', $this->jobLog($newJobId));
    }

    /** The pre-existing branch: a plain failed job without a result re-queues its old payload. */
    public function testPlainFailedJobRequeuesTheOriginalPayload(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('plain', 2);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, null);

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, $payload['mode']);
        self::assertSame($vmIds, $payload['vm_ids']);
        self::assertStringNotContainsString('export-only', $this->jobLog($newJobId));
    }

    public function testActiveAndSucceededJobsCannotBeRetried(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('guard', 1);
        foreach ([VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED] as $status) {
            $jobId = $this->insertTerminalJob($missionId, $status, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, null);
            try {
                repo_retry_deploy_job($this->db, $jobId, $this->userId);
                self::fail($status . ' must not be retryable');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('can be retried', $exception->getMessage());
            }
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
        }
    }

    private function insertCredential(string $type): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.phpunit.invalid';
        $user = 'svc';
        $secret = 'phpunit-ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $type, $name, $host, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @return array{0:int,1:list<int>} */
    private function insertMissionWithVms(string $suffix, int $vmCount): array
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $datacenter = 'DC1';
        $datastore = 'datastore1';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $status, $datacenter, $datastore);
        $stmt->execute();
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;

        $vmIds = [];
        for ($i = 0; $i < $vmCount; $i++) {
            $vmName = strtoupper($name . '_VM' . $i);
            $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $missionId, $vmName, $vmName);
            $stmt->execute();
            $vmIds[] = (int) $this->db->insert_id;
        }

        return [$missionId, $vmIds];
    }

    /** @param list<int> $vmIds */
    private function insertTerminalJob(int $missionId, string $status, string $mode, array $vmIds, ?string $resultJson): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, result_json, credential_esxi_id, credential_ansible_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisssii', $missionId, $this->userId, $status, $payload, $resultJson, $this->esxiCredentialId, $this->ansibleCredentialId);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param list<int> $successful @param list<int> $failed */
    private function resultJson(string $outcome, array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => $outcome,
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'errors' => [],
            'counts' => [
                'expected_vms' => count($successful) + count($failed),
                'successful_vms' => count($successful),
                'failed_vms' => count($failed),
                'updated_interfaces' => count($successful),
            ],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function jobPayload(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function jobStatus(int $jobId): string
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return (string) $job['status'];
    }

    private function jobLog(int $jobId): string
    {
        $stmt = $this->db->prepare('SELECT line FROM deploy_job_logs WHERE job_id = ? ORDER BY id');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return implode("\n", array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'line'));
    }
}
