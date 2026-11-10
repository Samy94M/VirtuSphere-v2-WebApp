<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/directory_auth.php';
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/repo/directory_users.php';

/**
 * Input a sign-in may not even reserve an attempt for: it answers "invalid"
 * without LDAP, counters or audit, exactly like an empty field.
 * - N1a A1: a NUL byte in the password, for both sources. ldap_bind() throws
 *   for it, which used to reveal which UPNs are imported; a local hash is
 *   never made from one (password_verify() itself does not throw).
 * - N1a A8: an AD password above VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES.
 * - N1a F4: an AD name that is not name@domain (DOMAIN\name, a bare name):
 *   ADR-0039 signs in by exact UPN only, and a form mistake must not use up
 *   the attempt budget of the name.
 */
function auth_login_input_is_admissible(string $username, string $password, string $source): bool
{
    if ($username === '' || $password === '' || str_contains($password, "\0") || !in_array($source, VIRTUSPHERE_AUTH_SOURCES, true)) {
        return false;
    }
    if ($source === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY) {
        return directory_password_is_acceptable($password) && directory_upn_is_valid($username);
    }

    return true;
}

/**
 * The AD half of current_user(): ends the session when AD sign-in was switched
 * off, and rechecks the account with the search account every
 * VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS, with a bounded grace period
 * for a directory outage.
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>|null
 */
function auth_directory_session_user(mysqli $db, array $user, int $userId): ?array
{
    $verifiedAt = (int) ($_SESSION['directory_verified_at'] ?? 0);
    if (!directory_is_enabled($db)) {
        auth_end_session($db, $userId, VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_INTEGRATION_DISABLED);
        return null;
    }
    if ($verifiedAt !== 0 && time() - $verifiedAt < VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS) {
        return $user;
    }
    $retryAt = (int) ($_SESSION['directory_retry_at'] ?? 0);
    if ($retryAt > time()) {
        return $user;
    }
    $remembered = $_SESSION['directory_pwd_last_set'] ?? null;
    $baseline = is_string($remembered) && $remembered !== '' ? $remembered : null;
    $check = directory_revalidate_user($db, $user, $baseline);
    if ($check['ok']) {
        $_SESSION['directory_verified_at'] = time();
        // A session from before N1a F1 learns its baseline here.
        $_SESSION['directory_pwd_last_set'] = $baseline ?? (string) ($check['pwd_last_set'] ?? '');
        unset($_SESSION['directory_retry_at']);
        $fresh = repo_directory_user_by_guid($db, (string) $user['ad_object_guid']);

        return $fresh ?? $user;
    }
    if ($check['temporary'] && $verifiedAt > 0 && time() - $verifiedAt < VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS) {
        $remainingGrace = VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS - (time() - $verifiedAt);
        $_SESSION['directory_retry_at'] = time() + min(60, max(1, $remainingGrace));

        return $user;
    }
    auth_end_session($db, $userId, $check['reason']);

    return null;
}

function auth_login_directory(mysqli $db, string $username, string $password, int $attemptId = 0): array
{
    $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
    if (!virtusphere_is_request_secure() || !directory_is_enabled($db)) {
        auth_finish_infrastructure_login($db, $attemptId);
        return ['ok' => false, 'reason' => 'directory_unavailable'];
    }

    try {
        $result = directory_authenticate_user($db, $username, $password);
        $user = repo_transaction($db, function () use ($db, $result, $username, $source, $attemptId): array {
            $user = repo_directory_login_snapshot(
                $db,
                (string) $result['entry']['guid_bytes'],
                (int) $result['controller_id'],
                (int) $result['config_revision']
            );
            if ($user === null) {
                // The password was already accepted. A changed local gate is
                // an infrastructure/concurrency decision, never another bad
                // credential that may feed lockout counters.
                throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, true);
            }

            if ($attemptId > 0) {
                auth_finish_login_attempt($db, $attemptId, VIRTUSPHERE_LOGIN_RESULT_SUCCESS);
            } else {
                auth_record_login_attempt($db, $username, true, $source);
            }
            audit_event($db, VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', (int) $user['id'], VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'source' => VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY,
                'controller_id' => (int) $result['controller_id'],
            ], (int) $user['id']);
            auth_mark_login_seen($db, (int) $user['id']);

            return $user;
        });

        // N1a F1: the password generation this session is opened with; the
        // recheck ends the session once the account has a newer one.
        // session_regenerate_id() in auth_complete_login() keeps the value.
        $_SESSION['directory_pwd_last_set'] = (string) $result['entry']['pwd_last_set'];

        return auth_complete_login($user, $source);
    } catch (DirectoryLdapException $exception) {
        if ($exception->transportFailure
            || in_array($exception->outcome, VIRTUSPHERE_DIRECTORY_INFRASTRUCTURE_OUTCOMES, true)
        ) {
            auth_finish_infrastructure_login($db, $attemptId);
            return ['ok' => false, 'reason' => 'directory_unavailable'];
        }
        auth_finish_failed_login($db, $attemptId, $username, $source);
        // N1a F5: the typed AD sub-code of a rejected password, audit only. The
        // sign-in page stays generic: AD reports "locked" for a wrong password.
        $directoryReason = $exception->bindReason !== '' ? ['directory_reason' => $exception->bindReason] : [];
        audit_event($db, VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', null, VIRTUSPHERE_AUDIT_RESULT_DENIED, [
            'source' => VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY,
            'reason' => VIRTUSPHERE_AUDIT_LOGIN_REASON_INVALID_CREDENTIALS,
            ...$directoryReason,
            ...virtusphere_login_name_audit_context($username, repo_directory_upn_is_imported($db, $username)),
        ]);

        return ['ok' => false, 'reason' => 'invalid'];
    } catch (Throwable) {
        auth_finish_infrastructure_login($db, $attemptId);
        return ['ok' => false, 'reason' => 'directory_unavailable'];
    }
}
