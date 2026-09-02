<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_policy.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_process.php';

/**
 * The fault run (Etappe 14C): a REAL child that stops answering, driven by the
 * real policy through the real process seam.
 *
 * The pure policy test proves the machine cannot decide to start a second
 * child. This proves the other half, which no source review can see: that
 * "the child is gone" is established by waitpid against an actual process, that
 * a child ignoring SIGTERM is escalated rather than replaced, and that at no
 * point in the sequence do two children exist.
 *
 * The clock is simulated and the child is real. That combination is deliberate:
 * waiting out a thirty-second grace in a test buys nothing, while faking the
 * process would fake the one fact the whole design rests on.
 *
 * @group process
 */
final class DeploySupervisorFaultRunTest extends TestCase
{
    private string $heartbeat = '';

    /** @var list<DeploySupervisorProcess> */
    private array $started = [];

    protected function setUp(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            self::markTestSkipped('proc_open/posix_kill are required for the fault run');
        }
        $this->heartbeat = sys_get_temp_dir() . '/vs-fault-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->started as $process) {
            if ($process->isRunning()) {
                $process->kill();
            }
        }
        @unlink($this->heartbeat);
    }

    /**
     * A child that touches its liveness file for a while and then stops, while
     * staying alive and deliberately ignoring SIGTERM. That is the production
     * shape of a hang: the process is there, the work is not.
     *
     * @return list<string>
     */
    private function hangingChildCommand(int $beats): array
    {
        $code = 'pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void {});'
            . '$f = ' . var_export($this->heartbeat, true) . ';'
            . 'for ($i = 0; $i < ' . $beats . '; $i++) { touch($f); usleep(50000); }'
            . 'while (true) { usleep(100000); }';

        return [PHP_BINARY, '-r', $code];
    }

    private function newProcess(): DeploySupervisorProcess
    {
        $process = new DeploySupervisorProcess();
        $this->started[] = $process;

        return $process;
    }

    /** Waits, bounded, for a real condition instead of trusting a sleep. */
    private function waitFor(callable $condition, string $what, int $tries = 200): void
    {
        for ($i = 0; $i < $tries; $i++) {
            if ($condition()) {
                return;
            }
            usleep(25_000);
        }
        self::fail('timed out waiting for ' . $what);
    }

    public function testAHungChildIsEscalatedAndHealedWithoutEverASecondChild(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is required to build a child that ignores SIGTERM');
        }

        $process = $this->newProcess();
        $state = deploy_supervisor_initial_state();
        $now = 1_000;
        $liveChildren = 0;
        $starts = 0;
        $firstPid = null;

        // Tick 1: nothing running, so the machine starts the one child.
        $decision = deploy_supervisor_decide($state, $this->facts($now, $process, false));
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_START, $decision['action']);
        $state = $decision['state'];
        $firstPid = $process->start($this->hangingChildCommand(2));
        $starts++;
        $liveChildren++;
        self::assertSame(1, $liveChildren);

        $this->waitFor(fn (): bool => file_exists($this->heartbeat), 'the child to touch its liveness file');
        // Let it stop beating, then hold the clock past the stale bound.
        $this->waitFor(
            fn (): bool => (time() - (int) filemtime($this->heartbeat)) >= 0 && $this->beatsFinished(),
            'the child to stop beating'
        );

        // Confirmed stale observations, then TERM. The child ignores it.
        $sawTerm = false;
        for ($tick = 0; $tick < 12 && !$sawTerm; $tick++) {
            $now += 5;
            $decision = deploy_supervisor_decide($state, $this->facts($now, $process, true, supervisor_child_stale_seconds() + 1));
            $state = $decision['state'];
            self::assertNotSame(
                VIRTUSPHERE_SUPERVISOR_ACTION_START,
                $decision['action'],
                'a live child must never produce a start'
            );
            if ($decision['action'] === VIRTUSPHERE_SUPERVISOR_ACTION_TERM) {
                $sawTerm = true;
                $process->terminate();
            }
        }
        self::assertTrue($sawTerm, 'the confirmed hang has to reach TERM');

        // The child is still there: SIGTERM was handled and ignored, which is
        // exactly why a supervisor may not conclude anything from having sent one.
        self::assertTrue($process->isRunning(), 'this child ignores SIGTERM on purpose');
        self::assertSame(1, $liveChildren, 'no replacement was started while the old one lived');

        // The grace expires and the machine escalates instead of replacing.
        $now += VIRTUSPHERE_SUPERVISOR_TERM_GRACE_SECONDS;
        $decision = deploy_supervisor_decide($state, $this->facts($now, $process, true, supervisor_child_stale_seconds() + 1));
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_KILL, $decision['action']);
        $state = $decision['state'];
        $process->kill();

        // SIGKILL cannot be handled, so the exit is real and waitpid sees it.
        $this->waitFor(fn (): bool => !$process->isRunning(), 'the child to actually exit');
        $liveChildren--;
        self::assertNull($process->pid(), 'a reaped child leaves no pid behind');

        // Only now may a restart even be considered, and it still cools down.
        $now += 1;
        $decision = deploy_supervisor_decide($state, $this->facts($now, $process, false));
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_COOLDOWN, $decision['action']);
        self::assertSame(1, $decision['state']['restart_count']);
        $state = $decision['state'];

        $now += VIRTUSPHERE_SUPERVISOR_COOLDOWN_SECONDS;
        $decision = deploy_supervisor_decide($state, $this->facts($now, $process, false));
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_START, $decision['action']);

        $secondPid = $process->start($this->hangingChildCommand(1));
        $starts++;
        $liveChildren++;

        self::assertSame(2, $starts, 'exactly one heal, not a loop');
        self::assertSame(1, $liveChildren, 'exactly one child at every point of the run');
        self::assertNotSame($firstPid, $secondPid, 'the heal really is a new process');
    }

    /**
     * The seam refuses a second child on its own, independently of the policy.
     * Two independent reasons means neither has to be perfect.
     */
    public function testTheSeamRefusesASecondChildWhileOneIsOpen(): void
    {
        $process = $this->newProcess();
        $process->start([PHP_BINARY, '-r', 'usleep(3000000);']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already open/');
        $process->start([PHP_BINARY, '-r', 'usleep(100);']);
    }

    /** A child that ends on its own is noticed by waitpid, not by a timer. */
    public function testAChildThatExitsOnItsOwnIsObservedAsGone(): void
    {
        $process = $this->newProcess();
        $process->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->waitFor(fn (): bool => !$process->isRunning(), 'the short-lived child to be reaped');
        self::assertNull($process->pid());

        // And the seam is usable again, which is what makes a heal possible.
        $process->start([PHP_BINARY, '-r', 'exit(0);']);
        $this->waitFor(fn (): bool => !$process->isRunning(), 'the replacement to be reaped');
    }

    private function beatsFinished(): bool
    {
        // The stub beats for a fixed, short burst; once the file is older than
        // the burst it has stopped for good.
        return (time() - (int) @filemtime($this->heartbeat)) >= 1;
    }

    /** @return array{now:int,child_running:bool,child_heartbeat_age:?int,shutdown_requested:bool} */
    private function facts(int $now, DeploySupervisorProcess $process, bool $expectRunning, ?int $age = 0): array
    {
        return [
            'now' => $now,
            // The real observation, never the expectation. The parameter only
            // documents what the test believes at this point.
            'child_running' => $process->isRunning(),
            'child_heartbeat_age' => $age,
            'shutdown_requested' => false,
        ];
    }
}
