<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/status.php';

final class HeartbeatStalenessTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function seenAgo(int $seconds): string
    {
        return date('Y-m-d H:i:s', self::NOW - $seconds);
    }

    public function testFreshHeartbeatIsOk(): void
    {
        self::assertSame('ok', virtusphere_heartbeat_staleness($this->seenAgo(5), 10, null, self::NOW));
    }

    public function testWarnAfterThreeTimesIntervalWithFloor(): void
    {
        // 10s interval: warn threshold floors at 60s, not 30s.
        self::assertSame('ok', virtusphere_heartbeat_staleness($this->seenAgo(45), 10, null, self::NOW));
        self::assertSame('warning', virtusphere_heartbeat_staleness($this->seenAgo(61), 10, null, self::NOW));
        // 60s interval: warn after 180s.
        self::assertSame('ok', virtusphere_heartbeat_staleness($this->seenAgo(170), 60, null, self::NOW));
        self::assertSame('warning', virtusphere_heartbeat_staleness($this->seenAgo(181), 60, null, self::NOW));
    }

    public function testDangerAfterTenTimesIntervalWithFloor(): void
    {
        // 10s interval: danger threshold floors at 300s.
        self::assertSame('warning', virtusphere_heartbeat_staleness($this->seenAgo(299), 10, null, self::NOW));
        self::assertSame('danger', virtusphere_heartbeat_staleness($this->seenAgo(301), 10, null, self::NOW));
        // 60s interval: danger after 600s.
        self::assertSame('danger', virtusphere_heartbeat_staleness($this->seenAgo(601), 60, null, self::NOW));
    }

    public function testFailStatusBeatsStaleness(): void
    {
        // A fresh failure must be red even while the last success is recent.
        self::assertSame('danger', virtusphere_heartbeat_staleness($this->seenAgo(5), 10, VIRTUSPHERE_HEARTBEAT_STATUS_FAIL, self::NOW));
    }

    public function testNeverSeenIsUnknown(): void
    {
        self::assertSame('unknown', virtusphere_heartbeat_staleness(null, 10, null, self::NOW));
        self::assertSame('unknown', virtusphere_heartbeat_staleness('', 10, null, self::NOW));
        self::assertSame('unknown', virtusphere_heartbeat_staleness('not-a-date', 10, null, self::NOW));
    }

    public function testHeartbeatMetaBadges(): void
    {
        self::assertSame('success', virtusphere_heartbeat_meta('ok')['badge']);
        self::assertSame('warning', virtusphere_heartbeat_meta('warning')['badge']);
        self::assertSame('warning', virtusphere_heartbeat_meta('missing')['badge']);
        self::assertSame('danger', virtusphere_heartbeat_meta('danger')['badge']);
        self::assertSame('neutral', virtusphere_heartbeat_meta('unknown')['badge']);
    }

    public function testClientPhaseStateDerivation(): void
    {
        self::assertSame('none', virtusphere_client_phase_state(null, self::NOW));
        self::assertSame('finished', virtusphere_client_phase_state(['event' => 'finished', 'created_at' => $this->seenAgo(10)], self::NOW));
        self::assertSame('failed', virtusphere_client_phase_state(['event' => 'failed', 'created_at' => $this->seenAgo(10)], self::NOW));
        self::assertSame('running', virtusphere_client_phase_state(['event' => 'started', 'created_at' => $this->seenAgo(60)], self::NOW));
        self::assertSame('unconfirmed', virtusphere_client_phase_state(['event' => 'started', 'created_at' => $this->seenAgo(VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS + 1)], self::NOW));
    }
}
