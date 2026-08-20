<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/portal_sort.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/layout_modals.php';
require_once __DIR__ . '/settings_page.php';

// A double submit must not stack the same alert twice, and a queue that is
// never rendered (redirect chains) must not grow without bound.
const VIRTUSPHERE_FLASH_MAX = 6;

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/layout_response.php';
require_once __DIR__ . '/layout_presenters.php';

function layout_nav_item(string $key, string $label, string $href, string $active): void
{
    $class = $key === $active ? ' class="active" aria-current="page"' : '';
    echo '<a' . $class . ' href="' . h($href) . '">' . h($label) . '</a>';
}

function layout_asset_url(string $path): string
{
    $relativePath = ltrim($path, '/');
    $fullPath = dirname(__DIR__) . '/portal/' . $relativePath;
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : '1';

    return $relativePath . '?v=' . rawurlencode($version);
}

/**
 * The portal's client scripts, in load order: core (theme, modals, tabs,
 * session) then forms then deploy. Each is an independent IIFE; `defer`
 * preserves this order. Emitted from one place so the head of layout.php and
 * login.php cannot drift apart. Every tag carries the CSP nonce.
 */
function layout_app_scripts(string $nonce): void
{
    foreach (['assets/core.js', 'assets/forms.js', 'assets/deploy.js'] as $script) {
        echo '<script defer nonce="' . h($nonce) . '" src="' . h(layout_asset_url($script)) . '"></script>' . "\n";
    }
}

function layout_header(string $title, array $user, string $active = 'dashboard', ?string $helpAnchor = null): void
{
    $nonce = h(virtusphere_csp_nonce());
    $displayUser = trim((string) ($user['name'] ?? ''));
    $accountLabel = $displayUser !== '' ? $displayUser : __t('layout.account');
    $accountInitial = strtoupper(substr($accountLabel, 0, 1));
    $role = (string) ($user['role'] ?? VIRTUSPHERE_ROLE_USER);
    ?>
<!DOCTYPE html>
<html lang="<?php echo h(Lang::locale()); ?>" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?> - VirtuSphere</title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo h(layout_asset_url('assets/img/logo-64.png')); ?>">
    <link rel="stylesheet" href="<?php echo h(layout_asset_url('assets/css/base.css')); ?>">
    <link rel="stylesheet" href="<?php echo h(layout_asset_url('assets/css/layout.css')); ?>">
    <link rel="stylesheet" href="<?php echo h(layout_asset_url('assets/css/components.css')); ?>">
    <?php // status.css owns the System status page's inner rhythm and must load
          // after components.css: several of its rules are specificity-equal with
          // their counterparts there and win only on position
          // (StatusSpacingContractTest). ?>
    <link rel="stylesheet" href="<?php echo h(layout_asset_url('assets/css/status.css')); ?>">
    <script nonce="<?php echo $nonce; ?>">
        try {
            var theme = localStorage.getItem('virtusphere.theme');
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.dataset.theme = theme;
            }
        } catch (error) {}
    </script>
    <?php layout_app_scripts($nonce); ?>
</head>
<body>
<a class="skip-link" href="#main"><?php echo h(__t('layout.skip_to_content')); ?></a>
<div class="app-shell">
    <aside class="sidebar" aria-label="<?php echo h(__t('layout.nav_primary_label')); ?>">
        <div class="brand">
            <img class="brand-mark" src="<?php echo h(layout_asset_url('assets/img/logo-64.png')); ?>" srcset="<?php echo h(layout_asset_url('assets/img/logo-64.png')); ?> 1x, <?php echo h(layout_asset_url('assets/img/logo-64@2x.png')); ?> 2x" width="48" height="48" alt="">
            <span>VirtuSphere</span>
            <button class="button button-ghost nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-label="<?php echo h(__t('layout.nav_toggle')); ?>">
                <span aria-hidden="true">&#9776;</span>
            </button>
        </div>
        <nav class="nav-group">
            <p class="nav-group-title"><?php echo h(__t('layout.nav_group_operations')); ?></p>
            <?php layout_nav_item('dashboard', __t('layout.nav_dashboard'), 'dashboard.php', $active); ?>
            <?php layout_nav_item('missions', __t('layout.nav_missions'), 'missions.php?type=missions', $active); ?>
            <?php layout_nav_item('templates', __t('layout.nav_templates'), 'missions.php?type=templates', $active); ?>
            <?php if (can('deploy.run', $user)) { ?>
                <?php layout_nav_item('deploy', __t('layout.nav_deploy'), 'deploy.php', $active); ?>
            <?php } ?>
            <?php layout_nav_item('system-status', __t('layout.nav_system_status'), 'system_status.php', $active); ?>
            <?php layout_nav_item('help', __t('layout.nav_help'), 'help.php', $active); ?>
        </nav>
        <?php if (can('catalog.write', $user)) { ?>
            <nav class="nav-group">
                <p class="nav-group-title"><?php echo h(__t('layout.nav_group_catalog')); ?></p>
                <?php layout_nav_item('os', __t('layout.nav_os'), 'os.php', $active); ?>
                <?php layout_nav_item('vlans', __t('layout.nav_vlans'), 'vlans.php', $active); ?>
                <?php layout_nav_item('packages', __t('layout.nav_packages'), 'packages.php', $active); ?>
            </nav>
        <?php } ?>
        <?php if (can('credentials.manage', $user) || can('system.config', $user) || can('users.manage', $user)) { ?>
            <nav class="nav-group nav-admin">
                <p class="nav-group-title"><?php echo h(__t('layout.nav_group_admin')); ?></p>
                <?php if (can('credentials.manage', $user)) { ?>
                    <?php layout_nav_item('credentials', __t('layout.nav_credentials'), 'credentials.php', $active); ?>
                <?php } ?>
                <?php if (can('system.config', $user)) { ?>
                    <?php layout_nav_item('settings', __t('layout.nav_settings'), 'settings.php', $active); ?>
                <?php } ?>
                <?php if (can('users.manage', $user)) { ?>
                    <?php layout_nav_item('users', __t('layout.nav_users'), 'users.php', $active); ?>
                    <?php layout_nav_item('logs', __t('layout.nav_logs'), 'logs.php', $active); ?>
                <?php } ?>
            </nav>
        <?php } ?>
        <div class="session-timer" data-session-timer
             data-expires-in="<?php echo (int) session_remaining_seconds(); ?>"
             data-warn-at="<?php echo VIRTUSPHERE_SESSION_WARN_SECONDS; ?>">
            <div class="session-timer-head">
                <span class="session-timer-title"><?php echo h(__t('layout.session_title')); ?></span>
                <span class="session-timer-clock" data-session-clock aria-live="polite">--:--</span>
            </div>
            <button class="button button-ghost session-timer-extend" type="button" data-session-extend><?php echo h(__t('layout.session_extend')); ?></button>
            <?php echo csrf_field(); ?>
        </div>
    </aside>
    <div class="app-main">
        <header class="topbar">
            <div>
                <p class="eyebrow"><?php echo h(role_label($role)); ?></p>
                <div class="page-title-row">
                    <h1><?php echo h($title); ?></h1>
                    <?php if ($helpAnchor !== null) { ?>
                        <a class="page-help-link" href="help.php#panel-<?php echo h($helpAnchor); ?>" title="<?php echo h(__t('layout.help_page_title')); ?>">
                            <span class="page-help-mark" aria-hidden="true">?</span><span><?php echo h(__t('layout.help_page_link')); ?></span>
                        </a>
                    <?php } ?>
                </div>
            </div>
            <div class="topbar-actions">
                <a class="account-link" href="account.php" title="<?php echo h(__t('layout.account')); ?>" aria-label="<?php echo h(__t('layout.account') . ': ' . $accountLabel); ?>">
                    <span class="account-avatar" aria-hidden="true"><?php echo h($accountInitial); ?></span>
                    <span class="account-name"><?php echo h($accountLabel); ?></span>
                </a>
                <button class="button button-ghost theme-toggle" type="button" data-theme-toggle title="<?php echo h(__t('layout.theme_title')); ?>">
                    <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
                    <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9789;</span>
                    <span class="sr-only"><?php echo h(__t('layout.theme')); ?></span>
                </button>
                <form method="post" action="logout.php">
                    <?php echo csrf_field(); ?>
                    <button class="button button-ghost" type="submit"><?php echo h(__t('layout.logout')); ?></button>
                </form>
            </div>
        </header>
        <main class="content" id="main" tabindex="-1">
            <?php foreach (flash_messages() as $message) {
                echo flash_alert_html(is_array($message) ? $message : []);
            } ?>
            <?php
            // Backup health banner (Paket A / ADR-0021): dashboard only, admins
            // only, and only when a run is not healthy. Fail-soft: a broken
            // reader must never take down the page.
            if ($active === 'dashboard' && can('system.config', $user)) {
                try {
                    $backupState = backup_status_read()['state'];
                    if ($backupState !== VIRTUSPHERE_BACKUP_STATE_OK) {
                        $backupMessage = backup_status_message($backupState);
                        if ($backupMessage !== '') {
                            echo '<div class="alert ' . h(backup_status_alert_class($backupState)) . '">'
                                . '<strong>' . h(__t('dashboard.backup_banner_title')) . '</strong> '
                                . h($backupMessage)
                                . ' <a href="' . h(settings_url('backup')) . '">' . h(__t('dashboard.backup_banner_link')) . '</a>'
                                . '</div>';
                        }
                    }
                } catch (Throwable $backupBannerError) {
                    error_log('[backup-banner] ' . $backupBannerError::class . ': ' . $backupBannerError->getMessage());
                }
            }
            ?>
    <?php
}

function layout_footer(): void
{
    ?>
        </main>
    </div>
</div>
    <?php
    // Every portal page renders both modals (lib/layout_modals.php). Dropping
    // layout_confirm_dialog() would leave every [data-confirm] button submitting
    // without a prompt, silently: PortalConfirmContractTest pins both calls.
    layout_session_modal();
    layout_confirm_dialog();
    ?>
</body>
</html>
    <?php
}
