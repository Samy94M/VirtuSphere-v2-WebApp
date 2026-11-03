<?php

declare(strict_types=1);

/**
 * MX1 (FM-05, 03.11.2026): the one cause per VM that the MECM device sync last
 * reported through `mecm_report.php?action=reportDeviceCauses`.
 *
 *  - `vm_id` is the primary key: one cause per VM (Q4). The foreign key cascades,
 *    so deleting a VM removes its cause; the writer never inserts for a VM that
 *    is gone (conditional INSERT ... SELECT in lib/repo/vm_sync_causes.php).
 *  - `rollout_revision` is the revision the sync read from getDeviceList. A
 *    reset raises the VM's revision, and the reader then ignores the old row.
 *  - `run_id` is the device-sync run that last wrote the row; a complete report
 *    replaces every row of other runs. `observed_at` is server time of the last
 *    observation and drives the 7-day retention.
 *
 * Additive (class A in docs/operations/upgrade-recovery.md): an older portal
 * neither reads nor writes the table. No backfill: before this release the sync
 * sent no per-VM causes. Idempotent through CREATE TABLE IF NOT EXISTS.
 */
function migrate_0072_device_sync_causes(mysqli $db): void
{
    $db->query('CREATE TABLE IF NOT EXISTS deploy_vm_sync_causes (
        vm_id INT NOT NULL,
        code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        rollout_revision BIGINT UNSIGNED NOT NULL,
        run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vm_id),
        INDEX deploy_vm_sync_causes_observed (observed_at),
        CONSTRAINT fk_deploy_vm_sync_causes_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
