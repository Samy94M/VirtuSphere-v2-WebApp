<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_late_start.php';

/**
 * K18 (FM-04, FM-E3): a scheduled job that started at least the threshold
 * after its time is named with its delay; an on-time, unscheduled or not yet
 * started job is not. There is no expiry, so this is the only consequence.
 */
final class DeployLateStartTest extends TestCase
{
    public function testTheThresholdIsInclusiveAndBelowItNothingIsNamed(): void
    {
        $threshold = VIRTUSPHERE_DEPLOY_LATE_START_THRESHOLD_MINUTES;
        $planned = '2026-10-11 06:00:00';
        $at = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', strtotime($planned . ' UTC') + $seconds);

        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => $planned, 'started_at' => $at($threshold * 60 - 1)]));
        self::assertSame($threshold, deploy_job_late_start_minutes(['scheduled_at' => $planned, 'started_at' => $at($threshold * 60)]));
        self::assertSame(95, deploy_job_late_start_minutes(['scheduled_at' => $planned, 'started_at' => $at(95 * 60 + 59)]));
        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => $planned, 'started_at' => $planned]));
    }

    public function testUnscheduledUnstartedAndUnreadableJobsAreNeverLate(): void
    {
        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => null, 'started_at' => '2026-10-11 09:00:00']));
        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => '2026-10-11 06:00:00', 'started_at' => null]));
        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => '2026-10-11 06:00:00']));
        self::assertNull(deploy_job_late_start_minutes(['scheduled_at' => 'garbage', 'started_at' => '2026-10-11 09:00:00']));
    }
}
