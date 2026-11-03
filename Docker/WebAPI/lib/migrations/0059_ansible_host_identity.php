<?php

declare(strict_types=1);

/** Only pre-upgrade rows may use accept-new once; portal stays closed during migration. */
function migrate_0059_ansible_host_identity(mysqli $db): void
{
    // The eligibility marker comes last: after interrupted DDL a retry still
    // identifies the upgrade population. A completed marker is never reset.
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_fingerprint', 'VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_key_type', 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_first_seen_at', 'TIMESTAMP NULL DEFAULT NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_confirmed_at', 'TIMESTAMP NULL DEFAULT NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_confirmed_by', 'INT NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_observed_fingerprint', 'VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_observed_type', 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
    migrator_add_column($db, 'deploy_credentials', 'ansible_host_observed_at', 'TIMESTAMP NULL DEFAULT NULL');
    if (!migrator_column_exists($db, 'deploy_credentials', 'ansible_host_accept_new')) {
        // Default 1 bridges interruption between adding the marker and its
        // default change; existing Ansible rows remain eligible on retry.
        migrator_add_column($db, 'deploy_credentials', 'ansible_host_accept_new', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
    }
    $db->query('ALTER TABLE deploy_credentials ALTER COLUMN ansible_host_accept_new SET DEFAULT 0');
    $db->query("UPDATE deploy_credentials SET ansible_host_accept_new = 0 WHERE type <> 'ansible'");
    migrator_out('0059: Ansible host identity, with one-time upgrade eligibility');
}
