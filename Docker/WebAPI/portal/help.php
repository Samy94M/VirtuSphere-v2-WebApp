<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
// The integrations help panel interpolates the ESXi traffic-light thresholds
// (ADR-0023); without this require the constants are undefined here and PHP
// fatals mid-render, truncating every panel after it. The same panel renders the
// cause table from connection_error_message(), the SSoT for that wording.
require_once __DIR__ . '/../lib/deploy_constants.php';
require_once __DIR__ . '/../lib/connection_errors.php';
// The users panel quotes the configured password minimum, not a hardcoded one.
require_once __DIR__ . '/../lib/password_policy.php';
// The missions panel quotes how long an import preview stays valid; that bound
// lives next to the importer, not in constants.php.
require_once __DIR__ . '/../lib/mission_transfer.php';

$user = portal_require_user($connection);

$canConfig = can('system.config', $user);
$canUsers = can('users.manage', $user);

// Reihenfolge = Arbeitsablauf: kennenlernen -> planen -> bereitstellen ->
// Fehler suchen -> verstehen; Verwaltungsthemen (Rollen, Einstellungen) am Ende.
// Die Panel-Inhalte liegen als Partials unter lib/help/ (nicht direkt aufrufbar,
// nginx sperrt /lib/); help.php ist nur noch die Tab-Hülle (ADR-0006).
$helpTabs = [
    'overview' => __t('help.tab_overview'),
    'missions' => __t('help.tab_missions'),
    'packages' => __t('help.tab_packages'),
    'deploy' => __t('help.tab_deploy'),
    'integrations' => __t('help.tab_integrations'),
    'stack' => __t('help.tab_stack'),
    'users' => __t('help.tab_users'),
];
if ($canConfig) {
    $helpTabs['settings'] = __t('help.tab_settings');
}

layout_header(__t('help.title'), $user, 'help');
?>
<div class="stack" data-tabs>
    <div class="tab-list" role="tablist" aria-label="<?php echo h(__t('help.tabs_label')); ?>" data-tab-list hidden>
        <?php foreach ($helpTabs as $tabKey => $tabLabel): ?>
            <button type="button" class="tab" id="tab-<?php echo h($tabKey); ?>" role="tab"
                    aria-controls="panel-<?php echo h($tabKey); ?>" aria-selected="false"
                    data-tab-target="panel-<?php echo h($tabKey); ?>"><?php echo h($tabLabel); ?></button>
        <?php endforeach; ?>
    </div>

    <?php
    require __DIR__ . '/../lib/help/overview.php';
    require __DIR__ . '/../lib/help/missions.php';
    require __DIR__ . '/../lib/help/deploy.php';
    require __DIR__ . '/../lib/help/packages.php';
    require __DIR__ . '/../lib/help/integrations.php';
    require __DIR__ . '/../lib/help/users.php';
    if ($canConfig) {
        require __DIR__ . '/../lib/help/settings.php';
    }
    require __DIR__ . '/../lib/help/stack.php';
    ?>
</div>
<?php layout_footer(); ?>
