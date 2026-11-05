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

/**
 * Resumes the caller's session, but never creates one (SEC2, C-02). This is the
 * function session_start_secure() (lib/auth.php) and therefore current_user()
 * reach on every portal request. A request without the session cookie starts
 * nothing, and a cookie that leads to no data (an id PHP rejects under
 * use_strict_mode, or a session that holds nothing) loses its file and its
 * cookie again at once. Without this every anonymous request left one file in
 * the memory-backed /tmp for session.gc_maxlifetime (measured: 20 cookieless
 * GET login.php, 20 files). A session is created only where it has a reason to
 * exist, through virtusphere_create_session(): the sign-in after its token
 * check (login.php), a flash message, the sign-out.
 */
function virtusphere_start_session(): void
{
    $_SESSION ??= [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $id = $_COOKIE[session_name()] ?? null;
    if (!is_string($id) || $id === '') {
        return;
    }

    virtusphere_create_session();
    if ($_SESSION === []) {
        virtusphere_discard_session();
    }
}

/** Starts (or resumes) the session for a request that needs one. */
function virtusphere_create_session(): void
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

/**
 * Ends the active session, file and cookie included. The cookie leaves $_COOKIE
 * too, so a later virtusphere_start_session() in the same request does not
 * create and delete a file a second time.
 */
function virtusphere_discard_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    session_destroy();
    $_SESSION = [];
    unset($_COOKIE[session_name()]);
    if (!headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
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
