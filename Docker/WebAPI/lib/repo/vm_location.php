<?php

declare(strict_types=1);

/**
 * One-off data correction for the per-VM location overrides, shared by migration
 * 0014 and its test. Kept dependency-free (plain mysqli) because migrate.php runs
 * outside the portal bootstrap.
 *
 * Before the deploy honoured vm_datastore/vm_datacenter, the VM editor prefilled
 * both fields with the mission value and wrote them straight back, so most rows
 * hold a copy rather than an override. With "empty = inherit from the mission"
 * those copies would become silent, permanent overrides that keep pointing at the
 * value the mission had when the VM was last saved.
 *
 * Only exact copies are cleared. The comparison is binary on purpose: a
 * deliberate case variant is a real override and has to survive.
 *
 * @return array{datastore:int, datacenter:int} rows cleared per column
 */
function repo_normalize_vm_location_overrides(mysqli $db): array
{
    $db->query("UPDATE deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
        SET v.vm_datastore = ''
        WHERE COALESCE(v.vm_datastore, '') <> ''
          AND CAST(v.vm_datastore AS BINARY) = CAST(m.hypervisor_datastorage AS BINARY)");
    $datastore = max(0, $db->affected_rows);

    $db->query("UPDATE deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
        SET v.vm_datacenter = ''
        WHERE COALESCE(v.vm_datacenter, '') <> ''
          AND CAST(v.vm_datacenter AS BINARY) = CAST(m.hypervisor_datacenter AS BINARY)");
    $datacenter = max(0, $db->affected_rows);

    return ['datastore' => $datastore, 'datacenter' => $datacenter];
}
