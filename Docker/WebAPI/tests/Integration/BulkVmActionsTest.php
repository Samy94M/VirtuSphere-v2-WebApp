<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * Paket C bulk VM actions against the live DB: bulk delete removes only portal
 * records, skips every VM while the mission has an active deploy job, and
 * bulk MECM-ID reset skips VMs without an imported MAC.
 */
final class BulkVmActionsTest extends TestCase
{
    private const PREFIX = 'phpunit_bulk_';

    private ?mysqli $db = null;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testBulkDeleteRemovesSelectedVms(): void
    {
        $a = $this->makeVm('PHPUNITBULK1');
        $b = $this->makeVm('PHPUNITBULK2');
        $this->makeVm('PHPUNITBULK3');

        $result = repo_bulk_delete_vms($this->db, $this->missionId, [$a, $b]);
        self::assertSame(2, $result['deleted']);
        self::assertSame([], $result['skipped']);
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms WHERE mission_id = ?', 'i', [$this->missionId]));
    }

    public function testBulkDeleteSkipsWhenMissionHasActiveJob(): void
    {
        $a = $this->makeVm('PHPUNITBULK4');
        $stmt = $this->db->prepare("INSERT INTO deploy_jobs (mission_id, status) VALUES (?, 'queued')");
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();

        $result = repo_bulk_delete_vms($this->db, $this->missionId, [$a]);
        self::assertSame(0, $result['deleted']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('active_job', $result['skipped'][0]['reason']);
        // VM still present.
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms WHERE mission_id = ?', 'i', [$this->missionId]));
    }

    public function testBulkResetSkipsVmsWithoutImportedMac(): void
    {
        $a = $this->makeVm('PHPUNITBULK5'); // interface has empty MAC
        $result = repo_bulk_reset_mecm_ids($this->db, $this->missionId, [$a], 1);
        self::assertSame(0, $result['done']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('no_mac', $result['skipped'][0]['reason']);
    }

    private function makeVm(string $name): int
    {
        return repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '10.0.0.5', 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => '', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach (['DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
