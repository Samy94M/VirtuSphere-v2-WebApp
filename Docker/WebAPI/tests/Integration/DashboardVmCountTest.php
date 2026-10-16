<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * The dashboard's VM tile counts the VMs of real missions only. Template VMs
 * are the blueprint that a clone copies, so counting them reports each machine
 * twice. The shared dev database holds other rows, so both assertions are
 * deltas around the seeded rows and never absolute numbers.
 */
final class DashboardVmCountTest extends TestCase
{
    private const PREFIX = 'phpunit_dashvmcount_';

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

    public function testTemplateVmsAreExcludedAndRealMissionVmsAreCounted(): void
    {
        $tileBefore = repo_vm_count_in_real_missions($this->db);
        $allBefore = (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms');

        $template = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . bin2hex(random_bytes(3))]);
        $mission = repo_create_mission($this->db, ['mission_name' => self::PREFIX . bin2hex(random_bytes(3))]);
        $this->insertVms($template, 3);
        $this->insertVms($mission, 2);

        self::assertSame(
            2,
            repo_vm_count_in_real_missions($this->db) - $tileBefore,
            'the tile counts the two VMs of the real mission and none of the template'
        );
        self::assertSame(
            5,
            (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms') - $allBefore,
            'the unfiltered table holds all five seeded rows, which is what the tile used to show'
        );
    }

    public function testATemplateWithoutAnyRealMissionAddsNothing(): void
    {
        $before = repo_vm_count_in_real_missions($this->db);

        $template = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'only_tpl']);
        $this->insertVms($template, 4);

        self::assertSame($before, repo_vm_count_in_real_missions($this->db));
    }

    private function insertVms(int $missionId, int $count): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
        $syncState = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        for ($index = 0; $index < $count; $index++) {
            $name = 'DASHVM' . bin2hex(random_bytes(4));
            $stmt->bind_param('issss', $missionId, $name, $name, $lifecycle, $syncState);
            $stmt->execute();
        }
    }

    private function cleanup(): void
    {
        // The template prefix is a LIKE wildcard, so it is escaped before the pattern is built.
        $templatePattern = str_replace('_', '\\_', VIRTUSPHERE_TEMPLATE_PREFIX) . self::PREFIX . '%';
        $plainPattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ? OR mission_name LIKE ?');
        $stmt->bind_param('ss', $templatePattern, $plainPattern);
        $stmt->execute();
    }
}
