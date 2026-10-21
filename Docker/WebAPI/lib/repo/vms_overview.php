<?php

declare(strict_types=1);

/**
 * Read model of the dashboard's VM tiles and of the list they open
 * (portal/vm_overview.php).
 *
 * Each filter has exactly one predicate, and the tile count, the list total and
 * the list rows all call it, so a tile and the list it opens answer the same
 * question. Only the mission is joined: interfaces, disks and packages stay out
 * of the query, so a VM that carries several of them is still one row.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../portal_sort_sql.php';
require_once __DIR__ . '/../vm_phase.php';

const VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL = 'all';
const VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING = 'mecm_pending';
const VIRTUSPHERE_VM_OVERVIEW_FILTERS = [
    VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL,
    VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING,
];
const VIRTUSPHERE_VM_OVERVIEW_PER_PAGE = 50;

/**
 * The WHERE clause of one filter as [sql, types, params] over the aliases
 * v (deploy_vms) and m (deploy_missions).
 *
 * `all` is the VM tile: real missions only, because a template's VMs are the
 * blueprint a clone copies. `mecm_pending` is the MECM takeover queue with its
 * meaning unchanged (a queued transfer or a pending sync). It keeps template
 * rows, which the list marks, so the number and the list cannot disagree.
 *
 * @return array{0:string,1:string,2:list<string>}
 */
function repo_vm_overview_predicate(string $filter): array
{
    return match ($filter) {
        VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL => ['LEFT(m.mission_name, 1) <> ?', 's', [VIRTUSPHERE_TEMPLATE_PREFIX]],
        VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING => ['(v.updated = 1 OR v.mecm_sync_state = ?)', 's', [VIRTUSPHERE_MECM_SYNC_PENDING]],
        default => throw new InvalidArgumentException('Unknown VM overview filter.'),
    };
}

function repo_vm_overview_count(mysqli $db, string $filter): int
{
    [$where, $types, $params] = repo_vm_overview_predicate($filter);

    return (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id WHERE ' . $where,
        $types,
        $params
    );
}

/**
 * Sort key => ORDER BY expressions. The first expression follows the requested
 * direction and the others ascend. Every list ends with the unique VM id, so a
 * LIMIT window cuts one global order. The order is the database collation, not
 * the PHP natural order of portal_sort_apply(), and it is the same on every page.
 *
 * @return array<string,list<string>>
 */
function repo_vm_overview_sort_columns(): array
{
    return [
        'mission' => ['m.mission_name', 'v.vm_name', 'v.id'],
        'name' => ['v.vm_name', 'm.mission_name', 'v.id'],
        'hostname' => ['v.vm_hostname', 'm.mission_name', 'v.vm_name', 'v.id'],
        // The SQL twin of the PHP phase (virtusphere_vm_phase), so the column sorts
        // like it shows. The finding is not sortable: it needs several sources.
        'status' => [virtusphere_vm_phase_sql('v'), 'm.mission_name', 'v.vm_name', 'v.id'],
        'mecm' => ['v.mecm_sync_state', 'm.mission_name', 'v.vm_name', 'v.id'],
    ];
}

/**
 * One page of the list and its total, both from the same predicate. A page past
 * the end is clamped to the last one, so a list that shrank after its tile was
 * counted still shows its rows instead of an empty window.
 *
 * @return array{total:int,pages:int,page:int,rows:list<array<string,mixed>>}
 */
function repo_vm_overview_page(mysqli $db, string $filter, string $sort, string $dir, int $page, int $perPage = VIRTUSPHERE_VM_OVERVIEW_PER_PAGE): array
{
    [$where, $types, $params] = repo_vm_overview_predicate($filter);
    $perPage = max(1, $perPage);
    $total = repo_vm_overview_count($db, $filter);
    $pages = max(1, intdiv($total + $perPage - 1, $perPage));
    $page = max(1, min($page, $pages));
    if ($total === 0) {
        return ['total' => 0, 'pages' => 1, 'page' => 1, 'rows' => []];
    }

    $order = portal_sort_sql_order(repo_vm_overview_sort_columns(), $sort, $dir, 'mission');
    $stmt = $db->prepare(
        // The columns beyond the list's own are the evidence of the phase and of the findings.
        'SELECT v.id, v.mission_id, v.vm_name, v.vm_hostname, v.vm_status, v.mecm_sync_state, v.updated, m.mission_name,'
        . ' v.lifecycle_state, v.vm_instance_uuid, v.vm_bound_credential_id, v.vm_bound_esxi_host, v.mecm_previous_id,'
        . ' v.mecm_pending_since, v.os_install_watch_started_at'
        . ' FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id'
        . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?'
    );
    $args = array_merge($params, [$perPage, ($page - 1) * $perPage]);
    $stmt->bind_param($types . 'ii', ...$args);
    $stmt->execute();

    return [
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'rows' => repo_fetch_all($stmt->get_result()),
    ];
}
