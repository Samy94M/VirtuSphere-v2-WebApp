<?php

declare(strict_types=1);

/**
 * IDR-P02: the preparation of a create unit records the stored instance UUID
 * it looked for live and did not find. That fact, and nothing weaker, lets the
 * success commit replace the binding of an externally deleted VM with the new
 * instance. Historical rows stay NULL: they never carried the evidence.
 */
function migrate_0057_create_replaced_identity(mysqli $db): void
{
    migrator_add_column($db, 'deploy_create_vm_results', 'replaced_instance_uuid',
        'VARCHAR(64) NULL AFTER precheck_instance_uuid');

    if (!migrator_check_exists($db, 'deploy_create_vm_results', 'deploy_create_result_replacement_check')) {
        $db->query("ALTER TABLE deploy_create_vm_results ADD CONSTRAINT deploy_create_result_replacement_check CHECK (
            replaced_instance_uuid IS NULL OR existed_before = 0
        )");
    }

    migrator_out('0057: create units record the stored identity proven absent before a replacement');
}
