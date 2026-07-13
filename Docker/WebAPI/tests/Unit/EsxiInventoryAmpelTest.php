<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * Traffic-light matrix for esxi_inventory_ampel (ADR-0023). $now is injected so
 * the staleness branch is deterministic. Interval 0 (automation off) must never
 * warn about age; only real failures colour the light then.
 */
final class EsxiInventoryAmpelTest extends TestCase
{
    private const NOW = '2026-07-09 12:00:00';

    private function now(): int
    {
        return (int) strtotime(self::NOW . ' UTC');
    }

    /** @param array<string, mixed> $overrides */
    private function state(array $overrides = []): array
    {
        return $overrides + [
            'last_attempt_at' => self::NOW,
            'last_success_at' => self::NOW,
            'last_status' => 'ok',
            'last_error_category' => null,
            'failure_streak' => 0,
            'paused_until_credential_change' => 0,
        ];
    }

    public function testNoStateOrNoAttemptIsUnknown(): void
    {
        self::assertSame('unknown', esxi_inventory_ampel(null, 6, $this->now()));
        self::assertSame('unknown', esxi_inventory_ampel($this->state(['last_attempt_at' => null]), 6, $this->now()));
    }

    public function testAuthPauseIsDangerRegardlessOfInterval(): void
    {
        $paused = $this->state(['paused_until_credential_change' => 1, 'last_status' => 'failed']);
        self::assertSame('danger', esxi_inventory_ampel($paused, 6, $this->now()));
        self::assertSame('danger', esxi_inventory_ampel($paused, 0, $this->now()));
    }

    public function testFailureStreakThresholdIsDanger(): void
    {
        $atThreshold = $this->state(['failure_streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER, 'last_status' => 'failed']);
        self::assertSame('danger', esxi_inventory_ampel($atThreshold, 6, $this->now()));

        $belowThreshold = $this->state(['failure_streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER - 1, 'last_status' => 'failed']);
        self::assertSame('warning', esxi_inventory_ampel($belowThreshold, 6, $this->now()));
    }

    public function testNeverSucceededIsWarning(): void
    {
        $neverSucceeded = $this->state(['last_success_at' => null, 'last_status' => 'failed', 'failure_streak' => 1]);
        self::assertSame('warning', esxi_inventory_ampel($neverSucceeded, 6, $this->now()));
        // Also with the automation off: "no success ever" is a real signal.
        self::assertSame('warning', esxi_inventory_ampel($neverSucceeded, 0, $this->now()));
    }

    public function testFreshSuccessIsOk(): void
    {
        self::assertSame('ok', esxi_inventory_ampel($this->state(['last_success_at' => '2026-07-09 11:00:00']), 6, $this->now()));
    }

    public function testSuccessOlderThanStaleFactorTimesIntervalIsWarning(): void
    {
        // 24h old success with a 6h interval: 24h > 2 x 6h.
        $stale = $this->state(['last_success_at' => '2026-07-08 12:00:00']);
        self::assertSame('warning', esxi_inventory_ampel($stale, 6, $this->now()));
    }

    public function testIntervalZeroSuppressesStalenessWarning(): void
    {
        // Automation off: a months-old but successful pull stays ok.
        $old = $this->state(['last_success_at' => '2026-01-01 00:00:00']);
        self::assertSame('ok', esxi_inventory_ampel($old, 0, $this->now()));
    }

    public function testIntervalZeroStillWarnsOnFailedLastFetch(): void
    {
        $failed = $this->state(['last_success_at' => '2026-01-01 00:00:00', 'last_status' => 'failed', 'failure_streak' => 1]);
        self::assertSame('warning', esxi_inventory_ampel($failed, 0, $this->now()));
    }
}
