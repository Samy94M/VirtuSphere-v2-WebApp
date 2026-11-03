<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * Paket F: the create-playbook serverlist must carry per-VM hotadd_cpu /
 * hotadd_memory flags derived from cpu_hotplug / ram_hotplug. Pure string
 * generation, no DB.
 */
final class AnsibleHotplugYamlTest extends TestCase
{
    private function vm(string $name, int $cpuHotplug, int $ramHotplug): array
    {
        return [
            'vm_name' => $name,
            'vm_ram' => '4096',
            'vm_cpu' => '2',
            'vm_guest_id' => 'windows2019srv_64Guest',
            'cpu_hotplug' => $cpuHotplug,
            'ram_hotplug' => $ramHotplug,
            'disks' => [],
            'interfaces' => [],
            'packages' => [],
        ];
    }

    public function testFlagsAreEmittedPerVm(): void
    {
        $mission = ['hypervisor_datacenter' => 'DC1', 'hypervisor_datastorage' => 'ds1', 'wds_vlan' => 'VLAN10'];
        $yml = ansible_serverlist_yml($mission, [
            $this->vm('ON', 1, 1),
            $this->vm('OFF', 0, 0),
        ]);

        self::assertStringContainsString('hotadd_cpu: true', $yml);
        self::assertStringContainsString('hotadd_memory: true', $yml);
        self::assertStringContainsString('hotadd_cpu: false', $yml);
        self::assertStringContainsString('hotadd_memory: false', $yml);
    }

    public function testAbsentFlagsDefaultToOn(): void
    {
        // A pre-migration row without the columns must still emit true.
        $mission = ['hypervisor_datacenter' => 'DC1', 'hypervisor_datastorage' => 'ds1', 'wds_vlan' => 'VLAN10'];
        $vm = $this->vm('LEGACY', 1, 1);
        unset($vm['cpu_hotplug'], $vm['ram_hotplug']);

        $yml = ansible_serverlist_yml($mission, [$vm]);
        self::assertStringContainsString('hotadd_cpu: true', $yml);
        self::assertStringContainsString('hotadd_memory: true', $yml);
        self::assertStringNotContainsString('hotadd_cpu: false', $yml);
    }
}
