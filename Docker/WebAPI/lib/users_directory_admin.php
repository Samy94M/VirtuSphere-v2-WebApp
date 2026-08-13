<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_config.php';
require_once __DIR__ . '/directory_service.php';
require_once __DIR__ . '/repo/directory.php';
require_once __DIR__ . '/users_page.php';

function users_directory_handle_action(mysqli $db, array $actor, string $action): bool
{
    if (str_starts_with($action, 'directory_') && !directory_schema_available($db)) {
        throw new ValidationException(['action' => __t('directory.err_schema_pending')]);
    }
    $actorId = (int) $actor['id'];
    if ($action === 'directory_save_config') {
        $existing = repo_directory_config($db);
        $expectedRevision = request_int($_POST, 'expected_revision');
        $candidate = directory_config_candidate($_POST, $existing);
        $testedControllerId = null;
        $certificate = [];
        if ($existing !== null && (int) $existing['enabled'] === 1) {
            $testedControllerId = request_int($_POST, 'controller_id');
            if ($testedControllerId <= 0) {
                throw new ValidationException(['controller_id' => __t('directory.err_controller_required')]);
            }
            try {
                $test = directory_test_candidate($db, $candidate, $testedControllerId);
            } catch (DirectoryLdapException $exception) {
                throw new ValidationException(['controller_id' => directory_outcome_message($exception->outcome)]);
            }
            $candidate['default_naming_context'] = $test['default_naming_context'];
            $certificate = $test['certificate'];
        }
        try {
            $revision = repo_directory_save_config($db, $candidate, $actorId, $testedControllerId, $expectedRevision);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'directory_config_stale') {
                throw new ValidationException(['expected_revision' => __t('directory.err_config_stale')]);
            }
            throw $exception;
        }
        if ($testedControllerId !== null) {
            $controller = repo_directory_controller($db, $testedControllerId);
            if ($controller !== null) {
                directory_observe_controller($db, $controller, $revision, VIRTUSPHERE_DIRECTORY_OUTCOME_OK, $certificate);
            }
        }
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'saved directory configuration revision ' . $revision, $actorId);
        flash_set('success', __t('directory.flash_config_saved'));

        return true;
    }
    if ($action === 'directory_add_controller') {
        if (repo_directory_config($db) === null) {
            throw new ValidationException(['host' => __t('directory.err_config_first')]);
        }
        $host = strtolower(trim(request_string($_POST, 'host')));
        $port = request_int($_POST, 'port', VIRTUSPHERE_DIRECTORY_DEFAULT_PORT);
        $errors = [];
        if (!directory_host_is_valid($host)) {
            $errors['host'] = __t('directory.err_host');
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = __t('directory.err_port');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $controllerId = repo_directory_add_controller($db, $host, $port, $actorId);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'added directory controller ' . $controllerId . ' (' . $host . ':' . $port . ')', $actorId);
        flash_set('success', __t('directory.flash_controller_added'));

        return true;
    }
    if ($action === 'directory_test_controller') {
        $controllerId = request_int($_POST, 'controller_id');
        $result = directory_test_saved_controller($db, $controllerId, $actorId);
        flash_set($result['ok'] ? 'success' : 'error', $result['ok']
            ? __t('directory.flash_test_ok')
            : directory_outcome_message($result['outcome']));

        return true;
    }
    if ($action === 'directory_set_controller_enabled') {
        $controllerId = request_int($_POST, 'controller_id');
        $enable = request_int($_POST, 'enabled') === 1;
        $result = repo_directory_change_controller_enabled($db, $controllerId, $enable, $actorId);
        if ($result === 'missing') {
            throw new ValidationException(['controller_id' => __t('directory.err_controller_missing')]);
        }
        if ($result === 'retest') {
            throw new ValidationException(['controller_id' => __t('directory.err_controller_retest')]);
        }
        if ($result === 'last_controller') {
            throw new ValidationException(['controller_id' => __t('directory.err_last_controller')]);
        }
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ($enable ? 'enabled' : 'disabled') . ' directory controller ' . $controllerId, $actorId);
        flash_set('success', $enable ? __t('directory.flash_controller_enabled') : __t('directory.flash_controller_disabled'));

        return true;
    }
    if ($action === 'directory_move_controller') {
        $controllerId = request_int($_POST, 'controller_id');
        $direction = request_string($_POST, 'direction') === 'up' ? -1 : 1;
        repo_directory_move_controller($db, $controllerId, $direction, $actorId);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'changed priority of directory controller ' . $controllerId, $actorId);
        flash_set('success', __t('directory.flash_controller_moved'));

        return true;
    }
    if ($action === 'directory_delete_controller') {
        $controllerId = request_int($_POST, 'controller_id');
        $result = repo_directory_delete_controller_guarded($db, $controllerId);
        if ($result === 'missing') {
            throw new ValidationException(['controller_id' => __t('directory.err_controller_missing')]);
        }
        if ($result === 'last_controller') {
            throw new ValidationException(['controller_id' => __t('directory.err_last_controller')]);
        }
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'deleted directory controller ' . $controllerId, $actorId);
        flash_set('success', __t('directory.flash_controller_deleted'));

        return true;
    }
    if ($action === 'directory_set_enabled') {
        $enable = request_int($_POST, 'enabled') === 1;
        repo_transaction($db, function () use ($db, $actor, $actorId, $enable): void {
            directory_lock_activation_state($db);
            if ($enable) {
                $blockers = directory_activation_blockers($db, $actor);
                if ($blockers !== [] || !virtusphere_is_request_secure()) {
                    throw new ValidationException(['enabled' => __t('directory.err_activation_blocked')]);
                }
            }
            repo_directory_set_config_enabled($db, $enable, $actorId);
            audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ($enable ? 'enabled' : 'disabled') . ' Active Directory login', $actorId);
        });
        flash_set('success', $enable ? __t('directory.flash_enabled') : __t('directory.flash_disabled'));

        return true;
    }
    if ($action === 'directory_delete_config') {
        repo_directory_delete_config($db);
        unset($_SESSION['directory_import_candidates'], $_SESSION['directory_search_display']);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'deleted directory configuration', $actorId);
        flash_set('success', __t('directory.flash_config_deleted'));

        return true;
    }
    if ($action === 'directory_search') {
        $result = directory_search_users($db, request_string($_POST, 'directory_search'));
        $_SESSION['directory_search_display'] = [
            'expires_at' => time() + VIRTUSPHERE_DIRECTORY_IMPORT_CANDIDATE_TTL_SECONDS,
            'rows' => directory_store_import_candidates($result['rows']),
        ];
        flash_set($result['truncated'] ? 'warning' : 'success', __t(
            $result['truncated'] ? 'directory.flash_search_truncated' : 'directory.flash_search_results',
            ['count' => count($result['rows'])]
        ));

        return true;
    }
    if ($action === 'directory_import') {
        $result = directory_import_candidate(
            $db,
            request_string($_POST, 'import_token'),
            request_string($_POST, 'role', VIRTUSPHERE_ROLE_USER),
            $actorId
        );
        flash_set($result['created'] ? 'success' : 'warning', $result['created']
            ? __t('directory.flash_imported')
            : __t('directory.flash_already_imported'));

        return true;
    }
    if ($action === 'directory_sync_user') {
        $target = repo_directory_user($db, request_int($_POST, 'user_id'));
        if ($target === null) {
            throw new ValidationException(['user_id' => __t('users.err_user_missing')]);
        }
        $entry = directory_find_user_by_guid($db, (string) $target['ad_object_guid']);
        repo_directory_update_user_cache($db, (int) $target['id'], $entry);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'synchronized Active Directory user id ' . (int) $target['id'], $actorId);
        flash_set('success', __t('directory.flash_user_synced'));

        return true;
    }

    return false;
}

/** @return list<array<string,mixed>> */
function users_directory_take_search_results(): array
{
    $state = $_SESSION['directory_search_display'] ?? [];
    if (!is_array($state)) {
        return [];
    }
    if (array_key_exists('rows', $state)) {
        if ((int) ($state['expires_at'] ?? 0) < time()) {
            unset($_SESSION['directory_search_display'], $_SESSION['directory_import_candidates']);
            return [];
        }
        $rows = $state['rows'] ?? [];
    } else {
        // Compatibility with a search performed by an older PHP worker.
        $rows = $state;
    }

    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}
