<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

/**
 * The two workers now have a traffic light, and it tells the truth.
 *
 * The deploy worker had none at all: its only liveness signal was a tmpfs file
 * for the container healthcheck, which the PHP container cannot read. A stopped
 * or crash-looping worker therefore left the System status page fully green above
 * a deploy queue that had stopped moving - the operator saw "everything ok" and a
 * job sitting at `queued` forever, with nothing connecting the two.
 *
 * The maintenance worker wrote its heartbeat at the START of a pass with a
 * hardcoded 'loop ok'. A pass that threw on every cycle kept the row fresh and
 * green: the one component whose job is to notice that other things are stuck
 * reported health it had not established.
 */
final class WorkerTrafficLightTest extends TestCase
{
    private mysqli $db;
    /** @var array<string, array<string, mixed>|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        // The live dev stack has both workers running and writing these rows, so
        // save and restore instead of deleting somebody else's state.
        foreach ([VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE] as $source) {
            $this->saved[$source] = repo_fetch_one($this->db, 'SELECT * FROM deploy_integration_heartbeats WHERE source = ?', 's', [$source]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->saved as $source => $row) {
            if ($row === null) {
                repo_execute($this->db, 'DELETE FROM deploy_integration_heartbeats WHERE source = ?', 's', [$source]);
                continue;
            }
            repo_execute(
                $this->db,
                'UPDATE deploy_integration_heartbeats SET last_seen_at = ?, last_checked_at = ?, last_status = ?, last_detail = ?, interval_seconds = ? WHERE source = ?',
                'ssssis',
                [$row['last_seen_at'], $row['last_checked_at'], $row['last_status'], $row['last_detail'], (int) $row['interval_seconds'], $source]
            );
        }
    }

    public function testTheDeployWorkerReportsItselfWithItsQueueDepth(): void
    {
        repo_record_worker_result($this->db, VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS, true, deploy_worker_queue_detail($this->db));

        $row = $this->row(VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER);
        self::assertNotNull($row, 'the deploy worker must have a status row at all');
        self::assertSame(VIRTUSPHERE_HEARTBEAT_STATUS_OK, (string) $row['last_status']);
        self::assertStringContainsString('queue:', (string) $row['last_detail'], 'the row is only actionable with the queue depth');
        self::assertStringContainsString('waiting', (string) $row['last_detail']);
    }

    /** A silent worker turns its own row red, which is the whole point. */
    public function testASilentDeployWorkerGoesStale(): void
    {
        repo_record_worker_result($this->db, VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS, true, 'queue: 0 waiting, 0 running');
        self::assertTrue(integration_deploy_worker_alive_now($this->db), 'a fresh report has to read as alive');

        // Age it past the danger multiplier instead of waiting.
        $stale = VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS * (VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER + 1) + VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS;
        repo_execute(
            $this->db,
            'UPDATE deploy_integration_heartbeats SET last_seen_at = DATE_SUB(NOW(), INTERVAL ? SECOND), last_checked_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE source = ?',
            'iis',
            [$stale, $stale, VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER]
        );

        $snapshot = integration_health_snapshot($this->db);
        $entry = $snapshot['by_source'][VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER] ?? null;
        self::assertNotNull($entry);
        self::assertSame('danger', (string) $entry['state'], 'a worker that stopped reporting must be red, not green');
        self::assertSame('danger', (string) $snapshot['internal']['state'], 'and it must colour the internal-services group');
        self::assertFalse(integration_deploy_worker_alive_now($this->db));
    }

    /**
     * The ESXi cadence line follows the same fact: the inventory pull is a deploy
     * job, so without a worker the cycle it promises does not run.
     */
    public function testADeadWorkerBecomesTheEsxiAutomationBlocker(): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_integration_heartbeats WHERE source = ?', 's', [VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER]);

        $snapshot = integration_health_snapshot($this->db);
        self::assertFalse($snapshot['esxi']['deploy_worker_alive'], 'a source that never reported is not alive');
        self::assertSame(
            VIRTUSPHERE_ESXI_AUTOMATION_NO_WORKER,
            esxi_inventory_automation_blocker(6, null, true, $snapshot['esxi']['deploy_worker_alive'])
        );
    }

    /**
     * The maintenance worker's verdict is the pass's outcome, and a failing pass
     * must not refresh last_seen_at: that column means "last successful contact"
     * for every other source on the page.
     */
    public function testAFailingMaintenancePassReportsFailAndKeepsTheOldSuccessTime(): void
    {
        repo_record_worker_result($this->db, VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS, true, 'loop ok');
        $before = $this->row(VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE);
        $successAt = (string) $before['last_seen_at'];
        self::assertNotSame('', $successAt);

        // Push the success back a minute so the "did it move" question is
        // observable at all: both writes would otherwise land in the same second.
        repo_execute($this->db, 'UPDATE deploy_integration_heartbeats SET last_seen_at = DATE_SUB(last_seen_at, INTERVAL 60 SECOND) WHERE source = ?', 's', [VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE]);
        $successAt = (string) $this->row(VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE)['last_seen_at'];

        repo_record_worker_result($this->db, VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS, false, 'failed jobs: retention');

        $row = $this->row(VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE);
        self::assertSame(VIRTUSPHERE_HEARTBEAT_STATUS_FAIL, (string) $row['last_status'], 'a failing pass must not report ok');
        self::assertStringContainsString('retention', (string) $row['last_detail'], 'and it must name what failed');
        self::assertSame($successAt, (string) $row['last_seen_at'], 'last_seen_at is the last SUCCESS and must not move on a failure');
        self::assertGreaterThan((int) $before['beat_count'], (int) $row['beat_count'], 'the failing pass did write; it just did not claim success');
    }

    /**
     * One failing job does not stop the others: they are independent, and a broken
     * retention purge must not keep the deploy-job reaper from running. But the
     * pass's verdict is a failure.
     */
    public function testOneFailingJobIsRecordedWithoutAbortingThePass(): void
    {
        $failures = [];
        $ran = [];

        maintenance_worker_job('first', $failures, static function () use (&$ran): void {
            $ran[] = 'first';
            throw new RuntimeException('boom');
        });
        maintenance_worker_job('second', $failures, static function () use (&$ran): void {
            $ran[] = 'second';
        });

        self::assertSame(['first', 'second'], $ran, 'the second job must still run');
        self::assertSame(['first'], $failures, 'and the failure must be named');
    }

    /** @return array<string, mixed>|null */
    private function row(string $source): ?array
    {
        return repo_fetch_one($this->db, 'SELECT * FROM deploy_integration_heartbeats WHERE source = ?', 's', [$source]);
    }
}
