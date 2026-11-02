<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/vm_history.php';
require_once __DIR__ . '/../lib/vm_urls.php';
require_once __DIR__ . '/../lib/deploy_urls.php';
require_once __DIR__ . '/../lib/package_report_page.php';
require_once __DIR__ . '/../lib/vm_history_page.php';

/**
 * The VM chronicle (SP3, F03): every stored event of one VM with source and day
 * filters and keyset paging. Opened by anyone who may open the VM editor; the
 * change log (with bulk changes) only with users.manage, decided before any read.
 */

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
$workContext = portal_work_context($_GET);
$missionId = request_int($_GET, 'mission_id');
$vmId = request_int($_GET, 'vm_id');
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to(portal_work_context_mission_list_url($workContext));
}
$vm = $vmId > 0 ? repo_get_vm_bundle($connection, $vmId) : null;
if ($vm === null || (int) $vm['mission_id'] !== $missionId) {
    flash_set('error', __t('portal.vm_not_found'));
    redirect_to(portal_work_context_vm_list_url($missionId, $workContext));
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);

// F2: the change log is a permission of its own, decided before any query or count.
$canAudit = can('users.manage', $user);
$permitted = virtusphere_vm_history_filter([], $canAudit, portal_timezone())['sources'];
$filter = virtusphere_vm_history_filter($_GET, $canAudit, portal_timezone());
$allSelected = $filter['sources'] === $permitted;
$linkFilter = ['sources' => $allSelected ? [] : $filter['sources'], 'from' => $filter['from'], 'to' => $filter['to']];
$cursorText = is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '';
$cursor = $cursorText === '' ? null : virtusphere_vm_history_cursor_decode($cursorText);
$cursorInvalid = $cursorText !== '' && $cursor === null;
$page = repo_vm_history_page($connection, $vmId, $filter['sources'], $filter['from_utc'], $filter['to_utc'], $cursor);

$vmName = trim((string) ($vm['vm_name'] ?? ''));
layout_header(__t('vm_history.title') . ($vmName !== '' ? ': ' . $vmName : ''), $user, $isTemplate ? 'templates' : 'missions', 'missions');
?>
<section class="panel" aria-labelledby="vm-history-filter-heading">
    <p><a href="<?php echo h(vm_edit_url($missionId, $vmId, null, $workContext)); ?>">&laquo; <?php echo h(__t('vm_history.back')); ?></a></p>
    <p class="muted"><?php echo h(__t('vm_history.intro')); ?></p>
    <h2 id="vm-history-filter-heading"><?php echo h(__t('vm_history.filter_heading')); ?></h2>
    <?php vm_history_render_filter($filter, $permitted, $missionId, $vmId, $workContext, $allSelected); ?>
</section>
<section class="panel" aria-labelledby="vm-history-entries-heading">
    <h2 id="vm-history-entries-heading"><?php echo h(__t('vm_timeline.heading')); ?></h2>
    <?php if ($cursorInvalid) { ?>
        <p class="alert alert-warning" role="status"><?php echo h(__t('vm_history.cursor_invalid')); ?></p>
    <?php } ?>
    <?php vm_history_render_entries($page, $missionId, $vmId, $workContext, $linkFilter, $cursor !== null, can('deploy.run', $user)); ?>
</section>
<?php
vm_history_render_retention($filter['sources']);
layout_footer();
