<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();
virtusphere_assert_log_dir_writable();

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/db.php';
// Before auth.php: sign-in records security events through it, and every portal
// page reaches for portal_forbid()/portal_reject_csrf() without its own require.
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/https_config.php';

function virtusphere_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => virtusphere_is_request_secure(),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

envboot_assert_secure_runtime();
virtusphere_send_security_headers();
virtusphere_start_session();
Lang::load(__locale_resolve());

$connection = db();

// Portal-only HTTP->HTTPS redirect (ADR-0012): only pages loading this
// bootstrap redirect; the machine API and health.php stay exempt because they
// never include it.
https_redirect_if_required($connection);
