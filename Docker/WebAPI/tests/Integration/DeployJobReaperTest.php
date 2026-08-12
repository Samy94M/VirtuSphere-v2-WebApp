<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/repo/deploy_jobs.php';

final class DeployJobReaperTest extends TestCase
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

        $this->prefix = 'phpunit_phase_c_' . bin2hex(random_bytes(4));
        $this->deleteTestMissions();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        $this->deleteTestMissions();
    }

    public function testReapStaleDeployJobsMarksOnlyStaleRunningJobsFailed(): void
    {
        $this->skipWhenForeignRunningJobsExist();

        $staleMissionId = $this->insertMission($this->prefix . '_stale');
        $freshMissionId = $this->insertMission($this->prefix . '_fresh');
        $staleJobId = $this->insertRunningJob($staleMissionId, 'worker-stale', 700);
        $freshJobId = $this->insertRunningJob($freshMissionId, 'worker-fresh', 0);

        $reaped = repo_reap_stale_deploy_jobs($this->db, 600);

        self::assertSame([$staleJobId], array_map(static fn (array $job): int => (int) $job['id'], $reaped));

        $stale = $this->job($staleJobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $stale['status']);
        self::assertNull($stale['locked_by']);
        self::assertNull($stale['heartbeat_at']);
        self::assertStringContainsString('limit 600 s', (string) $stale['last_error']);
        self::assertSame(1, $this->systemLogCount($staleJobId, 'Reaped stale deploy job%'));

        $fresh = $this->job($freshJobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $fresh['status']);
        self::assertSame('worker-fresh', $fresh['locked_by']);
        self::assertNotNull($fresh['heartbeat_at']);
    }

    /**
     * The message the operator actually reads. "no heartbeat for 600 seconds"
     * names the mechanism that noticed, never what happened: a stopped service
     * and a database outage under a perfectly healthy worker produced the same
     * sentence, so the one instruction it implies (restart the service) was as
     * likely wrong as right. The caller establishes the cause and it travels
     * into last_error and the job log, which are the two places anybody looks.
     */
    public function testTheReapMessageCarriesTheCallersSeparateObservation(): void
    {
        $this->skipWhenForeignRunningJobsExist();

        $missionId = $this->insertMission($this->prefix . '_cause');
        $jobId = $this->insertRunningJob($missionId, 'worker-gone', 700);
        $observation = 'Separate observation at this moment: no deploy service is reporting its status row.';

        repo_reap_stale_deploy_jobs($this->db, 600, $observation);

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertStringContainsString('limit 600 s', (string) $job['last_error'], 'the observation stays');
        self::assertStringContainsString($observation, (string) $job['last_error'], 'and the caller\'s note joins it');
        self::assertSame(1, $this->systemLogCount($jobId, '%' . $observation . '%'), 'the job log carries the same sentence');
    }

    /**
     * The message is an account of what this transaction could see, and nothing
     * else: the job, who held its lock, how stale its heartbeat is against the
     * limit, and the transition. It used to end at "no heartbeat for N seconds",
     * which names the mechanism that noticed rather than the event, and the
     * sentence that followed asserted a cause nothing had checked.
     */
    public function testTheMessageStatesOnlyWhatTheRowShows(): void
    {
        $this->skipWhenForeignRunningJobsExist();

        $missionId = $this->insertMission($this->prefix . '_nocause');
        $jobId = $this->insertRunningJob($missionId, 'worker-gone', 700);

        repo_reap_stale_deploy_jobs($this->db, 600);

        $lastError = (string) $this->job($jobId)['last_error'];
        self::assertStringContainsString('Reaped stale deploy job.', $lastError);
        self::assertStringContainsString('Job ' . $jobId . ':', $lastError, 'the job it is about');
        self::assertStringContainsString('lock held by worker-gone', $lastError, 'who held it');
        self::assertStringContainsString('limit 600 s', $lastError, 'against which limit');
        self::assertStringContainsString('running -> failed', $lastError, 'and the transition that follows');
        self::assertMatchesRegularExpression('/last heartbeat \d+ s ago/', $lastError);

        // No cause is claimed anywhere in it.
        foreach (['did not die', 'stopped reporting as well', 'database outage', 'the worker died'] as $claim) {
            self::assertStringNotContainsString($claim, $lastError, 'the reaper must not assert a cause it did not establish');
        }
    }

    public function testFinishDeployJobRequiresMatchingWorkerLockAndRunningStatus(): void
    {
        $missionId = $this->insertMission($this->prefix . '_finish');
        $jobId = $this->insertRunningJob($missionId, 'worker-a', 0);

        self::assertFalse(repo_finish_deploy_job($this->db, $jobId, 'worker-b', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $this->job($jobId)['status']);

        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, 'worker-a', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED));
        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $job['status']);
        self::assertNull($job['locked_by']);
        self::assertNull($job['heartbeat_at']);
    }

    public function testReapedInventoryJobBecomesAWorkerFailureWithItsExactLogPointer(): void
    {
        $this->skipWhenForeignRunningJobsExist();
        $credentialId = $this->insertEsxiCredential($this->prefix . '_esxi');
        $jobId = $this->insertRunningInventoryJob($credentialId, 'worker-gone', 700);

        repo_reap_stale_deploy_jobs($this->db, 600);

        $state = repo_esxi_inventory_state($this->db, $credentialId);
        self::assertIsArray($state);
        self::assertSame('failed', $state['last_status']);
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_WORKER, $state['last_error_category']);
        self::assertSame($jobId, (int) $state['last_job_id']);
        self::assertSame(1, $this->systemLogCount($jobId, 'Reaped stale deploy job%'));
    }

    private function insertMission(string $name): int
    {
        $status = 'template';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertRunningJob(int $missionId, string $workerId, int $heartbeatAgeSeconds): int
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'verbose' => false, 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, locked_at, locked_by, heartbeat_at, payload_json) VALUES (?, ?, NOW(), ?, NOW(), ?)');
        $stmt->bind_param('isss', $missionId, $running, $workerId, $payload);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;

        if ($heartbeatAgeSeconds > 0) {
            $stmt = $this->db->prepare('UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?');
            $stmt->bind_param('ii', $heartbeatAgeSeconds, $jobId);
            $stmt->execute();
        }

        return $jobId;
    }

    private function insertEsxiCredential(string $name): int
    {
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
        $host = 'esxi.example.test';
        $port = 443;
        $username = 'svc';
        $secret = 'fixture';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertRunningInventoryJob(int $credentialId, string $workerId, int $heartbeatAgeSeconds): int
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, locked_at, locked_by, heartbeat_at, payload_json, credential_esxi_id) VALUES (NULL, ?, NOW(), ?, NOW(), ?, ?)');
        $stmt->bind_param('sssi', $running, $workerId, $payload, $credentialId);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;

        $stmt = $this->db->prepare('UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?');
        $stmt->bind_param('ii', $heartbeatAgeSeconds, $jobId);
        $stmt->execute();

        return $jobId;
    }

    private function skipWhenForeignRunningJobsExist(): void
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $like = 'phpunit_phase_c_%';
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_jobs j LEFT JOIN deploy_missions m ON m.id = j.mission_id LEFT JOIN deploy_credentials c ON c.id = j.credential_esxi_id WHERE j.status = ? AND (m.mission_name IS NULL OR m.mission_name NOT LIKE ?) AND (c.name IS NULL OR c.name NOT LIKE ?)');
        $stmt->bind_param('sss', $running, $like, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ((int) ($row['c'] ?? 0) > 0) {
            self::markTestSkipped('Foreign running deploy jobs exist; global reaper test would mutate live state.');
        }
    }

    private function deleteTestMissions(): void
    {
        $like = 'phpunit_phase_c_%';
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    private function systemLogCount(int $jobId, string $lineLike): int
    {
        $stream = VIRTUSPHERE_DEPLOY_LOG_SYSTEM;
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_job_logs WHERE job_id = ? AND stream = ? AND line LIKE ?');
        $stmt->bind_param('iss', $jobId, $stream, $lineLike);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }
}
