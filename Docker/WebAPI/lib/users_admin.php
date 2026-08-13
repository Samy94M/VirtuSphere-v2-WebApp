<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/auth_schema.php';
require_once __DIR__ . '/password_policy.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/validate.php';

function user_is_last_active_local_admin(mysqli $db, int $targetId): bool
{
    if ($targetId <= 0) {
        return false;
    }
    $target = repo_fetch_one(
        $db,
        'SELECT role, is_active, auth_source FROM deploy_users WHERE id = ? LIMIT 1',
        'i',
        [$targetId]
    );
    if ($target === null
        || (string) $target['role'] !== VIRTUSPHERE_ROLE_ADMIN
        || (int) $target['is_active'] !== 1
        || (string) $target['auth_source'] !== VIRTUSPHERE_AUTH_SOURCE_LOCAL
    ) {
        return false;
    }

    if (auth_user_source_schema_available($db)) {
        return (int) repo_scalar(
            $db,
            'SELECT COUNT(*) FROM deploy_users WHERE role = ? AND is_active = 1 AND auth_source = ?',
            'ss',
            [VIRTUSPHERE_ROLE_ADMIN, VIRTUSPHERE_AUTH_SOURCE_LOCAL]
        ) <= 1;
    }

    return (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_users WHERE role = ? AND is_active = 1', 's', [VIRTUSPHERE_ROLE_ADMIN]) <= 1;
}

/** @return array<string,mixed>|null */
function users_admin_target(mysqli $db, int $targetId): ?array
{
    $source = auth_user_source_schema_available($db) ? 'auth_source' : "'local' AS auth_source";
    // csp-allow: interpolated-sql
    return repo_fetch_one($db, 'SELECT id, name, ' . $source . ', role, is_active FROM deploy_users WHERE id = ? LIMIT 1', 'i', [$targetId]);
}

function users_require_local_target(mysqli $db, int $targetId): array
{
    $target = users_admin_target($db, $targetId);
    if ($target === null || (string) $target['auth_source'] !== VIRTUSPHERE_AUTH_SOURCE_LOCAL) {
        throw new ValidationException(['user_id' => __t('users.err_local_action_only')]);
    }

    return $target;
}

function users_handle_account_action(mysqli $db, array $actor, string $action): bool
{
    if ($action === 'create') {
        $validator = new Validator();
        $name = $validator->requireString('name', $_POST['name'] ?? '', __t('common.name'), 191);
        $email = $validator->optionalString('email', $_POST['email'] ?? '', __t('users.field_email'), 191);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $validator->add('email', __t('users.err_email_invalid'));
        }
        $password = request_string($_POST, 'password');
        $policyError = password_policy_error($password, password_policy_min_length($db), 'users.err_password_min');
        if ($policyError !== null) {
            $validator->add('password', $policyError);
        }
        $role = role_normalize(request_string($_POST, 'role', VIRTUSPHERE_ROLE_USER));
        $validator->throwIfInvalid();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $active = 1;
        $mustChange = 1;
        $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
        if (auth_user_source_schema_available($db)) {
            $stmt = $db->prepare('INSERT INTO deploy_users (name, auth_source, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssii', $name, $source, $hash, $email, $role, $active, $mustChange);
        } else {
            $stmt = $db->prepare('INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssii', $name, $hash, $email, $role, $active, $mustChange);
        }
        $stmt->execute();
        audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'created local user ' . audit_snippet($name), (int) $actor['id']);
        flash_set('success', __t('users.flash_created'));

        return true;
    }
    if (!in_array($action, ['set_active', 'set_role', 'reset_password', 'clear_lock'], true)) {
        return false;
    }

    $targetId = request_int($_POST, 'user_id');
    $target = users_admin_target($db, $targetId);
    if ($target === null) {
        throw new ValidationException(['user_id' => __t('users.err_user_missing')]);
    }
    if ($action === 'set_active') {
        $active = request_int($_POST, 'is_active') === 1 ? 1 : 0;
        if ($targetId === (int) $actor['id'] && $active === 0) {
            throw new RuntimeException(__t('users.err_self_deactivate'));
        }
        $result = repo_transaction($db, function () use ($db, $targetId, $active, $actor): string {
            $lockSql = auth_user_source_schema_available($db)
                ? "SELECT id FROM deploy_users WHERE role = 'admin' AND is_active = 1 AND auth_source = 'local' ORDER BY id FOR UPDATE"
                : "SELECT id FROM deploy_users WHERE role = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE";
            $db->query($lockSql);
            $lockedTarget = repo_fetch_one($db, 'SELECT id, role, is_active, auth_source FROM deploy_users WHERE id = ? FOR UPDATE', 'i', [$targetId]);
            if ($lockedTarget === null) {
                return 'missing';
            }
            if ($active === 0 && user_is_last_active_local_admin($db, $targetId)) {
                return 'last_admin';
            }
            $stmt = $db->prepare('UPDATE deploy_users SET is_active = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('ii', $active, $targetId);
            $stmt->execute();
            audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'changed active state for user id ' . $targetId, (int) $actor['id']);

            return 'ok';
        });
        if ($result === 'missing') {
            throw new ValidationException(['user_id' => __t('users.err_user_missing')]);
        }
        if ($result === 'last_admin') {
            throw new RuntimeException(__t('users.err_last_local_admin'));
        }
        flash_set('success', __t('users.flash_status'));

        return true;
    }
    if ($action === 'set_role') {
        $role = role_normalize(request_string($_POST, 'role', VIRTUSPHERE_ROLE_USER));
        $result = repo_transaction($db, function () use ($db, $targetId, $role, $actor): string {
            $lockSql = auth_user_source_schema_available($db)
                ? "SELECT id FROM deploy_users WHERE role = 'admin' AND is_active = 1 AND auth_source = 'local' ORDER BY id FOR UPDATE"
                : "SELECT id FROM deploy_users WHERE role = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE";
            $db->query($lockSql);
            $lockedTarget = repo_fetch_one($db, 'SELECT id, role, is_active, auth_source FROM deploy_users WHERE id = ? FOR UPDATE', 'i', [$targetId]);
            if ($lockedTarget === null) {
                return 'missing';
            }
            if ($role !== VIRTUSPHERE_ROLE_ADMIN && user_is_last_active_local_admin($db, $targetId)) {
                return 'last_admin';
            }
            $stmt = $db->prepare('UPDATE deploy_users SET role = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('si', $role, $targetId);
            $stmt->execute();
            audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'changed role for user id ' . $targetId, (int) $actor['id']);

            return 'ok';
        });
        if ($result === 'missing') {
            throw new ValidationException(['user_id' => __t('users.err_user_missing')]);
        }
        if ($result === 'last_admin') {
            throw new RuntimeException(__t('users.err_last_local_admin'));
        }
        flash_set('success', __t('users.flash_role'));

        return true;
    }
    users_require_local_target($db, $targetId);
    if ($action === 'reset_password') {
        $password = request_string($_POST, 'password');
        $policyError = password_policy_error($password, password_policy_min_length($db), 'users.err_password_min');
        if ($policyError !== null) {
            throw new ValidationException(['password' => $policyError]);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $mustChange = 1;
        if (auth_user_source_schema_available($db)) {
            $stmt = $db->prepare('UPDATE deploy_users SET password = ?, must_change_password = ?, updated_at = NOW() WHERE id = ? AND auth_source = ?');
            $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
            $stmt->bind_param('siis', $hash, $mustChange, $targetId, $source);
        } else {
            $stmt = $db->prepare('UPDATE deploy_users SET password = ?, must_change_password = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('sii', $hash, $mustChange, $targetId);
        }
        $stmt->execute();
        audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'reset password for local user id ' . $targetId, (int) $actor['id']);
        flash_set('success', __t('users.flash_password_reset'));
    } else {
        if (auth_user_source_schema_available($db)) {
            $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
            $stmt = $db->prepare('UPDATE deploy_users SET locked_until = NULL, updated_at = NOW() WHERE id = ? AND auth_source = ?');
            $stmt->bind_param('is', $targetId, $source);
        } else {
            $stmt = $db->prepare('UPDATE deploy_users SET locked_until = NULL, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $targetId);
        }
        $stmt->execute();
        audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'cleared login lock for local user id ' . $targetId, (int) $actor['id']);
        flash_set('success', __t('users.flash_lock_cleared'));
    }

    return true;
}

/** @return list<array<string,mixed>> */
function users_admin_rows(mysqli $db): array
{
    $directoryColumns = auth_user_source_schema_available($db)
        ? 'auth_source, ad_upn, ad_display_name, ad_account_enabled, ad_last_checked_at'
        : "'local' AS auth_source, NULL AS ad_upn, NULL AS ad_display_name, NULL AS ad_account_enabled, NULL AS ad_last_checked_at";
    // csp-allow: interpolated-sql
    $stmt = $db->prepare(
        'SELECT id, name, ' . $directoryColumns . ', email, role, is_active, must_change_password,
                locked_until, last_seen_at, created_at, updated_at
         FROM deploy_users ORDER BY id'
    );
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}
