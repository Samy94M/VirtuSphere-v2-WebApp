<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * ADR-0023 refinement: the serverlist resolves datacenter/datastore per VM. An
 * own value wins, an empty one inherits the mission value. The
 * mission_configuration block stays mission-wide either way, because the
 * playbooks read it as the deploy's global context, not as a VM property.
 * Pure string generation, no DB.
 */
final class AnsibleLocationOverrideTest extends TestCase
{
    private const MISSION = [
        'mission_name' => 'M1',
        'hypervisor_datacenter' => 'ha-datacenter',
        'hypervisor_datastorage' => 'datastore1',
        'wds_vlan' => 'VLAN10',
    ];

    private function vm(string $name, string $datacenter = '', string $datastore = ''): array
    {
        return [
            'vm_name' => $name,
            'vm_ram' => '4096',
            'vm_cpu' => '2',
            'vm_guest_id' => 'windows2019srv_64Guest',
            'vm_datacenter' => $datacenter,
            'vm_datastore' => $datastore,
            'disks' => [],
            'interfaces' => [],
            'packages' => [],
        ];
    }

    public function testEmptyOverrideInheritsTheMissionValue(): void
    {
        $yml = ansible_serverlist_yml(self::MISSION, [$this->vm('INHERIT')]);

        self::assertStringContainsString("    datastore_name: \"datastore1\"", $yml);
        self::assertStringContainsString("    datacenter_name: \"ha-datacenter\"", $yml);
    }

    public function testAbsentColumnsInheritTheMissionValue(): void
    {
        // Rows created through the legacy token API never carry the columns.
        $vm = $this->vm('LEGACY');
        unset($vm['vm_datacenter'], $vm['vm_datastore']);

        $yml = ansible_serverlist_yml(self::MISSION, [$vm]);
        self::assertStringContainsString("    datastore_name: \"datastore1\"", $yml);
        self::assertStringContainsString("    datacenter_name: \"ha-datacenter\"", $yml);
    }

    public function testPerVmOverrideWinsAndDoesNotLeakToOtherVms(): void
    {
        $yml = ansible_serverlist_yml(self::MISSION, [
            $this->vm('OVERRIDE', 'DC-B', 'ssd-fast'),
            $this->vm('DEFAULT'),
            $this->vm('ALSO_DEFAULT'),
        ]);

        // Exactly one VM carries the override, the other two inherit. Counting
        // rules out an override that silently bleeds into every VM.
        self::assertSame(1, substr_count($yml, "    datastore_name: \"ssd-fast\""));
        self::assertSame(1, substr_count($yml, "    datacenter_name: \"DC-B\""));
        self::assertSame(2, substr_count($yml, "    datastore_name: \"datastore1\""));
        self::assertSame(2, substr_count($yml, "    datacenter_name: \"ha-datacenter\""));
    }

    public function testOneOverriddenAxisDoesNotDragTheOtherAlong(): void
    {
        $yml = ansible_serverlist_yml(self::MISSION, [$this->vm('SPLIT', '', 'ssd-fast')]);

        self::assertStringContainsString("    datastore_name: \"ssd-fast\"", $yml);
        self::assertStringContainsString("    datacenter_name: \"ha-datacenter\"", $yml);
    }

    public function testWhitespaceOnlyOverrideCountsAsEmpty(): void
    {
        $yml = ansible_serverlist_yml(self::MISSION, [$this->vm('BLANK', '   ', "\t")]);

        self::assertStringContainsString("    datastore_name: \"datastore1\"", $yml);
        self::assertStringContainsString("    datacenter_name: \"ha-datacenter\"", $yml);
    }

    public function testMissionConfigurationStaysMissionWide(): void
    {
        $yml = ansible_serverlist_yml(self::MISSION, [$this->vm('OVERRIDE', 'DC-B', 'ssd-fast')]);

        self::assertStringContainsString("  mission_datacenter: \"ha-datacenter\"", $yml);
        self::assertStringContainsString("  mission_datastore: \"datastore1\"", $yml);
    }

    public function testResolversAreUsableStandalone(): void
    {
        self::assertSame('DC-B', ansible_effective_datacenter(self::MISSION, $this->vm('X', 'DC-B')));
        self::assertSame('ha-datacenter', ansible_effective_datacenter(self::MISSION, $this->vm('X')));
        self::assertSame('ssd-fast', ansible_effective_datastore(self::MISSION, $this->vm('X', '', 'ssd-fast')));
        self::assertSame('datastore1', ansible_effective_datastore(self::MISSION, $this->vm('X')));
    }

    // --- third level: the sole datacenter of the deploy target host ---

    /** @return array<string,string> a mission that leaves the datacenter to the host */
    private function missionWithoutDatacenter(): array
    {
        return ['mission_name' => 'M1', 'hypervisor_datacenter' => '', 'hypervisor_datastorage' => 'datastore1', 'wds_vlan' => 'VLAN10'];
    }

    public function testTheHostDatacenterFillsAnEmptyMission(): void
    {
        $yml = ansible_serverlist_yml($this->missionWithoutDatacenter(), [$this->vm('HOSTFALLBACK')], 30, 'ha-datacenter');

        self::assertStringContainsString("    datacenter_name: \"ha-datacenter\"", $yml);
        self::assertStringContainsString("  mission_datacenter: \"ha-datacenter\"", $yml);
    }

    public function testTheChainIsOverrideThenMissionThenHost(): void
    {
        $mission = $this->missionWithoutDatacenter();
        self::assertSame('DC-B', ansible_effective_datacenter($mission, $this->vm('X', 'DC-B'), 'ha-datacenter'));
        self::assertSame('ha-datacenter', ansible_effective_datacenter($mission, $this->vm('X'), 'ha-datacenter'));
        // A mission value always beats the host; the host is the last resort.
        self::assertSame('DC-Nord', ansible_effective_datacenter(['hypervisor_datacenter' => 'DC-Nord'], $this->vm('X'), 'ha-datacenter'));
    }

    public function testAnAbsentHostDatacenterKeepsTheOldBehaviour(): void
    {
        // Default parameter: every existing caller and artifact stays identical.
        self::assertSame('ha-datacenter', ansible_effective_datacenter(self::MISSION, $this->vm('X')));
        self::assertSame('', ansible_effective_datacenter($this->missionWithoutDatacenter(), $this->vm('X')));
    }

    public function testReadinessGateAcceptsAResolvableHostAndRejectsNothing(): void
    {
        // Mission empty + host known: fine.
        ansible_assert_mission_ready($this->missionWithoutDatacenter(), 'ha-datacenter');
        // Mission set + host unknown: fine.
        ansible_assert_mission_ready(self::MISSION, '');
        self::assertTrue(true);

        // Neither: the job must not run against a guessed location.
        $this->expectException(RuntimeException::class);
        ansible_assert_mission_ready($this->missionWithoutDatacenter(), '');
    }

    public function testDatastoreStaysMandatoryEvenWithAHostDatacenter(): void
    {
        $this->expectException(RuntimeException::class);
        ansible_assert_mission_ready(['hypervisor_datacenter' => '', 'hypervisor_datastorage' => ''], 'ha-datacenter');
    }
}
