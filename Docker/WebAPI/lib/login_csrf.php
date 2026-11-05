<?php

declare(strict_types=1);

require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/headers.php';

/**
 * CSRF protection for the sign-in form without a session (SEC2, C-02).
 *
 * Every other form is protected by the token in the session (csrf.php). The
 * sign-in form is the one form an anonymous caller can load, and loading it used
 * to create a session file each time: one file per cookieless request, kept for
 * session.gc_maxlifetime, in the PHP container's memory-backed /tmp. A loop over
 * GET login.php filled it.
 *
 * Signed double submit instead (OWASP CSRF Prevention Cheat Sheet): a random
 * nonce in a cookie that only the sign-in page receives, and in the form the
 * HMAC of that nonce under a key derived from APP_KEY. A cross-site POST does not
 * carry the cookie (SameSite=Strict), and a page that cannot read the cookie
 * cannot compute the field. Nothing is stored on the server. The session starts
 * only when a POST with a valid token is about to check a password, and a failed
 * check discards it again (login.php).
 */

const VIRTUSPHERE_LOGIN_CSRF_COOKIE = 'vs_login_csrf';
const VIRTUSPHERE_LOGIN_CSRF_FIELD = '_csrf';
// The only page that reads the cookie; no other request carries it.
const VIRTUSPHERE_LOGIN_CSRF_COOKIE_PATH = '/portal/login.php';

function login_csrf_nonce_valid(mixed $nonce): bool
{
    return is_string($nonce) && preg_match('/^[0-9a-f]{64}$/D', $nonce) === 1;
}

/** The field value for a nonce. $key exists for the unit tests only. */
function login_csrf_field_value(string $nonce, ?string $key = null): string
{
    return hash_hmac('sha256', 'virtusphere.login-csrf.v1' . "\0" . $nonce, $key ?? envboot_app_key_bytes());
}

/**
 * The nonce of this browser: the one its cookie already carries, so two open
 * sign-in tabs stay valid, or a new one.
 *
 * @param array<string, mixed> $cookies
 */
function login_csrf_nonce(array $cookies): string
{
    $current = $cookies[VIRTUSPHERE_LOGIN_CSRF_COOKIE] ?? null;

    return login_csrf_nonce_valid($current) ? (string) $current : bin2hex(random_bytes(32));
}

/** Renders the hidden field and (re)sets the cookie. Call before any output. */
function login_csrf_field(): string
{
    $nonce = login_csrf_nonce($_COOKIE);
    login_csrf_set_cookie($nonce, 0);

    return '<input type="hidden" name="' . VIRTUSPHERE_LOGIN_CSRF_FIELD . '" value="'
        . htmlspecialchars(login_csrf_field_value($nonce), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * @param array<string, mixed> $cookies
 * @param mixed $field the raw request value; an array must reject, not throw
 */
function login_csrf_verify(array $cookies, mixed $field, ?string $key = null): bool
{
    $nonce = $cookies[VIRTUSPHERE_LOGIN_CSRF_COOKIE] ?? null;
    if (!login_csrf_nonce_valid($nonce) || !is_string($field)) {
        return false;
    }

    return hash_equals(login_csrf_field_value((string) $nonce, $key), $field);
}

/** After a sign-in the nonce has done its job; the session token takes over. */
function login_csrf_clear_cookie(): void
{
    login_csrf_set_cookie('', time() - 42000);
}

function login_csrf_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(VIRTUSPHERE_LOGIN_CSRF_COOKIE, $value, [
        'expires' => $expires,
        'path' => VIRTUSPHERE_LOGIN_CSRF_COOKIE_PATH,
        'secure' => virtusphere_is_request_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
