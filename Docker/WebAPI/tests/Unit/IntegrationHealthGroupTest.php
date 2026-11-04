<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/integration_health.php';

final class IntegrationHealthGroupTest extends TestCase
{
    public function testMaintenanceDoesNotBelongToMecmGroups(): void
    {
        $rows = [[
            'source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
            'row' => [],
            'state' => 'warning',
        ]];
        self::assertSame([], repo_integration_rows_for_sources(
            $rows,
            VIRTUSPHERE_INTEGRATION_MECM_SYNC_SOURCES
        ));
        self::assertSame([], repo_integration_rows_for_sources(
            $rows,
            VIRTUSPHERE_INTEGRATION_MECM_SITE_SOURCES
        ));
        self::assertSame($rows, repo_integration_rows_for_sources(
            $rows,
            VIRTUSPHERE_INTEGRATION_INTERNAL_SOURCES
        ));
    }

    public function testGroupFilteringAndWorstStateAreDeterministic(): void
    {
        $rows = [
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'row' => [], 'state' => 'ok'],
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => [], 'state' => 'danger'],
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE, 'row' => [], 'state' => 'warning'],
        ];
        $mecm = array_merge(
            repo_integration_rows_for_sources($rows, VIRTUSPHERE_INTEGRATION_MECM_SYNC_SOURCES),
            repo_integration_rows_for_sources($rows, VIRTUSPHERE_INTEGRATION_MECM_SITE_SOURCES)
        );
        self::assertSame('danger', repo_integration_worst_state($mecm));
        self::assertCount(2, $mecm);
        self::assertSame(
            'warning',
            repo_integration_group_worst_state($rows, VIRTUSPHERE_INTEGRATION_INTERNAL_SOURCES)
        );
    }

    public function testStatusRankingIsStable(): void
    {
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('warning'), virtusphere_heartbeat_state_rank('danger'));
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('warning'), virtusphere_heartbeat_state_rank('missing'));
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('unknown'), virtusphere_heartbeat_state_rank('missing'));
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('ok'), virtusphere_heartbeat_state_rank('unknown'));
        // A fresh legacy heartbeat is milder than a stale reporter but worse than
        // an unconfigured source: unknown < legacy < warning.
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('legacy'), virtusphere_heartbeat_state_rank('warning'));
        self::assertGreaterThan(virtusphere_heartbeat_state_rank('unknown'), virtusphere_heartbeat_state_rank('legacy'));
    }

    // The heartbeat roll-up (repo) and the ESXi/Ansible roll-ups (health
    // snapshot) used to carry their own rank tables and disagreed about
    // `missing`. One function now serves all three.
    public function testOneRankingServesEveryGroup(): void
    {
        $rows = [
            ['source' => 'a', 'row' => null, 'state' => 'warning'],
            ['source' => 'b', 'row' => null, 'state' => 'missing'],
        ];
        self::assertSame('missing', repo_integration_worst_state($rows));
    }

    public function testCompletedEvidenceNeedsAReadableNonFutureTimestamp(): void
    {
        $now = strtotime('2026-09-08 12:00:00 UTC');
        $row = ['last_status' => VIRTUSPHERE_RUN_OUTCOME_OK, 'interval_seconds' => 60];
        self::assertSame('unknown', virtusphere_run_completed_state($row, $now));
        $row['last_result_at'] = 'not-a-time';
        self::assertSame('unknown', virtusphere_run_completed_state($row, $now));
        $row['last_result_at'] = gmdate('Y-m-d H:i:s', $now + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS + 1);
        self::assertSame('unknown', virtusphere_run_completed_state($row, $now));
        $row['last_result_at'] = gmdate('Y-m-d H:i:s', $now + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS);
        self::assertSame('ok', virtusphere_run_completed_state($row, $now));
    }

    public function testRunningEvidenceRejectsInvalidAttemptOrPreviousResultTime(): void
    {
        $now = strtotime('2026-09-08 12:00:00 UTC');
        $row = [
            'last_event' => VIRTUSPHERE_RUN_EVENT_STARTED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_OK,
            'interval_seconds' => 60,
            'last_attempt_at' => 'not-a-time',
            'last_result_at' => gmdate('Y-m-d H:i:s', $now - 30),
        ];
        self::assertSame('unknown', virtusphere_run_running_state($row, $now));
        $row['last_attempt_at'] = gmdate('Y-m-d H:i:s', $now - 10);
        $row['last_result_at'] = gmdate('Y-m-d H:i:s', $now + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS + 1);
        self::assertSame('unknown', virtusphere_run_running_state($row, $now));
    }

    public function testAPunctualWarningIsNotAnOverdueReporter(): void
    {
        // Prod shape (DS1): the device sync completes every 10 s with outcome
        // warning because two VMs are blocked. Yellow, but the task is alive;
        // only an aged-out result or an outlived start is "overdue".
        $now = strtotime('2026-11-04 12:00:00 UTC');
        $at = static fn (int $ago): string => gmdate('Y-m-d H:i:s', $now - $ago);
        $completed = [
            'last_event' => VIRTUSPHERE_RUN_EVENT_COMPLETED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_WARNING,
            'last_error_category' => VIRTUSPHERE_RUN_ERROR_PARTIAL_FAILURE,
            'interval_seconds' => 10,
            'last_result_at' => $at(5),
        ];
        self::assertSame('warning', virtusphere_run_completed_state($completed, $now));
        self::assertFalse(virtusphere_run_report_overdue($completed, $now));

        $warnAfter = max(10 * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER, VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS);
        $completed['last_status'] = VIRTUSPHERE_RUN_OUTCOME_OK;
        $completed['last_result_at'] = $at($warnAfter + 1);
        self::assertSame('warning', virtusphere_run_completed_state($completed, $now));
        self::assertTrue(virtusphere_run_report_overdue($completed, $now));
        $completed['last_result_at'] = 'not-a-time';
        self::assertTrue(virtusphere_run_report_overdue($completed, $now));

        $grace = virtusphere_run_grace_seconds(['interval_seconds' => 10]);
        $started = [
            'last_event' => VIRTUSPHERE_RUN_EVENT_STARTED,
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_WARNING,
            'interval_seconds' => 10,
            'last_attempt_at' => $at($grace),
            'last_result_at' => $at($grace + 10),
        ];
        self::assertSame('warning', virtusphere_run_running_state($started, $now));
        self::assertFalse(virtusphere_run_report_overdue($started, $now));
        $started['last_attempt_at'] = $at($grace + 1);
        self::assertTrue(virtusphere_run_report_overdue($started, $now));

        // A long scan whose last run took 20 min is still on time after the fixed
        // grace: the overdue hint and the running state share one deadline.
        $started['last_duration_ms'] = 1200 * 1000;
        self::assertFalse(virtusphere_run_report_overdue($started, $now));
        self::assertSame('warning', virtusphere_run_running_state($started, $now));
        $started['last_attempt_at'] = $at(2400 + 1);
        self::assertTrue(virtusphere_run_report_overdue($started, $now));

        $legacy = ['last_event' => VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT, 'interval_seconds' => 10, 'last_seen_at' => $at(5)];
        self::assertFalse(virtusphere_run_report_overdue($legacy, $now));
        $legacy['last_seen_at'] = $at($warnAfter + 1);
        self::assertTrue(virtusphere_run_report_overdue($legacy, $now));

        self::assertTrue(virtusphere_run_report_overdue(null, $now));
    }

    public function testSiteAgeAndProviderFaultNeverInventMecmCriticality(): void
    {
        $now = strtotime('2026-09-08 12:00:00 UTC');
        $row = [
            'last_status' => VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
            'last_error_category' => VIRTUSPHERE_RUN_ERROR_PROVIDER_ACCESS_DENIED,
            'last_result_at' => gmdate('Y-m-d H:i:s', $now - 30),
            'interval_seconds' => 60,
        ];
        self::assertSame('unknown', virtusphere_site_completed_state($row, $now));
        $freshFor = max(60 * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER, VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS);
        $row['last_result_at'] = gmdate('Y-m-d H:i:s', $now - $freshFor - 1);
        self::assertSame('stale', virtusphere_site_completed_state($row, $now));
        $row['last_status'] = VIRTUSPHERE_RUN_OUTCOME_FAIL;
        $row['last_error_category'] = VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL;
        self::assertSame('stale', virtusphere_site_completed_state($row, $now));
        $row['last_result_at'] = gmdate('Y-m-d H:i:s', $now - 30);
        self::assertSame('danger', virtusphere_site_completed_state($row, $now));
    }
}
