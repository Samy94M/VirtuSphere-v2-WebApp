<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/auth_directory_login.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/auth_password_rehash.php';
require_once __DIR__ . '/auth_schema.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/repo/helpers.php';

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
function login(string $username, string $password, ?mysqli $db = null, string $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL): array
{
    session_start_secure();
    $db = $db ?? db();
    $username = trim($username);
    if ($username === '' || $password === '' || !in_array($source, VIRTUSPHERE_AUTH_SOURCES, true)) {
        // An empty/invalid form submit touches neither LDAP nor the counters.
        return ['ok' => false, 'reason' => 'invalid'];
    }

    try {
        $reservation = auth_reserve_login_attempt($db, $username, $source);
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'directory_unavailable'];
    }
    if (!$reservation['ok']) {
        return [
            'ok' => false,
            'reason' => $reservation['reason'],
            'retry_after_seconds' => VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES * 60,
        ];
    }
    $attemptId = (int) $reservation['id'];

    if ($source === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY) {
        return auth_login_directory($db, $username, $password, $attemptId);
    }

    return auth_login_local($db, $username, $password, $attemptId);
}

function auth_login_local(mysqli $db, string $username, string $password, int $attemptId = 0): array
{
    $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
    if (auth_user_source_schema_available($db)) {
        $stmt = $db->prepare('SELECT id, name, auth_source, password, email, role, is_active, must_change_password, locked_until FROM deploy_users WHERE name = ? AND auth_source = ? LIMIT 1');
        $stmt->bind_param('ss', $username, $source);
    } else {
        $stmt = $db->prepare("SELECT id, name, 'local' AS auth_source, password, email, role, is_active, must_change_password, locked_until FROM deploy_users WHERE name = ? LIMIT 1");
        $stmt->bind_param('s', $username);
    }
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        auth_finish_failed_login($db, $attemptId, $username, $source);
        audit_auth($db, 'login rejected: account is locked', (int) $user['id']);
        return ['ok' => false, 'reason' => 'locked'];
    }

    if ($user) {
        $valid = (int) $user['is_active'] === 1
            && is_string($user['password'])
            && password_verify($password, $user['password']);
    } else {
        // Burn a hash comparison for unknown local usernames too.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        $valid = false;
    }

    if (!$valid) {
        auth_finish_failed_login($db, $attemptId, $username, $source);
        if ($user && auth_failed_attempt_count($db, $username, $source) >= VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT) {
            $lockSql = auth_user_source_schema_available($db)
                ? 'UPDATE deploy_users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ? AND auth_source = ?'
                : 'UPDATE deploy_users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?';
            $lockStmt = $db->prepare($lockSql);
            $lockMinutes = VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES;
            $userId = (int) $user['id'];
            if (auth_user_source_schema_available($db)) {
                $lockStmt->bind_param('iis', $lockMinutes, $userId, $source);
            } else {
                $lockStmt->bind_param('ii', $lockMinutes, $userId);
            }
            $lockStmt->execute();
            audit_auth($db, sprintf(
                'account locked for %d minutes after %d failed sign-ins',
                VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES,
                VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT
            ), $userId);
            return ['ok' => false, 'reason' => 'locked'];
        }

        $reason = $user && (int) $user['is_active'] !== 1
            ? 'login rejected: account is deactivated'
            : 'login failed for user "' . audit_snippet($username) . '"';
        audit_auth($db, $reason, $user ? (int) $user['id'] : null);

        return ['ok' => false, 'reason' => 'invalid'];
    }

    repo_transaction($db, function () use ($db, $attemptId, $username, $source, $user, $password): void {
        if ($attemptId > 0) {
            auth_finish_login_attempt($db, $attemptId, VIRTUSPHERE_LOGIN_RESULT_SUCCESS);
        } else {
            auth_record_login_attempt($db, $username, true, $source);
        }
        audit_auth($db, 'login succeeded (local)', (int) $user['id']);
        auth_rehash_password_if_needed($db, (int) $user['id'], $password, (string) $user['password']);
        auth_mark_login_seen($db, (int) $user['id']);
    });

    return auth_complete_login($user, $source);
}

function auth_complete_login(array $user, string $source): array
{
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user'] = (string) $user['name'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['auth_source'] = $source;
    $_SESSION['must_change_password'] = $source === VIRTUSPHERE_AUTH_SOURCE_LOCAL
        && (int) $user['must_change_password'] === 1;
    if ($source === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY) {
        $_SESSION['directory_verified_at'] = time();
        unset($_SESSION['directory_retry_at']);
    } else {
        unset($_SESSION['directory_verified_at'], $_SESSION['directory_retry_at']);
    }
    session_touch_expiry();

    return ['ok' => true, 'user' => $user, 'must_change_password' => $_SESSION['must_change_password']];
}

function auth_mark_login_seen(mysqli $db, int $userId): void
{
    $seenSql = auth_user_source_schema_available($db)
        ? 'UPDATE deploy_users SET last_seen_at = NOW(), locked_until = IF(auth_source = ?, NULL, locked_until) WHERE id = ?'
        : 'UPDATE deploy_users SET last_seen_at = NOW() WHERE id = ?';
    $seenStmt = $db->prepare($seenSql);
    $local = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
    if (auth_user_source_schema_available($db)) {
        $seenStmt->bind_param('si', $local, $userId);
    } else {
        $seenStmt->bind_param('i', $userId);
    }
    $seenStmt->execute();
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
    $userSelect = auth_user_source_schema_available($db)
        ? 'SELECT id, name, auth_source, email, role, is_active, must_change_password, last_seen_at, ad_object_guid, ad_upn, ad_sam_account_name, ad_display_name, ad_account_enabled, ad_last_checked_at FROM deploy_users WHERE id = ? LIMIT 1'
        : "SELECT id, name, 'local' AS auth_source, email, role, is_active, must_change_password, last_seen_at, NULL AS ad_object_guid, NULL AS ad_upn, NULL AS ad_sam_account_name, NULL AS ad_display_name, NULL AS ad_account_enabled, NULL AS ad_last_checked_at FROM deploy_users WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($userSelect);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || (int) $user['is_active'] !== 1) {
        return null;
    }

    if ((string) $user['auth_source'] === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY) {
        $verifiedAt = (int) ($_SESSION['directory_verified_at'] ?? 0);
        if (!directory_is_enabled($db)) {
            audit_auth($db, 'Active Directory session ended: directory integration disabled', $userId);
            logout();
            return null;
        }
        if ($verifiedAt === 0 || time() - $verifiedAt >= VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS) {
            $retryAt = (int) ($_SESSION['directory_retry_at'] ?? 0);
            if ($retryAt > time()) {
                return $user;
            }
            $check = directory_revalidate_user($db, $user);
            if ($check['ok']) {
                $_SESSION['directory_verified_at'] = time();
                unset($_SESSION['directory_retry_at']);
                $fresh = repo_directory_user_by_guid($db, (string) $user['ad_object_guid']);
                if ($fresh !== null) {
                    $user = $fresh;
                }
            } elseif ($check['temporary'] && $verifiedAt > 0 && time() - $verifiedAt < VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS) {
                $remainingGrace = VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS - (time() - $verifiedAt);
                $_SESSION['directory_retry_at'] = time() + min(60, max(1, $remainingGrace));
            } else {
                audit_auth($db, 'Active Directory session ended: ' . audit_snippet($check['reason']), $userId);
                logout();
                return null;
            }
        }
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

// No require_admin() wrapper: every page pairs portal_require_user() with its
// own can() gate, so the permission a page enforces is the permission its
// buttons check. A wrapper that hardcoded 'users.manage' invited a second,
// coarser answer to that question. A denial still goes through portal_forbid(),
// which localizes it and writes the auth audit line a bare exit() never did.

function change_own_password(mysqli $db, int $userId, string $currentPassword, string $newPassword): bool
{
    $passwordSelect = auth_user_source_schema_available($db)
        ? 'SELECT auth_source, password FROM deploy_users WHERE id = ? LIMIT 1'
        : "SELECT 'local' AS auth_source, password FROM deploy_users WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($passwordSelect);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row
        || (string) $row['auth_source'] !== VIRTUSPHERE_AUTH_SOURCE_LOCAL
        || !is_string($row['password'])
        || !password_verify($currentPassword, $row['password'])
    ) {
        return false;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mustChange = 0;
    $stmt = $db->prepare('UPDATE deploy_users SET password = ?, must_change_password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('sii', $hash, $mustChange, $userId);

    return $stmt->execute();
}
