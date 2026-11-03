<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/deploy_actions.php';
require_once __DIR__ . '/../lib/deploy_display.php';
require_once __DIR__ . '/../lib/deploy_form_state.php';
require_once __DIR__ . '/../lib/deploy_storage.php';
require_once __DIR__ . '/../lib/deploy_urls.php';
require_once __DIR__ . '/../lib/deploy_view_model.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('deploy.run', $user)) {
    portal_forbid($connection, $user, 'deploy.run');
}

$selectedMissionId = (int) deploy_form_value('mission_id', '0');
$redirectBase = $selectedMissionId > 0 ? deploy_mission_url($selectedMissionId) : 'deploy.php';
$deployPreview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    $deployPreview = deploy_handle_post($connection, $user, $selectedMissionId);
}

extract(deploy_build_view_model($connection, $selectedMissionId), EXTR_SKIP);

layout_header(__t('deploy.title'), $user, 'deploy', 'deploy');
?>
<div class="stack">
    <?php require __DIR__ . '/../lib/deploy_queue_panel.php'; ?>
    <?php require __DIR__ . '/../lib/deploy_jobs_panel.php'; ?>
</div>
<?php layout_footer(); ?>
