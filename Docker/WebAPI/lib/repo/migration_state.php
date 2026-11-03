<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Migration names the database has recorded as applied (deploy_migrations, written by
 * lib/migrate.php). The version overview compares them with the names the code
 * defines, to show whether the schema is behind the release.
 *
 * @return list<string>
 */
function repo_applied_migration_names(mysqli $db): array
{
    $stmt = $db->prepare('SELECT name FROM deploy_migrations ORDER BY name');
    $stmt->execute();

    $names = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $names[] = (string) $row['name'];
    }

    return $names;
}
