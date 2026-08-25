<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_job_output.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_view.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/** Proves the cursor windows against retained rows rather than array fixtures. */
final class DeployJobLogCursorTest extends TestCase
{
    private mysqli $db;
    /** @var int[] */
    private array $jobIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->jobIds as $jobId) {
            $this->db->query('DELETE FROM deploy_jobs WHERE id = ' . $jobId);
        }
    }

    public function testInitialTailForwardDrainAndOlderPagesAreComplete(): void
    {
        $jobId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'cursor-worker');
        $this->insertLines($jobId, 1, 1705);

        $tail = repo_deploy_job_log_initial_tail($this->db, $jobId);
        self::assertSame(range(706, 1705), $this->seqs($tail));
        self::assertSame(706, $tail['oldest_seq']);
        self::assertSame(1705, $tail['newest_seq']);
        self::assertTrue($tail['has_older']);
        self::assertFalse($tail['has_more']);
        self::assertTrue($tail['caught_up']);

        $seen = [];
        $after = 0;
        do {
            $page = repo_deploy_job_log_forward($this->db, $jobId, $after);
            foreach ($this->seqs($page) as $seq) {
                self::assertArrayNotHasKey($seq, $seen, 'forward drain repeated sequence ' . $seq);
                $seen[$seq] = true;
                $after = $seq;
            }
        } while ($page['has_more']);
        self::assertSame(range(1, 1705), array_keys($seen));
        self::assertTrue($page['caught_up']);

        $older = repo_deploy_job_log_older($this->db, $jobId, 706);
        self::assertSame(range(206, 705), $this->seqs($older));
        self::assertTrue($older['has_older']);
        $oldest = repo_deploy_job_log_older($this->db, $jobId, 206);
        self::assertSame(range(1, 205), $this->seqs($oldest));
        self::assertFalse($oldest['has_older']);
    }

    public function testTerminalBacklogDrainsPastFiveHundredAndEndsOnTheFinalLine(): void
    {
        $jobId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'terminal-worker');
        $this->insertLines($jobId, 1, 1100);
        self::assertTrue(repo_finish_deploy_job(
            $this->db,
            $jobId,
            'terminal-worker',
            VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED
        ));

        $first = repo_deploy_job_log_forward($this->db, $jobId, 500);
        self::assertCount(VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT, $first['logs']);
        self::assertTrue($first['has_more']);
        self::assertFalse($first['caught_up']);
        $second = repo_deploy_job_log_forward($this->db, $jobId, (int) $first['newest_seq']);
        self::assertSame(1101, $second['newest_seq']);
        self::assertFalse($second['has_more']);
        self::assertTrue($second['caught_up']);
        $last = $second['logs'][count($second['logs']) - 1];
        self::assertSame('Deploy job succeeded.', $last['line']);
        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $last['stream']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot append to a terminal deploy job.');
        repo_append_deploy_job_log($this->db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'too late');
    }

    public function testRawBatchesStayBoundedAndCoverTheSnapshotExactlyOnce(): void
    {
        $jobId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'raw-worker');
        $this->insertLines($jobId, 1, 1201);
        $seen = [];
        $batchSizes = [];
        foreach (repo_deploy_job_log_raw_batches($this->db, $jobId) as $batch) {
            $batchSizes[] = count($batch);
            foreach ($batch as $row) {
                $seen[] = (int) $row['seq'];
            }
        }

        self::assertSame([500, 500, 201], $batchSizes);
        self::assertSame(range(1, 1201), $seen);
        self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_LOG_RAW_BATCH_SIZE, max($batchSizes));
    }

    public function testRawReaderCanOnlySeeTheWritersRedactedLine(): void
    {
        $jobId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'raw-redaction-worker');
        $sentinel = 'vs-e10a-secret-7f39a2';
        $gate = new DeployJobOutputGate();
        $gate->withSecrets([$sentinel]);
        foreach ($gate->accept(VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, 'fatal: password=' . $sentinel) as $row) {
            repo_append_deploy_job_log($this->db, $jobId, $row['stream'], $row['line']);
        }

        $lines = [];
        foreach (repo_deploy_job_log_raw_batches($this->db, $jobId) as $batch) {
            foreach ($batch as $row) {
                $lines[] = (string) $row['line'];
            }
        }
        self::assertSame(['fatal: password=***'], $lines);
        self::assertStringNotContainsString($sentinel, implode("\n", $lines));
    }

    public function testEmptyCursorPagesAndInvalidBoundsAreExplicit(): void
    {
        $jobId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, null);
        $empty = repo_deploy_job_log_initial_tail($this->db, $jobId);
        self::assertSame([], $empty['logs']);
        self::assertNull($empty['oldest_seq']);
        self::assertNull($empty['newest_seq']);
        self::assertFalse($empty['has_older']);
        self::assertFalse($empty['has_more']);
        self::assertTrue($empty['caught_up']);
        self::assertSame('empty', deploy_job_log_empty_state(repo_deploy_job($this->db, $jobId) ?? [], []));

        $prunedId = $this->job(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, null);
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET updated_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?');
        $age = VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS + 1;
        $stmt->bind_param('ii', $age, $prunedId);
        $stmt->execute();
        self::assertSame('pruned', deploy_job_log_empty_state(repo_deploy_job($this->db, $prunedId) ?? [], []));

        try {
            repo_deploy_job_log_forward($this->db, $jobId, -1);
            self::fail('negative after cursor passed');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            repo_deploy_job_log_older($this->db, $jobId, 0);
            self::fail('zero before cursor passed');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            repo_deploy_job_log_forward($this->db, $jobId, 0, 0);
            self::fail('zero limit passed');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private function job(string $status, ?string $worker): int
    {
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, locked_by, locked_at, heartbeat_at, payload_json) VALUES (NULL, ?, ?, IF(? IS NULL, NULL, NOW()), IF(? IS NULL, NULL, NOW()), ?)');
        $stmt->bind_param('sssss', $status, $worker, $worker, $worker, $payload);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;
        $this->jobIds[] = $jobId;

        return $jobId;
    }

    private function insertLines(int $jobId, int $first, int $last): void
    {
        for ($start = $first; $start <= $last; $start += 250) {
            $values = [];
            for ($seq = $start; $seq <= min($last, $start + 249); $seq++) {
                $values[] = sprintf('(%d,%d,\'%s\',\'cursor line %d\')', $jobId, $seq, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $seq);
            }
            $this->db->query('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES ' . implode(',', $values));
        }
    }

    /** @return int[] */
    private function seqs(array $page): array
    {
        return array_map(static fn (array $row): int => (int) $row['seq'], $page['logs']);
    }
}
