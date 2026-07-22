<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * A2 mission export/import round-trip against the live DB. Proves payload
 * fidelity (VM/interface/disk/package counts), that MAC addresses never travel,
 * and that importing into the SAME environment is blocked on the global VM-name
 * uniqueness rule (MECM device names). Cleans up its own rows by name prefix.
 */
final class MissionTransferRoundTripTest extends TestCase
{
    private const PREFIX = 'phpunit_xfer_';
    private const PKG = 'phpunit_xfer_pkg-1.0';

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

    public function testRoundTripPreservesCountsAndDropsMacs(): void
    {
        $packageId = $this->makePackage();
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER1', '10.0.0.5', '00:11:22:33:44:55', $packageId);
        $this->makeVm($sourceId, 'PHPUNITXFER2', '10.0.0.6', 'AA:BB:CC:DD:EE:FF', $packageId);

        $payload = mission_export_payload($this->db, $sourceId);
        self::assertSame(VIRTUSPHERE_MISSION_EXPORT_VERSION, $payload['format_version']);
        self::assertCount(2, $payload['vms']);
        // The export format must not carry MAC addresses at all.
        foreach ($payload['vms'] as $vm) {
            foreach ($vm['interfaces'] as $interface) {
                self::assertArrayNotHasKey('mac', $interface);
            }
            self::assertSame('phpunit_xfer_pkg-1.0', $vm['packages'][0]['name']);
        }

        // Real transfer target = a different environment; emulate by removing the
        // source (its VM names free the global namespace) before importing.
        deleteMission($sourceId, $this->db);

        $report = mission_import($this->db, $payload, self::PREFIX . 'dst', false, 1);
        self::assertTrue($report['imported']);
        self::assertGreaterThan(0, (int) $report['mission_id']);

        $vms = getVMs($this->db, (int) $report['mission_id']);
        self::assertCount(2, $vms);
        $interfaceCount = 0;
        $diskCount = 0;
        foreach ($vms as $vm) {
            foreach ($vm['interfaces'] as $interface) {
                $interfaceCount++;
                // MAC must be empty after import (never carried across).
                self::assertSame('', (string) ($interface['mac'] ?? ''));
            }
            $diskCount += count($vm['disks']);
            self::assertCount(1, $vm['packages']);
        }
        self::assertSame(2, $interfaceCount);
        self::assertSame(2, $diskCount);
    }

    public function testImportIntoSameEnvironmentIsBlockedOnVmNameConflict(): void
    {
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src2',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER3', '10.0.0.7', '00:11:22:33:44:66', 0);

        $payload = mission_export_payload($this->db, $sourceId);

        // Source still present -> its VM name collides globally -> blocked.
        $report = mission_import($this->db, $payload, self::PREFIX . 'dst2', true);
        self::assertTrue($report['blocked']);
        self::assertNotEmpty($report['vm_name_conflicts']);

        // And a non-dry-run must refuse rather than write a partial mission.
        $this->expectException(RuntimeException::class);
        mission_import($this->db, $payload, self::PREFIX . 'dst2', false, 1);
    }

    private function makeVm(int $missionId, string $name, string $ip, string $mac, int $packageId): void
    {
        $packages = $packageId > 0 ? [['id' => $packageId]] : [];
        repo_save_vm(
            $this->db,
            $missionId,
            null,
            [
                'vm_name' => $name,
                'vm_hostname' => $name,
                'vm_os' => 'Windows Server 2019',
                'vm_domain' => 'dc.example.com',
                'vm_guest_id' => 'windows2019srv_64Guest',
            ],
            [[
                'ip' => $ip,
                'subnet' => '255.255.255.0',
                'gateway' => '10.0.0.1',
                'mode' => 'static',
                'type' => 'vmxnet3',
                'vlan' => '',
                'mac' => $mac,
            ]],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            $packages,
            '',
            1
        );
    }

    private function makePackage(): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status) VALUES (?, ?, ?, ?)');
        $name = self::PKG;
        $base = 'phpunit_xfer_pkg';
        $version = '1.0';
        $statusActive = 'Active';
        $stmt->bind_param('ssss', $name, $base, $version, $statusActive);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        foreach ([self::PREFIX . '%'] as $pattern) {
            $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_packages WHERE package_basename = ?');
        $base = 'phpunit_xfer_pkg';
        $stmt->bind_param('s', $base);
        $stmt->execute();
    }
}
