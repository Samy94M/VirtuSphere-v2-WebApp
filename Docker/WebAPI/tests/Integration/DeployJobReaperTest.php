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
        self::assertStringContainsString('600 seconds', (string) $stale['last_error']);
        self::assertSame(1, $this->systemLogCount($staleJobId, 'Reaped stale deploy job%'));

        $fresh = $this->job($freshJobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $fresh['status']);
        self::assertSame('worker-fresh', $fresh['locked_by']);
        self::assertNotNull($fresh['heartbeat_at']);
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

    private function skipWhenForeignRunningJobsExist(): void
    {
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $like = 'phpunit_phase_c_%';
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
        $like = 'phpunit_phase_c_%';
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