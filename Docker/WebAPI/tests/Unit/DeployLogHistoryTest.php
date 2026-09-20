<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_log_history.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_phases.php';

final class DeployLogHistoryTest extends TestCase
{
    public function testUnresolvedAndReusedVmsNeverClaimNewCreation(): void
    {
        $rows = deploy_log_history_rows([
            ['vm_name' => 'VM-A', 'status' => 'uncertain', 'outcome' => 'created', 'finished_at' => '2026-09-14 10:00:00'],
            ['vm_name' => 'VM-B', 'status' => 'skipped', 'outcome' => 'created'],
            ['vm_name' => 'VM-C', 'status' => 'succeeded', 'outcome' => 'updated'],
        ], null);
        self::assertSame(['unknown', 'verified', 'updated'], array_column($rows, 'result'));
        self::assertNull($rows[0]['finished_at']);
    }

    public function testMacResultsHaveNoInventedIndividualTimes(): void
    {
        $rows = deploy_log_history_rows([], ['vm_results' => [
            ['vm_name' => '<VM-A>', 'outcome' => 'success'],
            ['vm_name' => 'vm-a', 'outcome' => 'failed'],
        ]]);
        self::assertSame(['<VM-A>', 'vm-a'], array_column($rows, 'vm_name'));
        self::assertSame(['imported', 'failed'], array_column($rows, 'result'));
        self::assertSame([null, null], array_column($rows, 'finished_at'));
    }

    public function testPhaseEndRequiresMatchingEvidence(): void
    {
        $timeline = deploy_log_phase_timeline([
            ['seq' => 1, 'line' => ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'startVMs-ESXi_playbook.yml'), 'created_at' => '2026-09-14 10:00:00'],
            ['seq' => 2, 'line' => ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_END, 'exportVMs-Informations-ESXi_playbook.yml'), 'created_at' => '2026-09-14 10:01:00'],
        ]);
        self::assertSame('2026-09-14 10:00:00', $timeline['phases'][0]['started_at']);
        self::assertNull($timeline['phases'][0]['finished_at']);
        self::assertFalse($timeline['phases'][0]['complete']);
    }
}
