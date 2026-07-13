<?php

declare(strict_types=1);

// Admin management for the machine-API IP allowlist (deploy_accessToWebAPI).
// The table and machine_api_ip_allowed() wire behavior stay unchanged - this
// only adds the portal CRUD surface (ADR-0018).

require_once __DIR__ . '/helpers.php';

function repo_api_access_entries(mysqli $db): array
{
    $stmt = $db->prepare('SELECT id, ipAddress, description, created_at FROM deploy_accessToWebAPI ORDER BY id');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_api_access_exists(mysqli $db, string $ip): bool
{
    return (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_accessToWebAPI WHERE ipAddress = ?', 's', [$ip]) > 0;
}

function repo_api_access_add(mysqli $db, string $ip, string $description): void
{
    $stmt = $db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description) VALUES (?, ?)');
    $stmt->bind_param('ss', $ip, $description);
    $stmt->execute();
}

function repo_api_access_delete(mysqli $db, int $id): ?array
{
    $entry = repo_fetch_one($db, 'SELECT id, ipAddress, description FROM deploy_accessToWebAPI WHERE id = ? LIMIT 1', 'i', [$id]);
    if ($entry === null) {
        return null;
    }

    $stmt = $db->prepare('DELETE FROM deploy_accessToWebAPI WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    return $entry;
}
