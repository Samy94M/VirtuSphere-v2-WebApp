<?php

declare(strict_types=1);

/** Persist attempts, including interrupted tests, across worker restarts. */
function migrate_0055_ansible_test_schedule(mysqli $db): void
{
    migrator_add_column($db, 'deploy_credentials', 'ansible_test_started_at',
        'TIMESTAMP NULL DEFAULT NULL AFTER ansible_test_generation');
    migrator_out('0055: durable scheduling timestamp for Ansible full tests');
}
