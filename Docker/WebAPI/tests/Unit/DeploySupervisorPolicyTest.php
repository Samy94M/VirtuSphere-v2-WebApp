<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_policy.php';

/**
 * The supervisor's decision machine (Etappe 14C).
 *
 * The assertion this whole etappe exists for is a negative one: there is no
 * path through this machine that reaches `start` while the previous child might
 * still be alive. A second child would run the same job a second time and
 * create the same VM a second time, which is the failure the create repair of
 * Etappe 14B spent its whole existence making impossible.
 */
final class DeploySupervisorPolicyTest extends TestCase
{
    /**
     * @param array<string,mixed> $stateOverrides
     * @param array<string,mixed> $factOverrides
     * @return array{action:string,state:array<string,mixed>,reason:string}
     */
    private function tick(array $stateOverrides = [], array $factOverrides = []): array
    {
        $state = $stateOverrides + deploy_supervisor_initial_state();
        $facts = $factOverrides + [
            'now' => 1_000,
            'child_running' => true,
            'child_heartbeat_age' => 0,
            'shutdown_requested' => false,
        ];

        return deploy_supervisor_decide($state, $facts);
    }

    public function testTheFirstTickStartsTheOnlyChild(): void
    {
        $result = $this->tick(['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_IDLE], ['child_running' => false]);

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_START, $result['action']);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING, $result['state']['phase']);
        // The initial start is not a restart: nothing failed yet.
        self::assertSame(0, $result['state']['restart_count']);
    }

    public function testAFreshHeartbeatOnlyObservesAndClearsTheCounter(): void
    {
        $result = $this->tick([
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
            'stale_confirmations' => 2,
        ], ['child_heartbeat_age' => supervisor_child_stale_seconds()]);

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, $result['action']);
        self::assertSame(0, $result['state']['stale_confirmations'], 'a recovered child must not stay half-condemned');
    }

    public function testASingleStaleObservationIsNotAFinding(): void
    {
        $result = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING],
            ['child_heartbeat_age' => supervisor_child_stale_seconds() + 1]
        );

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, $result['action']);
        self::assertSame(1, $result['state']['stale_confirmations']);
    }

    public function testOnlyTheConfirmedFailureSendsTerm(): void
    {
        $state = ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING];
        $stale = ['child_heartbeat_age' => supervisor_child_stale_seconds() + 1];

        for ($i = 1; $i < VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS; $i++) {
            $result = $this->tick($state, $stale);
            self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, $result['action'], 'observation ' . $i);
            $state = $result['state'];
        }

        $result = $this->tick($state, $stale);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_TERM, $result['action']);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, $result['state']['phase']);
    }

    public function testTermIsSentExactlyOnceAndTheGraceIsWaitedOut(): void
    {
        $state = [
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
            'term_sent_at' => 1_000,
        ];

        $waiting = $this->tick($state, ['now' => 1_000 + VIRTUSPHERE_SUPERVISOR_TERM_GRACE_SECONDS - 1]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT, $waiting['action'], 'no second TERM inside the grace');

        $expired = $this->tick($state, ['now' => 1_000 + VIRTUSPHERE_SUPERVISOR_TERM_GRACE_SECONDS]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_KILL, $expired['action']);
    }

    public function testAChildThatSurvivesKillEndsInManualAndNeverInASecondChild(): void
    {
        $state = [
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
            'term_sent_at' => 1_000,
            'kill_sent_at' => 1_100,
        ];

        $waiting = $this->tick($state, ['now' => 1_100 + VIRTUSPHERE_SUPERVISOR_KILL_GRACE_SECONDS - 1]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_AWAIT_EXIT, $waiting['action']);

        $done = $this->tick($state, ['now' => 1_100 + VIRTUSPHERE_SUPERVISOR_KILL_GRACE_SECONDS]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL, $done['action']);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL, $done['state']['phase']);

        // And it stays there. A manual state that quietly resolves itself is a
        // manual state nobody ever looks at.
        $again = $this->tick($done['state'], ['now' => 9_999_999]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_MANUAL, $again['action']);
    }

    /**
     * The core negative assertion of the etappe: walk every state in which the
     * child might still be alive and prove that none of them answers `start`.
     */
    public function testNoStateWithALiveChildEverAnswersStart(): void
    {
        $states = [
            'running fresh' => ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING],
            'running stale once' => ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING, 'stale_confirmations' => 1],
            'running condemned' => [
                'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
                'stale_confirmations' => VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS,
            ],
            'term sent' => ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, 'term_sent_at' => 1],
            'kill sent' => [
                'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
                'term_sent_at' => 1,
                'kill_sent_at' => 2,
            ],
            'manual' => ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL],
            'cooldown with a live child' => [
                'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN,
                'cooldown_until' => 1,
            ],
        ];

        foreach ($states as $label => $state) {
            foreach ([0, 10_000] as $offset) {
                $result = $this->tick($state, ['now' => 1_000 + $offset, 'child_running' => true]);
                self::assertNotSame(
                    VIRTUSPHERE_SUPERVISOR_ACTION_START,
                    $result['action'],
                    $label . ' answered start while the child was still alive'
                );
            }
        }
    }

    public function testAConfirmedExitCoolsDownBeforeItRestarts(): void
    {
        $exited = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, 'term_sent_at' => 900],
            ['child_running' => false]
        );

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $exited['action']);
        self::assertSame(1, $exited['state']['restart_count']);
        self::assertSame(1_000 + VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS, $exited['state']['cooldown_until']);
        self::assertNull($exited['state']['term_sent_at'], 'the next stop sequence must start from scratch');

        $tooEarly = $this->tick($exited['state'], [
            'now' => 1_000 + VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS - 1,
            'child_running' => false,
        ]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $tooEarly['action']);

        $due = $this->tick($exited['state'], [
            'now' => 1_000 + VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS,
            'child_running' => false,
        ]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_START, $due['action']);
    }

    /**
     * Three consecutive hangs are the fault run the measurement rule of the
     * combined plan (section 24.2) requires for the cooldown and the window.
     * The window itself has to hold beyond that, and it must not become a hot
     * loop when it does.
     */
    public function testAnExhaustedRestartWindowWaitsInsteadOfSpinning(): void
    {
        $state = deploy_supervisor_initial_state();
        $now = 1_000;

        for ($restart = 1; $restart <= VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX; $restart++) {
            $result = $this->tick(
                ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING] + $state,
                ['now' => $now, 'child_running' => false]
            );
            self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $result['action'], 'restart ' . $restart);
            self::assertSame($restart, $result['state']['restart_count']);
            $state = $result['state'];
            $now += 10;
        }

        $over = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING] + $state,
            ['now' => $now, 'child_running' => false]
        );
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY, $over['action']);
        $retryAt = $over['state']['next_retry_at'];
        self::assertSame($now + supervisor_backoff_seconds(VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX + 1), $retryAt);

        // Two further ticks inside the wait keep waiting and do NOT push the
        // deadline out; a supervisor that re-arms its own timer never retries.
        $first = $this->tick($over['state'], ['now' => $now + 1, 'child_running' => false]);
        $second = $this->tick($first['state'], ['now' => $now + 2, 'child_running' => false]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY, $first['action']);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_WAIT_RETRY, $second['action']);
        self::assertSame($retryAt, $second['state']['next_retry_at']);

        $due = $this->tick($second['state'], ['now' => (int) $retryAt, 'child_running' => false]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_START, $due['action']);
    }

    public function testAChildThatRanLongerThanTheWindowStartsCountingAgain(): void
    {
        $state = [
            'phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
            'restart_window_started_at' => 1_000,
            'restart_count' => VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX,
        ];
        $later = 1_000 + VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_SECONDS + 1;

        $result = $this->tick($state, ['now' => $later, 'child_running' => false]);

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $result['action']);
        self::assertSame(1, $result['state']['restart_count'], 'a healthy stretch has to clear the history');
    }

    public function testShutdownStopsTheChildAndNeverStartsAnother(): void
    {
        $running = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING],
            ['shutdown_requested' => true]
        );
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_TERM, $running['action']);

        $gone = $this->tick($running['state'], ['shutdown_requested' => true, 'child_running' => false]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN, $gone['action']);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_PHASE_STOPPED, $gone['state']['phase']);
    }

    public function testShutdownOutranksAPendingCooldown(): void
    {
        $result = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN, 'cooldown_until' => 9_999],
            ['shutdown_requested' => true, 'child_running' => false]
        );

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN, $result['action'], 'a cooldown must not outlive PID 1');
    }

    public function testAMissingHeartbeatFileCountsAsStale(): void
    {
        $result = $this->tick(
            ['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING],
            ['child_heartbeat_age' => null]
        );

        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE, $result['action']);
        self::assertSame(1, $result['state']['stale_confirmations'], 'no file is not proof of life');
    }

    public function testTheCoolingDownPredicateCoversBothWaitingPhases(): void
    {
        self::assertTrue(deploy_supervisor_is_cooling_down(['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_COOLDOWN]));
        self::assertTrue(deploy_supervisor_is_cooling_down(['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY]));
        self::assertFalse(deploy_supervisor_is_cooling_down(['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING]));
        self::assertFalse(deploy_supervisor_is_cooling_down(['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL]));
    }

    /**
     * A vocabulary entry nothing can reach is a lie in a constant. Walking it
     * is cheap and catches an action that a later refactor orphaned.
     */
    public function testEveryDeclaredActionIsReachable(): void
    {
        $reached = [];
        $scenarios = [
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_IDLE], ['child_running' => false]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING], []],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING, 'stale_confirmations' => VIRTUSPHERE_SUPERVISOR_CHILD_STALE_CONFIRMATIONS - 1],
                ['child_heartbeat_age' => supervisor_child_stale_seconds() + 1]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, 'term_sent_at' => 1_000], ['now' => 1_001]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, 'term_sent_at' => 1], ['now' => 1_000]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING, 'term_sent_at' => 1, 'kill_sent_at' => 2], ['now' => 1_000]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING], ['child_running' => false]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING, 'restart_count' => VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX, 'restart_window_started_at' => 1_000],
                ['child_running' => false]],
            [['phase' => VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING], ['shutdown_requested' => true, 'child_running' => false]],
        ];
        foreach ($scenarios as [$state, $facts]) {
            $reached[$this->tick($state, $facts)['action']] = true;
        }

        self::assertSame(
            [],
            array_values(array_diff(VIRTUSPHERE_SUPERVISOR_ACTIONS, array_keys($reached))),
            'an action nothing reaches is dead vocabulary'
        );
    }
}
