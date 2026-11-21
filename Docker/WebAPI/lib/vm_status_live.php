<?php

declare(strict_types=1);

/**
 * The live status of the VM pages (VB2): markup and wire form shared by the four
 * pages and by the poll (portal/vm_status_live.php, reader in
 * lib/vm_status_live_views.php, client portal/assets/vm_status_live.js).
 *
 * A page marks what follows without a reload: a table cell with
 * vm_live_td(), a block or tile with vm_live_region(), and it
 * renders vm_live_line_html() once, which carries the poll address and the
 * interval. The poll answers with the same cells and regions from the same
 * renderers; each carries a signature, so the browser replaces only what changed.
 * Every sentence is rendered here or by the page; the script holds none (R3).
 * Without JavaScript the line stays hidden and the page is what it was.
 */

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/vm_overview.php';
require_once __DIR__ . '/portal_work_context.php';

// Seconds between two polls of a visible tab. One constant for all four pages;
// the measurement behind it is in docs/register/2026-11-21-VB2.md.
const VIRTUSPHERE_VM_STATUS_LIVE_INTERVAL_SECONDS = 20;
// The role="status" sentence speaks at most this often (R37).
const VIRTUSPHERE_VM_STATUS_LIVE_ANNOUNCE_SECONDS = 60;
// VM ids per request. The VM list of a mission is not paged, so the client asks
// in several requests above it.
const VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS = 200;

const VIRTUSPHERE_VM_STATUS_LIVE_VIEW_MISSION = 'mission';
const VIRTUSPHERE_VM_STATUS_LIVE_VIEW_OVERVIEW = 'overview';
const VIRTUSPHERE_VM_STATUS_LIVE_VIEW_EDITOR = 'editor';
const VIRTUSPHERE_VM_STATUS_LIVE_VIEW_DASHBOARD = 'dashboard';
const VIRTUSPHERE_VM_STATUS_LIVE_VIEWS = [
    VIRTUSPHERE_VM_STATUS_LIVE_VIEW_MISSION,
    VIRTUSPHERE_VM_STATUS_LIVE_VIEW_OVERVIEW,
    VIRTUSPHERE_VM_STATUS_LIVE_VIEW_EDITOR,
    VIRTUSPHERE_VM_STATUS_LIVE_VIEW_DASHBOARD,
];

/** The signature of a cell or region: equal markup, equal signature. */
function vm_live_sig(string $basis): string
{
    return substr(hash('sha256', $basis), 0, 16);
}

/** One live table cell. $html is finished markup of a cell renderer. */
function vm_live_td(string $key, string $html): string
{
    return '<td data-vm-live-cell="' . h($key) . '" data-vm-live-sig="' . vm_live_sig($html) . '">' . $html . '</td>';
}

/**
 * The cells of one row in their wire form.
 *
 * @param array<string,string> $cells key => finished markup (vm_live_cells())
 * @return array<string,array{sig:string,html:string}>
 */
function vm_live_row(array $cells): array
{
    $row = [];
    foreach ($cells as $key => $html) {
        $row[$key] = ['sig' => vm_live_sig($html), 'html' => $html];
    }

    return $row;
}

/**
 * One live block or tile. Its renderer marks the root element with
 * data-vm-live-region="<key>"; the signature is added there, so the page and the
 * poll emit the same element. Empty markup means the region is absent. The
 * signature basis differs from the markup where the markup carries something
 * that changes without the content changing (a CSRF field, a counted-at time).
 *
 * @return array{key:string,sig:string,html:string}
 */
function vm_live_region(string $key, string $html, ?string $sigBasis = null): array
{
    if ($html === '') {
        return ['key' => $key, 'sig' => '', 'html' => ''];
    }
    $marker = 'data-vm-live-region="' . $key . '"';
    if (substr_count($html, $marker) !== 1) {
        throw new LogicException('A live region carries its key exactly once: ' . $key);
    }
    $sig = vm_live_sig($sigBasis ?? $html);

    return [
        'key' => $key,
        'sig' => $sig,
        'html' => str_replace($marker, $marker . ' data-vm-live-sig="' . $sig . '"', $html),
    ];
}

/**
 * The poll address of a page, without the VM ids the client adds.
 *
 * @param array<string,int|string> $params
 * @param array<string,mixed> $workContext
 */
function vm_live_url(string $view, array $params = [], array $workContext = []): string
{
    if (!in_array($view, VIRTUSPHERE_VM_STATUS_LIVE_VIEWS, true)) {
        throw new InvalidArgumentException('Unknown live view: ' . $view);
    }

    $params = array_merge(['view' => $view], $params);

    return portal_work_context_append_url(
        'vm_status_live.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        $workContext
    );
}

/** The time of a reading as the line shows it, in the portal timezone. */
function vm_live_time(int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))
        ->setTimezone(new DateTimeZone(portal_timezone()))
        ->format('H:i:s');
}

/** "As of" sentence of a view; the dashboard counts, the other pages show a status. */
function vm_live_as_of(string $view, string $time): string
{
    return __t(
        $view === VIRTUSPHERE_VM_STATUS_LIVE_VIEW_DASHBOARD ? 'vm_live.as_of_counts' : 'vm_live.as_of_status',
        ['time' => $time]
    );
}

/**
 * The line that says how current the page is, and the slot of the "list has
 * changed" notice. Both are hidden until the script shows them. The script reads
 * the poll address, the interval and its two own sentences from here.
 */
function vm_live_line_html(string $view, string $url, string $reloadUrl, ?int $now = null): string
{
    $time = vm_live_time($now ?? time());

    return '<p class="muted" data-vm-live hidden'
        . ' data-vm-live-url="' . h($url) . '"'
        . ' data-vm-live-interval="' . VIRTUSPHERE_VM_STATUS_LIVE_INTERVAL_SECONDS . '"'
        . ' data-vm-live-announce="' . VIRTUSPHERE_VM_STATUS_LIVE_ANNOUNCE_SECONDS . '"'
        . ' data-vm-live-max-ids="' . VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS . '"'
        . ' data-vm-live-time="' . h($time) . '"'
        . ' data-vm-live-stale-text="' . h(__t('vm_live.stale')) . '"'
        . ' data-vm-live-stopped-text="' . h(__t('vm_live.stopped')) . '">'
        . '<span data-vm-live-as-of>' . h(vm_live_as_of($view, $time)) . '</span>'
        . ' <span role="status" data-vm-live-status></span>'
        . ' <a href="' . h($reloadUrl) . '" data-vm-live-reload hidden>' . h(__t('vm_live.reload')) . '</a>'
        . '</p>'
        . '<div data-vm-live-notice hidden></div>';
}

/**
 * A notice of the poll with the one link that resolves it (R11, R30). The
 * script reads the marked sentence into the role="status" line (R37).
 */
function vm_live_notice_html(string $text, string $url, string $label): string
{
    return '<div class="alert alert-info"><p data-vm-live-notice-text>' . h($text) . '</p>'
        . '<p class="alert-actions"><a href="' . h($url) . '">' . h($label) . '</a></p></div>';
}

/**
 * The VM ids of a request: decimal ids separated by commas, at most
 * VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS. Anything else is refused, never cut.
 *
 * @return list<int>
 */
function vm_live_parse_ids(string $raw): array
{
    if ($raw === '') {
        return [];
    }
    if (preg_match('/\A[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9})*\z/', $raw) !== 1) {
        throw new InvalidArgumentException('Malformed VM id list.');
    }
    $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
    if (count($ids) > VIRTUSPHERE_VM_STATUS_LIVE_MAX_IDS) {
        throw new InvalidArgumentException('Too many VM ids.');
    }

    return $ids;
}

/**
 * The validated request. List parameters pass through the owners the pages use,
 * so the poll reads the view the page rendered.
 *
 * @param array<string,mixed> $query
 * @return array{view:string,ids:list<int>,count:int,mission_id:int,vm_id:int,filter:string,sort:string,dir:string,page:int,work_context:array<string,string>}
 */
function vm_live_request(array $query): array
{
    $view = request_string($query, 'view');
    if (!in_array($view, VIRTUSPHERE_VM_STATUS_LIVE_VIEWS, true)) {
        throw new InvalidArgumentException('Unknown live view.');
    }
    $ids = vm_live_parse_ids(request_string($query, 'ids'));
    $request = [
        'view' => $view,
        'ids' => $ids,
        // The rows the page shows; above the id limit it is more than one request holds.
        'count' => max(count($ids), min(request_int($query, 'count'), VIRTUSPHERE_VM_OVERVIEW_COUNT_MAX)),
        'mission_id' => max(0, request_int($query, 'mission_id')),
        'vm_id' => max(0, request_int($query, 'vm_id')),
        'filter' => vm_overview_filter(request_string($query, 'filter', VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL)),
        'sort' => vm_overview_sort(request_string($query, 'sort', VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT)),
        'dir' => vm_overview_dir(request_string($query, 'dir', 'asc')),
        'page' => vm_overview_page_number(request_string($query, 'page', '1')),
        'work_context' => portal_work_context($query),
    ];
    $needsMission = in_array($view, [VIRTUSPHERE_VM_STATUS_LIVE_VIEW_MISSION, VIRTUSPHERE_VM_STATUS_LIVE_VIEW_EDITOR], true);
    if ($needsMission && $request['mission_id'] === 0) {
        throw new InvalidArgumentException('The view needs a mission.');
    }
    if ($view === VIRTUSPHERE_VM_STATUS_LIVE_VIEW_EDITOR && $request['vm_id'] === 0) {
        throw new InvalidArgumentException('The editor view needs a VM.');
    }

    return $request;
}

/**
 * The answer of the poll around what a view read. `rows` is keyed by VM id,
 * `regions` is ordered as the page shows them.
 *
 * @param array{rows?:array<int,array<string,array{sig:string,html:string}>>,regions?:list<array{key:string,sig:string,html:string}>,list_changed?:bool,notice?:string} $payload
 * @return array<string,mixed>
 */
function vm_live_envelope(string $view, array $payload, int $now): array
{
    $time = vm_live_time($now);

    return [
        'ok' => true,
        'view' => $view,
        'time' => $time,
        'as_of' => vm_live_as_of($view, $time),
        'changed' => __t('vm_live.changed', ['time' => $time]),
        'rows' => (object) ($payload['rows'] ?? []),
        'regions' => $payload['regions'] ?? [],
        'list_changed' => (bool) ($payload['list_changed'] ?? false),
        'notice' => (string) ($payload['notice'] ?? ''),
    ];
}
