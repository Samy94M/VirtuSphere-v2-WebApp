<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';

/**
 * A failure detector must not count silence it was not awake to observe.
 *
 * The reaper marks a job failed after VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS
 * without a heartbeat. While the database was unreachable NOTHING could write
 * one, so at the moment it returns every running job looks abandoned and the
 * reaper cannot tell a dead worker from its own blind spot. It then fails a
 * healthy job whose playbook keeps running against ESXi under a terminal row,
 * and the operator reads "no heartbeat for 600 seconds" about a worker that
 * never stopped. That is the shape behind deploy job 77.
 *
 * The rule is deliberately about THIS PROCESS, not about a timestamp in the
 * database: a row says when somebody last wrote, which is a different question
 * from whether this observer was awake. It also costs nothing in the case that
 * matters - an observer connected all along was never blind and reaps a
 * genuinely dead worker with no delay at all.
 *
 * The pure predicate is tested here; that the gate sits inside
 * deploy_worker_reap_stale_jobs() where no caller can skip it is covered by
 * DeployConvergenceContractTest and the reaper integration tests, which had to
 * start declaring an observer the moment it was introduced.
 */
final class DeployReapObserverGraceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Pulled in separately from the constants: the file runs the module's
        // function definitions, and lib/deploy_worker.php would run a loop.
        require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
    }

    public function testAnObserverThatNeverConnectedCountsAsBlind(): void
    {
        // The unset value must never read as "watching since epoch 0", which is
        // the one default that would silently disable the whole guard.
        self::assertTrue(deploy_reap_observer_is_blind(null));
        self::assertTrue(deploy_reap_observer_is_blind(null, 0));
        self::assertTrue(deploy_reap_observer_is_blind(null, PHP_INT_MAX));
    }

    public function testABriefObserverIsBlindAndAnEstablishedOneIsNot(): void
    {
        $now = 1_800_000_000;
        $grace = VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS;

        self::assertTrue(deploy_reap_observer_is_blind($now, $now), 'connected this very second');
        self::assertTrue(deploy_reap_observer_is_blind($now - ($grace - 1), $now), 'one second short of the grace');
        self::assertFalse(deploy_reap_observer_is_blind($now - $grace, $now), 'the grace is reached, not exceeded');
        self::assertFalse(deploy_reap_observer_is_blind($now - 86400, $now), 'connected all day');
    }

    /** The store is what the workers set on connect AND on every reconnect. */
    public function testTheObserverStoreKeepsWhatTheWorkerLastSet(): void
    {
        $first = 1_800_000_000;
        self::assertSame($first, deploy_reap_observer_since($first));
        self::assertSame($first, deploy_reap_observer_since(), 'reading must not clear it');

        $reconnect = $first + 5000;
        self::assertSame($reconnect, deploy_reap_observer_since($reconnect), 'a reconnect moves the mark forward');
    }

    /**
     * The grace has to outlast what a LIVE worker needs to restore its job
     * heartbeat once the database is back: its reconnect backoff plus one
     * heartbeat interval. Below that the guard would expire while the healthy
     * worker is still catching up, which is the bug it exists to prevent.
     */
    public function testTheGraceOutlastsAHealthyWorkersRecovery(): void
    {
        $reconnectBackoffCap = 30; // deploy_worker_connect_db(): sleep(min(30, 2 * attempt))
        self::assertGreaterThan(
            $reconnectBackoffCap + VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS,
            VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS,
            'a live worker would still be reconnecting when the grace expires'
        );

        // And it must stay well under the staleness window, or the grace would
        // become the real reap deadline instead of a guard on its edge.
        self::assertLessThan(
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS,
            'the grace must guard the reap window, not replace it'
        );
    }
}
