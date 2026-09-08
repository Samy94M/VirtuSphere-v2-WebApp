<?php

declare(strict_types=1);

/** Additive fencing for long-running Ansible credential diagnostics. */
function migrate_0052_ansible_preflight_generation(mysqli $db): void
{
    migrator_add_column($db, 'deploy_credentials', 'config_revision', 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at');
    migrator_add_column($db, 'deploy_credentials', 'ansible_test_generation', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER config_revision');
    migrator_add_column($db, 'deploy_ansible_preflight_state', 'tested_config_revision', 'BIGINT UNSIGNED NULL AFTER last_component');
    migrator_add_column($db, 'deploy_ansible_preflight_state', 'test_generation', 'BIGINT UNSIGNED NULL AFTER tested_config_revision');
    migrator_out('0052: fenced Ansible preflight evidence by credential revision and test generation');
}
