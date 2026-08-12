<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/deploy_worker_db_channel.php';

/**
 * Fails the four channel operations on demand, but performs the real ones
 * otherwise.
 *
 * The unit test proves the channel's state machine against a fully faked seam.
 * This one asks the remaining question that only a server can answer: after the
 * channel swaps its connection, do the writes land in the real tables, in the
 * real order, and does the real ownership check see what actually happened to
 * the row meanwhile.
 *
 * Two operations are deliberately narrowed to their job-scoped core.
 * heartbeatTick() calls repo_touch_deploy_job_heartbeat() directly instead of
 * deploy_worker_heartbeat_tick(), because the latter also writes the singleton
 * worker status row and touches the container heartbeat file - shared state of
 * the dev stack this test has no business moving, and none of it is the
 * contract under test. touchProcessHeartbeat() only counts for the same reason.
 */
final class RecordingDeployWorkerDbOperations extends DeployWorkerDbOperations
{
    public bool $writesFail = false;

    public int $processHeartbeats = 0;

    public function appendLog(mysqli $db, int $jobId, string $stream, string $line): void
    {
        $this->failIfDown();
        parent::appendLog($db, $jobId, $stream, $line);
    }

    public function touchJobHeartbeat(mysqli $db, int $jobId, string $workerId): void
    {
        $this->failIfDown();
        parent::touchJobHeartbeat($db, $jobId, $workerId);
    }

    public function heartbeatTick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds): void
    {
        $this->failIfDown();
        repo_touch_deploy_job_heartbeat($db, $jobId, $workerId);
    }

    public function assertJobIsOurs(mysqli $db, int $jobId, string $workerId): void
    {
        $this->failIfDown();
        parent::assertJobIsOurs($db, $jobId, $workerId);
    }

    public function touchProcessHeartbeat(): void
    {
        $this->processHeartbeats++;
    }

    private function failIfDown(): void
    {
        if ($this->writesFail) {
            throw new mysqli_sql_exception('MySQL server has gone away');
        }
    }
}

/**
 * The database side channel of a running job, against a real database.
 *
 * The behaviour it guards: a playbook executes on the Ansible host, so a
 * database outage during a run must not end the SSH stream. What that costs is
 * a second connection and a replay, and both are only really provable here -
 * the reconnect has to produce a working handle, the replayed lines have to
 * survive in `deploy_job_logs` in their original order, and the ownership
 * recheck has to read the row as another party actually left it.
 */
final class DeployWorkerDbChannelRecoveryTest extends TestCase
{
    private string $prefix;

    private int $clock = 1_000;

    private RecordingDeployWorkerDbOperations $ops;

    protected function setUp(): void
    {
        try {
            db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_dbchannel_' . bin2hex(random_bytes(4));
        $this->ops = new RecordingDeployWorkerDbOperations();
        $this->deleteTestData();
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteTestData();
        } catch (Throwable) {
            // A test that already failed on a closed connection must not be
            // reported as an error in teardown instead.
        }
    }

    /**
     * The whole point in one run: the outage does not reach the caller, the
     * lines survive it in order behind their own SYSTEM line, the job stays
     * claimed by this worker, and the outcome is written exactly once.
     */
    public function testATransientOutageReplaysTheJobLogAndStillFinalizesExactlyOnce(): void
    {
        $missionId = $this->insertMission($this->prefix . '_replay');
        $jobId = $this->insertRunningJob($missionId, 'worker-a');
        $channel = $this->channel($jobId, 'worker-a');

        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'before the outage');

        // The database goes away mid-playbook. Nothing here may throw: the
        // caller is an SSH stream callback, and an exception in it tears down
        // the transport that is still producing the only evidence there is.
        $this->ops->writesFail = true;
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'chunk one');
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'chunk two');
        $channel->tick(0);
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'chunk three');

        self::assertFalse($channel->isConnected());
        self::assertSame(3, $channel->spooledLineCount());
        self::assertSame(0, $channel->droppedLineCount());
        self::assertSame(1, $channel->outageCount(), 'one state line per outage, not per failed write');
        self::assertSame(['before the outage'], $this->jobLogLines($jobId), 'nothing lands while it is down');

        // The database is back and the backoff is due.
        $this->ops->writesFail = false;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick(0);

        self::assertTrue($channel->isConnected());
        self::assertFalse($channel->hasLostOwnership());
        self::assertSame(0, $channel->spooledLineCount());

        $lines = $this->jobLogLines($jobId);
        self::assertCount(5, $lines);
        self::assertSame('before the outage', $lines[0]);
        self::assertStringContainsString('Database was unreachable for', $lines[1]);
        self::assertStringContainsString('3 buffered output line(s) follow', $lines[1]);
        self::assertSame(['chunk one', 'chunk two', 'chunk three'], array_slice($lines, 2), 'replayed in their original order');

        // The reconnect handle is a working one, and it is the one the callers
        // are handed - a stale $db here is exactly the defect this class fixes.
        self::assertNotNull($this->job($jobId)['heartbeat_at'], 'the reconnect refreshed the job heartbeat');
        $connection = $channel->connection();
        self::assertTrue(repo_finish_deploy_job($connection, $jobId, 'worker-a', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED));
        self::assertFalse(
            repo_finish_deploy_job($connection, $jobId, 'worker-a', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED),
            'exactly one finalization: the second attempt finds no running job to conclude'
        );
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $this->job($jobId)['status']);
    }

    /**
     * While this worker could not write, somebody else concluded the job. Its
     * terminal state is established fact; this worker's own view is a guess
     * from before the outage, and publishing it either way would replace the
     * fact with the guess.
     */
    public function testOwnershipLostDuringTheOutageOverwritesNeitherSuccessNorFailure(): void
    {
        $missionId = $this->insertMission($this->prefix . '_lost');
        $jobId = $this->insertRunningJob($missionId, 'worker-a');
        $channel = $this->channel($jobId, 'worker-a');

        $this->ops->writesFail = true;
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'output nobody will see');
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_STDOUT, 'and neither this one');
        self::assertSame(2, $channel->spooledLineCount());

        // The reaper (or another worker) converged the job meanwhile. This is
        // what that leaves behind: terminal, unlocked, with its own account.
        $foreignError = 'Reaped stale deploy job. Job ' . $jobId . ': last heartbeat 700 s ago, limit 600 s; lock held by worker-a; running -> failed.';
        $this->concludeJobElsewhere($jobId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $foreignError);

        $this->ops->writesFail = false;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick(0);

        self::assertTrue($channel->isConnected());
        self::assertTrue($channel->hasLostOwnership());
        self::assertStringContainsString('no longer running', (string) $channel->ownershipReason());

        // The spool is dropped unwritten: those lines belong to a run whose
        // conclusion somebody else has already published.
        self::assertSame(0, $channel->spooledLineCount());
        self::assertSame([], $this->jobLogLines($jobId), 'no replay into a job that is not ours any more');

        $connection = $channel->connection();
        self::assertFalse(repo_finish_deploy_job($connection, $jobId, 'worker-a', VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED));
        self::assertFalse(repo_finish_deploy_job($connection, $jobId, 'worker-a', VIRTUSPHERE_DEPLOY_STATUS_FAILED));

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $job['status']);
        self::assertSame($foreignError, (string) $job['last_error'], 'the established account survives untouched');
    }

    private function channel(int $jobId, string $workerId): DeployWorkerDbChannel
    {
        return new DeployWorkerDbChannel(
            db(),
            static fn (): mysqli => db(true),
            $jobId,
            $workerId,
            fn (): int => $this->clock,
            $this->ops
        );
    }

    private function insertMission(string $name): int
    {
        $db = db();
        $status = 'template';
        $stmt = $db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $db->insert_id;
    }

    private function insertRunningJob(int $missionId, string $workerId): int
    {
        $db = db();
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'verbose' => false, 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, attempts, locked_at, locked_by, heartbeat_at, payload_json) VALUES (?, ?, 1, NOW(), ?, NOW(), ?)');
        $stmt->bind_param('isss', $missionId, $running, $workerId, $payload);
        $stmt->execute();

        return (int) $db->insert_id;
    }

    private function concludeJobElsewhere(int $jobId, string $status, string $lastError): void
    {
        $stmt = db()->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $status, $lastError, $jobId);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $jobId): array
    {
        $stmt = db()->prepare('SELECT * FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function jobLogLines(int $jobId): array
    {
        $stmt = db()->prepare('SELECT line FROM deploy_job_logs WHERE job_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $result = $stmt->get_result();

        $lines = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $lines[] = (string) $row['line'];
        }

        return $lines;
    }

    private function deleteTestData(): void
    {
        $db = db();
        $like = 'phpunit_dbchannel_%';
        $stmt = $db->prepare('DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
