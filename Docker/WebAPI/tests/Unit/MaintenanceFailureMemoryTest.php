<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';

/**
 * TS1-K4: the maintenance worker remembers failed jobs until the same job
 * succeeds again (G2-7), and the System status reads a running service with
 * failing jobs as "Beeinträchtigt", not "Nicht erreichbar" (G2-6).
 *
 * The memory rule and the display derivation are pure, so every corner is
 * pinned here without a worker or a database.
 */
final class MaintenanceFailureMemoryTest extends TestCase
{
    private const SCHEDULED = ['self-heartbeat', 'retention', 'log-rotation', 'deploy-job-reap', 'deploy-vm-sweep', 'esxi-inventory'];

    public function testAFailureOfAJobThatDidNotRunAgainIsKept(): void
    {
        // The hourly retention failed one pass ago; this pass only the reaper ran.
        self::assertSame(
            ['retention:client_events'],
            maintenance_worker_remembered_failures(['retention:client_events'], [], ['deploy-job-reap' => true], self::SCHEDULED),
            'the old code forgot it here and the next heartbeat said loop ok'
        );
    }

    public function testASuccessOfTheSameJobClearsIt(): void
    {
        self::assertSame([], maintenance_worker_remembered_failures(['deploy-job-reap'], [], ['deploy-job-reap' => true], self::SCHEDULED));
    }

    public function testARenewedFailureStaysAndANewOneJoinsSortedWithoutDuplicates(): void
    {
        self::assertSame(
            ['deploy-job-reap', 'retention:portal_logs'],
            maintenance_worker_remembered_failures(
                ['retention:portal_logs'],
                ['retention:portal_logs', 'deploy-job-reap'],
                ['retention' => true, 'deploy-job-reap' => true],
                self::SCHEDULED
            )
        );
    }

    public function testARetentionRunReJudgesEveryPurgeOfItsGroup(): void
    {
        // retention:audit runs only when something was purged. Keyed by its own
        // name it would stay reported for ever after a quiet hour; its group is
        // `retention`, whose next clean run clears it.
        self::assertSame([], maintenance_worker_remembered_failures(['retention:audit'], [], ['retention' => true], self::SCHEDULED));
        self::assertSame(['retention:audit'], maintenance_worker_remembered_failures(['retention:audit'], [], ['log-rotation' => true], self::SCHEDULED));
    }

    public function testAJobWithoutAScheduleIsReJudgedEveryPass(): void
    {
        // audit-transitions has no maintenance_worker_due() and never appears in
        // last_run: it runs in every pass, so every pass decides it anew.
        self::assertSame([], maintenance_worker_remembered_failures(['audit-transitions'], [], [], self::SCHEDULED));
        self::assertSame(['audit-transitions'], maintenance_worker_remembered_failures([], ['audit-transitions'], [], self::SCHEDULED));
    }

    public function testAFreshProcessStartsWithAnEmptyMemory(): void
    {
        // A restart loses the memory; the first pass runs every job because
        // last_run is empty, so whatever fails is reported at once.
        $state = ['last_run' => [], 'states' => []];
        foreach (self::SCHEDULED as $job) {
            self::assertTrue(maintenance_worker_due($state, $job, 3600, false), $job . ' must be due on the first pass');
        }
        self::assertSame(array_fill_keys(self::SCHEDULED, true), $state['ran']);
        self::assertSame(['esxi-inventory'], maintenance_worker_remembered_failures($state['failed_jobs'] ?? [], ['esxi-inventory'], $state['ran'], array_keys($state['last_run'])));
    }

    public function testAChangedListIsReportedAtOnceInBothDirections(): void
    {
        self::assertTrue(maintenance_worker_report_due(false, [], ['retention']), 'a new failure does not wait for the due heartbeat');
        self::assertTrue(maintenance_worker_report_due(false, ['retention'], []), 'neither does the recovery');
        self::assertTrue(maintenance_worker_report_due(false, null, []), 'a fresh process reports its first verdict');
        self::assertFalse(maintenance_worker_report_due(false, ['retention'], ['retention']), 'an unchanged list waits for its interval');
        self::assertTrue(maintenance_worker_report_due(true, ['retention'], ['retention']));
    }

    public function testTheFailureGroupIsThePartBeforeTheFirstColon(): void
    {
        self::assertSame('retention', maintenance_worker_failure_group('retention:portal_logs'));
        self::assertSame('deploy-job-reap', maintenance_worker_failure_group('deploy-job-reap'));
    }

    public function testTheHeartbeatDetailRoundTrips(): void
    {
        self::assertSame('loop ok', integration_maintenance_heartbeat_detail([]));
        $detail = integration_maintenance_heartbeat_detail(['deploy-job-reap', 'retention:client_events']);
        self::assertSame('failed jobs: deploy-job-reap, retention:client_events', $detail);
        self::assertSame(['deploy-job-reap', 'retention:client_events'], integration_maintenance_failed_jobs($detail));
        self::assertSame([], integration_maintenance_failed_jobs('loop ok'));
        self::assertSame([], integration_maintenance_failed_jobs(null));
        self::assertSame(['retention'], integration_maintenance_failed_jobs('failed jobs: retention, <b>x</b>'), 'only job-name characters reach the hint');
    }

    public function testTheWorkerWritesTheDetailThroughTheSharedOwner(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/maintenance_tasks.php');
        self::assertStringContainsString('integration_maintenance_heartbeat_detail($remembered)', $source);
        self::assertStringNotContainsString("'failed jobs: '", $source, 'the format has one owner (lib/integration_health.php)');
        // integration_maintenance_alive() names this job; it must stay the job's name.
        self::assertStringContainsString("maintenance_worker_due(\$state, 'esxi-inventory'", $source);
    }

    public function testARunningServiceWithFailedJobsIsDegraded(): void
    {
        $now = 100_000;
        $entry = integration_health_maintenance_entry($this->entry('fail', 'failed jobs: retention:client_events', $now - 30), $now);

        self::assertSame(VIRTUSPHERE_LIVENESS_STATE_DEGRADED, $entry['state']);
        self::assertSame(['retention:client_events'], $entry['failed_jobs']);
        self::assertSame('limited', status_tone_for('liveness', $entry['state']));
    }

    public function testALateFailureIsStillUnreachable(): void
    {
        // Nothing establishes that a service that stopped checking in still runs,
        // so it keeps the old state and with it the restart hint.
        $now = 100_000;
        $late = $now - VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER * 60 - 1;
        self::assertSame('danger', integration_health_maintenance_entry($this->entry('fail', 'failed jobs: retention', $late), $now)['state']);
        self::assertArrayNotHasKey('failed_jobs', integration_health_maintenance_entry($this->entry('fail', 'failed jobs: retention', $late), $now));
    }

    public function testAFailureWithoutNamedJobsAndOtherSourcesAreUntouched(): void
    {
        $now = 100_000;
        self::assertSame('danger', integration_health_maintenance_entry($this->entry('fail', 'something else', $now - 10), $now)['state']);
        self::assertSame('ok', integration_health_maintenance_entry($this->entry('ok', 'loop ok', $now - 10, 'ok'), $now)['state']);
        $deploy = $this->entry('fail', 'failed jobs: x', $now - 10);
        $deploy['source'] = VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER;
        self::assertSame('danger', integration_health_maintenance_entry($deploy, $now)['state']);
        $missing = ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'row' => null, 'state' => 'missing'];
        self::assertSame($missing, integration_health_maintenance_entry($missing, $now));
    }

    public function testTheInternalRollUpRanksDegradedByItsTone(): void
    {
        $row = static fn (string $source, string $state): array => ['source' => $source, 'row' => [], 'state' => $state];
        $maintenance = $row(VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, VIRTUSPHERE_LIVENESS_STATE_DEGRADED);

        self::assertSame(VIRTUSPHERE_LIVENESS_STATE_DEGRADED, integration_health_internal_state([$maintenance, $row(VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, 'ok')]));
        // Eingeschränkt outranks Warnung, Nicht erreichbar outranks both.
        self::assertSame(VIRTUSPHERE_LIVENESS_STATE_DEGRADED, integration_health_internal_state([$row(VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, 'warning'), $maintenance]));
        self::assertSame('danger', integration_health_internal_state([$maintenance, $row(VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, 'danger')]));
        self::assertSame('missing', integration_health_internal_state([$row(VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'missing'), $row(VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER, 'ok')]), 'without degraded the token roll-up is unchanged');
    }

    public function testADegradedServiceStillDrivesTheInventoryCycleUnlessThatJobFails(): void
    {
        $entry = ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'row' => [], 'state' => VIRTUSPHERE_LIVENESS_STATE_DEGRADED, 'failed_jobs' => ['retention']];
        self::assertTrue(integration_maintenance_alive($entry));
        $entry['failed_jobs'] = ['esxi-inventory'];
        self::assertFalse(integration_maintenance_alive($entry));
        self::assertFalse(integration_maintenance_alive(['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'row' => [], 'state' => 'danger']));
        self::assertTrue(integration_maintenance_alive(['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'row' => [], 'state' => 'ok']));
        self::assertFalse(integration_maintenance_alive(null));
    }

    /** @return array{source:string,row:array<string,mixed>,state:string} */
    private function entry(string $status, string $detail, int $checkedAt, string $state = 'danger'): array
    {
        return [
            'source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
            'row' => [
                'last_status' => $status,
                'last_detail' => $detail,
                'last_seen_at' => gmdate('Y-m-d H:i:s', $checkedAt - 3600),
                'last_checked_at' => gmdate('Y-m-d H:i:s', $checkedAt),
                'interval_seconds' => 60,
            ],
            'state' => $state,
        ];
    }
}
