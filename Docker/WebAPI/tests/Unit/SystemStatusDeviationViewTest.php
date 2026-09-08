<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/system_status_deviation_view.php';

final class SystemStatusDeviationViewTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function entries(): array
    {
        return [
            ['mission_id' => 2, 'mission_name' => 'zeta', 'is_template' => false, 'issues' => [['field' => 'vlan', 'value' => 'Data']]],
            ['mission_id' => 1, 'mission_name' => '_Base', 'is_template' => true, 'issues' => [['field' => 'datastore', 'value' => 'Archive']]],
            ['mission_id' => 2, 'mission_name' => 'zeta', 'is_template' => false, 'vm_id' => 4, 'vm_name' => 'VM-4', 'issues' => [
                ['field' => 'vm_datacenter', 'value' => 'Berlin'],
                ['field' => 'vlan', 'value' => 'PXE'],
            ]],
        ];
    }

    public function testKindsFilterEntriesAndIssuesWithoutChangingTheFullVlanFact(): void
    {
        $template = system_status_deviation_view($this->entries(), 'template', '', 1);
        self::assertSame(1, $template['total']);
        self::assertSame('_Base', $template['entries'][0]['mission_name']);

        $vlan = system_status_deviation_view($this->entries(), 'vlan', '', 1);
        self::assertSame(2, $vlan['total']);
        self::assertSame([['field' => 'vlan', 'value' => 'Data']], $vlan['entries'][0]['issues']);
        self::assertTrue($vlan['has_vlan']);

        $datacenter = system_status_deviation_view($this->entries(), 'datacenter', '', 1);
        self::assertSame(1, $datacenter['total']);
        self::assertSame([['field' => 'vm_datacenter', 'value' => 'Berlin']], $datacenter['entries'][0]['issues']);
    }

    public function testSearchPageClampingAndBinaryStableOrder(): void
    {
        $view = system_status_deviation_view($this->entries(), 'all', 'berlin', 99);
        self::assertSame(1, $view['page']);
        self::assertSame(1, $view['total']);
        self::assertSame(4, $view['entries'][0]['vm_id']);

        $all = system_status_deviation_view($this->entries(), 'not-valid', '', 1);
        self::assertSame('all', $all['filter']);
        self::assertSame(['_Base', 'zeta', 'zeta'], array_column($all['entries'], 'mission_name'));
    }
}
