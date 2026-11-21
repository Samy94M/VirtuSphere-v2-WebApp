<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_status_live.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase_cells.php';
require_once dirname(__DIR__, 2) . '/lib/vm_overview_page.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_live.php';
require_once dirname(__DIR__, 2) . '/lib/vm_status_live_views.php';

/**
 * The wire form of the live VM status (VB2): what a request may carry, what the
 * answer looks like, and that a cell or region renders the same for the page
 * and for the poll. The readers behind it run against the database in the
 * browser suite (tests/e2e/specs/vm-status-live.spec.js).
 */
final class VmStatusLiveTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testIdsAreAClosedDecimalListAndNeverCut(): void
    {
        self::assertSame([], vm_live_parse_ids(''));
        self::assertSame([3, 1, 20], vm_live_parse_ids('3,1,20'));
        self::assertSame([7, 8], vm_live_parse_ids('7,8,7'), 'a repeated id counts once and keeps its place');

        foreach (['0', '01', '-1', '1,,2', '1,', ',1', '1;2', '1 2', 'a', '1,2x', '12345678901', "1\n"] as $raw) {
            try {
                vm_live_parse_ids($raw);
                self::fail('accepted the id list ' . json_encode($raw));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $max = implode(',', range(1, VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS));
        self::assertCount(VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS, vm_live_parse_ids($max));
        $this->expectException(InvalidArgumentException::class);
        vm_live_parse_ids($max . ',' . (VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS + 1));
    }

    public function testARequestNamesAKnownViewAndWhatThatViewNeeds(): void
    {
        $mission = vm_live_request(['view' => 'mission', 'mission_id' => '4', 'ids' => '9,8', 'count' => '2', 'work_vm_sort' => 'status', 'work_vm_dir' => 'desc', 'evil' => 'x']);
        self::assertSame('mission', $mission['view']);
        self::assertSame(4, $mission['mission_id']);
        self::assertSame([9, 8], $mission['ids']);
        self::assertSame(2, $mission['count']);
        self::assertSame(['work_vm_sort' => 'status', 'work_vm_dir' => 'desc'], $mission['work_context'], 'only the closed work context travels');

        self::assertSame(3, vm_live_request(['view' => 'mission', 'mission_id' => '4', 'ids' => '1,2,3'])['count'], 'the count is never below the ids of the request');
        self::assertSame(250, vm_live_request(['view' => 'mission', 'mission_id' => '4', 'ids' => '1', 'count' => '250'])['count'], 'a list above the id limit names its whole size');

        $overview = vm_live_request(['view' => 'overview', 'filter' => 'nonsense', 'sort' => 'name; --', 'dir' => 'sideways', 'page' => '-2', 'ids' => ['1']]);
        self::assertSame(['all', VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT, 'asc', 1], [$overview['filter'], $overview['sort'], $overview['dir'], $overview['page']], 'list parameters pass through the overview owners');
        self::assertSame([], $overview['ids'], 'an array parameter is not an id list');

        self::assertSame('dashboard', vm_live_request(['view' => 'dashboard'])['view']);
        self::assertSame(12, vm_live_request(['view' => 'editor', 'mission_id' => '3', 'vm_id' => '12'])['vm_id']);

        foreach ([
            [],
            ['view' => 'missions'],
            ['view' => ['mission']],
            ['view' => 'mission'],
            ['view' => 'mission', 'mission_id' => '-3'],
            ['view' => 'editor', 'mission_id' => '3'],
            ['view' => 'editor', 'vm_id' => '3'],
            ['view' => 'overview', 'ids' => '1,x'],
        ] as $query) {
            try {
                vm_live_request($query);
                self::fail('accepted the request ' . json_encode($query));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACellCarriesItsKeyAndASignatureOfItsMarkup(): void
    {
        $cell = vm_live_td('phase', '<span class="badge">A &amp; B</span>');
        self::assertSame(
            '<td data-vm-live-cell="phase" data-vm-live-sig="' . vm_live_sig('<span class="badge">A &amp; B</span>') . '"><span class="badge">A &amp; B</span></td>',
            $cell
        );
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', vm_live_sig('x'));
        self::assertNotSame(vm_live_sig('a'), vm_live_sig('b'));

        $row = vm_live_row(['phase' => '<b>1</b>', 'mecm' => '&mdash;']);
        self::assertSame(['phase', 'mecm'], array_keys($row));
        self::assertSame(['sig' => vm_live_sig('<b>1</b>'), 'html' => '<b>1</b>'], $row['phase']);
        // The page's cell and the poll's row carry the same signature for the same markup.
        self::assertStringContainsString('data-vm-live-sig="' . $row['mecm']['sig'] . '"', vm_live_td('mecm', '&mdash;'));
    }

    public function testARegionCarriesItsKeyOnceAndAnAbsentRegionIsEmpty(): void
    {
        $region = vm_live_region('status', '<section class="panel" data-vm-live-region="status"><p>x</p></section>');
        self::assertSame('status', $region['key']);
        self::assertSame(
            '<section class="panel" data-vm-live-region="status" data-vm-live-sig="' . $region['sig'] . '"><p>x</p></section>',
            $region['html']
        );
        self::assertSame(['key' => 'progress', 'sig' => '', 'html' => ''], vm_live_region('progress', ''));

        // A basis decides the signature where the markup moves without the content.
        $first = vm_live_region('tile-all', '<a href="?counted=1" data-vm-live-region="tile-all">7</a>', 'count:7');
        $later = vm_live_region('tile-all', '<a href="?counted=2" data-vm-live-region="tile-all">7</a>', 'count:7');
        self::assertSame($first['sig'], $later['sig']);
        self::assertNotSame($first['html'], $later['html']);

        foreach (['<section>no key</section>', '<a data-vm-live-region="status"></a><a data-vm-live-region="status"></a>'] as $markup) {
            try {
                vm_live_region('status', $markup);
                self::fail('accepted a region without exactly one key');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAListSortedByStatusHasChangedWhenItsRowsLeaveThatOrder(): void
    {
        $ranks = [11 => 1, 12 => 4, 13 => 4, 14 => 6];
        $byStatus = ['work_vm_sort' => 'status'];
        self::assertTrue(vm_live_status_order_holds([11, 12, 13, 14], $ranks, $byStatus), 'equal ranks keep their place');
        self::assertFalse(vm_live_status_order_holds([12, 11, 13, 14], $ranks, $byStatus), 'a row above a lower rank');
        self::assertTrue(vm_live_status_order_holds([14, 13, 12, 11], $ranks, $byStatus + ['work_vm_dir' => 'desc']));
        self::assertFalse(vm_live_status_order_holds([11, 12, 13, 14], $ranks, $byStatus + ['work_vm_dir' => 'desc']));
        self::assertTrue(vm_live_status_order_holds([11, 99, 12], $ranks, $byStatus), 'an id without a row is not part of the order');

        // No other sort column moves through a state change.
        foreach ([[], ['work_vm_sort' => 'hostname'], ['work_vm_dir' => 'desc']] as $context) {
            self::assertTrue(vm_live_status_order_holds([14, 11, 13, 12], $ranks, $context));
        }
    }

    public function testTheAnswerHasOneFormForAllViews(): void
    {
        $now = 1795262585;
        $empty = vm_live_envelope('mission', [], $now);
        self::assertSame(['ok', 'view', 'time', 'as_of', 'changed', 'rows', 'regions', 'list_changed', 'notice'], array_keys($empty));
        $json = json_decode(json_encode($empty, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json->ok);
        self::assertEquals(new stdClass(), $json->rows, 'no rows is an empty object, never a list');
        self::assertSame([], $json->regions);
        self::assertFalse($json->list_changed);
        self::assertSame('', $json->notice);
        self::assertMatchesRegularExpression('/\A\d{2}:\d{2}:\d{2}\z/', $json->time);

        $filled = vm_live_envelope('overview', [
            'rows' => [17 => vm_live_row(['phase' => '<b>x</b>'])],
            'list_changed' => true,
            'notice' => vm_live_notice_html('Liste <geändert>', 'vm_overview.php?filter=all&page=2', 'Neu laden'),
        ], $now);
        $json = json_decode(json_encode($filled, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['17'], array_map('strval', array_keys($json['rows'])));
        self::assertSame(['sig', 'html'], array_keys($json['rows']['17']['phase']));
        self::assertTrue($json['list_changed']);
        self::assertStringContainsString('<p data-vm-live-notice-text>Liste &lt;geändert&gt;</p>', $json['notice'], 'the notice is escaped markup and marks the sentence the status line speaks');
        self::assertStringContainsString('<p class="alert-actions"><a href="vm_overview.php?filter=all&amp;page=2">Neu laden</a></p>', $json['notice']);

        $regions = vm_live_envelope('dashboard', ['regions' => [['key' => 'a', 'sig' => 's', 'html' => 'h'], ['key' => 'b', 'sig' => '', 'html' => '']]], $now);
        self::assertSame(['a', 'b'], array_column($regions['regions'], 'key'), 'regions are an ordered list');
        self::assertSame('[]', json_encode(vm_live_envelope('dashboard', [], $now)['regions'], JSON_THROW_ON_ERROR));
    }

    public function testTheSentencesNameTheViewAndTheTimeInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $status = vm_live_envelope('editor', [], 1795262585);
            $counts = vm_live_envelope('dashboard', [], 1795262585);
            self::assertSame(__t('vm_live.as_of_status', ['time' => $status['time']]), $status['as_of']);
            self::assertSame(__t('vm_live.as_of_counts', ['time' => $counts['time']]), $counts['as_of']);
            self::assertNotSame($status['as_of'], $counts['as_of'], "{$locale}: the dashboard counts, the other pages show a status");
            self::assertStringContainsString($status['time'], $status['changed']);
            $catalog = require dirname(__DIR__, 2) . "/lang/{$locale}/vm_live.php";
            foreach (['as_of_status', 'as_of_counts', 'changed', 'stale'] as $key) {
                self::assertStringContainsString(':time', (string) ($catalog[$key] ?? ''), "{$locale}: vm_live.{$key} names its time");
            }
        }
    }

    public function testTheLineIsHiddenAndCarriesWhatTheScriptReads(): void
    {
        $line = vm_live_line_html('mission', 'vm_status_live.php?view=mission&mission_id=3', 'vms.php?mission_id=3', 1795262585);
        self::assertStringStartsWith('<p class="muted" data-vm-live hidden', $line, 'without JavaScript the line is not shown');
        self::assertStringContainsString('data-vm-live-url="vm_status_live.php?view=mission&amp;mission_id=3"', $line);
        self::assertStringContainsString('data-vm-live-interval="' . VIRTUSPHERE_VM_STATUS_LIVE_INTERVAL_SECONDS . '"', $line);
        self::assertStringContainsString('data-vm-live-announce="' . VIRTUSPHERE_VM_STATUS_LIVE_ANNOUNCE_SECONDS . '"', $line);
        self::assertStringContainsString('data-vm-live-max-ids="' . VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS . '"', $line);
        self::assertStringContainsString('<span role="status" data-vm-live-status></span>', $line);
        self::assertStringContainsString('<a href="vms.php?mission_id=3" data-vm-live-reload hidden>', $line);
        self::assertStringEndsWith('<div data-vm-live-notice hidden></div>', $line);
        self::assertSame(1, substr_count($line, 'role="status"'));
        self::assertStringNotContainsString('style=', $line);
        self::assertStringNotContainsString('<script', $line);

        self::assertSame(
            'vm_status_live.php?view=overview&filter=job_failed&sort=name&dir=desc&page=2',
            vm_live_url('overview', ['filter' => 'job_failed', 'sort' => 'name', 'dir' => 'desc', 'page' => 2])
        );
        self::assertSame(
            'vm_status_live.php?view=editor&mission_id=3&vm_id=9&work_ov_filter=all',
            vm_live_url('editor', ['mission_id' => 3, 'vm_id' => 9], ['work_ov_filter' => 'all', 'free' => 'x'])
        );
        $this->expectException(InvalidArgumentException::class);
        vm_live_url('elsewhere');
    }

    public function testTheStatusCellsAreTheThreeLiveCells(): void
    {
        $vm = ['id' => 5, 'vm_status' => VIRTUSPHERE_STATUS_REGISTERED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING, 'updated' => 1];
        $phase = virtusphere_vm_phase($vm, []);
        $cells = vm_live_cells($vm, $phase, [], 3, []);
        self::assertSame(['phase', 'finding', 'mecm'], array_keys($cells));
        self::assertSame(vm_phase_cell($vm, $phase), $cells['phase']);
        self::assertSame('&mdash;', $cells['finding']);
        self::assertSame(
            mecm_sync_badge(VIRTUSPHERE_MECM_SYNC_PENDING) . ' <span class="muted">' . h(mecm_updated_display(1)) . '</span>',
            $cells['mecm'],
            'the MECM cell keeps the markup both tables rendered before'
        );
    }

    public function testTheDashboardTilesAreThreeRegionsWhoseSignatureIsTheirNumbers(): void
    {
        $none = array_fill_keys(array_keys(VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS), 0);
        $tiles = vm_overview_live_tiles(12, 0, $none, 1795262585);
        self::assertSame(['tile-all', 'tile-mecm_pending', 'tile-attention'], array_column($tiles, 'key'));
        self::assertSame('', $tiles[2]['html'], 'no group with hits, no tile');
        self::assertStringContainsString('data-vm-live-region="tile-all" data-vm-live-sig="' . $tiles[0]['sig'] . '"', $tiles[0]['html']);
        self::assertStringContainsString('counted=1795262585', $tiles[0]['html']);
        self::assertStringNotContainsString('value-warning', $tiles[1]['html']);

        $later = vm_overview_live_tiles(12, 2, [VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED => 1] + $none, 1795262605);
        self::assertSame($tiles[0]['sig'], $later[0]['sig'], 'the same number later is no change');
        self::assertNotSame($tiles[0]['html'], $later[0]['html'], 'the link still carries its own counting time');
        self::assertNotSame($tiles[1]['sig'], $later[1]['sig']);
        self::assertStringContainsString('value-warning', $later[1]['html']);
        self::assertNotSame('', $later[2]['html']);
        self::assertStringContainsString('data-vm-live-region="tile-attention"', $later[2]['html']);
        self::assertNotSame(
            $later[2]['sig'],
            vm_overview_live_tiles(12, 2, [VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED => 2] + $none, 1795262605)[2]['sig']
        );
    }

    public function testTheEditorRegionsAreTheSameForThePageAndThePoll(): void
    {
        $vm = [
            'id' => 9,
            'vm_status' => VIRTUSPHERE_STATUS_OS_INSTALLING,
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
            'updated' => 0,
            'os_install_watch_started_at' => null,
        ];
        $statusView = ['phase' => virtusphere_vm_phase($vm, []), 'findings' => [], 'inventory' => null, 'client_phase_summary' => []];
        $progressView = vm_edit_progress_view($vm, []);
        self::assertTrue($progressView['show'], 'a registered VM under installation shows "check progress"');
        self::assertSame('', $progressView['since']);

        $access = ['can_write' => true, 'can_system_config' => false];
        $pageToken = '<input type="hidden" name="_csrf" value="' . str_repeat('a', 64) . '">';
        $page = vm_edit_live_regions($vm, $statusView, $progressView, 3, [], $access, $pageToken);
        $poll = vm_edit_live_regions($vm, $statusView, $progressView, 3, [], $access, vm_edit_live_csrf_placeholder());

        self::assertSame(['progress', 'status'], array_column($page, 'key'));
        self::assertSame(array_column($page, 'sig'), array_column($poll, 'sig'), 'the CSRF field is not part of a signature');
        self::assertStringContainsString($pageToken, $page[0]['html']);
        self::assertStringNotContainsString(str_repeat('a', 64), $poll[0]['html'], 'the poll never renders a token');
        self::assertStringContainsString('name="_csrf" value="" data-vm-live-csrf', $poll[0]['html']);
        self::assertSame($page[1]['html'], $poll[1]['html']);
        self::assertStringContainsString('id="vm-progress-watch"', $page[0]['html']);
        self::assertStringContainsString('value="restart_progress_watch"', $page[0]['html']);

        $readOnly = vm_edit_live_regions($vm, $statusView, $progressView, 3, [], ['can_write' => false, 'can_system_config' => false], $pageToken);
        self::assertStringNotContainsString('<form', $readOnly[0]['html'], 'a reader without vms.write gets no action');
        self::assertNotSame($page[0]['sig'], $readOnly[0]['sig']);

        $idle = vm_edit_live_regions(['id' => 9, 'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_READY] + $vm, $statusView, ['show' => false] + $progressView, 3, [], $access, $pageToken);
        self::assertSame('', $idle[0]['html'], 'nothing observed, no "check progress" block');
        self::assertNotSame('', $idle[1]['html']);
    }
}
