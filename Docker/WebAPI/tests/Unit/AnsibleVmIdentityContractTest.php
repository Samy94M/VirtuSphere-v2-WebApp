<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * Stage 9: a VM name is only an address after its vSphere instance UUID has
 * proved which object owns that name. The full pipeline may carry an unbound
 * VM past create because that same sequence just proved the name absent and
 * created it; every standalone follow-up mode requires the stored UUID.
 */
final class AnsibleVmIdentityContractTest extends TestCase
{
    public function testServerlistCarriesStoredIdentityAndOnlyFullAllowsNewlyCreatedUnboundVms(): void
    {
        $mission = [
            'id' => 7,
            'mission_name' => 'Identity',
            'hypervisor_datacenter' => 'ha-datacenter',
            'hypervisor_datastorage' => 'datastore1',
            'wds_vlan' => 'PXE',
        ];
        $vm = [
            'vm_name' => 'vm01',
            'vm_moid' => 'vm-42',
            'vm_instance_uuid' => '50112233-4455-6677-8899-aabbccddeeff',
            'interfaces' => [],
            'disks' => [],
            'packages' => [],
        ];

        $full = ansible_serverlist_yml($mission, [$vm], 5, 'ha-datacenter', 'esxi01', 300, VIRTUSPHERE_DEPLOY_MODE_FULL);
        self::assertStringContainsString('vm_moid: "vm-42"', $full);
        self::assertStringContainsString('vm_instance_uuid: "50112233-4455-6677-8899-aabbccddeeff"', $full);
        self::assertStringContainsString('identity_unbound_allowed: true', $full);

        $export = ansible_serverlist_yml($mission, [$vm], 5, 'ha-datacenter', 'esxi01', 300, 'export');
        self::assertStringContainsString('identity_unbound_allowed: false', $export);
    }

    public function testCreateRefusesAnExistingNameWithoutMatchingInstanceUuidBeforeStatePresent(): void
    {
        $playbook = $this->source('createVMs-ESXi_playbook.yml');
        $inventory = strpos($playbook, 'community.vmware.vmware_vm_info:');
        $identity = strpos($playbook, 'vm_instance_uuid', (int) $inventory);
        $mutationModule = strpos($playbook, 'community.vmware.vmware_guest:', ((int) $inventory) + 1);
        $mutation = strpos($playbook, 'state: present', (int) $mutationModule);

        self::assertNotFalse($inventory);
        self::assertNotFalse($identity);
        self::assertNotFalse($mutationModule);
        self::assertNotFalse($mutation);
        self::assertLessThan($mutation, $identity, 'the identity assertion must run before vmware_guest state: present');
        self::assertStringContainsString('VirtuSphere VM identity collision', $playbook);
    }

    public function testPowerAndAutostartPlaybooksValidateTheUuidBeforeTheirFirstMutation(): void
    {
        foreach ([
            'powercycleVMs-ESXi_playbook.yml' => 'community.vmware.vmware_guest_powerstate:',
            'startVMs-ESXi_playbook.yml' => 'community.vmware.vmware_guest_powerstate:',
            'autostartVMs-ESXi_playbook.yml' => 'community.vmware.vmware_host_auto_start:',
        ] as $file => $mutationToken) {
            $playbook = $this->source($file);
            $query = strpos($playbook, 'community.vmware.vmware_guest_info:');
            $identity = strpos($playbook, 'vm_instance_uuid', (int) $query);
            $mutation = strpos($playbook, $mutationToken);

            self::assertNotFalse($query, $file . ' has no live identity query');
            self::assertNotFalse($identity, $file . ' does not compare the stored UUID');
            self::assertNotFalse($mutation, $file . ' has no mutation token');
            self::assertLessThan($mutation, $identity, $file . ' mutates before identity validation');
        }
    }

    public function testExportTurnsAnIdentityMismatchIntoAPerVmFailureBeforeTheCallback(): void
    {
        $playbook = $this->source('exportVMs-Informations-ESXi_playbook.yml');

        self::assertStringContainsString('vm_info_identity_results', $playbook);
        self::assertStringContainsString('vm_instance_uuid', $playbook);
        self::assertStringContainsString('VirtuSphere VM identity mismatch', $playbook);
        self::assertStringContainsString('content: "{{ vm_info_identity_results | to_nice_json }}"', $playbook);
        self::assertStringNotContainsString('content: "{{ vm_info.results | to_nice_json }}"', $playbook);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . $file);
        self::assertIsString($source);

        return $source;
    }
}
