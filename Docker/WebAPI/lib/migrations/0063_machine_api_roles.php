<?php

declare(strict_types=1);

/**
 * K20 (VT-01): one role per machine-API allowlist entry. Existing entries get
 * none and keep opening every endpoint until an administrator assigns one; the
 * role is never inferred from the description or from traffic.
 */
function migrate_0063_machine_api_roles(mysqli $db): void
{
    migrator_add_column($db, 'deploy_accessToWebAPI', 'role',
        'VARCHAR(16) NULL AFTER description');
    if (!migrator_check_exists($db, 'deploy_accessToWebAPI', 'deploy_access_role_check')) {
        $db->query("ALTER TABLE deploy_accessToWebAPI ADD CONSTRAINT deploy_access_role_check CHECK (
            role IS NULL OR role IN (_utf8mb4'mecm_server', _utf8mb4'ansible_host', _utf8mb4'client')
        )");
    }

    migrator_out('0063: machine API allowlist entries carry an optional role');
}
