<?php

declare(strict_types=1);

require_once __DIR__ . '/../directory_constants.php';
require_once __DIR__ . '/helpers.php';

/** @return array<string,mixed>|null */
function repo_directory_user_by_guid(mysqli $db, string $guidBytes): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT id, name, auth_source, email, role, is_active, must_change_password,
                ad_object_guid, ad_upn, ad_sam_account_name, ad_display_name,
                ad_account_enabled, ad_last_checked_at, last_seen_at
         FROM deploy_users WHERE auth_source = ? AND ad_object_guid = ? LIMIT 1',
        'ss',
        [VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY, $guidBytes]
    );
}

/** @return array<string,mixed>|null */
function repo_directory_user(mysqli $db, int $userId): ?array
{
    $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;

    return repo_fetch_one(
        $db,
        'SELECT id, name, auth_source, email, role, is_active, must_change_password,
                ad_object_guid, ad_upn, ad_sam_account_name, ad_display_name,
                ad_account_enabled, ad_last_checked_at, last_seen_at
         FROM deploy_users WHERE id = ? AND auth_source = ? LIMIT 1',
        'is',
        [$userId, $source]
    );
}

/**
 * Locks the three local authorization facts that must still agree immediately
 * before an AD session is created. LDAP success alone never grants a session.
 *
 * @return array<string,mixed>|null
 */
function repo_directory_login_snapshot(
    mysqli $db,
    string $guidBytes,
    int $controllerId,
    int $revision
): ?array {
    $config = repo_fetch_one(
        $db,
        'SELECT enabled, revision FROM deploy_ad_config WHERE id = 1 FOR UPDATE'
    );
    if ($config === null || (int) $config['enabled'] !== 1 || (int) $config['revision'] !== $revision) {
        return null;
    }

    $controller = repo_fetch_one(
        $db,
        'SELECT id FROM deploy_ad_controllers
         WHERE id = ? AND config_id = 1 AND enabled = 1 AND validated_revision = ?
         LIMIT 1 FOR UPDATE',
        'ii',
        [$controllerId, $revision]
    );
    if ($controller === null) {
        return null;
    }

    $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;

    return repo_fetch_one(
        $db,
        'SELECT id, name, auth_source, email, role, is_active,
                must_change_password, ad_object_guid, ad_upn,
                ad_sam_account_name, ad_display_name, ad_account_enabled,
                ad_last_checked_at, last_seen_at
         FROM deploy_users
         WHERE auth_source = ? AND ad_object_guid = ? AND is_active = 1
           AND ad_account_enabled = 1
         LIMIT 1 FOR UPDATE',
        'ss',
        [$source, $guidBytes]
    );
}

/**
 * @param array{guid_bytes:string,upn:string,sam:string,display_name:string,email:string,enabled:bool} $entry
 * @return array{created:bool,user_id:int,reason:string}
 */
function repo_directory_import_user(mysqli $db, array $entry, string $role): array
{
    return repo_transaction($db, function () use ($db, $entry, $role): array {
        $existing = repo_directory_user_by_guid($db, $entry['guid_bytes']);
        if ($existing !== null) {
            return ['created' => false, 'user_id' => (int) $existing['id'], 'reason' => 'guid_exists'];
        }
        $name = $entry['upn'];
        if (repo_fetch_one($db, 'SELECT id FROM deploy_users WHERE name = ? LIMIT 1', 's', [$name]) !== null) {
            return ['created' => false, 'user_id' => 0, 'reason' => 'name_conflict'];
        }

        $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
        $enabled = $entry['enabled'] ? 1 : 0;
        $stmt = $db->prepare(
            'INSERT INTO deploy_users
                (name, auth_source, password, email, role, is_active,
                 must_change_password, ad_object_guid, ad_upn,
                 ad_sam_account_name, ad_display_name, ad_account_enabled,
                 ad_last_checked_at)
             VALUES (?, ?, NULL, ?, ?, 1, 0, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->bind_param(
            'ssssssssi',
            $name,
            $source,
            $entry['email'],
            $role,
            $entry['guid_bytes'],
            $entry['upn'],
            $entry['sam'],
            $entry['display_name'],
            $enabled
        );
        $stmt->execute();

        return ['created' => true, 'user_id' => (int) $db->insert_id, 'reason' => 'created'];
    });
}

/** @param array{upn:string,sam:string,display_name:string,email:string,enabled:bool} $entry */
function repo_directory_update_user_cache(mysqli $db, int $userId, array $entry): void
{
    $enabled = $entry['enabled'] ? 1 : 0;
    $stmt = $db->prepare(
        'UPDATE deploy_users
         SET ad_upn = ?, ad_sam_account_name = ?, ad_display_name = ?, email = ?,
             ad_account_enabled = ?, ad_last_checked_at = NOW(), updated_at = updated_at
         WHERE id = ? AND auth_source = ?'
    );
    $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
    $stmt->bind_param('ssssiis', $entry['upn'], $entry['sam'], $entry['display_name'], $entry['email'], $enabled, $userId, $source);
    $stmt->execute();
}
