<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_service.php';
require_once __DIR__ . '/audit_vocabulary.php';
require_once __DIR__ . '/repo/directory.php';

/**
 * Authenticates an explicitly imported user. Search, local GUID gate and user
 * bind happen on the same controller. Invalid credentials are authoritative
 * and therefore never fan out to another DC.
 *
 * @return array{user:array<string,mixed>,entry:array<string,mixed>,controller_id:int,config_revision:int}
 */
function directory_authenticate_user(mysqli $db, string $upn, string $password): array
{
    // login() refuses these before any reservation (N1a A1/A8/F4); this is the
    // second line for any other caller.
    if (!directory_password_is_acceptable($password) || !directory_upn_is_valid($upn)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED);
    }

    $result = directory_read_with_failover(
        $db,
        static function (mixed $connection, array $runtime, array $controller, float $deadline) use ($db, $upn, $password): array {
            $entry = directory_ldap_find_user_by_upn($connection, directory_user_search_base($runtime), $upn, $deadline);
            $user = repo_directory_user_by_guid($db, (string) $entry['guid_bytes']);
            if ($user === null
                || (string) $user['auth_source'] !== VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY
                || (int) $user['is_active'] !== 1
                || empty($entry['enabled'])
            ) {
                throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED);
            }

            // The DN is server-supplied by the exact UPN search. It is never
            // built from the typed user name and is never stored or logged.
            directory_ldap_bind(
                $connection,
                (string) $entry['dn'],
                $password,
                VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED,
                $deadline
            );

            return [
                'user' => $user,
                'entry' => $entry,
                'controller_id' => (int) $controller['id'],
                'config_revision' => (int) $controller['validated_revision'],
            ];
        },
        true
    );

    repo_directory_update_user_cache($db, (int) $result['user']['id'], $result['entry']);
    $freshUser = repo_directory_user_by_guid($db, (string) $result['entry']['guid_bytes']);
    if ($freshUser !== null) {
        $result['user'] = $freshUser;
    }

    return $result;
}

/**
 * Periodic check of a running AD session with the search account. Ends the
 * session for a disabled, expired, locked (N1a F1) or deleted account and for
 * a password set after the sign-in (F1: pwdLastSet newer than the value the
 * session remembered). An only expired password keeps the session; the next
 * sign-in fails at the controller anyway.
 *
 * $sessionPasswordLastSet is the pwdLastSet the session remembered at sign-in,
 * or null for a session from before F1; 'pwd_last_set' in an ok answer is the
 * value to remember from now on.
 *
 * @return array{ok:bool,temporary:bool,reason:string,pwd_last_set?:string}
 */
function directory_revalidate_user(mysqli $db, array $user, ?string $sessionPasswordLastSet = null): array
{
    $guid = (string) ($user['ad_object_guid'] ?? '');
    if (strlen($guid) !== 16 || !directory_is_enabled($db)) {
        return ['ok' => false, 'temporary' => false, 'reason' => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_DISABLED];
    }
    try {
        $entry = directory_find_user_by_guid($db, $guid);
        // N1a A9: the cache follows every answer, so the user list shows a
        // disabled account as disabled once its session has ended.
        repo_directory_update_user_cache($db, (int) $user['id'], $entry);
        $reason = match (true) {
            empty($entry['enabled']) => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_ACCOUNT_DISABLED,
            !empty($entry['locked']) => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_ACCOUNT_LOCKED,
            $sessionPasswordLastSet !== null
                && directory_password_changed_since($sessionPasswordLastSet, (string) $entry['pwd_last_set'])
                => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_PASSWORD_CHANGED,
            default => '',
        };
        if ($reason !== '') {
            return ['ok' => false, 'temporary' => false, 'reason' => $reason];
        }

        return ['ok' => true, 'temporary' => false, 'reason' => 'ok', 'pwd_last_set' => (string) $entry['pwd_last_set']];
    } catch (DirectoryLdapException $exception) {
        if ($exception->transportFailure || in_array($exception->outcome, VIRTUSPHERE_DIRECTORY_INFRASTRUCTURE_OUTCOMES, true)) {
            return ['ok' => false, 'temporary' => true, 'reason' => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_UNAVAILABLE];
        }

        return ['ok' => false, 'temporary' => false, 'reason' => $exception->outcome];
    } catch (Throwable) {
        return ['ok' => false, 'temporary' => true, 'reason' => VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_UNAVAILABLE];
    }
}
