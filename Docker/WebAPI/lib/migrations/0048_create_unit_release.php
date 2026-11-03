<?php

declare(strict_types=1);

/**
 * The operator release of an unresolved create unit (Etappe 14B, Teiletappe F;
 * plan section 10.5).
 *
 * A create unit that ended `uncertain` states that nobody established what
 * happened to its VM. Only a person can end that, by looking where VirtuSphere
 * cannot: at the ESXi host. What they establish has to be recorded where the
 * existing external-review evidence lives, so a later reader finds one
 * append-only trail rather than two, and it has to name the exact unit it
 * covers - a job can have several unresolved units, released at different
 * times for different reasons.
 *
 * Additive: no existing row changes meaning, and a resolution written before
 * this migration keeps its scope and its NULL create result.
 */
function migrate_0048_create_unit_release(mysqli $db): void
{
    migrator_add_column(
        $db,
        'deploy_recovery_resolutions',
        'create_result_id',
        'BIGINT UNSIGNED NULL AFTER remote_execution_id'
    );

    // ON DELETE SET NULL rather than RESTRICT, for the reason migration 0047
    // records: results cascade away with their job and so does this table, and
    // RESTRICT could abort a job deletion depending on the order MySQL cascades
    // the two. The resolution survives with its reason and its previous_state
    // snapshot, which is the part that had to outlive the row.
    if (!migrator_fk_exists($db, 'deploy_recovery_resolutions', 'fk_deploy_recovery_resolution_create_result')) {
        $db->query(
            'ALTER TABLE deploy_recovery_resolutions
             ADD CONSTRAINT fk_deploy_recovery_resolution_create_result
             FOREIGN KEY (create_result_id) REFERENCES deploy_create_vm_results(id) ON DELETE SET NULL'
        );
    }

    // The scope vocabulary gains its third member. The create-unit half of the
    // rule - a `create_unit` row names its result and no remote handle - is
    // enforced in PHP for the same reason as in 0047: MySQL refuses a column
    // that appears both in a CHECK and in a foreign key with a referential
    // action, and SET NULL is the right action here.
    if (migrator_check_exists($db, 'deploy_recovery_resolutions', 'deploy_recovery_resolution_scope_check')) {
        $db->query('ALTER TABLE deploy_recovery_resolutions DROP CHECK deploy_recovery_resolution_scope_check');
    }
    $db->query(
        "ALTER TABLE deploy_recovery_resolutions
         ADD CONSTRAINT deploy_recovery_resolution_scope_check CHECK (
             (resolution_scope = _utf8mb4'remote_execution' AND remote_execution_id IS NOT NULL)
             OR (resolution_scope = _utf8mb4'legacy_job' AND remote_execution_id IS NULL)
             OR (resolution_scope = _utf8mb4'create_unit' AND remote_execution_id IS NULL)
         )"
    );

    migrator_out('0048: recovery resolutions can name the create unit they release');
}
