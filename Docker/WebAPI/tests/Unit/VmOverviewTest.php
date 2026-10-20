<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_overview_page.php';

final class VmOverviewTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testFilterSortDirectionAndPageFallBackOnAnyUnknownValue(): void
    {
        self::assertSame('all', vm_overview_filter(''));
        self::assertSame('all', vm_overview_filter('mecm_pending; DROP TABLE deploy_vms'));
        self::assertSame('mecm_pending', vm_overview_filter('mecm_pending'));
        self::assertSame('mission', vm_overview_sort('name; --'));
        self::assertSame('hostname', vm_overview_sort('hostname'));
        self::assertSame('desc', vm_overview_dir('desc'));
        self::assertSame('asc', vm_overview_dir('sideways'));
        self::assertSame('asc', vm_overview_dir(''));
    }

    public function testPageIsAPositiveBoundedInteger(): void
    {
        self::assertSame(1, vm_overview_page_number(''));
        self::assertSame(1, vm_overview_page_number('0'));
        self::assertSame(1, vm_overview_page_number('-3'));
        self::assertSame(1, vm_overview_page_number('2abc'));
        self::assertSame(3, vm_overview_page_number('3'));
        self::assertSame(100000, vm_overview_page_number('999999999999999999999999'));
    }

    public function testSnapshotNeedsBothValuesAndAPlausibleCaptureTime(): void
    {
        self::assertSame(['counted' => 1792400000, 'expected' => 17], vm_overview_snapshot('1792400000', '17'));
        self::assertNull(vm_overview_snapshot('1792400000', ''), 'a time without its number says nothing about a change');
        self::assertNull(vm_overview_snapshot('', '17'), 'a number without its time has no capture moment');
        self::assertNull(vm_overview_snapshot('12', '17'), 'a value before 2000 is not a capture time');
        self::assertNull(vm_overview_snapshot('1792400000', '-1'));
        self::assertNull(vm_overview_snapshot('1792400000', '99999999999999999999'));
    }

    public function testAChangedCountIsReportedOnlyAgainstAnExistingSnapshot(): void
    {
        $snapshot = ['counted' => 1792400000, 'expected' => 17];

        self::assertTrue(vm_overview_count_changed($snapshot, 16), 'the 17 to 16 case of the plan');
        self::assertTrue(vm_overview_count_changed($snapshot, 18), 'the 17 to 18 case of the plan');
        self::assertFalse(vm_overview_count_changed($snapshot, 17));
        self::assertFalse(vm_overview_count_changed(null, 0), 'without a tile there is nothing to compare');
    }

    public function testDefaultsAreOmittedAndAnUnknownFilterIsTheAllView(): void
    {
        self::assertSame('vm_overview.php?filter=all', vm_overview_url('all'));
        self::assertSame('vm_overview.php?filter=all', vm_overview_url('bogus', ['sort' => 'mission', 'dir' => 'asc', 'page' => 1]));
    }

    public function testPagingAndSortingKeepTheFilterAndTheSnapshot(): void
    {
        $snapshot = ['counted' => 1792400000, 'expected' => 17];

        self::assertSame(
            'vm_overview.php?filter=mecm_pending&sort=status&dir=desc&page=3&counted=1792400000&expected=17',
            vm_overview_url('mecm_pending', ['sort' => 'status', 'dir' => 'desc', 'page' => 3, 'snapshot' => $snapshot])
        );
    }

    public function testAFilterChangeStartsOverWithoutPageOrSnapshot(): void
    {
        self::assertSame(
            'vm_overview.php?filter=mecm_pending&sort=status&dir=desc',
            vm_overview_url('mecm_pending', ['sort' => 'status', 'dir' => 'desc']),
            'the old page and the old tile number do not describe the new list'
        );
    }

    public function testTheTileLinksToItsFilterAndCarriesItsCaptureTime(): void
    {
        ob_start();
        vm_overview_render_tile('mecm_pending', 'MECM ausstehend', 3, 1792400000, true);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="vm_overview.php?filter=mecm_pending&amp;counted=1792400000&amp;expected=3"', $html);
        self::assertStringContainsString('value value-warning', $html);
    }

    public function testAQuietTileIsNotMarkedAsWarning(): void
    {
        ob_start();
        vm_overview_render_tile('all', 'VMs in Missionen', 0, 1792400000);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('value-warning', $html);
        self::assertStringContainsString('filter=all&amp;counted=1792400000&amp;expected=0', $html);
    }

    public function testZeroHitsRenderTheEmptySentenceOfTheirOwnFilter(): void
    {
        ob_start();
        vm_overview_render_table([], 'mecm_pending', 'mission', 'asc', null);
        $html = (string) ob_get_clean();

        self::assertStringContainsString(h(__t('vm_overview.empty_mecm_pending')), $html);
        self::assertStringNotContainsString(h(__t('vm_overview.empty_all')), $html);
    }

    public function testATemplateRowIsMarkedAndEveryRowLinksItsMissionAndVm(): void
    {
        $rows = [[
            'id' => 7,
            'mission_id' => 3,
            'vm_name' => 'VM-A',
            'vm_hostname' => 'vm-a',
            'vm_status' => '2/5 Registered',
            'mecm_sync_state' => 'pending',
            'updated' => 1,
            'mission_name' => '_Vorlage1',
        ]];

        ob_start();
        vm_overview_render_table($rows, 'mecm_pending', 'mission', 'asc', null);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="mission_details.php?id=3"', $html);
        self::assertStringContainsString('href="vm_edit.php?mission_id=3&amp;vm_id=7&amp;work_ov_filter=mecm_pending"', $html, 'the VM link carries the list view; defaults stay out');
        self::assertStringContainsString('<tr id="vm-7">', $html, 'the row owns the fragment the editor returns to');
        self::assertStringContainsString(h(__t('vm_overview.template_badge')), $html);
        self::assertStringContainsString(h(__t('common.mecm_queued')), $html, 'the queued flag that put the row into the count is shown');
    }

    public function testARealMissionRowHasNoTemplateMarker(): void
    {
        $rows = [[
            'id' => 8,
            'mission_id' => 4,
            'vm_name' => 'VM-B',
            'vm_hostname' => 'vm-b',
            'vm_status' => '3/5 Deployed',
            'mecm_sync_state' => 'registered',
            'updated' => 0,
            'mission_name' => 'Produktion',
        ]];

        ob_start();
        vm_overview_render_table($rows, 'all', 'name', 'asc', null);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString(h(__t('vm_overview.template_badge')), $html);
        self::assertStringContainsString('href="mission_details.php?id=4"', $html);
        self::assertStringContainsString('href="vm_edit.php?mission_id=4&amp;vm_id=8&amp;work_ov_filter=all&amp;work_ov_sort=name"', $html);
    }

    public function testTheVmLinkCarriesPageAndDirectionButNeverTheTileSnapshot(): void
    {
        $rows = [[
            'id' => 9,
            'mission_id' => 5,
            'vm_name' => 'VM-C',
            'vm_hostname' => 'vm-c',
            'vm_status' => '3/5 Deployed',
            'mecm_sync_state' => 'registered',
            'updated' => 0,
            'mission_name' => 'Produktion',
        ]];

        ob_start();
        vm_overview_render_table($rows, 'all', 'status', 'desc', ['counted' => 1792400000, 'expected' => 3], 4);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="vm_edit.php?mission_id=5&amp;vm_id=9&amp;work_ov_filter=all&amp;work_ov_sort=status&amp;work_ov_dir=desc&amp;work_ov_page=4"', $html);
        self::assertStringNotContainsString('vm_edit.php?mission_id=5&amp;vm_id=9&amp;counted', $html);
        self::assertStringContainsString('href="mission_details.php?id=5"', $html, 'the mission link stays mission-scoped');
    }

    public function testPaginationLinksOnlyToExistingPagesOtherThanTheCurrentOne(): void
    {
        ob_start();
        vm_overview_render_pagination(['total' => 120, 'pages' => 3, 'page' => 2, 'rows' => []], 'all', 'name', 'desc', null);
        $html = (string) ob_get_clean();

        // The first page is the canonical address, so its link carries no page parameter.
        self::assertStringContainsString('href="vm_overview.php?filter=all&amp;sort=name&amp;dir=desc"', $html);
        self::assertStringContainsString('href="vm_overview.php?filter=all&amp;sort=name&amp;dir=desc&amp;page=3"', $html);
        self::assertStringNotContainsString('page=2"', $html, 'the current page links to nothing');
        self::assertStringNotContainsString('page=4', $html);
    }

    public function testAChangedCountNamesBothNumbersAndTheCaptureTime(): void
    {
        ob_start();
        vm_overview_render_change_notice(['counted' => 1792400000, 'expected' => 17], 16);
        $html = (string) ob_get_clean();

        self::assertStringContainsString(h(__t('vm_overview.count_changed', [
            'counted' => portal_format_epoch(1792400000),
            'expected' => 17,
            'actual' => 16,
        ])), $html);
    }

    public function testAnUnchangedCountShowsNoNotice(): void
    {
        ob_start();
        vm_overview_render_change_notice(['counted' => 1792400000, 'expected' => 17], 17);
        $html = (string) ob_get_clean();

        self::assertSame('', $html);
    }
}
