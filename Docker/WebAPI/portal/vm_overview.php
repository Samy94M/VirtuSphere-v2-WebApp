<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/vm_overview.php';
require_once __DIR__ . '/../lib/vm_overview_page.php';
require_once __DIR__ . '/../lib/vm_phase.php';
require_once __DIR__ . '/../lib/repo/vm_evidence.php';

/** @var mysqli $connection Provided by bootstrap.php. */

// The gate of the dashboard numbers this list opens: a signed-in user, before the
// first query. Read-only: nothing on this page writes, so it needs no CSRF token.
$user = portal_require_user($connection);

$filter = vm_overview_filter(request_string($_GET, 'filter', VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL));
$sort = vm_overview_sort(request_string($_GET, 'sort', VIRTUSPHERE_VM_OVERVIEW_SORT_DEFAULT));
$dir = vm_overview_dir(request_string($_GET, 'dir', 'asc'));
$page = vm_overview_page_number(request_string($_GET, 'page', '1'));
$snapshot = vm_overview_snapshot(request_string($_GET, 'counted', ''), request_string($_GET, 'expected', ''));

$listing = repo_vm_overview_page($connection, $filter, $sort, $dir, $page, VIRTUSPHERE_VM_OVERVIEW_PER_PAGE);

// The same derived phase and findings as the VM list, from one evidence batch
// for the page (lib/repo/vm_evidence.php); a template row is marked by its mission.
$vmEvidence = repo_vm_evidence($connection, $listing['rows']);
foreach ($listing['rows'] as &$row) {
    $evidence = repo_vm_evidence_for_vm($vmEvidence, $row, mission_name_is_template((string) $row['mission_name']));
    $row['phase'] = virtusphere_vm_phase($row, $evidence);
    $row['findings'] = virtusphere_vm_findings($row, $evidence);
}
unset($row);

layout_header(vm_overview_title($filter), $user, 'dashboard', 'overview', 'help-dashboard-counts');
?>
<div class="stack">
    <section class="panel">
        <p><?php echo h(vm_overview_intro($filter)); ?></p>
        <?php vm_overview_render_filter_nav($filter, $sort, $dir); ?>
        <div class="actions">
            <a class="button button-secondary" href="dashboard.php"><?php echo h(__t('vm_overview.back_dashboard')); ?></a>
            <span class="muted"><?php echo h(__t('vm_overview.hits', ['count' => $listing['total']])); ?></span>
        </div>
        <?php vm_overview_render_change_notice($snapshot, (int) $listing['total']); ?>
    </section>

    <section class="panel">
        <?php vm_overview_render_table($listing['rows'], $filter, $sort, $dir, $snapshot, (int) $listing['page']); ?>
        <?php vm_overview_render_pagination($listing, $filter, $sort, $dir, $snapshot); ?>
    </section>
</div>
<?php layout_footer(); ?>
