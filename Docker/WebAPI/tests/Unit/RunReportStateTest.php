<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/status.php';

/**
 * The central V2 result-reporting display derivation (ADR-0018). last_event is
 * the sole driver of legacy-vs-V2 and running-vs-completed; report_version never
 * enters it. All timestamps are server-side; the clock is injected.
 */
final class RunReportStateTest extends TestCase
{
    private const FRESH = '2026-07-23 11:59:30';
    private const STALE = '2026-07-23 11:40:00'; // 20 min old
    private const DEAD = '2026-07-23 10:00:00';  // 2 h old

    // Computed with strtotime so it shares the server timezone the row
    // timestamps are parsed in; a fixed epoch would drift by the TZ offset.
    private function now(): int
    {
        return (int) strtotime('2026-07-23 12:00:00');
    }

    private function state(array $row, string $kind = 'sync'): string
    {
        return virtusphere_integration_row_state($row, $kind, true, $this->now());
    }

    public function testKindMapping(): void
    {
        self::assertSame('sync', virtusphere_integration_source_kind('device-sync'));
        self::assertSame('site', virtusphere_integration_source_kind('mecm-site-health'));
        self::assertSame('internal', virtusphere_integration_source_kind('maintenance-worker'));
    }

    public function testCompletedOutcomesFresh(): void
    {
        self::assertSame('ok', $this->state(['last_event' => 'completed', 'last_status' => 'ok', 'last_result_at' => self::FRESH, 'interval_seconds' => 60]));
        self::assertSame('warning', $this->state(['last_event' => 'completed', 'last_status' => 'warning', 'last_result_at' => self::FRESH, 'interval_seconds' => 60]));
        self::assertSame('unknown', $this->state(['last_event' => 'completed', 'last_status' => 'unknown', 'last_result_at' => self::FRESH, 'interval_seconds' => 60]));
    }

    public function testFailIsAlwaysRedEvenFresh(): void
    {
        self::assertSame('danger', $this->state(['last_event' => 'completed', 'last_status' => 'fail', 'last_result_at' => self::FRESH, 'interval_seconds' => 60]));
    }

    public function testAnOkReporterAgesOutToWarningThenDanger(): void
    {
        self::assertSame('warning', $this->state(['last_event' => 'completed', 'last_status' => 'ok', 'last_result_at' => self::STALE, 'interval_seconds' => 300]));
        self::assertSame('danger', $this->state(['last_event' => 'completed', 'last_status' => 'ok', 'last_result_at' => self::DEAD, 'interval_seconds' => 60]));
    }

    public function testLegacyIsYellowWhenFreshAndAgesOut(): void
    {
        self::assertSame('legacy', $this->state(['last_event' => 'heartbeat', 'last_status' => 'ok', 'last_seen_at' => self::FRESH, 'interval_seconds' => 60]));
        self::assertSame('danger', $this->state(['last_event' => 'heartbeat', 'last_status' => 'ok', 'last_seen_at' => self::DEAD, 'interval_seconds' => 60]));
    }

    public function testTwoClockRunningKeepsLastResultWithinGrace(): void
    {
        // Last completed 2 h ago but a fresh started event: still ok, not stale.
        self::assertSame('ok', $this->state(['last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => self::DEAD, 'last_attempt_at' => self::FRESH, 'interval_seconds' => 60]));
        // Running with no completed result yet: grey.
        self::assertSame('unknown', $this->state(['last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => null, 'last_attempt_at' => self::FRESH, 'interval_seconds' => 60]));
        // A run stuck far beyond max(3x interval, 60s, RUN_GRACE=600): stale.
        self::assertSame('danger', $this->state(['last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => self::FRESH, 'last_attempt_at' => self::DEAD, 'interval_seconds' => 60]));
    }

    public function testRunGraceKeepsAShortRunFromGoingStale(): void
    {
        // interval 60 => 3x=180, but RUN_GRACE=600 dominates; a run 5 min in is
        // still fine even though 3x interval already passed.
        $fiveMinAgo = '2026-07-23 11:55:00';
        self::assertSame('ok', $this->state(['last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => self::FRESH, 'last_attempt_at' => $fiveMinAgo, 'interval_seconds' => 60]));
    }

    // AI1: the autoimporter scan on the target takes 906-1181 s at a 60 s
    // interval. With the fixed 600 s grace every normal scan went red after
    // 10 min and back to yellow on completion, about 160 log lines a day.
    public function testLongNormalRunStaysOnLastResultWithinItsMeasuredDuration(): void
    {
        $row = [
            'last_event' => 'started', 'last_status' => 'warning', 'last_result_at' => '2026-07-23 11:38:00',
            'interval_seconds' => 60, 'last_duration_ms' => 906000,
        ];
        // 17 min into a scan whose last run took 15 min: still the last result.
        self::assertSame('warning', $this->state($row + ['last_attempt_at' => '2026-07-23 11:43:00']));
        // A scan a third longer than the last one (20 min) stays inside as well.
        self::assertSame('warning', $this->state($row + ['last_attempt_at' => '2026-07-23 11:40:00']));
        self::assertSame(1812, virtusphere_run_grace_seconds($row));
    }

    public function testLongRunThatHangsStillTurnsRed(): void
    {
        $row = [
            'last_event' => 'started', 'last_status' => 'ok', 'last_result_at' => self::FRESH,
            'interval_seconds' => 60, 'last_duration_ms' => 906000,
        ];
        // 31 min in, past twice the last duration (30 min 12 s): stuck.
        self::assertSame('danger', $this->state($row + ['last_attempt_at' => '2026-07-23 11:29:00']));
        self::assertSame('danger', $this->state($row + ['last_attempt_at' => self::DEAD]));
    }

    /**
     * The state sequence the maintenance worker samples (every 15 s) across
     * autoimporter loops: started, scan, completed, 60 s pause. Every change in
     * it is one `integration.state_changed` row, so a normal loop must show none.
     *
     * @param list<int> $durations scan lengths in seconds, in order
     * @return list<string>
     */
    private function sampledLoopStates(int $previousDuration, array $durations, ?int $hangAfter = null): array
    {
        $clock = 1793000000;
        $at = static fn (int $epoch): string => gmdate('Y-m-d H:i:s', $epoch);
        $states = [];
        foreach ($durations as $index => $duration) {
            $started = [
                'last_event' => 'started', 'last_status' => 'warning', 'last_result_at' => $at($clock - 60),
                'last_attempt_at' => $at($clock), 'interval_seconds' => 60, 'last_duration_ms' => $previousDuration * 1000,
            ];
            $hangs = $index === array_key_last($durations) && $hangAfter !== null;
            for ($t = 0; $t <= ($hangs ? $hangAfter : $duration); $t += 15) {
                $states[] = virtusphere_integration_row_state($started, 'sync', true, $clock + $t);
            }
            if ($hangs) {
                break;
            }
            $clock += $duration;
            $completed = [
                'last_event' => 'completed', 'last_status' => 'warning', 'last_result_at' => $at($clock),
                'interval_seconds' => 60, 'last_duration_ms' => $duration * 1000,
            ];
            for ($t = 0; $t < 60; $t += 15) {
                $states[] = virtusphere_integration_row_state($completed, 'sync', true, $clock + $t);
            }
            $clock += 60;
            $previousDuration = $duration;
        }

        return $states;
    }

    public function testVaryingScanLengthsLogNoStateTransition(): void
    {
        // Measured on the target: 906 s, then 1181 s; plus a shorter scan.
        $states = $this->sampledLoopStates(906, [1181, 906, 1020, 1181]);
        self::assertSame(['warning'], array_values(array_unique($states)));
    }

    public function testHangingScanLogsExactlyOneTransition(): void
    {
        // The fourth scan never completes; sampled for an hour after its start.
        $states = $this->sampledLoopStates(906, [1181, 906, 1020, 0], 3600);
        self::assertSame(['warning', 'danger'], array_values(array_unique($states)));
        // Red only after twice the previous run's 1020 s (2040 s), not after
        // 10 min: the samples at 2055 s to 3600 s of the hanging run.
        self::assertCount(104, array_keys($states, 'danger', true));
        self::assertSame('danger', $states[array_key_last($states)]);
    }

    public function testDurationGraceIsCappedAndIgnoresMissingDurations(): void
    {
        $base = ['interval_seconds' => 60];
        // No completed duration (first run, legacy history): the fixed floors.
        self::assertSame(VIRTUSPHERE_RUN_GRACE_SECONDS, virtusphere_run_grace_seconds($base));
        self::assertSame(VIRTUSPHERE_RUN_GRACE_SECONDS, virtusphere_run_grace_seconds($base + ['last_duration_ms' => null]));
        self::assertSame(VIRTUSPHERE_RUN_GRACE_SECONDS, virtusphere_run_grace_seconds($base + ['last_duration_ms' => 0]));
        // A short run never lowers the floor.
        self::assertSame(VIRTUSPHERE_RUN_GRACE_SECONDS, virtusphere_run_grace_seconds($base + ['last_duration_ms' => 2500]));
        // mysqli returns the column as a string.
        self::assertSame(1812, virtusphere_run_grace_seconds($base + ['last_duration_ms' => '906000']));
        // One run that completed after 20 h does not hide the next hang for 40 h.
        self::assertSame(VIRTUSPHERE_RUN_DURATION_GRACE_MAX_SECONDS, virtusphere_run_grace_seconds($base + ['last_duration_ms' => 72000000]));
    }

    public function testSiteHealthUsesCompletedDerivation(): void
    {
        self::assertSame('danger', $this->state(['last_event' => 'completed', 'last_status' => 'fail', 'last_error_category' => VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, 'last_result_at' => self::FRESH, 'interval_seconds' => 300], 'site'));
        self::assertSame('unknown', $this->state(['last_event' => 'completed', 'last_status' => 'unknown', 'last_result_at' => self::FRESH, 'interval_seconds' => 300], 'site'));
    }

    public function testInternalNeverShowsLegacy(): void
    {
        // The maintenance worker writes last_event='heartbeat' but must derive as
        // plain liveness, never 'legacy'.
        self::assertSame('ok', $this->state(['last_event' => 'heartbeat', 'last_status' => 'ok', 'last_seen_at' => self::FRESH, 'interval_seconds' => 60], 'internal'));
    }

    public function testMissingIsPerGroup(): void
    {
        self::assertSame('missing', virtusphere_integration_row_state(null, 'sync', true, $this->now()));
        self::assertSame('unknown', virtusphere_integration_row_state(null, 'sync', false, $this->now()));
        self::assertSame('unknown', virtusphere_integration_row_state(null, 'site', false, $this->now()));
    }
}
