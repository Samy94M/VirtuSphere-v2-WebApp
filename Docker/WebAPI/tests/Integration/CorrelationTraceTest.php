<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * ADR-0032 test matrix, points 2-4: the enqueue persists the request's id on
 * the job (group slots share it), the retry runs under a NEW id and links the
 * old trace in its first system line, the job-log helper stamps the adopted
 * id, and the audit writer stamps the current trace.
 */
final class CorrelationTraceTest extends TestCase
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

        $this->prefix = 'phpunit_corr_' . bin2hex(random_bytes(4));
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
        virtusphere_correlation_adopt(null);
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
        $stmt = $this->db->prepare("DELETE FROM deploy_logs WHERE log_message LIKE ?");
        $like = '%' . $this->prefix . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
    }

    public function testEnqueuePersistsTheRequestIdAndTheQueueLineCarriesIt(): void
    {
        virtusphere_correlation_adopt('feedface00000010');
        $missionId = $this->insertMissionWithVms('single', 1);

        $jobId = repo_create_deploy_job($this->db, $missionId, $this->userId, $this->esxiCredentialId, $this->ansibleCredentialId, ['mode' => 'export'], null);

        self::assertSame('feedface00000010', $this->jobCorrelation($jobId));
        self::assertSame(['feedface00000010'], $this->jobLogCorrelations($jobId), 'the queue line carries the same trace');
    }

    public function testGroupSlotsShareTheEnqueueingRequestsId(): void
    {
        virtusphere_correlation_adopt('feedface00000011');
        $missionId = $this->insertMissionWithVms('group', 2);

        $result = repo_enqueue_deploy_group($this->db, $missionId, $this->userId, $this->esxiCredentialId, $this->ansibleCredentialId, ['mode' => 'powercycle'], gmdate('Y-m-d H:i:s', time() + 3600), 5);

        self::assertSame(2, $result['count']);
        $stmt = $this->db->prepare('SELECT DISTINCT correlation_id FROM deploy_jobs WHERE group_id = ?');
        $stmt->bind_param('s', $result['group_id']);
        $stmt->execute();
        $distinct = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'correlation_id');
        self::assertSame(['feedface00000011'], $distinct, 'one click, one trace, even fanned out into slots');
    }

    public function testRetryRunsUnderANewIdAndLinksTheOldTrace(): void
    {
        virtusphere_correlation_adopt('feedface00000012');
        $missionId = $this->insertMissionWithVms('retry', 1);
        $jobId = repo_create_deploy_job($this->db, $missionId, $this->userId, $this->esxiCredentialId, $this->ansibleCredentialId, ['mode' => 'export'], null);
        $status = VIRTUSPHERE_DEPLOY_STATUS_FAILED;
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $jobId);
        $stmt->execute();

        // The retrying request is a different execution with its own id.
        virtusphere_correlation_adopt('feedface00000013');
        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        self::assertSame('feedface00000013', $this->jobCorrelation($newJobId), 'two runs, two traces');
        $lines = $this->jobLogLines($newJobId);
        $linked = array_filter($lines, static fn (array $row): bool => str_contains((string) $row['line'], '[correlation feedface00000012]'));
        self::assertNotSame([], $linked, 'the retry line must reference the old trace');
    }

    public function testAuditRowsCarryTheCurrentTrace(): void
    {
        virtusphere_correlation_adopt('feedface00000014');
        audit($this->db, VIRTUSPHERE_LOG_CATEGORY_SYSTEM, 'correlation probe ' . $this->prefix, $this->userId);

        $stmt = $this->db->prepare('SELECT correlation_id FROM deploy_logs WHERE log_message = ? ORDER BY id DESC LIMIT 1');
        $message = 'correlation probe ' . $this->prefix;
        $stmt->bind_param('s', $message);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertSame('feedface00000014', (string) ($row['correlation_id'] ?? ''));
    }

    private function insertCredential(string $type): int
    {
        $name = $this->prefix . '_' . $type;
        $host = '127.0.0.1';
        $username = 'svc';
        $ciphertext = 'x';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $type, $name, $host, $username, $ciphertext);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertMissionWithVms(string $suffix, int $vmCount): int
    {
        $missionId = repo_create_mission($this->db, [
            'mission_name' => $this->prefix . '_' . $suffix,
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'phpunit.example.local',
        ], false, $this->userId);
        $this->missionIds[] = $missionId;

        for ($index = 1; $index <= $vmCount; $index++) {
            $vmName = strtoupper($this->prefix) . $suffix . $index;
            $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $missionId, $vmName, $vmName);
            $stmt->execute();
        }

        return $missionId;
    }

    private function jobCorrelation(int $jobId): string
    {
        $stmt = $this->db->prepare('SELECT correlation_id FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return (string) ($stmt->get_result()->fetch_assoc()['correlation_id'] ?? '');
    }

    /** @return list<array{line: string, correlation_id: ?string}> */
    private function jobLogLines(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT line, correlation_id FROM deploy_job_logs WHERE job_id = ? ORDER BY seq');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<string> distinct correlation ids on the job's log lines */
    private function jobLogCorrelations(int $jobId): array
    {
        $ids = [];
        foreach ($this->jobLogLines($jobId) as $row) {
            $ids[(string) $row['correlation_id']] = true;
        }

        return array_keys($ids);
    }
}
