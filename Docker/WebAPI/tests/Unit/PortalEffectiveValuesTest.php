<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/portal_effective_values.php';

final class PortalEffectiveValuesTest extends TestCase
{
    private const MISSION = [
        'hypervisor_datastorage' => 'mission-ds',
        'hypervisor_datacenter' => 'mission-dc',
        'autostart_enabled' => 1,
        'autostart_start_delay' => 90,
        'autostart_stop_delay' => 0,
    ];

    public function testLocationUsesTheExistingEffectiveValueOwners(): void
    {
        self::assertSame(
            ['value' => 'mission-ds', 'source' => 'mission', 'parent' => ''],
            portal_vm_effective_location('vm_datastore', self::MISSION, ['vm_datastore' => ''])
        );
        self::assertSame(
            ['value' => 'vm-ds', 'source' => 'vm_override', 'parent' => 'mission-ds'],
            portal_vm_effective_location('vm_datastore', self::MISSION, ['vm_datastore' => 'vm-ds'])
        );
        self::assertSame(
            ['value' => '', 'source' => 'target_host', 'parent' => ''],
            portal_vm_effective_location('vm_datacenter', [], [])
        );
        self::assertSame(
            ['value' => '', 'source' => 'unavailable', 'parent' => ''],
            portal_vm_effective_location('vm_datastore', [], [])
        );
    }

    public function testAutostartSeparatesMissionGateAndInheritedDelays(): void
    {
        $values = portal_vm_effective_values(self::MISSION, [
            'autostart_enabled' => 1,
            'autostart_start_delay' => -1,
            'autostart_stop_delay' => 7,
        ]);

        self::assertSame('vm_setting', $values['autostart']['source']);
        self::assertSame(__t('common.yes'), $values['autostart']['value']);
        self::assertSame('mission', $values['start_delay']['source']);
        self::assertSame(__t('vm_edit.effective_seconds', ['seconds' => 90]), $values['start_delay']['value']);
        self::assertSame('vm_override', $values['stop_delay']['source']);
        self::assertSame(__t('vm_edit.effective_seconds', ['seconds' => 0]), $values['stop_delay']['parent']);
    }

    public function testMissionOffDoesNotEraseThePreservedVmPreference(): void
    {
        $values = portal_vm_effective_values(
            array_replace(self::MISSION, ['autostart_enabled' => 0]),
            ['autostart_enabled' => 1]
        );

        self::assertSame('mission_block', $values['autostart']['source']);
        self::assertSame(__t('common.no'), $values['autostart']['value']);
        self::assertSame(__t('common.yes'), $values['autostart']['parent']);
    }

    public function testUnknownDescriptorSourceFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        portal_effective_descriptor('x', 'invented');
    }

    public function testNonWritingRenderContainsNoResetControls(): void
    {
        ob_start();
        portal_render_vm_effective_values(
            portal_vm_effective_values(self::MISSION, ['vm_datastore' => 'vm-ds']),
            self::MISSION,
            false,
            'mission_details.php?id=7'
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('mission_details.php?id=7', $html);
        self::assertStringNotContainsString('data-effective-reset=', $html);
    }
}
