<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_location.php';

/**
 * Migration 0014 (ADR-0023 refinement). The old VM editor prefilled
 * vm_datastore/vm_datacenter with the mission value, so the columns are full of
 * copies. Once the deploy resolves them ahead of the mission value, a copy
 * becomes a silent override that survives every later mission change. The
 * normalization clears the exact copies and nothing else. Prefix-scoped cleanup.
 */
final class VmLocationOverrideNormalizationTest extends TestCase
{
    private const PREFIX = 'phpunit_vmloc_';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testCopiesAreClearedWhileRealOverridesSurvive(): void
    {
        $missionId = $this->makeMission();
        $copy = $this->makeVm($missionId, 'PHPUNITLOC1', 'ds1', 'DC1');
        $override = $this->makeVm($missionId, 'PHPUNITLOC2', 'ssd-fast', 'DC-B');
        $mixed = $this->makeVm($missionId, 'PHPUNITLOC3', 'ds1', 'DC-B');

        // The normalization is database-wide, like the migration that calls it,
        // so only the per-row outcome can be asserted, not the returned counts.
        repo_normalize_vm_location_overrides($this->db);

        self::assertSame(['', ''], $this->location($copy));
        self::assertSame(['ssd-fast', 'DC-B'], $this->location($override));
        self::assertSame(['', 'DC-B'], $this->location($mixed), 'one axis clears independently');
    }

    public function testACaseVariantIsTreatedAsADeliberateOverride(): void
    {
        $missionId = $this->makeMission();
        $vmId = $this->makeVm($missionId, 'PHPUNITLOC4', 'DS1', 'dc1');

        repo_normalize_vm_location_overrides($this->db);
        self::assertSame(['DS1', 'dc1'], $this->location($vmId));
    }

    public function testRunningTwiceChangesNothingTheSecondTime(): void
    {
        $missionId = $this->makeMission();
        $this->makeVm($missionId, 'PHPUNITLOC5', 'ds1', 'DC1');

        repo_normalize_vm_location_overrides($this->db);
        self::assertSame(['datastore' => 0, 'datacenter' => 0], repo_normalize_vm_location_overrides($this->db));
    }

    private function makeMission(): int
    {
        return repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
            'wds_vlan' => 'VLAN10',
        ], true);
    }

    private function makeVm(int $missionId, string $name, string $datastore, string $datacenter): int
    {
        return repo_save_vm(
            $this->db,
            $missionId,
            null,
            [
                'vm_name' => $name,
                'vm_hostname' => $name,
                'vm_os' => 'Windows Server 2019',
                'vm_domain' => 'dc.example.com',
                'vm_guest_id' => 'windows2019srv_64Guest',
                'vm_datastore' => $datastore,
                'vm_datacenter' => $datacenter,
            ],
            [['ip' => '10.0.0.5', 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN10', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    /** @return array{0:string,1:string} datastore, datacenter */
    private function location(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT vm_datastore, vm_datacenter FROM deploy_vms WHERE id = ?', 'i', [$vmId]);

        return [(string) ($row['vm_datastore'] ?? ''), (string) ($row['vm_datacenter'] ?? '')];
    }

    private function cleanup(): void
    {
        $like = '%' . self::PREFIX . '%';
        foreach (['DELETE FROM deploy_interfaces WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?))',
                  'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
