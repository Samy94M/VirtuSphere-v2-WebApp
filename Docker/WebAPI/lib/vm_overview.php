<?php

declare(strict_types=1);

/**
 * URL state of the dashboard VM overview (portal/vm_overview.php): filter, sort,
 * direction and page, plus the snapshot a dashboard tile hands over.
 *
 * Every value is validated here and an unknown one falls back to its default,
 * the same as the other list pages. The snapshot is the tile's capture time and
 * its number. The list uses it only to explain a difference between the two,
 * and never to reproduce the old number by bending its filter.
 */

require_once __DIR__ . '/repo/vms_overview.php';

const VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT = 'mission';
const VIRTUSPHERE_VM_OVERVIEW_PAGE_MAX = 100000;
const VIRTUSPHERE_VM_OVERVIEW_COUNT_MAX = 10000000;
// 2000-01-01T00:00:00Z and 2100-01-01T00:00:00Z: anything outside is not a capture time.
const VIRTUSPHERE_VM_OVERVIEW_SNAPSHOT_MIN = 946684800;
const VIRTUSPHERE_VM_OVERVIEW_SNAPSHOT_MAX = 4102444800;

function vm_overview_filter(string $raw): string
{
    return in_array($raw, VIRTUSPHERE_VM_OVERVIEW_FILTERS, true) ? $raw : VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL;
}

function vm_overview_sort(string $raw): string
{
    return isset(repo_vm_overview_sort_columns()[$raw]) ? $raw : VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT;
}

function vm_overview_dir(string $raw): string
{
    return $raw === 'desc' ? 'desc' : 'asc';
}

function vm_overview_page_number(string $raw): int
{
    if (!ctype_digit($raw)) {
        return 1;
    }

    return max(1, min((int) $raw, VIRTUSPHERE_VM_OVERVIEW_PAGE_MAX));
}

/**
 * The capture time and number a tile carries. Both or neither: a time without
 * its number, or the reverse, cannot say what changed, so it is no snapshot.
 *
 * @return array{counted:int,expected:int}|null
 */
function vm_overview_snapshot(string $countedRaw, string $expectedRaw): ?array
{
    if (!ctype_digit($countedRaw) || !ctype_digit($expectedRaw)) {
        return null;
    }
    $counted = (int) $countedRaw;
    $expected = (int) $expectedRaw;
    if ($counted < VIRTUSPHERE_VM_OVERVIEW_SNAPSHOT_MIN || $counted > VIRTUSPHERE_VM_OVERVIEW_SNAPSHOT_MAX) {
        return null;
    }
    if ($expected > VIRTUSPHERE_VM_OVERVIEW_COUNT_MAX) {
        return null;
    }

    return ['counted' => $counted, 'expected' => $expected];
}

/**
 * True when the list's total is no longer the number the tile showed. The list
 * then states the difference and keeps its own current total.
 *
 * @param array{counted:int,expected:int}|null $snapshot
 */
function vm_overview_count_changed(?array $snapshot, int $actual): bool
{
    return $snapshot !== null && $snapshot['expected'] !== $actual;
}

/**
 * Link to the overview. Defaults are omitted, so the canonical address stays
 * short. A new filter is built without `page` and without a snapshot: the filter
 * change starts a new list, and the old tile's number no longer describes it.
 *
 * @param array{sort?:string,dir?:string,page?:int,snapshot?:array{counted:int,expected:int}|null} $state
 */
function vm_overview_url(string $filter, array $state = []): string
{
    $params = ['filter' => vm_overview_filter($filter)];

    $sort = vm_overview_sort((string) ($state['sort'] ?? ''));
    if ($sort !== VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT) {
        $params['sort'] = $sort;
    }
    $dir = vm_overview_dir((string) ($state['dir'] ?? ''));
    if ($dir !== 'asc') {
        $params['dir'] = $dir;
    }
    $page = max(1, (int) ($state['page'] ?? 1));
    if ($page > 1) {
        $params['page'] = (string) $page;
    }
    $snapshot = $state['snapshot'] ?? null;
    if (is_array($snapshot)) {
        $params['counted'] = (string) $snapshot['counted'];
        $params['expected'] = (string) $snapshot['expected'];
    }

    return 'vm_overview.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * The overview's part of the work context (UX03, F05; lib/portal_work_context.php).
 * A VM link of the list carries its filter, sort, direction and page, so saving or
 * going back in the editor reopens this list where the operator left it. The
 * filter is the marker: without a known filter there is no overview origin, and
 * the editor keeps its mission parent. Values are this list's own whitelist;
 * defaults are left out like everywhere else in the work context.
 *
 * @return array<string,string>
 */
function vm_overview_work_context(array $input): array
{
    $scalar = static function (string $key) use ($input): string {
        return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
    };
    $filter = $scalar('work_ov_filter');
    if (!in_array($filter, VIRTUSPHERE_VM_OVERVIEW_FILTERS, true)) {
        return [];
    }
    $context = ['work_ov_filter' => $filter];
    $sort = vm_overview_sort($scalar('work_ov_sort'));
    if ($sort !== VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT) {
        $context['work_ov_sort'] = $sort;
    }
    if (vm_overview_dir($scalar('work_ov_dir')) === 'desc') {
        $context['work_ov_dir'] = 'desc';
    }
    $page = vm_overview_page_number($scalar('work_ov_page'));
    if ($page > 1) {
        $context['work_ov_page'] = (string) $page;
    }

    return $context;
}

/**
 * The work context a VM link of the overview hands to the editor: this list's
 * filter, sort, direction and page. The tile snapshot stays behind; it describes
 * the tile, not the list the editor returns to.
 *
 * @return array<string,string>
 */
function vm_overview_editor_context(string $filter, string $sort, string $dir, int $page): array
{
    return vm_overview_work_context([
        'work_ov_filter' => $filter,
        'work_ov_sort' => $sort,
        'work_ov_dir' => $dir,
        'work_ov_page' => (string) $page,
    ]);
}

/**
 * Where the editor returns when it was opened from the overview: the same list,
 * with the VM's row as fragment. Null without an overview origin. The list clamps
 * the page itself (repo_vm_overview_page()), so a list that shrank in the meantime
 * still opens on a page with rows.
 */
function vm_overview_return_url(array $context, ?int $focusVmId = null): ?string
{
    $context = vm_overview_work_context($context);
    if ($context === []) {
        return null;
    }
    $url = vm_overview_url($context['work_ov_filter'], [
        'sort' => $context['work_ov_sort'] ?? '',
        'dir' => $context['work_ov_dir'] ?? '',
        'page' => (int) ($context['work_ov_page'] ?? '1'),
    ]);

    return $url . ($focusVmId !== null && $focusVmId > 0 ? '#vm-' . $focusVmId : '');
}
