<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory_scheduler.php';

/**
 * K4b: after a first `host_error` the automation tries exactly once more after
 * a few minutes; every other state waits for the interval.
 */
final class EsxiInventoryAttemptDueTest extends TestCase
{
    private const INTERVAL = 6 * 3600;
    private const NOW = 1_790_000_000;

    /** @return array<string, mixed> */
    private static function failed(string $category, int $streak, int $secondsAgo): array
    {
        return [
            'last_status' => 'failed',
            'last_error_category' => $category,
            'failure_streak' => $streak,
            'last_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW - $secondsAgo),
        ];
    }

    public function testNeverPulledIsDue(): void
    {
        self::assertTrue(esxi_inventory_attempt_due(null, self::INTERVAL, self::NOW));
        self::assertTrue(esxi_inventory_attempt_due(['last_attempt_at' => null], self::INTERVAL, self::NOW));
    }

    public function testTheFirstHostErrorIsRetriedAfterAFewMinutes(): void
    {
        $wait = VIRTUSPHERE_ESXI_INVENTORY_HOST_ERROR_RETRY_SECONDS;
        self::assertFalse(esxi_inventory_attempt_due(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 1, $wait - 1), self::INTERVAL, self::NOW));
        self::assertTrue(esxi_inventory_attempt_due(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 1, $wait), self::INTERVAL, self::NOW));
        self::assertTrue(esxi_inventory_host_error_follow_up_pending(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 1, 0)));
    }

    /** The follow-up that fails again raises the streak to 2: exactly one follow-up. */
    public function testASecondHostErrorWaitsForTheInterval(): void
    {
        self::assertFalse(esxi_inventory_attempt_due(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 2, 3600), self::INTERVAL, self::NOW));
        self::assertTrue(esxi_inventory_attempt_due(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 2, self::INTERVAL), self::INTERVAL, self::NOW));
    }

    /** Never for a rejected sign-in or missing rights, and not for any other category. */
    public function testOtherCategoriesNeverGetTheShortFollowUp(): void
    {
        foreach (VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES as $category) {
            if ($category === VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR) {
                continue;
            }
            self::assertFalse(
                esxi_inventory_attempt_due(self::failed($category, 1, 3600), self::INTERVAL, self::NOW),
                $category . ' must wait for the interval'
            );
            self::assertFalse(esxi_inventory_host_error_follow_up_pending(self::failed($category, 1, 0)), $category);
        }
    }

    public function testASuccessAfterwardsWaitsForTheInterval(): void
    {
        $state = [
            'last_status' => 'succeeded',
            'last_error_category' => null,
            'failure_streak' => 0,
            'last_attempt_at' => gmdate('Y-m-d H:i:s', self::NOW - 3600),
        ];
        self::assertFalse(esxi_inventory_attempt_due($state, self::INTERVAL, self::NOW));
    }

    /** A short interval is never stretched by the follow-up wait. */
    public function testTheFollowUpNeverWaitsLongerThanTheInterval(): void
    {
        self::assertTrue(esxi_inventory_attempt_due(self::failed(VIRTUSPHERE_INVENTORY_ERROR_HOST_ERROR, 1, 120), 120, self::NOW));
    }
}
