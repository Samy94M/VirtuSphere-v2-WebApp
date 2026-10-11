<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * K18 (FM-04, FM-E3): a due scheduled job is still claimed however late it is
 * (no expiry), the claim stores the actual start once, and the claim line of
 * the job log names a delay from the threshold on. An on-time job gets no note.
 */
final class K18LateStartClaimTest extends TestCase
{
    private mysqli $db;
    private string $worker;
    /** @var list<int> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->worker = 'k18-worker-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->jobIds as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE id = ?', 'i', [$id]);
        }
    }

    public function testALateScheduledJobIsClaimedAndItsDelayIsLogged(): void
    {
        $jobId = $this->scheduledSystemJob(40 * 60);

        $claimed = repo_claim_next_deploy_job($this->db, $this->worker);

        self::assertNotNull($claimed, 'a late job is still run: there is no expiry');
        self::assertSame($jobId, (int) $claimed['id']);
        $line = $this->claimLine($jobId);
        self::assertMatchesRegularExpression('/; started (4\d) minutes after its scheduled start \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/', $line);
        $row = repo_fetch_one($this->db, 'SELECT started_at, TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
        self::assertNotNull($row);
        self::assertNotNull($row['started_at'], 'the actual start is stored');
        self::assertLessThan(120, (int) $row['age']);
    }

    public function testAnOnTimeScheduledJobGetsNoNote(): void
    {
        $jobId = $this->scheduledSystemJob(60);

        $claimed = repo_claim_next_deploy_job($this->db, $this->worker);

        self::assertNotNull($claimed);
        self::assertSame($jobId, (int) $claimed['id']);
        self::assertSame('Deploy job claimed by ' . $this->worker, $this->claimLine($jobId));
    }

    public function testTheFirstClaimTimeIsKeptWhenTheJobIsClaimedAgain(): void
    {
        $jobId = $this->scheduledSystemJob(40 * 60);
        self::assertNotNull(repo_claim_next_deploy_job($this->db, $this->worker));
        repo_execute($this->db, "UPDATE deploy_jobs SET started_at = '2026-01-01 00:00:00', status = 'queued', locked_by = NULL, lock_token = NULL, worker_epoch = NULL WHERE id = ?", 'i', [$jobId]);

        self::assertNotNull(repo_claim_next_deploy_job($this->db, $this->worker));

        self::assertSame('2026-01-01 00:00:00', (string) repo_scalar($this->db, 'SELECT started_at FROM deploy_jobs WHERE id = ?', 'i', [$jobId]));
    }

    private function scheduledSystemJob(int $secondsAgo): int
    {
        $payload = json_encode(['mode' => 'inventory'], JSON_THROW_ON_ERROR);
        repo_execute(
            $this->db,
            "INSERT INTO deploy_jobs (mission_id, status, payload_json, scheduled_at) VALUES (NULL, 'queued', ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
            'si',
            [$payload, $secondsAgo]
        );
        $id = (int) $this->db->insert_id;
        $this->jobIds[] = $id;

        return $id;
    }

    private function claimLine(int $jobId): string
    {
        return (string) repo_scalar(
            $this->db,
            "SELECT line FROM deploy_job_logs WHERE job_id = ? AND line LIKE 'Deploy job claimed by %' ORDER BY seq LIMIT 1",
            'i',
            [$jobId]
        );
    }
}
