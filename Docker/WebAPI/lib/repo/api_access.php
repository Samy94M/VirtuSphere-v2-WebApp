<?php

declare(strict_types=1);

// Admin management for the machine-API IP allowlist (deploy_accessToWebAPI).
// The wire behaviour of a refusal stays the frozen legacy 403; since K20 each
// entry carries one caller role (lib/machine_api_roles.php), and an entry
// without one keeps opening every endpoint until a role is assigned.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../machine_api_roles.php';

function repo_api_access_entries(mysqli $db): array
{
    $stmt = $db->prepare('SELECT id, ipAddress, description, role, created_at FROM deploy_accessToWebAPI ORDER BY id');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Why an entry for $ip with $role cannot be added or assigned, or null. One
 * IP may hold one entry per role (a host can be MECM server and client); an
 * entry without a role already opens every endpoint, so a second entry for
 * that IP would only hide which one is meant. $exceptId skips the entry being
 * changed.
 */
function repo_api_access_conflict(mysqli $db, string $ip, string $role, int $exceptId = 0): ?string
{
    $rows = repo_fetch_all(repo_api_access_rows($db, $ip));
    foreach ($rows as $row) {
        if ((int) $row['id'] === $exceptId) {
            continue;
        }
        if ($row['role'] === null) {
            return 'legacy_present';
        }
        if ((string) $row['role'] === $role) {
            return 'duplicate';
        }
    }

    return null;
}

function repo_api_access_rows(mysqli $db, string $ip): mysqli_result
{
    $stmt = $db->prepare('SELECT id, role FROM deploy_accessToWebAPI WHERE ipAddress = ?');
    $stmt->bind_param('s', $ip);
    $stmt->execute();

    return $stmt->get_result();
}

function repo_api_access_add(mysqli $db, string $ip, string $description, string $role): void
{
    if (!in_array($role, VIRTUSPHERE_MACHINE_API_ROLES, true)) {
        throw new InvalidArgumentException('Unknown machine API role.');
    }
    $stmt = $db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description, role) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $ip, $description, $role);
    $stmt->execute();
}

/** @return array<string,mixed>|null the entry before the change, null when it does not exist */
function repo_api_access_set_role(mysqli $db, int $id, string $role): ?array
{
    if (!in_array($role, VIRTUSPHERE_MACHINE_API_ROLES, true)) {
        throw new InvalidArgumentException('Unknown machine API role.');
    }
    $entry = repo_fetch_one($db, 'SELECT id, ipAddress, description, role FROM deploy_accessToWebAPI WHERE id = ? LIMIT 1', 'i', [$id]);
    if ($entry === null) {
        return null;
    }
    $stmt = $db->prepare('UPDATE deploy_accessToWebAPI SET role = ? WHERE id = ?');
    $stmt->bind_param('si', $role, $id);
    $stmt->execute();

    return $entry;
}

function repo_api_access_without_role_count(mysqli $db): int
{
    return (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_accessToWebAPI WHERE role IS NULL');
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
