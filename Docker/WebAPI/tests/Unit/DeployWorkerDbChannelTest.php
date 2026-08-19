<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_db_channel.php';

/**
 * Records what the channel did and fails on demand, so every branch that only
 * exists for a broken database can be driven without one.
 */
final class FakeDeployWorkerDbOperations extends DeployWorkerDbOperations
{
    public bool $writesFail = false;

    public ?string $ownershipLostReason = null;

    /** @var list<string> the ordered call trace */
    public array $calls = [];

    /** @var list<string> lines that actually reached the job log */
    public array $written = [];

    public int $processHeartbeats = 0;

    public function appendLog(mysqli $db, int $jobId, string $stream, string $line): void
    {
        $this->calls[] = 'append';
        $this->failIfDown();
        $this->written[] = $line;
    }

    public function touchJobHeartbeat(mysqli $db, int $jobId, string $workerId): void
    {
        $this->calls[] = 'heartbeat';
        $this->failIfDown();
    }

    public function heartbeatTick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds): void
    {
        $this->calls[] = 'tick';
        $this->failIfDown();
    }

    public function assertJobIsOurs(mysqli $db, int $jobId, string $workerId): void
    {
        $this->calls[] = 'assert_ours';
        $this->failIfDown();
        if ($this->ownershipLostReason !== null) {
            throw new DeployWorkerCancelled($this->ownershipLostReason);
        }
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
 * The database side channel of a running job.
 *
 * The defect this class exists for: while a playbook was executing on the
 * Ansible host, the first mysqli_sql_exception from a stream logger or a
 * heartbeat escaped the SSH callback, tore down the transport, and the job was
 * then classified as a transport failure. So a MySQL restart read as "the
 * Ansible host answered unexpectedly" while the playbook kept creating VMs
 * unattended, and its exit code - the only thing still learnable about that
 * work - was thrown away.
 *
 * Everything here runs against the injected operations seam and an injected
 * clock. Nothing sleeps and nothing needs a database: a test that waited out a
 * real backoff would be a test nobody runs, and a real mysqli cannot be made to
 * fail on cue.
 */
final class DeployWorkerDbChannelTest extends TestCase
{
    private FakeDeployWorkerDbOperations $ops;

    private int $clock = 1_000;

    private int $connectAttempts = 0;

    private bool $connectSucceeds = false;

    protected function setUp(): void
    {
        $this->ops = new FakeDeployWorkerDbOperations();
        $this->clock = 1_000;
        $this->connectAttempts = 0;
        $this->connectSucceeds = false;
    }

    /**
     * The whole point, in one test: output keeps flowing, nothing throws into
     * the SSH callback, and every line survives the outage in order.
     */
    public function testAnOutageSpoolsOutputAndReplaysItInOrderAfterReconnect(): void
    {
        $channel = $this->channel();

        $channel->log('stdout', 'before-1');
        $this->ops->writesFail = true;

        // Three chunks arrive while the database is gone. None of them may throw.
        $channel->log('stdout', 'during-1');
        $channel->log('stdout', 'during-2');
        $channel->log('stdout', 'during-3');

        self::assertFalse($channel->isConnected());
        self::assertSame(3, $channel->spooledLineCount());
        self::assertSame(0, $channel->droppedLineCount());
        self::assertSame(1, $channel->outageCount(), 'one outage, one state line');

        $this->ops->writesFail = false;
        $this->connectSucceeds = true;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick();

        self::assertTrue($channel->isConnected());
        self::assertSame(0, $channel->spooledLineCount());

        self::assertSame('before-1', $this->ops->written[0]);
        self::assertStringContainsString('Database was unreachable', $this->ops->written[1]);
        self::assertSame(['during-1', 'during-2', 'during-3'], array_slice($this->ops->written, 2));

        // Ownership before heartbeat before replay: draining first could write a
        // foreign job's log, beating first could extend a lock we no longer hold.
        $resume = array_values(array_filter(
            $this->ops->calls,
            static fn (string $c): bool => in_array($c, ['assert_ours', 'heartbeat'], true)
        ));
        self::assertSame(['assert_ours', 'heartbeat'], $resume);
    }

    /** A callback may attempt one reconnect per tick, never a loop. */
    public function testATickAttemptsAtMostOneReconnectAndRespectsTheBackoff(): void
    {
        $channel = $this->channel();

        $this->ops->writesFail = true;
        $channel->log('stdout', 'x');
        self::assertSame(0, $this->connectAttempts, 'entering the outage must not connect');

        // Not due yet: the backoff was armed at the moment of the failure.
        $channel->tick();
        self::assertSame(0, $this->connectAttempts);

        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS;
        $channel->tick();
        self::assertSame(1, $this->connectAttempts, 'exactly one attempt when due');

        $channel->tick();
        self::assertSame(1, $this->connectAttempts, 'the failed attempt doubled the backoff');

        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MAX_SECONDS + 1;
        $channel->tick();
        self::assertSame(2, $this->connectAttempts);
    }

    /**
     * A bounded spool is the difference between a database restart and an OOM
     * kill of the process holding the job. The tail survives, because that is
     * what explains how the run ended, and the gap is stated rather than hidden.
     */
    public function testTheSpoolIsBoundedAndReportsWhatItDropped(): void
    {
        $channel = $this->channel();
        $this->ops->writesFail = true;

        $overflow = 25;
        $total = VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES + $overflow;
        for ($i = 1; $i <= $total; $i++) {
            $channel->log('stdout', 'line-' . $i);
        }

        self::assertSame(VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES, $channel->spooledLineCount());
        self::assertSame($overflow, $channel->droppedLineCount());

        $this->ops->writesFail = false;
        $this->connectSucceeds = true;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick();

        $summary = $this->ops->written[0];
        self::assertStringContainsString(VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES . ' buffered output line(s)', $summary);
        self::assertStringContainsString($overflow . ' older line(s) were dropped', $summary);
        // Oldest go first: the last line of the run is always present.
        self::assertSame('line-' . $total, $this->ops->written[count($this->ops->written) - 1]);
        self::assertSame('line-' . ($overflow + 1), $this->ops->written[1], 'the first surviving line is the oldest that fit');
    }

    /** One state line per outage, however many writes fail inside it. */
    public function testExactlyOneStateMessagePerOutage(): void
    {
        $channel = $this->channel();

        $this->ops->writesFail = true;
        for ($i = 0; $i < 50; $i++) {
            $channel->log('stdout', 'noise-' . $i);
        }
        $channel->tick();
        self::assertSame(1, $channel->outageCount());

        $this->ops->writesFail = false;
        $this->connectSucceeds = true;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MAX_SECONDS + 1;
        $channel->tick();
        self::assertTrue($channel->isConnected());

        // A second, separate outage counts again.
        $this->ops->writesFail = true;
        $channel->log('stdout', 'again');
        self::assertSame(2, $channel->outageCount());
    }

    /**
     * Losing the lock during the outage is the case where writing anything at
     * all is wrong: somebody else has already published a terminal state for
     * this job, and the spooled lines belong to a run they concluded.
     */
    public function testLostOwnershipDropsTheSpoolAndIsReportedToTheCaller(): void
    {
        $channel = $this->channel();

        $this->ops->writesFail = true;
        $channel->log('stdout', 'orphan-1');
        $channel->log('stdout', 'orphan-2');

        $this->ops->writesFail = false;
        $this->ops->ownershipLostReason = 'Deploy job is locked by other-host:9, not by this worker.';
        $this->connectSucceeds = true;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick();

        self::assertTrue($channel->hasLostOwnership());
        self::assertStringContainsString('other-host:9', (string) $channel->ownershipReason());
        self::assertSame(0, $channel->spooledLineCount());
        self::assertSame([], $this->ops->written, 'not one line of a job we no longer own');
        self::assertNotContains('heartbeat', $this->ops->calls, 'a lost lock must not be extended by a heartbeat');
    }

    /** The container heartbeat is what keeps a waiting worker from being killed. */
    public function testEveryTickTouchesTheProcessHeartbeatEvenWhileDisconnected(): void
    {
        $channel = $this->channel();
        $this->ops->writesFail = true;
        $channel->log('stdout', 'x');

        $before = $this->ops->processHeartbeats;
        $channel->tick();
        $channel->tick();
        self::assertSame($before + 2, $this->ops->processHeartbeats);
    }

    /** recover() is bounded: it returns false instead of waiting forever. */
    public function testRecoveryIsBoundedByAttempts(): void
    {
        $channel = $this->channel();
        $this->ops->writesFail = true;
        $channel->log('stdout', 'x');

        $slept = [];
        self::assertFalse($channel->recover(3, static function (int $s) use (&$slept): void {
            $slept[] = $s;
        }));
        self::assertSame(3, $this->connectAttempts, 'exactly the attempts asked for');
        self::assertSame(
            [
                VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS * 2,
                VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS * 4,
                VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS * 8,
            ],
            $slept,
            'the backoff doubles between attempts'
        );
    }

    /** And it stops the moment the database is back. */
    public function testRecoveryReturnsAsSoonAsTheDatabaseIsBack(): void
    {
        $channel = $this->channel();
        $this->ops->writesFail = true;
        $channel->log('stdout', 'x');

        $this->ops->writesFail = false;
        $this->connectSucceeds = true;
        self::assertTrue($channel->recover(VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_LOOP, static function (): void {}));
        self::assertSame(1, $this->connectAttempts);
        self::assertTrue($channel->isConnected());
    }

    /**
     * A spooled line is already redacted, and the drain must not undo that.
     *
     * Since Etappe 8 the caller does not redact either: the output gate runs
     * before the line can reach the spool, precisely so an outage cannot park
     * a secret that a later drain then persists.
     */
    public function testTheSpoolNeverPersistsASecretItWasNotGiven(): void
    {
        $channel = $this->channel();
        $this->ops->writesFail = true;

        $secret = 'SuperSecretEsxiPassword';
        $channel->withSecrets([$secret]);
        $channel->log('stdout', 'connecting with ' . $secret . ' now');

        $this->ops->writesFail = false;
        $this->connectSucceeds = true;
        $this->clock += VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS + 1;
        $channel->tick();

        $written = implode("\n", $this->ops->written);
        self::assertStringNotContainsString($secret, $written);
        self::assertStringContainsString('connecting with *** now', $written);
    }

    /**
     * `--once` is tooling and stays bounded; the loop worker is a service and
     * may wait considerably longer for the outcome of work that already ran.
     */
    public function testTheOnceBudgetIsSmallerThanTheLoopBudget(): void
    {
        self::assertLessThan(
            VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_LOOP,
            VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_ONCE
        );
        self::assertGreaterThan(0, VIRTUSPHERE_DEPLOY_DB_CHANNEL_RECOVER_ATTEMPTS_ONCE);
    }

    private function channel(): DeployWorkerDbChannel
    {
        return new DeployWorkerDbChannel(
            new mysqli(),
            function (): mysqli {
                $this->connectAttempts++;
                if (!$this->connectSucceeds) {
                    throw new mysqli_sql_exception('Connection refused');
                }

                return new mysqli();
            },
            42,
            'test-host:1',
            fn (): int => $this->clock,
            $this->ops
        );
    }
}
