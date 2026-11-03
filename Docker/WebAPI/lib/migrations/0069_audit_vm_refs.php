<?php

declare(strict_types=1);

/**
 * The VMs an audit row names in its context (SP3, decision F4, 28.10.2026). A
 * bulk change (`vm.bulk_changed`) and the convergence sweep
 * (`deploy.convergence_sweep`) are written against their mission and list the
 * affected VMs only inside context_json, so the VM chronicle could not find them
 * without reading every row of the log.
 *
 *  - `deploy_log_vm_refs`: one row per (VM, audit row). audit_event() fills it in
 *    the same transaction as the audit row, for every row whose context carries
 *    `vm_ids` (lib/repo/audit_vm_refs.php). The foreign key to deploy_logs
 *    cascades, so the log retention removes the references with their row. There
 *    is no foreign key to deploy_vms: a bulk delete names VMs that are gone.
 *  - Backfill: once, from the `vm_ids` already stored in the context. The writer
 *    always capped that list at VIRTUSPHERE_AUDIT_ID_LIST_MAX; what it cut was
 *    never stored and cannot be recovered.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS and INSERT IGNORE on the primary key.
 */
function migrate_0069_audit_vm_refs(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_logs')) {
        return;
    }
    $db->query('CREATE TABLE IF NOT EXISTS deploy_log_vm_refs (
        vm_id INT NOT NULL,
        log_id INT NOT NULL,
        PRIMARY KEY (vm_id, log_id),
        INDEX deploy_log_vm_refs_log (log_id),
        CONSTRAINT fk_deploy_log_vm_refs_log FOREIGN KEY (log_id) REFERENCES deploy_logs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $db->query("INSERT IGNORE INTO deploy_log_vm_refs (vm_id, log_id)
        SELECT DISTINCT ids.vm_id, l.id
        FROM deploy_logs l,
            JSON_TABLE(l.context_json, '$.vm_ids[*]' COLUMNS (vm_id INT PATH '$' NULL ON EMPTY NULL ON ERROR)) ids
        WHERE l.context_json IS NOT NULL
            AND JSON_CONTAINS_PATH(l.context_json, 'one', '$.vm_ids')
            AND ids.vm_id > 0");

    migrator_out('0069: audit rows are indexed by the VMs their context names (' . max(0, $db->affected_rows) . ' references)');
}
