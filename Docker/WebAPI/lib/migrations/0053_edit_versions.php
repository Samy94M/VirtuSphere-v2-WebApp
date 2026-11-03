<?php

declare(strict_types=1);

/** Editor versions are advanced by the repository writers, independently of time. */
function migrate_0053_edit_versions(mysqli $db): void
{
    foreach (['deploy_missions', 'deploy_vms'] as $table) {
        migrator_add_column($db, $table, 'edit_version', 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at');
    }
    migrator_out('0053: monotone editor versions for missions and VMs');
}
