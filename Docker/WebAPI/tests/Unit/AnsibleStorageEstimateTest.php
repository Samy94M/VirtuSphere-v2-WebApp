<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * The deploy page's storage estimate. It reads the same ansible_vm_disks() and
 * ansible_effective_datastore() the serverlist is built from, so what the preview
 * promises and what the create playbook provisions cannot drift apart. Warn-only
 * numbers (ADR-0023): nothing here may ever block a deploy. Pure, no database.
 */
final class AnsibleStorageEstimateTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    private const MISSION = [
        'mission_name' => 'M1',
        'hypervisor_datacenter' => 'ha-datacenter',
        'hypervisor_datastorage' => 'datastore1',
    ];

    /** @param array<int, array{disk_size:int, disk_type?:string}> $disks */
    private function vm(int $id, array $disks, string $datastore = ''): array
    {
        return ['id' => $id, 'vm_name' => 'VM' . $id, 'vm_datastore' => $datastore, 'disks' => $disks];
    }

    public function testDiskSizesAreSummedFromGigabytesToBytes(): void
    {
        $vm = $this->vm(1, [['disk_size' => 50, 'disk_type' => 'thick'], ['disk_size' => 200, 'disk_type' => 'thin']]);

        self::assertSame(250 * self::GB, ansible_vm_disk_bytes($vm));
    }

    public function testThinDisksCountWithTheirProvisionedSize(): void
    {
        // The datastore has to be able to hold the provisioned size; a thin disk
        // only defers the claim, it does not shrink it.
        $thin = $this->vm(1, [['disk_size' => 100, 'disk_type' => 'thin']]);
        $thick = $this->vm(2, [['disk_size' => 100, 'disk_type' => 'thick']]);

        self::assertSame(ansible_vm_disk_bytes($thick), ansible_vm_disk_bytes($thin));
    }

    public function testVmWithoutDiskRowsCountsTheDefaultDiskTheDeployWouldCreate(): void
    {
        // ansible_vm_disks() injects one default disk, so the estimate must too.
        $expected = (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'] * self::GB;

        self::assertSame($expected, ansible_vm_disk_bytes($this->vm(1, [])));
    }

    public function testTenVmsWithFiftyGigabytesEachAddUpOnTheMissionDatastore(): void
    {
        $vms = [];
        for ($i = 1; $i <= 10; $i++) {
            $vms[] = $this->vm($i, [['disk_size' => 50]]);
        }

        $rows = ansible_storage_by_datastore(self::MISSION, $vms);

        self::assertSame(['datastore1'], array_keys($rows));
        self::assertSame(500 * self::GB, $rows['datastore1']['bytes']);
        self::assertSame(10, $rows['datastore1']['vm_count']);
        self::assertCount(10, $rows['datastore1']['per_vm']);
        self::assertSame(50 * self::GB, $rows['datastore1']['per_vm'][7]);
    }

    public function testManyDisksOfDifferentSizesOnOneVm(): void
    {
        $disks = [];
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150] as $size) {
            $disks[] = ['disk_size' => $size];
        }

        self::assertSame(1200 * self::GB, ansible_vm_disk_bytes($this->vm(1, $disks)));
    }

    public function testPerVmOverrideGetsItsOwnRow(): void
    {
        $rows = ansible_storage_by_datastore(self::MISSION, [
            $this->vm(1, [['disk_size' => 50]]),
            $this->vm(2, [['disk_size' => 80]], 'ssd-fast'),
        ]);

        self::assertSame(50 * self::GB, $rows['datastore1']['bytes']);
        self::assertSame(80 * self::GB, $rows['ssd-fast']['bytes']);
        self::assertSame(1, $rows['datastore1']['vm_count']);
        self::assertSame(1, $rows['ssd-fast']['vm_count']);
    }

    public function testCaseAndPaddingVariantsOfTheSameDatastoreShareOneRow(): void
    {
        // Grouped by esxi_inventory_name_key so the row can be matched against the
        // cached inventory; the label keeps the first spelling seen.
        $rows = ansible_storage_by_datastore(self::MISSION, [
            $this->vm(1, [['disk_size' => 10]], 'SSD-Fast'),
            $this->vm(2, [['disk_size' => 10]], ' ssd-fast '),
        ]);

        self::assertSame(['ssd-fast'], array_keys($rows));
        self::assertSame('SSD-Fast', $rows['ssd-fast']['name']);
        self::assertSame(20 * self::GB, $rows['ssd-fast']['bytes']);
    }

    public function testMissionWithoutDatastoreLandsUnderTheEmptyKey(): void
    {
        // A template has no mandatory datastore; the caller labels this row
        // "no datastore set" instead of comparing it against an inventory.
        $rows = ansible_storage_by_datastore(['mission_name' => 'T'], [$this->vm(1, [['disk_size' => 50]])]);

        self::assertSame([''], array_keys($rows));
        self::assertSame(50 * self::GB, $rows['']['bytes']);
    }

    public function testNoVmsYieldNoRows(): void
    {
        self::assertSame([], ansible_storage_by_datastore(self::MISSION, []));
    }
}
