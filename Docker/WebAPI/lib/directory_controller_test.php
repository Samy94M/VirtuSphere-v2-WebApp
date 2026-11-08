<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_service.php';

/**
 * The two controller tests of the users page (moved out of directory_service.php
 * by W3A to keep it within the ADR-0006 budget). The LDAP test is the network I/O
 * and runs before any transaction; only the writes that follow share one with
 * their audit row (R19).
 */

/**
 * Tests one saved controller. This is the only operation that can admit it for
 * login on the current configuration revision.
 *
 * @return array{ok:bool,outcome:string}
 */
function directory_test_saved_controller(mysqli $db, int $controllerId, int $actorId): array
{
    $config = repo_directory_config($db);
    $controller = repo_directory_controller($db, $controllerId);
    if ($config === null || $controller === null) {
        return ['ok' => false, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE];
    }
    $revision = (int) $config['revision'];
    try {
        $runtime = directory_runtime_config($config);
        $result = directory_ldap_test_controller(directory_controller_endpoint($controller), $runtime);
    } catch (DirectoryLdapException $exception) {
        return directory_record_failed_controller_test($db, $controller, $revision, $actorId, $exception);
    } catch (Throwable) {
        return directory_record_failed_controller_test($db, $controller, $revision, $actorId, new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE));
    }
    // The admission, the observation and the row are one transaction.
    try {
        $admitted = repo_transaction($db, static function () use ($db, $controller, $controllerId, $revision, $result, $actorId): bool {
            if (!repo_directory_apply_controller_test_success($db, $controllerId, $revision, $result['default_naming_context'], $actorId)) {
                return false;
            }
            directory_observe_controller($db, $controller, $revision, VIRTUSPHERE_DIRECTORY_OUTCOME_OK, $result['certificate']);
            audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED, 'directory_controller', $controllerId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_OK,
            ], $actorId);

            return true;
        });
    } catch (Throwable) {
        // As before: a fault after a passed test reads as an invalid response
        // and withdraws the validation, in a transaction of its own.
        return directory_record_failed_controller_test($db, $controller, $revision, $actorId, new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE));
    }
    if (!$admitted) {
        return ['ok' => false, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE];
    }

    return ['ok' => true, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_OK];
}

/**
 * The failure half of a saved-controller test: the withdrawn validation, the
 * observation and the row commit together.
 *
 * @param array<string,mixed> $controller
 * @return array{ok:bool,outcome:string}
 */
function directory_record_failed_controller_test(mysqli $db, array $controller, int $revision, int $actorId, DirectoryLdapException $exception): array
{
    $controllerId = (int) $controller['id'];
    repo_transaction($db, static function () use ($db, $controller, $controllerId, $revision, $actorId, $exception): void {
        repo_directory_clear_controller_validation($db, $controllerId, $revision, $actorId);
        directory_observe_controller($db, $controller, $revision, $exception);
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED, 'directory_controller', $controllerId, VIRTUSPHERE_AUDIT_RESULT_FAILURE, [
            'outcome' => $exception->outcome,
        ], $actorId);
    });

    return ['ok' => false, 'outcome' => $exception->outcome];
}

/**
 * Tests a not-yet-saved configuration while AD is active. The current config
 * remains untouched unless this succeeds; the caller atomically saves it with
 * the returned naming context and controller id.
 *
 * @param array<string,mixed> $candidate
 * @return array{default_naming_context:string,certificate:array{fingerprint:string,not_after:string}}
 */
function directory_test_candidate(mysqli $db, array $candidate, int $controllerId): array
{
    $controller = repo_directory_controller($db, $controllerId);
    if ($controller === null) {
        throw new ValidationException(['controller_id' => __t('directory.err_controller_required')]);
    }

    return directory_ldap_test_controller(directory_controller_endpoint($controller), [
        'bind_upn' => (string) $candidate['bind_upn'],
        'bind_password' => (string) $candidate['bind_password'],
        'ca_certificate_pem' => (string) $candidate['ca_certificate_pem'],
        'user_search_base_dn' => (string) $candidate['user_search_base_dn'],
        'default_naming_context' => (string) $candidate['default_naming_context'],
    ]);
}
