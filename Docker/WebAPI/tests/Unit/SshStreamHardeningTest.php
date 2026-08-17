<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh.php';

/**
 * Proves the bounded streaming loop of the hardened SSH transport (AP6) with a
 * scripted reader and a fake clock: idle and total timeouts throw instead of
 * ending as a made-up exit 0, and every silent slice ticks the onSilence hook
 * that carries the worker's time-based heartbeat.
 */
final class SshStreamHardeningTest extends TestCase
{
    /**
     * @param list<string|bool> $slices
     * @return callable():(string|bool)
     */
    private function scriptedReader(array $slices): callable
    {
        $index = 0;

        return static function () use (&$index, $slices) {
            if (!array_key_exists($index, $slices)) {
                self::fail('Reader consumed more slices than scripted.');
            }

            return $slices[$index++];
        };
    }

    /** @return callable():int A clock advancing $step seconds per read slice. */
    private function steppingClock(int $step): callable
    {
        $now = 1000;

        return static function () use (&$now, $step): int {
            $now += $step;

            return $now;
        };
    }

    public function testDeliversDataAndEndsOnChannelClose(): void
    {
        $chunks = [];
        $silence = 0;
        ssh_stream_command_output(
            $this->scriptedReader(['hello ', 'world', false]),
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            static function () use (&$silence): void {
                $silence++;
            },
            1800,
            14400,
            $this->steppingClock(1)
        );

        self::assertSame(['hello ', 'world'], $chunks);
        self::assertSame(0, $silence);
    }

    public function testSilentSlicesTickTheHeartbeatHook(): void
    {
        $silence = 0;
        ssh_stream_command_output(
            $this->scriptedReader([true, true, true, 'data', false]),
            static function (string $chunk): void {
            },
            static function () use (&$silence): void {
                $silence++;
            },
            1800,
            14400,
            $this->steppingClock(15)
        );

        self::assertSame(3, $silence, 'every silent slice must tick the time-based heartbeat');
    }

    public function testIdleTimeoutThrowsAfterSilenceBudget(): void
    {
        // 15 s per slice, idle budget 30 s: the second consecutive silent
        // slice crosses the budget and must throw, not return exit 0.
        $this->expectException(SshTransportBudgetExceeded::class);
        $this->expectExceptionMessage('idle timeout');

        ssh_stream_command_output(
            $this->scriptedReader([true, true, true]),
            static function (string $chunk): void {
            },
            null,
            30,
            14400,
            $this->steppingClock(15)
        );
    }

    public function testDataResetsTheIdleWindow(): void
    {
        // Silence, data, silence: no single silent stretch reaches 30 s, so
        // the loop must survive until the channel closes.
        $chunks = [];
        ssh_stream_command_output(
            $this->scriptedReader([true, 'alive', true, 'still alive', false]),
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            null,
            30,
            14400,
            $this->steppingClock(15)
        );

        self::assertSame(['alive', 'still alive'], $chunks);
    }

    public function testTotalTimeoutThrowsEvenWithSteadyOutput(): void
    {
        $this->expectException(SshTransportBudgetExceeded::class);
        $this->expectExceptionMessage('total time limit');

        ssh_stream_command_output(
            $this->scriptedReader(['tick', 'tick', 'tick', 'tick']),
            static function (string $chunk): void {
            },
            null,
            1800,
            45,
            $this->steppingClock(15)
        );
    }

    public function testHardeningConstantsStayConsistent(): void
    {
        // The silence tick drives the time-based heartbeat: it must fire well
        // inside the stale-heartbeat window, or a silent-but-alive playbook is
        // reaped mid-run. The reap interval and keepalive live inside the same
        // window for the same reason. Idle can never exceed total.
        self::assertLessThan(VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS);
        self::assertLessThan(VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS);
        self::assertLessThan(VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, VIRTUSPHERE_SSH_KEEPALIVE_INTERVAL_SECONDS);
        self::assertLessThan(VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, VIRTUSPHERE_DEPLOY_REAP_INTERVAL_SECONDS);
        self::assertLessThanOrEqual(VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS, VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS);
        self::assertGreaterThan(0, VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS);

        // The tick is the only thing that reaches the heartbeat during a silent
        // step, so a tick slower than the heartbeat cadence silently demotes the
        // heartbeat to the tick's rate. deploy_constants.php has always SAID
        // "must stay below HEARTBEAT_INTERVAL"; nothing enforced it, and the two
        // were only ever compared against STALE_AFTER separately.
        self::assertLessThan(
            VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS,
            VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS,
            'a silence tick at or above the heartbeat interval sets the real heartbeat cadence'
        );

        // "Well inside" is a number, not a feeling: the reaper must survive
        // several missed heartbeats, or one slow DB write ends a healthy job.
        self::assertGreaterThanOrEqual(
            5 * VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS,
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            'the reap window must tolerate several missed heartbeats, not just one'
        );

        // The transport tolerates silence far longer than the reaper does
        // (1800 s vs 600 s today), so between those two numbers the ONLY thing
        // keeping a live job alive is the tick above. That is a deliberate
        // asymmetry - a clone or an eager-zeroed disk is legitimately silent for
        // longer than the reap window - but it means the tick has no fallback,
        // and a job reaped while its worker is still running keeps mutating ESXi
        // under a terminal job row. Pinned so the asymmetry stays a decision.
        self::assertGreaterThan(
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS,
            'if the transport gave up FIRST, the reaper would never see a live worker; that is a different design and needs its own decision'
        );

        // SFTP upload bounds (AP6-Rest): both positive, and one file's per-op
        // timeout must fit inside the whole-directory budget, or the total cap
        // could never trip before a single operation already blew past it.
        self::assertGreaterThan(0, VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS);
        self::assertLessThanOrEqual(VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS, VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS);
    }
}
