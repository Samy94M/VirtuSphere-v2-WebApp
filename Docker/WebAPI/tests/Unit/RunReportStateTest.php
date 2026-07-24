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

    public function testSiteHealthUsesCompletedDerivation(): void
    {
        self::assertSame('danger', $this->state(['last_event' => 'completed', 'last_status' => 'fail', 'last_result_at' => self::FRESH, 'interval_seconds' => 300], 'site'));
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
