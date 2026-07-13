<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/permissions.php';

const VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT = 5;
const VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT = 25;

// Absolute session lifetime fallback. The clock starts at login and is only
// ever refreshed when the user explicitly clicks "Verlaengern"
// (session_ping.php); ordinary page navigation deliberately does NOT extend
// it. The effective value comes from the admin-configured setting via
// session_lifetime_seconds(); this constant is the DB-down fallback.
const VIRTUSPHERE_SESSION_LIFETIME_SECONDS = 3600;

// How long before expiry the client shows the "you are about to be logged out"
// warning modal. Kept server-side so PHP and JS agree on the threshold.
const VIRTUSPHERE_SESSION_WARN_SECONDS = 300;

/**
 * Effective absolute session lifetime. Reads the admin setting once per
 * request (static cache), clamps to the configured bounds so a hand-edited
 * DB row cannot produce a zero-second session, and falls back to the old
 * constant when the settings table is unreachable (login must still work
 * while MySQL restarts).
 */
function session_lifetime_seconds(?mysqli $db = null): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    require_once __DIR__ . '/repo/settings.php';
    try {
        $minutes = (int) repo_setting_value($db ?? db(), VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES, (string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT);
    } catch (Throwable) {
        return VIRTUSPHERE_SESSION_LIFETIME_SECONDS;
    }
    $minutes = max(VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN, min(VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX, $minutes));

    return $cached = $minutes * 60;
}

function session_touch_expiry(): void
{
    $_SESSION['session_expires_at'] = time() + session_lifetime_seconds();
}

function session_remaining_seconds(): int
{
    $expiresAt = (int) ($_SESSION['session_expires_at'] ?? 0);
    if ($expiresAt === 0) {
        return session_lifetime_seconds();
    }

    return max(0, $expiresAt - time());
}

function session_start_secure(): void
{
    if (function_exists('virtusphere_start_session')) {
        virtusphere_start_session();
        return;
    }

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

function is_request_secure(): bool
{
    return virtusphere_is_request_secure();
}

function auth_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function auth_record_login_attempt(mysqli $db, string $username, bool $success): void
{
    $ip = auth_client_ip();
    $successInt = $success ? 1 : 0;
    $stmt = $db->prepare('INSERT INTO deploy_login_attempts (username, ip_address, success) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $username, $ip, $successInt);
    $stmt->execute();
}

function auth_failed_attempt_count(mysqli $db, string $username): int
{
    $ip = auth_client_ip();
    $window = VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES;
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE username = ? AND ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
    $stmt->bind_param('ssi', $username, $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

function auth_failed_ip_attempt_count(mysqli $db): int
{
    $ip = auth_client_ip();
    $window = VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES;
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

/**
 * Records one failed sign-in and audits the IP rate-limit onset: the attempt
 * that tips the counter over VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT writes one
 * line, later attempts in the window only count. One row per rejected request
 * would hand an unauthenticated client a way to fill the auth channel for the
 * whole retention window; this bounds it to the per-attempt failures below the
 * limit plus one onset line per IP and window. The counter table itself still
 * records every attempt (it has to, it is the limit).
 */
function auth_record_failed_login(mysqli $db, string $username): void
{
    $before = auth_failed_ip_attempt_count($db);
    auth_record_login_attempt($db, $username, false);
    if ($before < VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT && auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT) {
        // Both numbers interpolated: an audit line that states a duration the
        // code no longer enforces is worse than one that states none, because an
        // operator reading the log has no way to tell.
        audit_auth($db, sprintf(
            'ip rate limited for %d minutes after %d failed sign-ins',
            VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES,
            VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT
        ));
    }
}

/**
 * Sign-in outcomes reach the `auth` audit channel, not just the lockout counter
 * in deploy_login_attempts, which no page ever shows. The typed user name is
 * recorded on failures because that is the only handle an admin has on an
 * unknown account; it is bounded and escaped on render, never trusted.
 *
 * The account is deliberately NOT named on a rejected sign-in for an existing
 * user beyond the typed string, and no reason is leaked to the user, so this
 * stays consistent with the anti-enumeration handling below.
 *
 * The channel is flood-bounded: once the IP rate limit is active, rejected
 * attempts are counted but not audited (auth_record_failed_login logs the
 * onset once), so an unauthenticated client cannot write more than the
 * below-limit failures plus one line per window into deploy_logs.
 */
function login(string $username, string $password, ?mysqli $db = null): array
{
    session_start_secure();
    $db = $db ?? db();
    $username = trim($username);
    if ($username === '' || $password === '') {
        // An empty form submit touches nothing and is not an event.
        return ['ok' => false, 'reason' => 'invalid'];
    }

    if (auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT) {
        // Counted but not audited: the onset line from auth_record_failed_login()
        // already marks this window, and a per-attempt line would let anyone
        // without credentials grow the audit log one row per request.
        auth_record_login_attempt($db, $username, false);
        return [
            'ok' => false,
            'reason' => 'ip_locked',
            // Was the literal 900. The client is told to come back when the
            // counting window has moved on, so it is that window, not the
            // account lockout, that decides the number.
            'retry_after_seconds' => VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES * 60,
        ];
    }

    $stmt = $db->prepare('SELECT id, name, password, email, role, is_active, must_change_password, locked_until FROM deploy_users WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        auth_record_failed_login($db, $username);
        audit_auth($db, 'login rejected: account is locked', (int) $user['id']);
        return ['ok' => false, 'reason' => 'locked'];
    }

    if ($user) {
        $valid = (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password']);
    } else {
        // Burn a hash comparison for unknown usernames too, so response timing
        // does not reveal whether an account exists (OWASP authentication
        // cheat sheet, user-enumeration guidance).
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        $valid = false;
    }

    if (!$valid) {
        auth_record_failed_login($db, $username);
        if ($user && auth_failed_attempt_count($db, $username) >= VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT) {
            $lockStmt = $db->prepare('UPDATE deploy_users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?');
            $lockMinutes = VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES;
            $userId = (int) $user['id'];
            $lockStmt->bind_param('ii', $lockMinutes, $userId);
            $lockStmt->execute();
            // The lockout itself, not just the failed attempt that tripped it.
            // The duration comes from the same constant the UPDATE above uses:
            // it said "15 minutes" in the text while the SQL already read the
            // constant, so raising the constant made the audit line lie.
            audit_auth($db, sprintf(
                'account locked for %d minutes after %d failed sign-ins',
                VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES,
                VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT
            ), $userId);
            return ['ok' => false, 'reason' => 'locked'];
        }

        // A deactivated account with the right password is a different story from
        // a wrong password, and only the log gets to know it: the response stays
        // the same generic "invalid" either way.
        $reason = $user && (int) $user['is_active'] !== 1
            ? 'login rejected: account is deactivated'
            : 'login failed for user "' . audit_snippet($username) . '"';
        audit_auth($db, $reason, $user ? (int) $user['id'] : null);

        return ['ok' => false, 'reason' => 'invalid'];
    }

    auth_record_login_attempt($db, $username, true);
    audit_auth($db, 'login succeeded', (int) $user['id']);

    // Upgrade a hash that predates the current cost/algorithm, while we hold the
    // one thing needed to do it: the plaintext. Anything else would mean a forced
    // password reset for every user (OWASP password-storage cheat sheet).
    auth_rehash_password_if_needed($db, (int) $user['id'], $password, (string) $user['password']);

    session_regenerate_id(true);
    // Rotate the CSRF token with the session ID. The pre-login token was handed
    // to an unauthenticated visitor; there is no reason for it to keep working
    // inside a privileged session, and rotating on privilege change is a one-line
    // habit worth having.
    unset($_SESSION['_csrf']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user'] = (string) $user['name'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;
    session_touch_expiry();

    $seenStmt = $db->prepare('UPDATE deploy_users SET last_seen_at = NOW(), locked_until = NULL WHERE id = ?');
    $userId = (int) $user['id'];
    $seenStmt->bind_param('i', $userId);
    $seenStmt->execute();

    return ['ok' => true, 'user' => $user, 'must_change_password' => $_SESSION['must_change_password']];
}

/**
 * Re-hashes the password when the stored hash no longer matches the current
 * algorithm or cost (PASSWORD_DEFAULT moves with the PHP version). Sign-in is the
 * only moment the plaintext exists, so it is the only moment this is possible
 * without forcing everyone to reset.
 *
 * Best effort by design: a failed rehash must never turn a valid sign-in into a
 * rejected one. The old hash keeps working, and the next login tries again.
 */
function auth_rehash_password_if_needed(mysqli $db, int $userId, string $plaintext, string $storedHash): void
{
    if (!password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
        return;
    }
    // A password already stored can be longer than today's limit; do not re-hash
    // it into a *different* truncation. Leave it and let the next change fix it.
    if (strlen($plaintext) > VIRTUSPHERE_PASSWORD_MAX_BYTES) {
        return;
    }

    try {
        $hash = password_hash($plaintext, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE deploy_users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
    } catch (Throwable) {
        // Deliberately silent: the user is signed in either way.
    }
}

function logout(): void
{
    session_start_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();

    // Ask the browser to drop what it cached for this origin. Destroying the
    // session server-side does not empty the back/forward cache, so without this
    // the Back button can still paint the last protected page from memory on a
    // shared workstation. Advisory (the server-side invalidation above is what
    // actually protects the data), and skipped once output has begun.
    if (!headers_sent()) {
        header('Clear-Site-Data: "cache", "cookies", "storage"');
    }
}

function current_user(?mysqli $db = null): ?array
{
    session_start_secure();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    // Enforce the absolute session lifetime server-side so the timeout cannot be
    // bypassed by simply not running the client countdown. Sessions created
    // before this feature existed have no expiry stamp yet: seed one lazily
    // instead of treating them as already expired.
    $expiresAt = (int) ($_SESSION['session_expires_at'] ?? 0);
    if ($expiresAt === 0) {
        session_touch_expiry();
    } elseif (time() >= $expiresAt) {
        // Audit before logout(): it wipes the session this entry is attributed to.
        // Recorded once, because the branch runs once per session.
        audit_auth($db ?? db(), 'session expired after ' . session_lifetime_seconds($db) . ' seconds', $userId);
        logout();
        return null;
    }

    $db = $db ?? db();
    $stmt = $db->prepare('SELECT id, name, email, role, is_active, must_change_password, last_seen_at FROM deploy_users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || (int) $user['is_active'] !== 1) {
        return null;
    }

    return $user;
}

function require_login(?mysqli $db = null): array
{
    $user = current_user($db);
    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function can(string $permission, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if ($user === null) {
        return false;
    }

    return role_has_permission((string) ($user['role'] ?? VIRTUSPHERE_ROLE_USER), $permission);
}

function require_admin(?mysqli $db = null): array
{
    $user = require_login($db);
    if (!can('users.manage', $user)) {
        // portal_forbid() instead of a bare exit('Forbidden'): the rejection is
        // an untranslated, unstyled English word otherwise, and - worse - the
        // denial never reaches the auth audit channel, so an operator probing
        // admin pages leaves no trace. Every other guard in the portal already
        // goes through this helper.
        portal_forbid($db ?? db(), $user, 'users.manage');
    }

    return $user;
}

function change_own_password(mysqli $db, int $userId, string $currentPassword, string $newPassword): bool
{
    $stmt = $db->prepare('SELECT password FROM deploy_users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !password_verify($currentPassword, (string) $row['password'])) {
        return false;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mustChange = 0;
    $stmt = $db->prepare('UPDATE deploy_users SET password = ?, must_change_password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('sii', $hash, $mustChange, $userId);

    return $stmt->execute();
}
