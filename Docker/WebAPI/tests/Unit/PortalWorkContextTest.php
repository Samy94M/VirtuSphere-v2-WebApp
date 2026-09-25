<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/portal_work_context.php';

final class PortalWorkContextTest extends TestCase
{
    public function testContextAcceptsOnlyClosedScalarValues(): void
    {
        self::assertSame([
            'work_list_sort' => 'attention',
            'work_list_dir' => 'desc',
            'work_list_attention' => '1',
            'work_vm_sort' => 'ram',
        ], portal_work_context([
            'work_list_type' => 'missions',
            'work_list_sort' => 'attention',
            'work_list_dir' => 'desc',
            'work_list_attention' => '1',
            'work_vm_sort' => 'ram',
            'work_vm_dir' => 'asc',
            'return_to' => 'https://example.invalid/steal',
            'csrf_token' => 'secret',
        ]));

        self::assertSame([], portal_work_context([
            'work_list_type' => ['missions'],
            'work_list_sort' => '../../admin',
            'work_list_dir' => 'sideways',
            'work_vm_sort' => 'anything',
            'work_vm_dir' => ['desc'],
        ]));
    }

    public function testTemplateContextCannotRetainMissionOnlyAttentionFilter(): void
    {
        self::assertSame([
            'work_list_type' => 'templates',
        ], portal_work_context_from_mission_list('templates', 'name', 'asc', true));
    }

    public function testMissionAndVmReturnUrlsRestoreSortAndStableFocus(): void
    {
        $context = portal_work_context_with_vm_list(
            portal_work_context_from_mission_list('missions', 'vms', 'desc', true),
            'ram',
            'asc'
        );

        self::assertSame(
            'missions.php?type=missions&sort=vms&dir=desc&attention=1#mission-17',
            portal_work_context_mission_list_url($context, 17)
        );
        self::assertSame(
            'vms.php?mission_id=17&sort=ram&work_list_sort=vms&work_list_dir=desc&work_list_attention=1#vm-42',
            portal_work_context_vm_list_url(17, $context, 42)
        );
    }

    public function testContextIsInsertedBeforeAnExistingFragment(): void
    {
        self::assertSame(
            'vm_edit.php?mission_id=17&vm_id=42&work_vm_sort=ram#interfaces',
            portal_work_context_append_url('vm_edit.php?mission_id=17&vm_id=42#interfaces', ['work_vm_sort' => 'ram'])
        );
    }

    public function testDirectEntryFallsBackToNormalParentAndRejectsInvalidMissionId(): void
    {
        self::assertSame('missions.php?type=missions', portal_work_context_mission_list_url([]));
        self::assertSame('vms.php?mission_id=17', portal_work_context_vm_list_url(17, []));

        $this->expectException(InvalidArgumentException::class);
        portal_work_context_vm_list_url(0, []);
    }
}
