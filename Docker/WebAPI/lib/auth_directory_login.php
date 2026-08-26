<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/directory_auth.php';
require_once __DIR__ . '/headers.php';

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

        return auth_complete_login($user, $source);
    } catch (DirectoryLdapException $exception) {
        if ($exception->transportFailure
            || in_array($exception->outcome, [
                VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING,
                VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
                VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE,
                VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED,
                VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE,
                VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS,
                VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH,
                VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED,
            ], true)
        ) {
            auth_finish_infrastructure_login($db, $attemptId);
            return ['ok' => false, 'reason' => 'directory_unavailable'];
        }
        auth_finish_failed_login($db, $attemptId, $username, $source);
        audit_event($db, VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', null, VIRTUSPHERE_AUDIT_RESULT_DENIED, [
            'source' => VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY,
            'reason' => 'invalid credentials',
            'username' => audit_snippet($username, 191),
        ]);

        return ['ok' => false, 'reason' => 'invalid'];
    } catch (Throwable) {
        auth_finish_infrastructure_login($db, $attemptId);
        return ['ok' => false, 'reason' => 'directory_unavailable'];
    }
}
