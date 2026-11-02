<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * SP4 R1: the phase distribution of the mission list. Per mission the phase
 * counts add up to its VMs, and each count is the number of VMs the PHP rule
 * puts in that phase (the twin of VmPhaseSqlTwinTest, grouped). Template
 * missions are not counted: their VMs have no phase.
 */
final class MissionPhaseCountTest extends TestCase
{
    private const PREFIX = 'phpunit_missionphase_';
    private const MAC = '02:00:00:00:A7:41';

    private mysqli $db;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testThePhaseCountsOfAMissionAddUpToItsVmsAndMatchThePhpRule(): void
    {
        $first = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'a_' . bin2hex(random_bytes(3))]);
        $second = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'b_' . bin2hex(random_bytes(3))]);
        $template = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 't_' . bin2hex(random_bytes(3))]);

        $index = 0;
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $lifecycle) {
            foreach (VIRTUSPHERE_MECM_SYNC_STATES as $mecm) {
                $index++;
                $this->insertVm($index % 2 === 0 ? $first : $second, $index, $lifecycle, $mecm, $index % 3 === 0 ? 'uuid-' . $index : null);
            }
        }
        $clean = $this->insertVm($first, 900, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, 'uuid-clean');
        foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
            repo_record_client_event($this->db, $clean, self::MAC, $phase, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        }
        $this->insertVm($template, 901, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, 'uuid-tpl');

        $counts = repo_vm_phase_counts_by_mission($this->db);

        foreach ([$first, $second] as $missionId) {
            $vms = $this->vmRows($missionId);
            $evidence = repo_vm_evidence($this->db, $vms);
            $expected = [];
            foreach ($vms as $vm) {
                $phase = virtusphere_vm_phase($vm, repo_vm_evidence_for_vm($evidence, $vm, false));
                $expected[$phase] = ($expected[$phase] ?? 0) + 1;
            }
            // The reader keeps the chain order of VIRTUSPHERE_VM_PHASES.
            $ordered = array_intersect_key(array_fill_keys(VIRTUSPHERE_VM_PHASES, 0), $expected);
            foreach ($expected as $phase => $count) {
                $ordered[$phase] = $count;
            }

            self::assertArrayHasKey($missionId, $counts);
            self::assertSame($ordered, $counts[$missionId], 'mission ' . $missionId . ': SQL and PHP phases differ');
            self::assertSame(count($vms), array_sum($counts[$missionId]), 'the badges add up to the VM count');
            self::assertNotContains(0, $counts[$missionId], 'an empty phase is left out');
        }

        self::assertSame(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, array_key_last($counts[$first]), 'the clean installed VM reaches the last phase');
        self::assertArrayNotHasKey($template, $counts, 'a template mission has no phase distribution');
    }

    private function insertVm(int $missionId, int $index, string $lifecycle, string $mecm, ?string $uuid): int
    {
        $name = sprintf('PMC%03d%s', $index, bin2hex(random_bytes(2)));
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid) VALUES (?, ?, ?, ?, ?, ?)',
            'isssss',
            [$missionId, $name, $name, $lifecycle, $mecm, $uuid]
        );

        return (int) $this->db->insert_id;
    }

    /** @return list<array<string,mixed>> */
    private function vmRows(int $missionId): array
    {
        $stmt = $this->db->prepare('SELECT v.* FROM deploy_vms v WHERE v.mission_id = ? ORDER BY v.id');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    private function cleanup(): void
    {
        $templatePattern = str_replace('_', '\\_', VIRTUSPHERE_TEMPLATE_PREFIX) . self::PREFIX . '%';
        $plainPattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ? OR mission_name LIKE ?');
        $stmt->bind_param('ss', $templatePattern, $plainPattern);
        $stmt->execute();
    }
}
