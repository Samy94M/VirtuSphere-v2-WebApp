<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/settings_actions.php';
require_once __DIR__ . '/../lib/settings_view_model.php';
require_once __DIR__ . '/../lib/system_status.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('system.config', $user)) {
    portal_forbid($connection, $user, 'system.config');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    settings_handle_post($connection, $user);
}

extract(settings_build_view_model($connection), EXTR_SKIP);
/** @var array<string,string> $settingsTabs */

layout_header(__t('settings.title'), $user, 'settings', 'settings');
?>
<div class="stack" data-tabs>
    <div class="tab-list" role="tablist" aria-label="<?php echo h(__t('settings.tabs_label')); ?>" data-tab-list hidden>
        <?php foreach ($settingsTabs as $tabKey => $tabLabel): ?>
            <button type="button" class="tab" id="tab-<?php echo h($tabKey); ?>" role="tab"
                    aria-controls="panel-<?php echo h($tabKey); ?>" aria-selected="false"
                    data-tab-target="panel-<?php echo h($tabKey); ?>"><?php echo h($tabLabel); ?></button>
        <?php endforeach; ?>
    </div>

    <?php require __DIR__ . '/../lib/settings/deploy_panel.php'; ?>
    <?php require __DIR__ . '/../lib/settings/machine_api_panel.php'; ?>
    <?php require __DIR__ . '/../lib/settings/catalog_panel.php'; ?>
    <?php require __DIR__ . '/../lib/settings/https_panel.php'; ?>
    <?php require __DIR__ . '/../lib/settings/system_panel.php'; ?>
</div>
<?php layout_footer(); ?>
