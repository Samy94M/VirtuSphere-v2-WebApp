<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * Stage 9: a VM name is only an address after its vSphere instance UUID has
 * proved which object owns that name. Since IDR-R2 no mode carries an unbound
 * VM past create any more: a full pipeline rewrites serverlist.yml after its
 * create section, so every follow-up playbook requires the bound UUID.
 */
final class AnsibleVmIdentityContractTest extends TestCase
{
    public function testServerlistCarriesStoredIdentityAndNoUnboundBypass(): void
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

        $serverlist = ansible_serverlist_yml($mission, [$vm], 5, 'ha-datacenter', 'esxi01', 300);
        self::assertStringContainsString('vm_moid: "vm-42"', $serverlist);
        self::assertStringContainsString('vm_instance_uuid: "50112233-4455-6677-8899-aabbccddeeff"', $serverlist);
        self::assertStringNotContainsString('identity_unbound_allowed', $serverlist);
    }

    /**
     * IDR-R2 guard, derived rather than listed: no playbook may read the removed
     * full-mode bypass, because a new playbook would otherwise inherit it by
     * copying a neighbour.
     */
    public function testNoPlaybookReadsTheRemovedUnboundBypass(): void
    {
        $files = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*.yml') ?: [];
        self::assertNotEmpty($files, 'no playbook found to check');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            self::assertIsString($content);
            self::assertStringNotContainsString('identity_unbound_allowed', $content, basename($file));
        }
    }

    /**
     * IDR-P02: the shared check looks the stored UUID up in the same inventory,
     * refuses a bound VM that lives on under another name, and states the
     * stored UUID it proved absent so the commit may replace exactly that one.
     */
    public function testIdentityCheckFindsRenamedAndProvesAbsentBoundVms(): void
    {
        $identityTasks = $this->source('create_identity_check_tasks.yml');
        self::assertStringContainsString("'identity_bound_vm_renamed'", $identityTasks);
        self::assertStringContainsString('vs_identity_uuid_matches', $identityTasks);
        self::assertStringContainsString('vs_replaced_instance_uuid', $identityTasks);
        self::assertSame(1, substr_count($identityTasks, 'community.vmware.vmware_vm_info:'), 'the UUID lookup must reuse the one inventory read');

        $prepare = $this->source('createVMPrepare-ESXi_playbook.yml');
        self::assertStringContainsString("'replaced_instance_uuid': (vs_replaced_instance_uuid", $prepare);
    }

    /**
     * Umgeschrieben in Etappe 14B-E. Der Vertrag ist unveraendert - eine
     * vorhandene namensgleiche VM ohne die gespeicherte Instance-UUID wird vor
     * `state: present` abgelehnt - aber er liegt jetzt an zwei Stellen: die
     * gemeinsame Identitaetspruefung stellt den Befund fest, und das
     * Launch-Playbook beendet den Lauf damit, bevor es das Modul erreicht. Der
     * Abbruch ist bewusst kein Playbookfehler mehr, sondern ein
     * `rejected`-Marker: eine fremde Namensgleichheit ist der haeufigste echte
     * Betriebsfall, und als abgebrochener Aufruf ohne Marker kaeme sie beim
     * Worker als `protocol_error` an.
     */
    public function testCreateRefusesAnExistingNameWithoutMatchingInstanceUuidBeforeStatePresent(): void
    {
        $identityTasks = $this->source('create_identity_check_tasks.yml');
        self::assertStringContainsString('vm_instance_uuid', $identityTasks);
        self::assertStringContainsString('community.vmware.vmware_vm_info:', $identityTasks);
        // Der geschlossene Code, nicht freier Text: der Worker speichert ihn,
        // und das Portal entscheidet danach.
        self::assertStringContainsString("'identity_conflict'", $identityTasks);

        $launch = $this->source('createVMLaunch-ESXi_playbook.yml');
        $include = strpos($launch, 'include_tasks: ./create_identity_check_tasks.yml');
        $reject = strpos($launch, 'vs_identity_conflict | bool', (int) $include);
        $endPlay = strpos($launch, 'meta: end_play', (int) $reject);
        $mutationModule = strpos($launch, 'community.vmware.vmware_guest:');
        $mutation = strpos($launch, 'state: present', (int) $mutationModule);

        self::assertNotFalse($include, 'the launch playbook re-runs the shared identity check');
        self::assertNotFalse($reject);
        self::assertNotFalse($endPlay);
        self::assertNotFalse($mutation);
        self::assertLessThan($mutation, $reject, 'the identity verdict must be read before vmware_guest state: present');
        self::assertLessThan($mutation, $endPlay, 'the refusal must end the play before the mutation');
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

    public function testEveryPowerAndAutostartMutationSelectsTheValidatedLiveInstanceUuid(): void
    {
        foreach ([
            'startVMs-ESXi_playbook.yml' => 'community.vmware.vmware_guest_powerstate:',
            'powercycleVMs-ESXi_playbook.yml' => 'community.vmware.vmware_guest_powerstate:',
            'autostartVMs-ESXi_playbook.yml' => 'community.vmware.vmware_host_auto_start:',
        ] as $file => $module) {
            $playbook = $this->source($file);
            preg_match_all('/' . preg_quote($module, '/') . '\R((?:\s{8}.+\R)+)/', $playbook, $matches);
            self::assertNotEmpty($matches[1], $file . ' has no inspectable mutation arguments');
            foreach ($matches[1] as $arguments) {
                // The host-wide autostart defaults intentionally select no VM.
                if ($file === 'autostartVMs-ESXi_playbook.yml' && str_contains($arguments, 'system_defaults:')) {
                    continue;
                }
                self::assertStringContainsString('uuid:', $arguments, $file);
                self::assertStringContainsString('instance_uuid', $arguments, $file);
                self::assertStringContainsString('use_instance_uuid: true', $arguments, $file);
                self::assertDoesNotMatchRegularExpression('/^\s*name:/m', $arguments, $file . ' must not resolve the VM by name again');
            }
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

        if ($file === 'powercycleVMs-ESXi_playbook.yml') {
            $source .= "\n" . file_get_contents(ansible_source_dir() . '/powercycle_vm_tasks.yml');
        }

        return $source;
    }
}
