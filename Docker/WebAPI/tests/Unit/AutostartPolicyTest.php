<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * ESXi autostart policy (ADR-0025). The load-bearing rule of this feature is
 * that -1 ("inherit the mission value") and 0 ("start without waiting") are two
 * different things. Collapsing one into the other silently changes when a VM
 * boots after a host restart, and nothing else in the system would notice.
 *
 * Pure string / value logic, no database.
 */
final class AutostartPolicyTest extends TestCase
{
    private function mission(array $overrides = []): array
    {
        return $overrides + [
            'hypervisor_datacenter' => 'ha-datacenter',
            'hypervisor_datastorage' => 'ds1',
            'wds_vlan' => 'VLAN10',
        ];
    }

    private function vm(string $name, array $overrides = []): array
    {
        return $overrides + [
            'vm_name' => $name,
            'vm_ram' => '4096',
            'vm_cpu' => '2',
            'disks' => [],
            'interfaces' => [],
            'packages' => [],
        ];
    }

    // --- repo_vm_delay_value: the inherit/no-wait distinction --------------

    public function testAbsentDelayInherits(): void
    {
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value([], 'autostart_start_delay'));
    }

    public function testEmptyDelayFieldInheritsAndNeverBecomesZero(): void
    {
        // The editor's "inherit" state is a blank input. Storing 0 there would
        // mean "start immediately", which is a different instruction.
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value(['autostart_start_delay' => ''], 'autostart_start_delay'));
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value(['autostart_start_delay' => '  '], 'autostart_start_delay'));
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value(['autostart_start_delay' => null], 'autostart_start_delay'));
    }

    public function testExplicitZeroIsKept(): void
    {
        self::assertSame(0, repo_vm_delay_value(['autostart_start_delay' => '0'], 'autostart_start_delay'));
        self::assertSame(0, repo_vm_delay_value(['autostart_start_delay' => 0], 'autostart_start_delay'));
    }

    public function testOutOfRangeDelaysAreClampedNotRejected(): void
    {
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_MAX, repo_vm_delay_value(['autostart_stop_delay' => '999999'], 'autostart_stop_delay'));
        // Any negative value collapses onto the single inherit sentinel.
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value(['autostart_stop_delay' => '-42'], 'autostart_stop_delay'));
    }

    public function testNonScalarDelayDoesNotFatal(): void
    {
        // An untrusted import can carry an array here; casting it would raise a
        // warning the global handler turns into a 500.
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, repo_vm_delay_value(['autostart_start_delay' => ['x']], 'autostart_start_delay'));
    }

    public function testAutostartDefaultsOffNotOn(): void
    {
        // A VM must be opted in. An import or legacy-API create must never
        // silently enrol a VM into the host boot sequence.
        self::assertSame(0, repo_vm_autostart_flag([], 'autostart_enabled'));
        self::assertSame(1, repo_vm_autostart_flag(['autostart_enabled' => '1'], 'autostart_enabled'));
        self::assertSame(0, repo_vm_autostart_flag(['autostart_enabled' => '0'], 'autostart_enabled'));
    }

    // --- ansible_autostart_delay: a mission may not inherit ----------------

    public function testMissionDelayNeverInherits(): void
    {
        // The mission's delays ARE the host default; there is nothing above them.
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT, ansible_autostart_delay(null, false));
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT, ansible_autostart_delay('', false));
        self::assertSame(0, ansible_autostart_delay('-5', false));
        self::assertSame(0, ansible_autostart_delay('0', false));
    }

    public function testVmDelayMayInherit(): void
    {
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, ansible_autostart_delay(null, true));
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, ansible_autostart_delay('-1', true));
        self::assertSame(0, ansible_autostart_delay('0', true));
        self::assertSame(45, ansible_autostart_delay('45', true));
    }

    // --- serverlist.yml ----------------------------------------------------

    public function testServerlistCarriesMissionDefaultsAndPerVmOverride(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission([
                'autostart_enabled' => 1,
                'autostart_start_delay' => 90,
                'autostart_stop_delay' => 30,
                'autostart_stop_action' => 'powerOff',
                'autostart_wait_for_heartbeat' => 1,
            ]),
            [
                $this->vm('INHERITS', ['autostart_enabled' => 1, 'autostart_start_delay' => -1, 'autostart_stop_delay' => -1]),
                $this->vm('OVERRIDES', ['autostart_enabled' => 1, 'autostart_start_delay' => 5, 'autostart_stop_delay' => 0]),
            ]
        );

        self::assertStringContainsString("  autostart:\n    enabled: true\n    start_delay: 90\n    stop_delay: 30\n    stop_action: powerOff\n    wait_for_heartbeat: true\n", $yml);
        // -1 travels to the module unchanged: the inheritance resolves on ESXi.
        self::assertStringContainsString("      enabled: true\n      start_delay: -1\n      stop_delay: -1\n", $yml);
        self::assertStringContainsString("      enabled: true\n      start_delay: 5\n      stop_delay: 0\n", $yml);
    }

    public function testAVmCannotParticipateWhileItsMissionHasAutostartOff(): void
    {
        // The mission switch decides whether the host's autostart manager is
        // turned on at all. A VM written with powerOn while the manager stays off
        // would sit in the host's list waiting for some other mission to enable
        // it, and would then boot without anybody having asked for it.
        $yml = ansible_serverlist_yml(
            $this->mission(['autostart_enabled' => 0]),
            [$this->vm('EAGER', ['autostart_enabled' => 1])]
        );

        self::assertStringContainsString("  autostart:\n    enabled: false\n", $yml);
        self::assertStringNotContainsString('      enabled: true', $yml);
    }

    public function testTheVmCheckboxStillCountsWhenTheMissionIsOn(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(['autostart_enabled' => 1]),
            [
                $this->vm('IN', ['autostart_enabled' => 1]),
                $this->vm('OUT', ['autostart_enabled' => 0]),
            ]
        );

        self::assertStringContainsString("      enabled: true\n", $yml);
        self::assertStringContainsString("      enabled: false\n", $yml);
    }

    public function testEffectiveEnabledIsTheAndOfBothSwitches(): void
    {
        $cases = [
            [0, 0, false],
            [0, 1, false],
            [1, 0, false],
            [1, 1, true],
        ];
        foreach ($cases as [$missionOn, $vmOn, $expected]) {
            $actual = ansible_vm_autostart(['autostart_enabled' => $vmOn], ['autostart_enabled' => $missionOn])['enabled'];
            self::assertSame($expected, $actual, "mission=$missionOn vm=$vmOn");
        }
    }

    public function testTheEsxiHostObjectNameIsEmittedForTheModule(): void
    {
        // vmware_host_auto_start's esxi_hostname names the host object, not the
        // address we connect to; a credential holding an IP would not resolve.
        $yml = ansible_serverlist_yml($this->mission(), [$this->vm('A')], 5, 'ha-datacenter', 'esxi01.lan');
        self::assertStringContainsString('    esxi_host: "esxi01.lan"', $yml);

        // Never pulled, or ambiguous (vCenter): the playbook falls back.
        $yml = ansible_serverlist_yml($this->mission(), [$this->vm('A')]);
        self::assertStringContainsString('    esxi_host: ""', $yml);
    }

    public function testServerlistDefaultsForARowFromBeforeTheMigration(): void
    {
        // Neither the mission nor the VM has the columns. The YAML must still be
        // complete, or the playbook hits an undefined variable.
        $yml = ansible_serverlist_yml($this->mission(), [$this->vm('LEGACY')]);

        self::assertStringContainsString("  autostart:\n    enabled: false\n    start_delay: 120\n    stop_delay: 120\n    stop_action: guestShutdown\n    wait_for_heartbeat: false\n", $yml);
        self::assertStringContainsString("      enabled: false\n      start_delay: -1\n      stop_delay: -1\n", $yml);
    }

    public function testUnknownStopActionFallsBackToTheDefault(): void
    {
        // ESXi compares these literals case-sensitively; a lower-cased value is
        // not a valid stop action and must not be handed to the module.
        $yml = ansible_serverlist_yml($this->mission(['autostart_stop_action' => 'guestshutdown']), [$this->vm('A')]);

        self::assertStringContainsString('stop_action: guestShutdown', $yml);
        self::assertStringNotContainsString('stop_action: guestshutdown', $yml);
    }

    // --- mode mapping ------------------------------------------------------

    public function testAutostartModeRunsOnlyItsPlaybook(): void
    {
        self::assertSame(
            [VIRTUSPHERE_PLAYBOOKS['autostart']],
            ansible_playbooks_for_mode(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, false)
        );
    }

    public function testFullPipelineAppendsAutostartOnlyForAnEnabledMission(): void
    {
        $without = ansible_playbooks_for_mode(VIRTUSPHERE_DEPLOY_MODE_FULL, false);
        $with = ansible_playbooks_for_mode(VIRTUSPHERE_DEPLOY_MODE_FULL, true);

        // A full deploy of a mission that never asked for autostart must not
        // touch the host's autostart manager: other missions may live there.
        self::assertNotContains(VIRTUSPHERE_PLAYBOOKS['autostart'], $without);
        self::assertSame(4, count($without));
        self::assertSame(VIRTUSPHERE_PLAYBOOKS['autostart'], $with[4]);
        self::assertSame(5, count($with));
    }

    public function testOtherModesNeverGainTheAutostartStep(): void
    {
        foreach (['create', 'powercycle', 'export', 'start'] as $mode) {
            self::assertNotContains(VIRTUSPHERE_PLAYBOOKS['autostart'], ansible_playbooks_for_mode($mode, true), $mode);
        }
    }

    public function testAutostartIsNeitherStaggerableNorAnInventoryTrigger(): void
    {
        // It writes a configuration; it moves no power and creates no resources.
        self::assertNotContains(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, VIRTUSPHERE_DEPLOY_STAGGER_MODES);
        self::assertNotContains(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES);
    }

    public function testTheModeIsOfferedInTheUiAndKnownToTheJobValidator(): void
    {
        self::assertArrayHasKey(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, virtusphere_deploy_mode_labels());
        self::assertContains(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, virtusphere_deploy_modes());
    }

    public function testThePlaybookIsUploadedWithEveryJob(): void
    {
        self::assertContains(VIRTUSPHERE_PLAYBOOKS['autostart'], ansible_required_files());
    }

    /**
     * The VM editor must lock the control while the mission has autostart off,
     * and it must do so without losing the VM's stored setting: a disabled input
     * posts nothing, so the value has to travel in a hidden field. Getting only
     * half of this right silently clears the setting on the next save.
     */
    public function testTheVmEditorLocksTheControlWithoutClearingIt(): void
    {
        $source = file_get_contents(__DIR__ . '/../../portal/vm_edit.php');
        self::assertIsString($source);

        self::assertStringContainsString('$autostartLocked = !$canWrite || !$missionAutostartOn;', $source);
        self::assertStringContainsString('<input type="checkbox" <?php echo $autostartOn ? \'checked\' : \'\'; ?> disabled>', $source);
        self::assertStringContainsString('<input type="hidden" name="autostart_enabled" value="<?php echo $autostartOn ? \'1\' : \'0\'; ?>">', $source);
        // Delays stay readonly rather than disabled, so a blank field keeps
        // posting "" (inherit) instead of vanishing from the payload.
        self::assertStringContainsString("name=\"autostart_start_delay\"", $source);
        self::assertStringNotContainsString('name="autostart_start_delay" ... disabled', $source);
    }

    /**
     * The playbook is a dumb executor: it must not re-derive the mission/VM AND,
     * or the two implementations can drift apart.
     *
     * Resolved through ansible_source_dir(), the same function the worker uses,
     * so the test reads the file the deploy would actually upload. The php
     * container mounts Ansible/ read-only for exactly this reason: a test that
     * skips has silently stopped guarding anything.
     */
    public function testThePlaybookTrustsTheEffectiveFlagFromPhp(): void
    {
        $playbook = (string) file_get_contents($this->playbookPath('autostart'));

        self::assertStringContainsString("'powerOn' if (item.autostart.enabled | bool) else 'none'", $playbook);
        self::assertStringNotContainsString('mission_configuration.autostart.enabled | bool) and', $playbook);
        // Never disables the host manager: other missions may live on this host.
        self::assertStringNotContainsString('enabled: false', $playbook);
        // start_order is pinned; community.vmware#1903 rejects anything above 1.
        self::assertStringContainsString('start_order: -1', $playbook);
    }

    public function testThePlaybookAddressesTheHostByItsObjectName(): void
    {
        // vmware_host_auto_start's esxi_hostname names the host object; the
        // connection address (possibly an IP) would not resolve to it.
        $playbook = (string) file_get_contents($this->playbookPath('autostart'));

        self::assertStringContainsString('esxi_hostname: "{{ vs_esxi_host }}"', $playbook);
        self::assertStringContainsString("mission_configuration.autostart.esxi_host | default('', true) or esxi_hostname", $playbook);
    }

    /** Every playbook the mode map names must exist where the worker looks. */
    public function testEveryMappedPlaybookExists(): void
    {
        foreach (VIRTUSPHERE_PLAYBOOKS as $mode => $file) {
            self::assertFileExists($this->playbookPath($mode), $mode);
        }
        foreach (VIRTUSPHERE_SYSTEM_PLAYBOOKS as $file) {
            self::assertFileExists(ansible_source_dir() . DIRECTORY_SEPARATOR . $file);
        }
    }

    private function playbookPath(string $mode): string
    {
        return ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_PLAYBOOKS[$mode];
    }
}
