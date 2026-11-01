<?php

declare(strict_types=1);

/**
 * A failed deploy job marks the MECM axis as `deploy_failed` (MX0, decision
 * F8/F8a, 28.10.2026). The worker used to write `failed`, although MECM never
 * ran: the sync was not failed, it was never attempted. `failed` had no other
 * writer, ever (only the deploy failure path, since the value existed), so it
 * is withdrawn here like `submitted` in 0028 instead of being kept without a
 * writer. A real MECM failure value comes with its own writer.
 *
 * Every stored `failed` is therefore the deploy failure mark and is renamed,
 * not reinterpreted, in the three places that hold the token:
 *  - `deploy_vms.mecm_sync_state` (the ENUM), without moving `updated_at`;
 *  - `deploy_vm_status_events.mecm_sync_state`, so history keeps its label;
 *  - `deploy_job_vm_states.mecm_before`, the captured prestate. The restore
 *    path writes it back and compares it with the VM's current value; an old
 *    token there would either be refused by the ENUM or leave the VM
 *    `deploying`. The captured fact stays the same, only its name changes.
 *
 * The wire is unchanged: getDeviceList still emits `failed` for this state
 * (virtusphere_mecm_sync_wire_value()).
 *
 * Idempotent: widen to the union, rename, narrow to the final set. A second run
 * renames nothing. On a fresh schema 0028 narrows the empty column to the old
 * set first, and this migration brings it back to the final one.
 */
function migrate_0070_mecm_sync_deploy_failed(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_vms') || !migrator_column_exists($db, 'deploy_vms', 'mecm_sync_state')) {
        return;
    }
    $old = 'failed';
    $new = 'deploy_failed';

    $db->query("ALTER TABLE deploy_vms MODIFY mecm_sync_state ENUM('not_ready','pending','registered','failed','deploy_failed') NOT NULL DEFAULT 'not_ready'");

    $stmt = $db->prepare('UPDATE deploy_vms SET mecm_sync_state = ?, updated_at = updated_at WHERE mecm_sync_state = ?');
    $stmt->bind_param('ss', $new, $old);
    $stmt->execute();
    $vms = max(0, $stmt->affected_rows);

    $events = 0;
    if (migrator_table_exists($db, 'deploy_vm_status_events')) {
        $stmt = $db->prepare('UPDATE deploy_vm_status_events SET mecm_sync_state = ? WHERE mecm_sync_state = ?');
        $stmt->bind_param('ss', $new, $old);
        $stmt->execute();
        $events = max(0, $stmt->affected_rows);
    }

    $prestates = 0;
    if (migrator_table_exists($db, 'deploy_job_vm_states')) {
        $stmt = $db->prepare('UPDATE deploy_job_vm_states SET mecm_before = ? WHERE mecm_before = ?');
        $stmt->bind_param('ss', $new, $old);
        $stmt->execute();
        $prestates = max(0, $stmt->affected_rows);
    }

    $db->query("ALTER TABLE deploy_vms MODIFY mecm_sync_state ENUM('not_ready','pending','registered','deploy_failed') NOT NULL DEFAULT 'not_ready'");

    migrator_out('0070: the deploy failure mark on the MECM axis is deploy_failed (renamed: ' . $vms . ' VM(s), '
        . $events . ' status event(s), ' . $prestates . ' job prestate(s))');
}
