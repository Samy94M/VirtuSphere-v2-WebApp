<?php

declare(strict_types=1);

require_once __DIR__ . '/vm_overview.php';

/**
 * Closed, URL-bound list context for mission/VM drill-down navigation and for the
 * VM editor opened from the dashboard VM overview.
 *
 * These are display preferences, not a return URL and not authorization. Every
 * destination still loads the object and checks the current user normally.
 */
const VIRTUSPHERE_WORK_CONTEXT_KEYS = [
    'work_list_type',
    'work_list_sort',
    'work_list_dir',
    'work_list_attention',
    'work_vm_sort',
    'work_vm_dir',
    'work_ov_filter',
    'work_ov_sort',
    'work_ov_dir',
    'work_ov_page',
    'work_ov_vm',
];

/**
 * The VM overview origin (UX03, F05). Its owner is vm_overview_work_context(); it
 * travels to the VM editor and the editor's own round trips, and with the row VM
 * (work_ov_vm) only to the back button of the mission details.
 */
const VIRTUSPHERE_WORK_CONTEXT_OVERVIEW_KEYS = [
    'work_ov_filter',
    'work_ov_sort',
    'work_ov_dir',
    'work_ov_page',
    'work_ov_vm',
];

/** @return array<string,string> */
function portal_work_context(array $input): array
{
    $scalar = static function (string $key) use ($input): string {
        return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
    };
    $allowed = static function (string $value, array $values): string {
        return in_array($value, $values, true) ? $value : '';
    };

    $rawType = $scalar('work_list_type');
    $type = $allowed($rawType, ['missions', 'templates']);
    $hasListValues = $scalar('work_list_sort') !== ''
        || $scalar('work_list_dir') !== ''
        || $scalar('work_list_attention') !== '';
    $context = [];
    if ($type !== '' || ($rawType === '' && $hasListValues)) {
        $type = $type !== '' ? $type : 'missions';
        $context['work_list_type'] = $type;
        $sort = $allowed($scalar('work_list_sort'), ['name', 'vms', 'attention']);
        $dir = $allowed($scalar('work_list_dir'), ['asc', 'desc']);
        if ($sort !== '') {
            $context['work_list_sort'] = $sort;
        }
        if ($dir !== '') {
            $context['work_list_dir'] = $dir;
        }
        if ($type === 'missions' && $scalar('work_list_attention') === '1') {
            $context['work_list_attention'] = '1';
        }
    }

    $vmSort = $allowed($scalar('work_vm_sort'), ['name', 'hostname', 'os', 'cpu', 'ram', 'status']);
    $vmDir = $allowed($scalar('work_vm_dir'), ['asc', 'desc']);
    if ($vmSort !== '') {
        $context['work_vm_sort'] = $vmSort;
    }
    if ($vmDir !== '') {
        $context['work_vm_dir'] = $vmDir;
    }

    // Canonical defaults need no payload. Besides keeping old bookmarks and
    // selectors stable, omission is what makes a direct entry indistinguishable
    // from the normal parent rather than manufacturing navigation history.
    if (($context['work_list_type'] ?? '') === 'missions') {
        unset($context['work_list_type']);
    }
    if (($context['work_list_sort'] ?? '') === 'name') {
        unset($context['work_list_sort']);
    }
    if (($context['work_list_dir'] ?? '') === 'asc') {
        unset($context['work_list_dir']);
    }
    if (($context['work_vm_sort'] ?? '') === 'name') {
        unset($context['work_vm_sort']);
    }
    if (($context['work_vm_dir'] ?? '') === 'asc') {
        unset($context['work_vm_dir']);
    }

    // The overview origin has its own owner: its filter, sort and page are that
    // list's whitelist, not the mission list's.
    return $context + vm_overview_work_context($input);
}

/** @return array<string,string> */
function portal_work_context_from_mission_list(string $type, string $sort, string $dir, bool $attentionOnly): array
{
    return portal_work_context([
        'work_list_type' => $type,
        'work_list_sort' => $sort,
        'work_list_dir' => $dir,
        'work_list_attention' => $attentionOnly ? '1' : '',
    ]);
}

/** @param array<string,string> $context @return array<string,string> */
function portal_work_context_with_vm_list(array $context, string $sort, string $dir): array
{
    $context = portal_work_context($context);
    $context['work_vm_sort'] = $sort;
    $context['work_vm_dir'] = $dir;

    return portal_work_context($context);
}

/**
 * Context for a mission-scoped destination (mission list, details, VM list). The
 * overview origin stays behind: whoever moves on into the mission's own pages has
 * left the overview, and a later save there returns to the mission's VM list.
 *
 * @param array<string,string> $context
 * @return array<string,string>
 */
function portal_work_context_mission_scope(array $context): array
{
    return array_diff_key(portal_work_context($context), array_flip(VIRTUSPHERE_WORK_CONTEXT_OVERVIEW_KEYS));
}

/** @param array<string,string> $context */
function portal_work_context_append_url(string $url, array $context): string
{
    $context = portal_work_context($context);
    if ($context === []) {
        return $url;
    }

    $fragment = '';
    $fragmentAt = strpos($url, '#');
    if ($fragmentAt !== false) {
        $fragment = substr($url, $fragmentAt);
        $url = substr($url, 0, $fragmentAt);
    }

    $query = http_build_query($context, '', '&', PHP_QUERY_RFC3986);

    return $url . (str_contains($url, '?') ? '&' : '?') . $query . $fragment;
}

/** @param array<string,string> $context */
function portal_work_context_mission_list_url(array $context, ?int $focusMissionId = null): string
{
    $context = portal_work_context($context);
    $type = $context['work_list_type'] ?? 'missions';
    $params = ['type' => $type];
    if (isset($context['work_list_sort'])) {
        $params['sort'] = $context['work_list_sort'];
    }
    if (isset($context['work_list_dir'])) {
        $params['dir'] = $context['work_list_dir'];
    }
    if (($context['work_list_attention'] ?? '') === '1') {
        $params['attention'] = '1';
    }

    $url = 'missions.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $url . ($focusMissionId !== null && $focusMissionId > 0 ? '#mission-' . $focusMissionId : '');
}

/** @param array<string,string> $context */
function portal_work_context_vm_list_url(int $missionId, array $context, ?int $focusVmId = null): string
{
    if ($missionId <= 0) {
        throw new InvalidArgumentException('Mission id must be positive.');
    }
    $context = portal_work_context($context);
    $params = ['mission_id' => (string) $missionId];
    if (isset($context['work_vm_sort'])) {
        $params['sort'] = $context['work_vm_sort'];
    }
    if (isset($context['work_vm_dir'])) {
        $params['dir'] = $context['work_vm_dir'];
    }
    $url = 'vms.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return portal_work_context_append_url(
        $url . ($focusVmId !== null && $focusVmId > 0 ? '#vm-' . $focusVmId : ''),
        array_diff_key(portal_work_context_mission_scope($context), array_flip(['work_vm_sort', 'work_vm_dir']))
    );
}

/**
 * Target of the editor's own forms that post to vms.php and come back to the
 * editor (hidden return marker set to editor). Unlike the list link it keeps the
 * overview origin, so the editor that vms.php redirects to still knows where to
 * return.
 *
 * @param array<string,string> $context
 */
function portal_work_context_vm_editor_action_url(int $missionId, array $context): string
{
    $context = portal_work_context($context);

    return portal_work_context_append_url(
        portal_work_context_vm_list_url($missionId, $context),
        array_intersect_key($context, array_flip(VIRTUSPHERE_WORK_CONTEXT_OVERVIEW_KEYS))
    );
}
