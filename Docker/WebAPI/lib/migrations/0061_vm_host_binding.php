<?php

declare(strict_types=1);

/**
 * K17 (FM-01, FM-E1): the binding of a VM remembers through which ESXi access
 * and against which access target its instance UUID was proven. Create warns
 * before it builds a bound VM anew on a different host; nothing is blocked.
 *
 * The host is a snapshot of `deploy_credentials.host` at binding time, because
 * FM-01 is exactly the case of an access whose target was changed afterwards.
 *
 * Backfill is evidence only: the newest succeeded or skipped create unit that
 * carried the stored UUID names its job's access. Access and host are taken
 * over only when that access has not been changed since the unit finished;
 * otherwise the host of that time is unknown and the row stays NULL (no
 * warning, never a guess). Bindings from a MAC export carry no unit row and stay NULL as well.
 */
function migrate_0061_vm_host_binding(mysqli $db): void
{
    migrator_add_column($db, 'deploy_vms', 'vm_bound_credential_id', 'INT NULL AFTER vm_instance_uuid');
    migrator_add_column($db, 'deploy_vms', 'vm_bound_esxi_host', 'VARCHAR(255) NULL AFTER vm_bound_credential_id');
    if (!migrator_fk_exists($db, 'deploy_vms', 'fk_deploy_vms_bound_credential')) {
        $db->query('ALTER TABLE deploy_vms ADD CONSTRAINT fk_deploy_vms_bound_credential FOREIGN KEY (vm_bound_credential_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL');
    }

    $db->query("UPDATE deploy_vms v
        INNER JOIN deploy_create_vm_results r ON r.id = (
            SELECT MAX(r2.id) FROM deploy_create_vm_results r2
            WHERE r2.vm_id = v.id
              AND r2.status IN ('succeeded', 'skipped')
              AND r2.vm_instance_uuid = v.vm_instance_uuid
        )
        INNER JOIN deploy_jobs j ON j.id = r.job_id
        INNER JOIN deploy_credentials c ON c.id = j.credential_esxi_id AND c.type = 'esxi'
        SET v.vm_bound_credential_id = c.id, v.vm_bound_esxi_host = c.host, v.updated_at = v.updated_at
        WHERE COALESCE(v.vm_instance_uuid, '') <> ''
          AND v.vm_bound_esxi_host IS NULL
          AND r.finished_at IS NOT NULL
          AND c.updated_at <= r.finished_at");
    $backfilled = max(0, $db->affected_rows);

    migrator_out('0061: VM bindings remember their ESXi access and host (' . $backfilled . ' backfilled from create evidence)');
}
