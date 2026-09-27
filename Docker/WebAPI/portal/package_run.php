<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/package_run_reads.php';
require_once __DIR__ . '/../lib/vm_urls.php';
require_once __DIR__ . '/../lib/package_report_filter.php';
require_once __DIR__ . '/../lib/package_report_page.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
$workContext = portal_work_context($_GET);
$missionId = request_int($_GET, 'mission_id');
$vmId = request_int($_GET, 'vm_id');
$mission = repo_get_mission($connection, $missionId);
if ($mission === null || mission_name_is_template((string) $mission['mission_name'])) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to(portal_work_context_mission_list_url($workContext));
}
$vm = $vmId > 0 ? repo_get_vm_bundle($connection, $vmId) : null;
if ($vm === null || (int) $vm['mission_id'] !== $missionId) {
    flash_set('error', __t('portal.vm_not_found'));
    redirect_to(portal_work_context_vm_list_url($missionId, $workContext));
}

$runId = isset($_GET['run_id']) && is_string($_GET['run_id']) ? $_GET['run_id'] : '';
$run = repo_package_run_for_vm($connection, $vmId, $runId);
$steps = $run !== null ? repo_package_steps_for_vm_run($connection, $vmId, (int) $run['id']) : [];
$dateFilter = package_report_filter($_GET);
$fromList = ($_GET['list'] ?? '') === '1';
$returnUrl = vm_edit_url($missionId, $vmId, 'package-reports', $workContext);
if ($fromList && $dateFilter['errors'] === []) {
    $listQuery = package_report_filter_query($dateFilter);
    $returnUrl = 'package_reports.php' . ($listQuery === '' ? '' : '?' . $listQuery);
}

layout_header(__t('package_report.detail_heading'), $user, 'missions', 'missions');
if ($run === null) {
    ?>
    <section class="panel">
        <p><a href="<?php echo h($returnUrl); ?>"><?php echo h(__t($fromList && $dateFilter['errors'] === []
            ? 'package_report.back_list' : 'package_report.back_vm')); ?></a></p>
        <h1><?php echo h(__t('package_report.not_available')); ?></h1>
    </section>
    <?php
} else {
    package_report_render_detail($run, $steps, $returnUrl,
        package_report_detail_url($missionId, $vmId, (string) $run['run_id']), $missionId, $vmId,
        (string) $vm['vm_name'],
        $dateFilter, $workContext, $fromList && $dateFilter['errors'] === [] ? $dateFilter : []);
}
layout_footer();
