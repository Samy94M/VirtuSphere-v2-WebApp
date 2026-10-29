<?php

declare(strict_types=1);

/**
 * Where a stored create failure text came from (B8, 29.10.2026). Prod job 1527
 * showed "the create module reported a failure" under the label "Meldung des
 * Hosts", although that sentence is the inspector's fallback for a failure
 * without a message of its own. The origin is now recorded when the row is
 * written, so the portal never has to tell the two apart by comparing text.
 *
 *  - `error_detail_from_host` 1: `error_detail` is the module's own message;
 *    0: the module gave none and `error_detail` is the fallback; NULL: not
 *    recorded (every other code, and rows from before this migration).
 *
 * Additive and idempotent through the column and constraint probes. Existing
 * rows keep NULL, which the portal labels neutrally: their origin is unknown.
 */
function migrate_0067_create_error_detail_origin(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_create_vm_results')) {
        return;
    }
    migrator_add_column($db, 'deploy_create_vm_results', 'error_detail_from_host',
        'TINYINT(1) NULL AFTER error_detail');

    if (!migrator_check_exists($db, 'deploy_create_vm_results', 'deploy_create_result_detail_origin_check')) {
        $db->query('ALTER TABLE deploy_create_vm_results ADD CONSTRAINT deploy_create_result_detail_origin_check CHECK (
            error_detail_from_host IS NULL OR (error_detail_from_host IN (0, 1) AND error_detail IS NOT NULL)
        )');
    }

    migrator_out('0067: create failures record whether their text is the host message');
}
