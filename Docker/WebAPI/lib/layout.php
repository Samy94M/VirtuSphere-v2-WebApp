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

// A double submit must not stack the same alert twice, and a queue that is
// never rendered (redirect chains) must not grow without bound.
const VIRTUSPHERE_FLASH_MAX = 6;

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Display-only timestamp formatter (SSoT). DB values are UTC (db() pins the
// session to +00:00); portal_format_datetime() converts them to the configured
// portal timezone (ADR-0022, lib/portal_time.php).
function portal_format_timestamp(?string $value): string
{
    return portal_format_datetime($value);
}

function portal_error_message(Throwable $exception): string
{
    if ($exception instanceof ValidationException) {
        return $exception->getMessage();
    }

    $message = $exception->getMessage();
    if (str_contains($message, 'APP_PUBLIC_BASE_URL') || str_contains($message, 'deploy_settings.api_base_url')) {
        return __t('settings.api_base_url_missing');
    }
    // Repo diagnostics an operator can hit without a crafted POST, i.e. by
    // clicking a button the portal renders for them. The RuntimeException texts
    // stay English (operator diagnostics, machine layer); only the portal
    // rendering is localized. Crafted-only conditions (mission not found,
    // template, credential type) fall through to the raw message on purpose.
    //
    // A reachable condition missing from this map renders raw English in the
    // German portal, so add the key here whenever a repo grows a guard that a
    // rendered button can trip.
    $operatorReachableErrors = [
        'Mission has no VMs to deploy.' => 'deploy.err_mission_no_vms',
        'This mission already has an active deploy job.' => 'deploy.err_active_job',
        'Mission datastore is required before deployment.' => 'deploy.err_datastore_required',
        'None of the selected VMs belong to this mission.' => 'deploy.err_selection_gone',
        // credentials.php renders Delete for every credential, including one an
        // active job holds, so this guard is one click away.
        'Credential is used by an active deploy job.' => 'credentials.err_in_use',
        // Two operators editing the same VM: the optimistic-locking guard in
        // repo_save_vm rejects the second save. Reachable by construction, so it
        // must speak the operator's language, not raw English.
        'VM was changed by another user. Reload before saving.' => 'vm_edit.err_conflict',
    ];
    if (isset($operatorReachableErrors[$message])) {
        return __t($operatorReachableErrors[$message]);
    }
    if ($exception instanceof mysqli_sql_exception) {
        if (str_contains($message, 'user_name_unique') || str_contains($message, 'deploy_users.user_name_unique')) {
            return __t('layout.err_user_name_taken');
        }
        if (str_contains($message, 'mission_name_unique')) {
            return __t('layout.err_mission_name_taken');
        }
        if (str_contains($message, 'Duplicate entry')) {
            return __t('layout.err_entry_exists');
        }

        return __t('layout.err_db_generic');
    }

    if ($message === '') {
        return __t('layout.err_action_failed');
    }

    return $message;
}

function role_label(string $role): string
{
    return match ($role) {
        VIRTUSPHERE_ROLE_ADMIN => __t('layout.role_admin'),
        VIRTUSPHERE_ROLE_USER => __t('layout.role_user'),
        default => $role,
    };
}
/**
 * Queues a flash for the next render. $detail carries operator diagnostics
 * (exception text, command output) that the alert shows behind a collapsed
 * details element; it is never the message itself.
 */
function flash_set(string $type, string $message, string $detail = '', ?array $action = null): void
{
    $safeAction = flash_action_normalize($action);
    $queue = $_SESSION['_flash'] ?? [];
    if (!is_array($queue)) {
        $queue = [];
    }

    foreach ($queue as $existing) {
        if (($existing['type'] ?? '') === $type
            && ($existing['message'] ?? '') === $message
            && ($existing['detail'] ?? '') === $detail
            && ($existing['action'] ?? null) === $safeAction
        ) {
            return;
        }
    }

    if (count($queue) >= VIRTUSPHERE_FLASH_MAX) {
        array_shift($queue);
    }

    $queue[] = ['type' => $type, 'message' => $message, 'detail' => $detail, 'action' => $safeAction];
    $_SESSION['_flash'] = $queue;
}

/**
 * Flash actions are structured data, never caller-provided HTML. Only a
 * relative portal URL is accepted.
 *
 * @return array{url:string,label:string}|null
 */
function flash_action_normalize(?array $action): ?array
{
    if ($action === null) {
        return null;
    }
    $url = trim((string) ($action['url'] ?? ''));
    $label = trim((string) ($action['label'] ?? ''));
    $hasWhitespace = str_contains($url, ' ') || str_contains($url, chr(9))
        || str_contains($url, chr(10)) || str_contains($url, chr(13));
    if ($url === '' || $label === '' || $hasWhitespace
        || str_contains($url, chr(92)) || str_starts_with($url, '//')
    ) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if (isset($parts['scheme']) || isset($parts['host'])
        || str_starts_with($path, '/') || str_contains($path, '..')
    ) {
        return null;
    }

    return ['url' => $url, 'label' => $label];
}

function flash_messages(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($messages) ? $messages : [];
}

/**
 * Single source of the alert markup, shared by the portal shell and the login
 * page. 'detail' is optional so flashes queued before a deploy still render.
 *
 * @param array{type?: string, message?: string, detail?: string, action?: array<string, mixed>} $flash
 */
function flash_alert_html(array $flash): string
{
    // data-flash separates a one-shot flash from the static .alert info boxes;
    // core.js uses it to counter-scroll the tab anchor jump only after a POST.
    $html = '<div class="alert alert-' . h((string) ($flash['type'] ?? 'info')) . '" data-flash>'
        . h((string) ($flash['message'] ?? ''));

    $detail = trim((string) ($flash['detail'] ?? ''));
    if ($detail !== '') {
        $html .= '<details class="alert-details">'
            . '<summary>' . h(__t('common.technical_details')) . '</summary>'
            . '<pre>' . h($detail) . '</pre>'
            . '</details>';
    }

    $action = flash_action_normalize(is_array($flash['action'] ?? null) ? $flash['action'] : null);
    if ($action !== null) {
        $quote = chr(34);
        $html .= '<div class=' . $quote . 'alert-actions' . $quote . '><a class='
            . $quote . 'button button-secondary' . $quote . ' href=' . $quote
            . h($action['url']) . $quote . '>' . h($action['label']) . '</a></div>';
    }

    return $html . '</div>';
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function portal_require_user(mysqli $connection, bool $allowPasswordChange = false): array
{
    $user = require_login($connection);
    if (!$allowPasswordChange && (int) ($user['must_change_password'] ?? 0) === 1) {
        redirect_to('account.php');
    }

    return $user;
}

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
                                . ' <a href="settings.php#panel-backup">' . h(__t('dashboard.backup_banner_link')) . '</a>'
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

// Single source for the badge markup. Callers pass a variant (the palette suffix
// success/warning/danger/info/neutral) and the already-resolved label; both are
// escaped here, so the label must be the raw text, not pre-escaped.
function portal_badge(string $variant, string $label): string
{
    return '<span class="badge badge-' . h($variant) . '">' . h($label) . '</span>';
}

function status_badge(string $legacyStatus): string
{
    $meta = virtusphere_status_meta($legacyStatus);

    return portal_badge((string) $meta['badge'], $legacyStatus);
}

function lifecycle_badge(string $lifecycleState): string
{
    $meta = virtusphere_lifecycle_meta($lifecycleState);

    return portal_badge((string) $meta['badge'], $lifecycleState);
}

function mecm_sync_badge(string $mecmSyncState): string
{
    $meta = virtusphere_mecm_sync_meta($mecmSyncState);

    return portal_badge((string) $meta['badge'], $mecmSyncState);
}

// Heartbeat/staleness badge (ADR-0018) with a localized, portal-authored label.
function heartbeat_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.status_ok'),
        'warning' => __t('system_status.status_warning'),
        'danger' => __t('system_status.status_danger'),
        'missing' => __t('system_status.status_missing'),
        default => __t('system_status.status_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/**
 * ESXi credential badge (ADR-0023): the same palette as the heartbeat badge, but
 * its own labels. A heartbeat's `warning` means "delayed", which is true of a
 * stale inventory pull and plainly false of a host that pulls perfectly and
 * simply has a licence that forbids writing. Sharing the colours is right;
 * sharing the words was not.
 */
function esxi_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.esxi_state_ok'),
        'warning' => __t('system_status.esxi_state_warning'),
        'danger' => __t('system_status.esxi_state_danger'),
        default => __t('system_status.esxi_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/**
 * Traffic-light state for an Ansible credential's last preflight test: 'ok',
 * 'danger' (last test failed) or 'unknown' (never tested). There is deliberately
 * no staleness axis: the preflight is on-demand, so age is shown as a timestamp
 * rather than folded into the colour (an old green is still the last known good).
 *
 * @param array<string, mixed>|null $state
 */
function ansible_preflight_ampel(?array $state): string
{
    if ($state === null || trim((string) ($state['last_status'] ?? '')) === '') {
        return 'unknown';
    }

    // Literal comparison like the 'ok' case: the value set is owned by
    // lib/repo/ansible_preflight.php, which layout deliberately does not load.
    return match ((string) $state['last_status']) {
        'ok' => 'ok',
        'warning' => 'warning',
        default => 'danger',
    };
}

/**
 * Badge for an already-derived Ansible state, so a caller holding the state
 * (the overview roll-up, the legend) does not have to fake a preflight row to
 * get its badge back.
 */
function ansible_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.ansible_state_ok'),
        'warning' => __t('system_status.ansible_state_warning'),
        'danger' => __t('system_status.ansible_state_danger'),
        default => __t('system_status.ansible_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/** @param array<string, mixed>|null $state */
function ansible_preflight_badge(?array $state): string
{
    return ansible_state_badge(ansible_preflight_ampel($state));
}

// Client deploy-phase badge (none|running|unconfirmed|finished|failed).
function client_phase_badge(string $phaseState): string
{
    $meta = virtusphere_client_phase_meta($phaseState);
    $label = match ($phaseState) {
        'running' => __t('vm_edit.phase_state_running'),
        'unconfirmed' => __t('vm_edit.phase_state_unconfirmed'),
        'finished' => __t('vm_edit.phase_state_finished'),
        'failed' => __t('vm_edit.phase_state_failed'),
        default => __t('vm_edit.phase_state_none'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

// Deploy-job status to badge variant. Lives here with the other badge helpers,
// not in the repo layer; the deploy_log.php JSON path hands the variant to the
// deploy.js poller, so the class must be derivable without rendering a span.
function deploy_job_status_badge_class(string $status): string
{
    return match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => 'success',
        VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => 'danger',
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING => 'info',
        // Same variant as the default, pinned on purpose: partial is a terminal
        // per-VM verdict, not a transient state that merely lacks a mapping.
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => 'warning',
        default => 'warning',
    };
}

// Catalog status badge (E3): free-text status column, so map case-insensitive
// active variants ('Aktiv'/'active') to success and 'Retired' to neutral.
function catalog_status_badge(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($status === VIRTUSPHERE_CATALOG_STATUS_RETIRED) {
        return portal_badge('neutral', __t('packages.status_retired'));
    }
    if (in_array($normalized, ['aktiv', 'active'], true)) {
        return portal_badge('success', __t('packages.status_active'));
    }

    return portal_badge('neutral', $status);
}

/**
 * Renders the shared catalog status-filter <select> form (os.php, packages.php).
 * Iterates VIRTUSPHERE_CATALOG_FILTERS so the option set stays in one place.
 * $labels carries the localized texts ('label', 'apply', and one per filter
 * token); the caller builds it with static __t() literals so the lang catalog
 * test still sees the keys. $hidden preserves query state (sort/dir) across the
 * GET submit.
 *
 * @param array<string,string> $labels
 * @param array<string,string> $hidden
 */
function portal_catalog_status_filter(string $action, string $current, array $labels, array $hidden = []): string
{
    $html = '<form class="actions" method="get" action="' . h($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    $html .= '<label class="filter-field">' . h($labels['label'] ?? '');
    $html .= '<select name="status">';
    foreach (VIRTUSPHERE_CATALOG_FILTERS as $token) {
        $selected = $current === $token ? ' selected' : '';
        $html .= '<option value="' . h($token) . '"' . $selected . '>' . h($labels[$token] ?? $token) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<button class="button button-secondary" type="submit">' . h($labels['apply'] ?? '') . '</button>';
    $html .= '</form>';

    return $html;
}

// Localized label for a client deploy phase (fixed set, full-literal keys so
// the lang catalog test can verify them).
function client_phase_label(string $phase): string
{
    return match ($phase) {
        VIRTUSPHERE_CLIENT_PHASE_GETINFO => __t('vm_edit.phase_getinfo'),
        VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => __t('vm_edit.phase_hostname'),
        VIRTUSPHERE_CLIENT_PHASE_STATICIP => __t('vm_edit.phase_staticip'),
        VIRTUSPHERE_CLIENT_PHASE_DISKS => __t('vm_edit.phase_disks'),
        default => $phase,
    };
}

// Localized label and action hint for an integration heartbeat source.
function integration_source_label(string $source): string
{
    return match ($source) {
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => __t('system_status.source_device_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => __t('system_status.source_packages_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => __t('system_status.source_autoimporter'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE => __t('system_status.source_mecm_server_probe'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => __t('system_status.source_maintenance_worker'),
        default => $source,
    };
}

function integration_action_hint(string $source): string
{
    return match ($source) {
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => __t('system_status.action_device_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => __t('system_status.action_packages_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => __t('system_status.action_autoimporter'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE => __t('system_status.action_mecm_server_probe'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => __t('system_status.action_maintenance_worker'),
        default => '',
    };
}

function portal_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds > 0 && $seconds % 3600 === 0) {
        return __t('common.duration_hours', ['count' => intdiv($seconds, 3600)]);
    }
    if ($seconds >= 60 && $seconds % 60 === 0) {
        return __t('common.duration_minutes', ['count' => intdiv($seconds, 60)]);
    }

    return __t('common.duration_seconds', ['count' => $seconds]);
}
