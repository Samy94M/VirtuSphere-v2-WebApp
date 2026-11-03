<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_edit_form.php';

/**
 * What the VM editor does with the interface rows it is handed, and in
 * particular with none.
 *
 * Removing the last NIC row used to substitute an empty default NIC. repo_save_vm
 * rewrites deploy_interfaces from that list, so the substitute replaced the real
 * row: the MAC Ansible had exported and MECM was waiting for was gone, together
 * with the VLAN, the IP and the mode, and the save reported success. A VM that
 * had reached 3/5 fell back to having no MAC at all, with nothing anywhere saying
 * why. There is no honest default for "the operator removed every interface", so
 * this is a validation failure now and the stored rows stay untouched.
 */
final class VmFormInterfaceRowsTest extends TestCase
{
    private const MISSION = ['wds_vlan' => 'VLAN10'];

    public function testRemovingTheLastInterfaceRowIsRejectedInsteadOfDefaulted(): void
    {
        try {
            vm_parse_interfaces([], self::MISSION);
            self::fail('an empty interface list must not be replaced by a default NIC');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('interfaces', $exception->errors(), 'the failure must be addressable as a field error');
            self::assertNotSame('', $exception->getMessage());
        }
    }

    /**
     * The same, one step earlier: rows that exist but carry nothing are the shape
     * a submitted-but-emptied form has, and they used to collapse to the same
     * silent default.
     */
    public function testRowsWithoutAnyDataAreTreatedAsNoRows(): void
    {
        $this->expectException(ValidationException::class);
        vm_parse_interfaces([['ip' => '', 'subnet' => '', 'vlan' => '', 'mode' => '', 'type' => '']], self::MISSION);
    }

    public function testANonArrayRowIsIgnoredAndDoesNotCountAsAnInterface(): void
    {
        $this->expectException(ValidationException::class);
        vm_parse_interfaces(['not-a-row'], self::MISSION);
    }

    public function testOneRealRowSurvivesWithItsValues(): void
    {
        $rows = vm_parse_interfaces([[
            'id' => '7',
            'ip' => '10.9.9.10',
            'subnet' => '255.255.255.0',
            'gateway' => '10.9.9.1',
            'dns1' => '10.9.9.2',
            'dns2' => '',
            'vlan' => 'VLAN20',
            'mode' => 'static',
            'type' => 'e1000e',
        ]], self::MISSION);

        self::assertCount(1, $rows);
        self::assertSame(7, $rows[0]['id']);
        self::assertSame('10.9.9.10', $rows[0]['ip']);
        self::assertSame('VLAN20', $rows[0]['vlan']);
        self::assertSame('static', $rows[0]['mode']);
        self::assertSame('e1000e', $rows[0]['type']);
    }

    /**
     * The render default stays: offering a prefilled row to a form is not the
     * same as writing one over stored data, and a new VM has to start somewhere.
     */
    public function testTheRenderDefaultStillExistsAndCarriesTheMissionVlan(): void
    {
        $rows = vm_default_interfaces(self::MISSION);

        self::assertCount(1, $rows);
        self::assertSame('VLAN10', $rows[0]['vlan']);
        self::assertSame(VIRTUSPHERE_VM_DEFAULTS['interface_mode'], $rows[0]['mode']);
    }
}
