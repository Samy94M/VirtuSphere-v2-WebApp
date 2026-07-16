<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * `deploy_job_logs` had no retention: the interval inventory pull writes one job
 * per credential every few hours, each keeping its full playbook output forever.
 *
 * The prune measures the window on the JOB, not on the log row, and only touches
 * terminal jobs. A job that streams for an hour must not lose its opening lines
 * while it is still running, and a live tail must never race a purge.
 */
final class DeployJobRetentionTest extends TestCase
{
    private ?mysqli $db = null;
    /** @var int[] */
    private array $jobIds = [];
    private ?int $fixtureMissionId = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        foreach ($this->jobIds as $id) {
            $this->db->query('DELETE FROM deploy_jobs WHERE id = ' . (int) $id);
        }
        $this->jobIds = [];
        if ($this->fixtureMissionId !== null) {
            $this->db->query('DELETE FROM deploy_missions WHERE id = ' . $this->fixtureMissionId);
            $this->fixtureMissionId = null;
        }
    }

    public function testFinishedJobLogsArePrunedAndRunningOnesAreNot(): void
    {
        $oldSucceeded = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 90);
        $oldRunning = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 90);
        $oldQueued = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, 90);
        $freshFailed = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED, 1);

        $purged = repo_purge_deploy_job_logs($this->db, VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS);

        self::assertGreaterThanOrEqual(1, $purged);
        self::assertSame(0, $this->logCount($oldSucceeded), 'old terminal job keeps output');
        // A long-running job must keep the lines it already streamed.
        self::assertSame(1, $this->logCount($oldRunning), 'running job lost output');
        self::assertSame(1, $this->logCount($oldQueued), 'queued job lost output');
        self::assertSame(1, $this->logCount($freshFailed), 'recent failure lost output');

        // The job row itself survives, so the deploy list keeps its history.
        self::assertNotNull(repo_deploy_job($this->db, $oldSucceeded));
    }

    public function testFinishedSystemJobsAreRemovedButMissionJobsAreKept(): void
    {
        $oldSystem = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 90, null);
        $freshSystem = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 1, null);
        $runningSystem = $this->makeJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 90, null);

        $removed = repo_purge_finished_system_jobs($this->db, VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertNull(repo_deploy_job($this->db, $oldSystem));
        self::assertNotNull(repo_deploy_job($this->db, $freshSystem));
        self::assertNotNull(repo_deploy_job($this->db, $runningSystem));
        // The FK cascade took the log rows with it.
        self::assertSame(0, $this->logCount($oldSystem));
    }

    /** @param int|null $missionId null makes it a mission-less system job */
    private function makeJob(string $status, int $ageDays, ?int $missionId = 0): int
    {
        if ($missionId === 0) {
            $missionId = $this->missionId();
        }

        $payload = json_encode(['mode' => $missionId === null ? VIRTUSPHERE_DEPLOY_MODE_INVENTORY : 'start'], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $missionId, $status, $payload);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;
        $this->jobIds[] = $jobId;

        $stmt = $this->db->prepare('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES (?, 1, ?, ?)');
        $stream = VIRTUSPHERE_DEPLOY_LOG_SYSTEM;
        $line = 'phpunit retention fixture';
        $stmt->bind_param('iss', $jobId, $stream, $line);
        $stmt->execute();

        // updated_at is ON UPDATE CURRENT_TIMESTAMP, so it has to be forced back.
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET updated_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?');
        $stmt->bind_param('ii', $ageDays, $jobId);
        $stmt->execute();

        return $jobId;
    }

    private function missionId(): int
    {
        // Eigene Fixture-Mission statt einer geliehenen aus dem Umgebungsbestand:
        // auf der frischen QA-Datenbank existiert keine Mission, und ein
        // dynamischer Skip ist in der Integration-Lane nie legitim
        // (ADR-0015-Ergaenzung). tearDown raeumt die Zeile wieder ab.
        if ($this->fixtureMissionId === null) {
            $name = 'phpunit_retention_mission';
            $status = 'active';
            $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
            $stmt->bind_param('ss', $name, $status);
            $stmt->execute();
            $this->fixtureMissionId = (int) $this->db->insert_id;
        }

        return $this->fixtureMissionId;
    }

    private function logCount(int $jobId): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ?', 'i', [$jobId]);
    }
}
