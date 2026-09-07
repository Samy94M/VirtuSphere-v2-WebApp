<?php

declare(strict_types=1);

/** VM bundle reads and atomic child/bundle persistence. */

function repo_replace_interfaces(mysqli $db, int $vmId, mixed $interfaces, bool $preserveExistingMacs): void
{
    if (!is_iterable($interfaces)) {
        return;
    }

    $interfaces = repo_validate_interfaces($interfaces);
    $vm = repo_fetch_one($db, 'SELECT mission_id, vm_name FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$vmId]);
    if ($vm === null) {
        throw new RuntimeException('VM not found for interface persistence.');
    }
    $missionId = (int) $vm['mission_id'];
    if (repo_deploy_lock_mission($db, $missionId) === null) {
        throw new RuntimeException('Mission not found for interface persistence.');
    }

    // Resolve preserved MACs before the fingerprint comparison. A form that
    // omits an unchanged MAC must compare equal to the effective stored bundle.
    $effectiveInterfaces = [];
    foreach ($interfaces as $interface) {
        $interfaceId = repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id')));
        $effective = repo_allowed_columns($interface, ['ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type']);
        $effective['id'] = $interfaceId;
        $effective['mac'] = repo_interface_mac_value($db, $vmId, $interfaceId, $interface, $preserveExistingMacs);
        $effectiveInterfaces[] = $effective;
    }
    repo_vm_network_assert_bundle_write_allowed($db, $missionId, $vmId, (string) $vm['vm_name'], $effectiveInterfaces);

    $seenIds = [];
    foreach ($effectiveInterfaces as $interface) {
        $interfaceId = repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id')));
        $values = repo_allowed_columns($interface, ['ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type']);
        $values['mac'] = (string) $interface['mac'];

        if ($interfaceId > 0) {
            repo_update_from_values($db, 'deploy_interfaces', $values, 'id = ? AND vm_id = ?', 'ii', [$interfaceId, $vmId]);
            $seenIds[] = $interfaceId;
        } else {
            $values['vm_id'] = $vmId;
            $seenIds[] = repo_insert_from_values($db, 'deploy_interfaces', $values);
        }
    }

    if ($preserveExistingMacs) {
        if ($seenIds === []) {
            repo_execute($db, 'DELETE FROM deploy_interfaces WHERE vm_id = ?', 'i', [$vmId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $types = 'i' . str_repeat('i', count($seenIds));
            $params = array_merge([$vmId], $seenIds);
            $stmt = $db->prepare("DELETE FROM deploy_interfaces WHERE vm_id = ? AND id NOT IN ({$placeholders})");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        }
    } elseif ($seenIds === []) {
        repo_execute($db, 'DELETE FROM deploy_interfaces WHERE vm_id = ?', 'i', [$vmId]);
    }
}

function repo_interface_mac_value(mysqli $db, int $vmId, int $interfaceId, object|array $interface, bool $preserveExistingMacs): string
{
    if ($preserveExistingMacs && $interfaceId > 0) {
        // The MAC callback is the authority for an existing interface. Portal,
        // legacy and transfer writers may round-trip a stale or forged value,
        // but the effective bundle and its fingerprint must keep the stored MAC.
        $stmt = $db->prepare('SELECT mac FROM deploy_interfaces WHERE id = ? AND vm_id = ?');
        $stmt->bind_param('ii', $interfaceId, $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (string) ($row['mac'] ?? '');
    }

    $macValue = repo_object_get($interface, 'mac');
    if ($macValue !== null && trim((string) $macValue) !== '') {
        // Canonicalize on write (E2); unparseable values pass through so the
        // interface validator can flag them.
        return virtusphere_normalize_mac((string) $macValue) ?? (string) $macValue;
    }

    return '';
}

function repo_replace_packages(mysqli $db, int $vmId, mixed $packages): void
{
    repo_execute($db, 'DELETE FROM deploy_vm_packages WHERE vm_id = ?', 'i', [$vmId]);
    if (!is_iterable($packages)) {
        return;
    }

    foreach ($packages as $package) {
        $packageId = repo_id(repo_object_get($package, 'id', repo_object_get($package, 'package_id')));
        if ($packageId === 0 && repo_object_has($package, 'package_name')) {
            $name = (string) repo_object_get($package, 'package_name');
            $version = (string) repo_object_get($package, 'package_version', '');
            $packageId = (int) repo_scalar($db, 'SELECT id FROM deploy_packages WHERE package_name = ? AND (? = "" OR package_version = ?) LIMIT 1', 'sss', [$name, $version, $version]);
        }
        if ($packageId > 0) {
            repo_execute($db, 'INSERT IGNORE INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)', 'ii', [$vmId, $packageId]);
        }
    }
}

function repo_replace_disks(mysqli $db, int $vmId, mixed $disks): void
{
    repo_execute($db, 'DELETE FROM deploy_disks WHERE vm_id = ?', 'i', [$vmId]);
    if (!is_iterable($disks)) {
        return;
    }

    $disks = repo_validate_disks($disks);
    foreach ($disks as $disk) {
        $name = (string) repo_object_get($disk, 'disk_name', VIRTUSPHERE_VM_DEFAULTS['disk_name']);
        $size = (int) repo_object_get($disk, 'disk_size', VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']);
        $type = strtolower((string) repo_object_get($disk, 'disk_type', VIRTUSPHERE_VM_DEFAULTS['disk_type']));
        repo_execute($db, 'INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type) VALUES (?, ?, ?, ?)', 'isis', [$vmId, $name, $size, $type]);
    }
}

function repo_get_vm_bundle(mysqli $db, int $vmId): ?array
{
    $vm = repo_fetch_one($db, 'SELECT v.*, m.mission_name, m.wds_vlan AS mission_wds_vlan, m.domain AS mission_domain FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id WHERE v.id = ? LIMIT 1', 'i', [$vmId]);
    if ($vm === null) {
        return null;
    }

    $vm['interfaces'] = repo_fetch_related($db, 'SELECT * FROM deploy_interfaces WHERE vm_id = ? ORDER BY id', $vmId);
    $vm['disks'] = repo_fetch_related($db, 'SELECT * FROM deploy_disks WHERE vm_id = ? ORDER BY id', $vmId);
    $vm['packages'] = repo_fetch_related($db, 'SELECT package_id AS id FROM deploy_vm_packages WHERE vm_id = ? ORDER BY package_id', $vmId);

    return $vm;
}

// The ESXi/portal VM name is unique at most once across all non-template
// missions (templates intentionally duplicate names when cloning).
//
// This is NOT the MECM device name and has not been since Etappe 14D: MECM
// receives `mecm_rollout_hostname`, whose global uniqueness is enforced
// transactionally in `deploy_vm_hostname_claims`. This check stays as it is
// because loosening the ESXi/portal naming policy would be its own decision,
// not a side effect of moving the MECM name somewhere else.
//
// Application-level check only - no DB unique index is possible.
function repo_vm_name_conflict_global(mysqli $db, string $vmName, int $excludeVmId = 0): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT v.id, v.mission_id, m.mission_name FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id WHERE v.vm_name = ? AND v.id <> ? AND LEFT(m.mission_name, 1) <> ? LIMIT 1',
        'sis',
        [$vmName, $excludeVmId, VIRTUSPHERE_TEMPLATE_PREFIX]
    );
}

function repo_vm_name_exists(mysqli $db, int $missionId, string $vmName, int $excludeVmId = 0): bool
{
    if ($excludeVmId > 0) {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_vms WHERE mission_id = ? AND vm_name = ? AND id <> ? LIMIT 1', 'isi', [$missionId, $vmName, $excludeVmId]);
    } else {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_vms WHERE mission_id = ? AND vm_name = ? LIMIT 1', 'is', [$missionId, $vmName]);
    }

    return $row !== null;
}

/**
 * A template may contain VM names that already exist in real missions. Turning
 * it into a real mission by renaming it must therefore run the same global-name
 * decision that a normal VM save runs. Per-mission duplicates remain owned by
 * the existing mission_vm_unique database constraint.
 * The caller already owns the mission lock; locking its VM rows here keeps the
 * check and the subsequent mission update in the same transaction.
 */
function repo_mission_assert_vm_names_unique_for_activation(mysqli $db, int $missionId): void
{
    $stmt = $db->prepare('SELECT id, vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY BINARY vm_name, id FOR UPDATE');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());

    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $vmName = (string) $vm['vm_name'];
        $conflict = repo_vm_name_conflict_global($db, $vmName, $vmId);

        if ($conflict !== null) {
            $message = validator_text(
                'validate.mission_activation_vm_name_conflict',
                'Template cannot become a mission: VM name ":vm" is already used in mission ":mission".',
                ['vm' => $vmName, 'mission' => (string) $conflict['mission_name']]
            );
            throw new ValidationException(['mission_name' => $message], $message);
        }
    }
}

function repo_save_vm(mysqli $db, int $missionId, ?int $vmId, array $vmData, array $interfaces, array $disks, array $packages, string $expectedUpdatedAt, ?int $userId = null): int
{
    if ($missionId <= 0) {
        throw new RuntimeException('Mission is required.');
    }

    $vmId = $vmId !== null ? max(0, $vmId) : 0;
    $values = repo_validate_vm_payload($db, $missionId, $vmData, $vmId);
    // Provenance is owned here, not by the caller's payload: stamped from the
    // acting user on create and preserved from the stored row on update. This is
    // the single choke point for every editor-style write, so a new caller cannot
    // forget it or forge it. The import and legacy-API paths do not come through
    // here; they carry their own creator through repo_validate_vm_payload().
    $values['vm_creator'] = $vmId > 0
        ? (string) (repo_scalar($db, 'SELECT vm_creator FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '')
        : repo_creator_name($db, $userId);

    return repo_transaction($db, static function () use ($db, $missionId, $vmId, $values, $interfaces, $disks, $packages, $expectedUpdatedAt, $userId): int {
        if (repo_deploy_lock_mission($db, $missionId) === null) {
            throw new RuntimeException('Mission not found.');
        }
        $isTemplate = mission_name_is_template(
            (string) (repo_scalar($db, 'SELECT mission_name FROM deploy_missions WHERE id = ? LIMIT 1', 'i', [$missionId]) ?? '')
        );
        $desiredHostname = (string) $values['vm_hostname'];

        if ($vmId > 0) {
            repo_vm_network_assert_scope_idle($db, $missionId, [$vmId]);
            $current = repo_fetch_one($db, 'SELECT id, updated_at, vm_hostname FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]);
            if ($current === null) {
                throw new RuntimeException('VM not found.');
            }
            if ($expectedUpdatedAt !== '' && (string) $current['updated_at'] !== $expectedUpdatedAt) {
                throw new RuntimeException('VM was changed by another user. Reload before saving.');
            }

            // Rollout identity (Etappe 14D). The state is read under the row lock
            // taken just above, so the snapshot/revision decision and the write
            // it produces cannot be separated by another writer.
            $state = repo_vm_rollout_state($db, $vmId);
            if ($state !== null) {
                // An active deploy job blocks ONLY a hostname edit that changes
                // the machine's identity: the job already carries the name, and
                // renaming underneath it leaves the playbook creating something
                // the row no longer describes. Every other edit that is allowed
                // today stays allowed.
                if (
                    repo_vm_rollout_edit_changes_identity($state, $desiredHostname, (string) $current['vm_hostname'])
                    && repo_deploy_active_job_exists($db, $missionId)
                ) {
                    $message = validator_text('validate.vm_hostname_active_job', 'The Windows hostname cannot be changed while a deploy job of this mission is running.');
                    throw new ValidationException(['vm_hostname' => $message], $message);
                }
                $values += repo_vm_rollout_values_for_edit($state, $desiredHostname);
            }

            repo_update_from_values($db, 'deploy_vms', $values, 'id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
            repo_vm_hostname_claims_sync($db, $vmId, $isTemplate, $desiredHostname, $values['mecm_rollout_hostname'] ?? null);
            repo_replace_interfaces($db, $vmId, $interfaces, true);
            repo_replace_disks($db, $vmId, $disks);
            repo_replace_packages($db, $vmId, $packages);
            repo_execute($db, 'UPDATE deploy_vms SET updated_at = NOW() WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
        } else {
            $values['mission_id'] = $missionId;
            $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
            $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
            $values['mecm_sync_state'] = VIRTUSPHERE_MECM_SYNC_NOT_READY;
            $values['updated'] = 0;
            // A fresh VM starts its first rollout: snapshot equal to the desired
            // value, revision 1, no tombstone. A template VM gets none of it.
            $values += repo_vm_rollout_values_for_edit(
                ['mecm_id' => null, 'mecm_rollout_hostname' => null, 'mecm_rollout_revision' => null, 'is_template' => $isTemplate],
                $desiredHostname
            );
            $vmId = repo_insert_from_values($db, 'deploy_vms', $values);
            repo_vm_hostname_claims_sync($db, $vmId, $isTemplate, $desiredHostname, $values['mecm_rollout_hostname'] ?? null);
            repo_replace_interfaces($db, $vmId, $interfaces, false);
            repo_replace_disks($db, $vmId, $disks);
            repo_replace_packages($db, $vmId, $packages);
            repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'created from portal', $userId);
        }

        return $vmId;
    });
}
