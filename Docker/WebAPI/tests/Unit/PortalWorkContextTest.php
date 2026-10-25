<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/portal_work_context.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_urls.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/mission_nav.php';

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

    public function testOverviewOriginIsClosedCompressedAndOwnedByTheOverview(): void
    {
        self::assertSame([
            'work_ov_filter' => 'mecm_pending',
            'work_ov_sort' => 'status',
            'work_ov_dir' => 'desc',
            'work_ov_page' => '3',
        ], portal_work_context([
            'work_ov_filter' => 'mecm_pending',
            'work_ov_sort' => 'status',
            'work_ov_dir' => 'desc',
            'work_ov_page' => '3',
            'counted' => '1792400000',
            'expected' => '4',
        ]));

        self::assertSame(['work_ov_filter' => 'all'], portal_work_context([
            'work_ov_filter' => 'all',
            'work_ov_sort' => 'mission',
            'work_ov_dir' => 'asc',
            'work_ov_page' => '1',
        ]));
        self::assertSame(['work_ov_filter' => 'all'], portal_work_context([
            'work_ov_filter' => 'all',
            'work_ov_sort' => 'ram',
            'work_ov_dir' => 'sideways',
            'work_ov_page' => '-2',
        ]), 'the mission VM list sort ram is not an overview sort');

        self::assertSame([], portal_work_context([
            'work_ov_filter' => 'everything',
            'work_ov_sort' => 'name',
            'work_ov_page' => '2',
        ]), 'an unknown filter is no overview origin');
        self::assertSame([], portal_work_context([
            'work_ov_sort' => 'name',
            'work_ov_dir' => 'desc',
        ]), 'sort without filter is no overview origin');
        self::assertSame([], portal_work_context(['work_ov_filter' => ['all']]));
    }

    public function testOverviewReturnUrlRestoresTheListAndFocusesTheRow(): void
    {
        $context = vm_overview_editor_context('mecm_pending', 'status', 'desc', 3);

        self::assertSame(
            'vm_overview.php?filter=mecm_pending&sort=status&dir=desc&page=3#vm-42',
            vm_overview_return_url($context, 42)
        );
        self::assertSame('vm_overview.php?filter=all', vm_overview_return_url(['work_ov_filter' => 'all']));
        self::assertNull(vm_overview_return_url([]));
        self::assertNull(vm_overview_return_url(portal_work_context_from_mission_list('missions', 'vms', 'desc', false), 42));
    }

    public function testMissionScopedDestinationsDropTheOverviewOriginButTheEditorRoundTripKeepsIt(): void
    {
        $context = portal_work_context(array_merge(
            ['work_list_type' => 'templates', 'work_vm_sort' => 'ram'],
            vm_overview_editor_context('all', 'name', 'desc', 2)
        ));

        self::assertSame(['work_list_type' => 'templates', 'work_vm_sort' => 'ram'], portal_work_context_mission_scope($context));
        self::assertSame(
            'vms.php?mission_id=17&sort=ram&work_list_type=templates#vm-42',
            portal_work_context_vm_list_url(17, $context, 42)
        );
        self::assertSame(
            'vms.php?mission_id=17&sort=ram&work_list_type=templates&work_ov_filter=all&work_ov_sort=name&work_ov_dir=desc&work_ov_page=2',
            portal_work_context_vm_editor_action_url(17, $context)
        );
        self::assertSame(
            'vm_edit.php?mission_id=17&vm_id=42&work_list_type=templates&work_vm_sort=ram&work_ov_filter=all&work_ov_sort=name&work_ov_dir=desc&work_ov_page=2',
            portal_work_context_append_url('vm_edit.php?mission_id=17&vm_id=42', $context)
        );
        self::assertSame('vms.php?mission_id=17', portal_work_context_vm_editor_action_url(17, []));
    }

    public function testOverviewOriginCarriesTheRowVmOnlyAsACanonicalPositiveId(): void
    {
        self::assertSame(
            ['work_ov_filter' => 'all', 'work_ov_vm' => '42'],
            portal_work_context(['work_ov_filter' => 'all', 'work_ov_vm' => '42'])
        );

        foreach (['0', '-1', '042', '4x', '', ' 42', '1.5', '99999999999999999999', ['42']] as $invalidVm) {
            self::assertSame(['work_ov_filter' => 'all'], portal_work_context([
                'work_ov_filter' => 'all',
                'work_ov_vm' => $invalidVm,
            ]), 'work_ov_vm ' . var_export($invalidVm, true) . ' is no canonical row VM');
        }

        self::assertSame([], portal_work_context(['work_ov_vm' => '42']), 'a row VM without a filter is no overview origin');
        self::assertSame([], portal_work_context(['work_ov_filter' => 'bogus', 'work_ov_vm' => '42']));
        self::assertSame(
            ['work_ov_filter' => 'mecm_pending', 'work_ov_sort' => 'status', 'work_ov_dir' => 'desc', 'work_ov_page' => '3', 'work_ov_vm' => '42'],
            portal_work_context([
                'work_ov_filter' => 'mecm_pending',
                'work_ov_sort' => 'status',
                'work_ov_dir' => 'desc',
                'work_ov_page' => '3',
                'work_ov_vm' => '42',
            ])
        );
    }

    public function testTheMissionLinkOfAnOverviewRowCarriesTheViewAndTheRowVm(): void
    {
        self::assertSame(
            ['work_ov_filter' => 'mecm_pending', 'work_ov_sort' => 'status', 'work_ov_dir' => 'desc', 'work_ov_page' => '3', 'work_ov_vm' => '42'],
            vm_overview_mission_context('mecm_pending', 'status', 'desc', 3, 42)
        );
        self::assertSame(['work_ov_filter' => 'all'], vm_overview_mission_context('all', 'mission', 'asc', 1, 0));

        self::assertSame(
            'mission_details.php?id=17&work_ov_filter=mecm_pending&work_ov_sort=status&work_ov_dir=desc&work_ov_page=3&work_ov_vm=42',
            mission_details_url_from_overview(17, vm_overview_mission_context('mecm_pending', 'status', 'desc', 3, 42))
        );
        self::assertSame(
            'mission_details.php?id=17',
            mission_details_url_from_overview(17, vm_overview_editor_context('all', 'name', 'desc', 2)),
            'an editor context names no row VM and so no overview origin'
        );
        self::assertSame(
            'mission_details.php?id=17&work_ov_filter=all&work_ov_vm=5',
            mission_details_url_from_overview(17, array_merge(
                ['work_list_type' => 'templates', 'work_vm_sort' => 'ram'],
                vm_overview_mission_context('all', 'mission', 'asc', 1, 5)
            )),
            'mission-list keys are never carried into the details link'
        );
        self::assertSame(
            'mission_details.php?id=17',
            mission_details_url(17, vm_overview_mission_context('all', 'mission', 'asc', 1, 5)),
            'the generic mission URL stays mission-scoped'
        );

        $this->expectException(InvalidArgumentException::class);
        mission_details_url_from_overview(0, []);
    }

    public function testDetailsReturnToTheOverviewOnlyWithTheRowVm(): void
    {
        self::assertSame(
            'vm_overview.php?filter=mecm_pending&sort=status&dir=desc&page=3#vm-42',
            vm_overview_details_return_url(vm_overview_mission_context('mecm_pending', 'status', 'desc', 3, 42))
        );
        self::assertSame(
            'vm_overview.php?filter=mecm_pending&sort=status&dir=desc&page=3',
            vm_overview_details_return_url(vm_overview_mission_context('mecm_pending', 'status', 'desc', 3, 42), false)
        );
        self::assertNull(vm_overview_details_return_url(vm_overview_editor_context('all', 'name', 'asc', 1)));
        self::assertNull(vm_overview_details_return_url([]));
        self::assertNull(vm_overview_details_return_url(portal_work_context_from_mission_list('missions', 'vms', 'desc', false)));
    }

    public function testEveryMissionScopedDestinationDropsTheRowVmToo(): void
    {
        $context = portal_work_context(array_merge(
            ['work_list_type' => 'templates', 'work_vm_sort' => 'ram'],
            vm_overview_mission_context('all', 'name', 'desc', 2, 42)
        ));

        self::assertSame(['work_list_type' => 'templates', 'work_vm_sort' => 'ram'], portal_work_context_mission_scope($context));
        self::assertSame(
            'mission_details.php?id=17&work_list_type=templates&work_vm_sort=ram',
            mission_details_url(17, $context)
        );
        self::assertSame(
            'vms.php?mission_id=17&sort=ram&work_list_type=templates',
            portal_work_context_vm_list_url(17, $context)
        );
        self::assertSame('missions.php?type=templates#mission-17', portal_work_context_mission_list_url($context, 17));
        self::assertStringNotContainsString(
            'work_ov_',
            mission_detail_nav(17, true, 'details', portal_work_context_mission_scope($context))
        );
    }
}
