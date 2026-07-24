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
}
