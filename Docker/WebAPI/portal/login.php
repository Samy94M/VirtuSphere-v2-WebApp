<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

/** @var mysqli $connection Provided by bootstrap.php. */

if (current_user($connection) !== null) {
    redirect_to(!empty($_SESSION['must_change_password']) ? 'account.php' : 'dashboard.php');
}

$error = '';
$username = '';
$directoryEnabled = directory_is_enabled($connection);
$directoryLoginAvailable = $directoryEnabled && virtusphere_is_request_secure();
$source = $directoryLoginAvailable
    ? VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY
    : VIRTUSPHERE_AUTH_SOURCE_LOCAL;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        // Not portal_reject_csrf(): a stale token on the sign-in form is a normal
        // consequence of a page left open, and the user is sent back to a fresh
        // form rather than a 400. Still worth a line, because a flood of these
        // without any matching sign-in is not a user leaving a tab open.
        audit_auth($connection, 'csrf token rejected on login.php');
        flash_set('error', __t('login.session_expired'));
        redirect_to('login.php');
    }

    $username = request_trimmed($_POST, 'username');
    $source = request_string($_POST, 'auth_source', VIRTUSPHERE_AUTH_SOURCE_LOCAL);
    $result = login($username, request_string($_POST, 'password'), $connection, $source);
    if ($result['ok'] ?? false) {
        redirect_to(!empty($result['must_change_password']) ? 'account.php' : 'dashboard.php');
    }

    $error = match ($result['reason'] ?? '') {
        'locked' => __t('login.error_locked'),
        'ip_locked' => __t('login.error_ip_locked'),
        'rate_limited' => __t('login.error_rate_limited'),
        'directory_unavailable' => __t('login.error_directory_unavailable'),
        default => __t('login.error_invalid'),
    };
}
$nonce = h(virtusphere_csp_nonce());
?>
<!DOCTYPE html>
<html lang="<?php echo h(Lang::locale()); ?>" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(__t('login.page_title')); ?></title>
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
<body class="login-page">
    <button class="button button-ghost theme-toggle login-theme-toggle" type="button" data-theme-toggle title="<?php echo h(__t('layout.theme_title')); ?>">
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9789;</span>
        <span class="sr-only"><?php echo h(__t('layout.theme')); ?></span>
    </button>
<main class="panel login-panel">
    <img class="login-logo" src="<?php echo h(layout_asset_url('assets/img/logo-160.png')); ?>" srcset="<?php echo h(layout_asset_url('assets/img/logo-160.png')); ?> 1x, <?php echo h(layout_asset_url('assets/img/logo-160@2x.png')); ?> 2x" width="129" height="129" alt="">
    <h1>VirtuSphere</h1>
    <p class="login-tagline"><?php echo h(__t('login.tagline')); ?></p>
    <?php foreach (flash_messages() as $flash) { echo flash_alert_html(is_array($flash) ? $flash : []); } ?>
    <?php if ($error !== '') { ?><div class="alert alert-error"><?php echo h($error); ?></div><?php } ?>
    <?php if ($directoryEnabled && !$directoryLoginAvailable) { ?><div class="alert alert-warning"><?php echo h(__t('login.directory_requires_https')); ?></div><?php } ?>
    <form class="stack" method="post" action="login.php">
        <?php echo csrf_field(); ?>
        <?php if ($directoryLoginAvailable) { ?>
        <label for="auth_source"><?php echo h(__t('login.auth_source')); ?><select id="auth_source" name="auth_source"><option value="active_directory"<?php echo $source === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY ? ' selected' : ''; ?>><?php echo h(__t('login.source_directory')); ?></option><option value="local"<?php echo $source === VIRTUSPHERE_AUTH_SOURCE_LOCAL ? ' selected' : ''; ?>><?php echo h(__t('login.source_local')); ?></option></select></label>
        <?php } else { ?><input type="hidden" name="auth_source" value="local"><?php } ?>
        <label for="username"><?php echo h(__t('login.username')); ?><span class="login-input"><svg class="login-input-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"></circle><path d="M2.5 14c0-3 2.5-4.6 5.5-4.6s5.5 1.6 5.5 4.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg><input id="username" name="username" autocomplete="username" value="<?php echo h($username); ?>" required></span></label>
        <label for="password"><?php echo h(__t('login.password')); ?><span class="login-input"><svg class="login-input-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.6" stroke="currentColor" stroke-width="1.5"></rect><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2" stroke="currentColor" stroke-width="1.5"></path></svg><input id="password" name="password" type="password" autocomplete="current-password" required></span></label>
        <button class="button" type="submit"><?php echo h(__t('login.submit')); ?> <span class="login-btn-arrow" aria-hidden="true">&rarr;</span></button>
    </form>
</main>
</body>
</html>
