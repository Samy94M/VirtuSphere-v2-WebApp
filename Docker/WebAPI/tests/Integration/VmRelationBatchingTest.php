<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/** Pins the exact legacy getVMs() aggregate while relations are batch-loaded. */
final class VmRelationBatchingTest extends TestCase
{
    private const PREFIX = 'phpunit_vm_batch_';

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

    public function testBatchAggregateIsByteShapeEquivalentToThePreviousPerVmRead(): void
    {
        $missionId = $this->mission(self::PREFIX . 'target');
        $foreignMissionId = $this->mission(self::PREFIX . 'foreign');

        $zetaId = $this->vm($missionId, 'PHPUNIT-BATCH-ZETA', '', null);
        $alphaId = $this->vm($missionId, 'PHPUNIT-BATCH-ALPHA', null, '');
        $foreignId = $this->vm($foreignMissionId, 'PHPUNIT-BATCH-FOREIGN', 'foreign', 'foreign');

        $zuluPackage = $this->package(self::PREFIX . 'Zulu', '2.0');
        $alphaPackage = $this->package(self::PREFIX . 'Alpha', '1.0');
        $foreignPackage = $this->package(self::PREFIX . 'Foreign', '9.0');
        $this->linkPackage($zetaId, $zuluPackage);
        $this->linkPackage($zetaId, $alphaPackage);
        $this->linkPackage($foreignId, $foreignPackage);

        $this->interface($zetaId, '10.23.0.20', null, '');
        $this->interface($zetaId, '', '', null);
        $this->interface($foreignId, '10.99.0.1', 'foreign', 'foreign');
        $this->disk($zetaId, 'System', 40, 'thick');
        $this->disk($zetaId, 'Data', 0, 'thin');
        $this->disk($foreignId, 'Foreign', 99, 'thin');

        $expected = $this->legacyPerVmAggregate($missionId);
        $actual = getVMs($this->db, $missionId);

        self::assertSame($expected, $actual, 'row types, key order, null/empty values and relation multiplicity changed');
        self::assertSame(['PHPUNIT-BATCH-ALPHA', 'PHPUNIT-BATCH-ZETA'], array_column($actual, 'vm_name'));
        self::assertSame([], $actual[0]['packages']);
        self::assertSame([], $actual[0]['interfaces']);
        self::assertSame([], $actual[0]['disks']);
        self::assertSame([self::PREFIX . 'Alpha', self::PREFIX . 'Zulu'], array_column($actual[1]['packages'], 'package_name'));
        self::assertNull($actual[1]['interfaces'][0]['dns1']);
        self::assertSame('', $actual[1]['interfaces'][0]['dns2']);
        self::assertNotContains('PHPUNIT-BATCH-FOREIGN', array_column($actual, 'vm_name'), 'a foreign mission VM crossed the owner filter');
        self::assertNotContains(self::PREFIX . 'Foreign', array_column($actual[1]['packages'], 'package_name'), 'a foreign relation crossed the exact VM-ID scope');
        self::assertSame($alphaId, (int) $actual[0]['id']);
    }

    public function testQueryCountIsConstantForMultipleVmsAndEmptyMissionsStayEmpty(): void
    {
        $missionId = $this->mission(self::PREFIX . 'queries');
        $this->vm($missionId, 'PHPUNIT-BATCH-Q2', null, '');
        $this->vm($missionId, 'PHPUNIT-BATCH-Q1', '', null);

        $before = $this->selectCount();
        $vms = getVMs($this->db, $missionId);
        $after = $this->selectCount();

        self::assertCount(2, $vms);
        self::assertSame(4, $after - $before, 'one VM query plus one query for each of three relation kinds is required');

        $emptyMissionId = $this->mission(self::PREFIX . 'empty');
        $beforeEmpty = $this->selectCount();
        $empty = getVMs($this->db, $emptyMissionId);
        $afterEmpty = $this->selectCount();
        self::assertSame([], $empty);
        self::assertSame(1, $afterEmpty - $beforeEmpty, 'an empty mission must not issue relation queries');
    }

    public function testTheSecondBoundedBatchCarriesRelationsAndEveryReadIsFresh(): void
    {
        $missionId = $this->mission(self::PREFIX . 'boundary');
        $lastVmId = 0;
        for ($index = 1; $index <= REPO_VM_RELATION_BATCH_SIZE + 1; $index++) {
            $lastVmId = $this->vm(
                $missionId,
                sprintf('PHPUNIT-BATCH-BOUNDARY-%04d', $index),
                null,
                ''
            );
        }
        $this->interface($lastVmId, '10.23.250.1', null, '');

        $beforeFirst = $this->selectCount();
        $first = getVMs($this->db, $missionId);
        $afterFirst = $this->selectCount();
        self::assertSame(7, $afterFirst - $beforeFirst, '501 VMs require one owner query and two bounded batches for each relation kind');
        self::assertCount(REPO_VM_RELATION_BATCH_SIZE + 1, $first);
        self::assertSame($lastVmId, (int) $first[REPO_VM_RELATION_BATCH_SIZE]['id']);
        self::assertCount(1, $first[REPO_VM_RELATION_BATCH_SIZE]['interfaces'], 'the VM just beyond the first chunk lost its relation');
        self::assertSame([], $first[REPO_VM_RELATION_BATCH_SIZE]['disks']);

        $this->disk($lastVmId, 'Fresh', 1, 'thin');
        $beforeSecond = $this->selectCount();
        $second = getVMs($this->db, $missionId);
        $afterSecond = $this->selectCount();
        self::assertSame(7, $afterSecond - $beforeSecond);
        self::assertSame('Fresh', $second[REPO_VM_RELATION_BATCH_SIZE]['disks'][0]['disk_name'], 'a request-local read returned a cached relation snapshot');
    }

    private function legacyPerVmAggregate(int $missionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $vms = repo_fetch_all($stmt->get_result());
        foreach ($vms as &$vm) {
            $vmId = (int) $vm['id'];
            $vm['packages'] = repo_fetch_related($this->db, 'SELECT dp.* FROM deploy_packages dp INNER JOIN deploy_vm_packages dvp ON dp.id = dvp.package_id WHERE dvp.vm_id = ? ORDER BY dp.package_name', $vmId);
            $vm['interfaces'] = repo_fetch_related($this->db, 'SELECT * FROM deploy_interfaces WHERE vm_id = ? ORDER BY id', $vmId);
            $vm['disks'] = repo_fetch_related($this->db, 'SELECT * FROM deploy_disks WHERE vm_id = ? ORDER BY id', $vmId);
            $vm['progress_watch_kind'] = virtusphere_vm_progress_watch_kind($vm);
            $vm['progress_attention'] = virtusphere_vm_progress_attention($vm);
        }
        unset($vm);

        return $vms;
    }

    private function selectCount(): int
    {
        $stmt = $this->db->prepare("SHOW SESSION STATUS LIKE 'Com_select'");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();

        return (int) ($row[1] ?? 0);
    }

    private function mission(string $name): int
    {
        $status = VIRTUSPHERE_MISSION_STATUS_DEFAULT;
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function vm(int $missionId, string $name, ?string $domain, ?string $notes): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_domain, vm_os, vm_notes) VALUES (?, ?, ?, ?, ?, ?)');
        $hostname = $name;
        $os = '';
        $stmt->bind_param('isssss', $missionId, $name, $hostname, $domain, $os, $notes);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function package(string $name, string $version): int
    {
        $status = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
        $basename = self::PREFIX . 'base';
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $basename, $version, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function linkPackage(int $vmId, int $packageId): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $vmId, $packageId);
        $stmt->execute();
    }

    private function interface(int $vmId, string $ip, ?string $dns1, ?string $dns2): void
    {
        $subnet = '';
        $gateway = '';
        $vlan = 'PHPUNIT-BATCH-VLAN';
        $mac = '';
        $mode = 'static';
        $type = 'vmxnet3';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssssss', $vmId, $ip, $subnet, $gateway, $dns1, $dns2, $vlan, $mac, $mode, $type);
        $stmt->execute();
    }

    private function disk(int $vmId, string $name, int $size, string $type): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isis', $vmId, $name, $size, $type);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        $missionPattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $missionPattern);
        $stmt->execute();

        $packagePattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_packages WHERE package_name LIKE ?');
        $stmt->bind_param('s', $packagePattern);
        $stmt->execute();
    }
}
