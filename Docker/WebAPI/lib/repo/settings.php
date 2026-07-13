<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function repo_setting(mysqli $db, string $key): ?array
{
    $stmt = $db->prepare('SELECT setting_key, setting_value, updated_at FROM deploy_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function repo_setting_value(mysqli $db, string $key, string $default = ''): string
{
    $row = repo_setting($db, $key);

    return $row === null ? $default : (string) ($row['setting_value'] ?? $default);
}

function repo_set_setting(mysqli $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO deploy_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()');
    $stmt->bind_param('sss', $key, $value, $value);
    $stmt->execute();
}