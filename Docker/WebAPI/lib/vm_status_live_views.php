<?php

declare(strict_types=1);

/**
 * What the live status poll reads per view (VB2). Each view takes its cells or
 * regions from the renderer its page uses: vm_live_cells() for the two tables,
 * vm_edit_live_regions() for the editor, vm_overview_live_tiles() for the
 * dashboard. Evidence is read per batch through lib/repo/vm_evidence.php, never
 * per row. Nothing here writes, and nothing reads the session: the endpoint has
 * released it and passes the decided permissions in.
 *
 * The requested ids are resolved against what the reader may read. The VM pages
 * show every VM to every signed-in user, so that is "the VM exists" plus the
 * scope of the view (a mission's VMs for its list). An id outside it is left out
 * without a word. A template has no status that moves and yields no poll content.
 */

require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/vm_phase_cells.php';
require_once __DIR__ . '/vm_status_live.php';
require_once __DIR__ . '/vm_edit_live.php';
require_once __DIR__ . '/vm_overview_page.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/vms_overview.php';
require_once __DIR__ . '/repo/vm_evidence.php';
require_once __DIR__ . '/repo/status_events.php';

/**
 * @param array{view:string,ids:list<int>,count:int,mission_id:int,vm_id:int,filter:string,sort:string,dir:string,page:int,work_context:array<string,string>} $request from vm_live_request()
 * @param array{can_write:bool,can_system_config:bool} $access
 * @return array{rows?:array<int,array<string,array{sig:string,html:string}>>,regions?:list<array{key:string,sig:string,html:string}>,list_changed?:bool,notice?:string}
 */
function vm_live_payload(mysqli $db, array $request, array $access): array
{
    return match ($request['view']) {
        VIRTUSPHERE_VM_STATUS_LIVE_VIEW_MISSION => vm_live_mission_payload($db, $request),
        VIRTUSPHERE_VM_STATUS_LIVE_VIEW_OVERVIEW => vm_live_overview_payload($db, $request),
        VIRTUSPHERE_VM_STATUS_LIVE_VIEW_EDITOR => vm_live_editor_payload($db, $request, $access),
        VIRTUSPHERE_VM_STATUS_LIVE_VIEW_DASHBOARD => vm_live_dashboard_payload($db),
        default => throw new InvalidArgumentException('Unknown live view.'),
    };
}

/**
 * The cells of the given stored rows, keyed by VM id, from one evidence batch.
 * Template rows are left out.
 *
 * @param list<array<string,mixed>> $vms rows of repo_vm_overview_rows_by_ids()
 * @param array<string,mixed> $workContext the context the page's links carry
 * @param array<int,int> $phaseRanks filled with VM id => phase rank, the sort key of the status column
 * @param-out array<int,int> $phaseRanks
 * @return array<int,array<string,array{sig:string,html:string}>>
 */
function vm_live_rows(mysqli $db, array $vms, array $workContext, array &$phaseRanks = []): array
{
    $phaseRanks = [];
    $vms = array_values(array_filter(
        $vms,
        static fn (array $vm): bool => !mission_name_is_template((string) ($vm['mission_name'] ?? ''))
    ));
    $evidence = repo_vm_evidence($db, $vms);
    $rows = [];
    foreach ($vms as $vm) {
        $slice = repo_vm_evidence_for_vm($evidence, $vm, false);
        $phase = virtusphere_vm_phase($vm, $slice);
        $phaseRanks[(int) $vm['id']] = virtusphere_vm_phase_rank($phase);
        $rows[(int) $vm['id']] = vm_live_row(vm_live_cells(
            $vm,
            $phase,
            virtusphere_vm_findings($vm, $slice),
            (int) $vm['mission_id'],
            $workContext
        ));
    }

    return $rows;
}

/**
 * Whether the rows of the VM list, in the order the page shows them, are still
 * sorted by its status column. Only that column moves through a state change;
 * the other sort columns change through an edit, which reloads the page. The
 * sort travels in the work context (portal_work_context_with_vm_list()).
 *
 * @param list<int> $ids the requested ids in page order
 * @param array<int,int> $phaseRanks VM id => phase rank (vm_live_rows())
 * @param array<string,mixed> $workContext
 */
function vm_live_status_order_holds(array $ids, array $phaseRanks, array $workContext): bool
{
    if (($workContext['work_vm_sort'] ?? '') !== 'status') {
        return true;
    }
    $descending = ($workContext['work_vm_dir'] ?? 'asc') === 'desc';
    $previous = null;
    foreach ($ids as $id) {
        if (!isset($phaseRanks[$id])) {
            continue;
        }
        $rank = $phaseRanks[$id];
        if ($previous !== null && ($descending ? $rank > $previous : $rank < $previous)) {
            return false;
        }
        $previous = $rank;
    }

    return true;
}

/** The "list has changed" notice with the link that shows the current list. */
function vm_live_list_changed_notice(string $pageUrl): string
{
    return vm_live_notice_html(__t('vm_live.list_changed'), $pageUrl, __t('vm_live.reload'));
}

/**
 * The VM list of a mission. The list is not paged, so the membership is the
 * mission's VMs: it changed when the page shows another number of rows or a
 * requested VM is no longer in the mission. A list sorted by status has also
 * changed when its rows are no longer in that order.
 *
 * @param array<string,mixed> $request
 * @return array{rows:array<int,array<string,array{sig:string,html:string}>>,list_changed:bool,notice:string}
 */
function vm_live_mission_payload(mysqli $db, array $request): array
{
    $missionId = (int) $request['mission_id'];
    $workContext = (array) $request['work_context'];
    $mission = repo_get_mission($db, $missionId);
    if ($mission === null) {
        // The page itself would leave for the mission list; the notice leads there.
        return [
            'rows' => [],
            'list_changed' => true,
            'notice' => vm_live_list_changed_notice(portal_work_context_mission_list_url($workContext)),
        ];
    }
    if (mission_name_is_template((string) $mission['mission_name'])) {
        return ['rows' => [], 'list_changed' => false, 'notice' => ''];
    }

    $memberIds = repo_vm_overview_mission_vm_ids($db, $missionId);
    $requested = array_values(array_intersect($request['ids'], $memberIds));
    $phaseRanks = [];
    $rows = vm_live_rows($db, repo_vm_overview_rows_by_ids($db, $requested), $workContext, $phaseRanks);
    $changed = count($memberIds) !== (int) $request['count']
        || count($requested) !== count($request['ids'])
        || !vm_live_status_order_holds($request['ids'], $phaseRanks, $workContext);

    return [
        'rows' => $rows,
        'list_changed' => $changed,
        'notice' => $changed
            ? vm_live_list_changed_notice(portal_work_context_vm_list_url($missionId, $workContext))
            : '',
    ];
}

/**
 * One page of the VM overview. The membership is that page of the filter in its
 * order, read through the list's own reader: it changed when the page would now
 * show other VMs or another order. A VM that left the filter still gets its
 * cells, so the row never shows a status that is no longer true.
 *
 * @param array<string,mixed> $request
 * @return array{rows:array<int,array<string,array{sig:string,html:string}>>,list_changed:bool,notice:string}
 */
function vm_live_overview_payload(mysqli $db, array $request): array
{
    $filter = (string) $request['filter'];
    $sort = (string) $request['sort'];
    $dir = (string) $request['dir'];
    $listing = repo_vm_overview_page($db, $filter, $sort, $dir, (int) $request['page'], VIRTUSPHERE_VM_OVERVIEW_PER_PAGE);
    $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $listing['rows']);
    $changed = $currentIds !== $request['ids'];

    return [
        'rows' => vm_live_rows(
            $db,
            repo_vm_overview_rows_by_ids($db, $request['ids']),
            vm_overview_editor_context($filter, $sort, $dir, (int) $request['page'])
        ),
        'list_changed' => $changed,
        'notice' => $changed
            ? vm_live_list_changed_notice(vm_overview_url($filter, ['sort' => $sort, 'dir' => $dir, 'page' => (int) $request['page']]))
            : '',
    ];
}

/**
 * The two live regions of the VM editor. A VM that is gone, moved to another
 * mission or belongs to a template has none; the notice says so for the first
 * two and leads back to the VM list.
 *
 * @param array<string,mixed> $request
 * @param array{can_write:bool,can_system_config:bool} $access
 * @return array{regions:list<array{key:string,sig:string,html:string}>,list_changed:bool,notice:string}
 */
function vm_live_editor_payload(mysqli $db, array $request, array $access): array
{
    $missionId = (int) $request['mission_id'];
    $vmId = (int) $request['vm_id'];
    $workContext = (array) $request['work_context'];
    $vm = repo_get_vm_bundle($db, $vmId);
    if ($vm === null || (int) $vm['mission_id'] !== $missionId) {
        $mission = repo_get_mission($db, $missionId);

        return [
            'regions' => [],
            'list_changed' => true,
            'notice' => vm_live_notice_html(
                __t('vm_live.vm_gone'),
                $mission === null
                    ? portal_work_context_mission_list_url($workContext)
                    : portal_work_context_vm_list_url($missionId, $workContext),
                __t($mission === null ? 'vm_live.open_missions' : 'vm_edit.back_to_vms')
            ),
        ];
    }
    if (mission_name_is_template((string) $vm['mission_name'])) {
        return ['regions' => [], 'list_changed' => false, 'notice' => ''];
    }

    return [
        'regions' => vm_edit_live_regions(
            $vm,
            vm_edit_status_view($db, $vm),
            vm_edit_progress_view($vm, repo_vm_status_events($db, $vmId)),
            $missionId,
            $workContext,
            $access,
            vm_edit_live_csrf_placeholder()
        ),
        'list_changed' => false,
        'notice' => '',
    ];
}

/**
 * The dashboard's VM tiles, counted like the dashboard counts them.
 *
 * @return array{regions:list<array{key:string,sig:string,html:string}>}
 */
function vm_live_dashboard_payload(mysqli $db): array
{
    return [
        'regions' => vm_overview_live_tiles(
            repo_vm_overview_count($db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL),
            repo_vm_overview_count($db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING),
            repo_vm_overview_attention_counts($db),
            time()
        ),
    ];
}
