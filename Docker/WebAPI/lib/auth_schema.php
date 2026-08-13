<?php

declare(strict_types=1);

/**
 * Additive migrations may be installed immediately before the matching web
 * image. These probes keep local sign-in available during that narrow rollout
 * window without treating an unknown row value as a local authentication
 * source.
 */
function auth_schema_column_exists(mysqli $db, string $table, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($db) . ':' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $cache[$key] = (int) ($row['c'] ?? 0) === 1;
}

function auth_schema_table_exists(mysqli $db, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($db) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $cache[$key] = (int) ($row['c'] ?? 0) === 1;
}

function auth_user_source_schema_available(mysqli $db): bool
{
    return auth_schema_column_exists($db, 'deploy_users', 'auth_source');
}

function auth_attempt_source_schema_available(mysqli $db): bool
{
    return auth_schema_column_exists($db, 'deploy_login_attempts', 'auth_source');
}

function auth_attempt_result_schema_available(mysqli $db): bool
{
    return auth_schema_column_exists($db, 'deploy_login_attempts', 'result');
}

function directory_schema_available(mysqli $db): bool
{
    return auth_user_source_schema_available($db)
        && auth_attempt_source_schema_available($db)
        && auth_attempt_result_schema_available($db)
        && auth_schema_table_exists($db, 'deploy_ad_config')
        && auth_schema_table_exists($db, 'deploy_ad_controllers')
        && auth_schema_table_exists($db, 'deploy_ad_controller_state');
}
