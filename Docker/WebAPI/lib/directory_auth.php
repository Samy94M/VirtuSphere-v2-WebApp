<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_service.php';
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
    if ($password === '' || !directory_upn_is_valid($upn)) {
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

/** @return array{ok:bool,temporary:bool,reason:string} */
function directory_revalidate_user(mysqli $db, array $user): array
{
    $guid = (string) ($user['ad_object_guid'] ?? '');
    if (strlen($guid) !== 16 || !directory_is_enabled($db)) {
        return ['ok' => false, 'temporary' => false, 'reason' => 'directory_disabled'];
    }
    try {
        $entry = directory_find_user_by_guid($db, $guid);
        if (empty($entry['enabled'])) {
            return ['ok' => false, 'temporary' => false, 'reason' => 'directory_account_disabled'];
        }
        repo_directory_update_user_cache($db, (int) $user['id'], $entry);

        return ['ok' => true, 'temporary' => false, 'reason' => 'ok'];
    } catch (DirectoryLdapException $exception) {
        if ($exception->transportFailure || in_array($exception->outcome, [
            VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING,
            VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
            VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE,
            VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED,
            VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE,
            VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS,
            VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH,
            VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED,
        ], true)) {
            return ['ok' => false, 'temporary' => true, 'reason' => 'directory_unavailable'];
        }

        return ['ok' => false, 'temporary' => false, 'reason' => $exception->outcome];
    } catch (Throwable) {
        return ['ok' => false, 'temporary' => true, 'reason' => 'directory_unavailable'];
    }
}
