<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/../mac.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/status_events.php';

const REPO_VM_COLUMNS = [
    'vm_name',
    'vm_hostname',
    'vm_domain',
    'vm_os',
    'vm_ram',
    'vm_cpu',
    'vm_disk',
    'vm_datastore',
    'vm_datacenter',
    'vm_guest_id',
    'vm_creator',
    'vm_notes',
    'cpu_hotplug',
    'ram_hotplug',
    'autostart_enabled',
    'autostart_start_delay',
    'autostart_stop_delay',
];

/**
 * Normalizes a VM boolean flag (cpu_hotplug/ram_hotplug). An absent key uses the
 * VIRTUSPHERE_VM_DEFAULTS value (true), so legacy-API creates and field-tolerant
 * imports get the default; a present value is read as a checkbox/bool.
 */
function repo_vm_flag_value(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (bool) (VIRTUSPHERE_VM_DEFAULTS[$key] ?? true) ? 1 : 0;
    }

    return in_array(strtolower((string) $vmData[$key]), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * Same shape as repo_vm_flag_value(), but autostart defaults to OFF: a VM has to
 * be opted in, never opted out, so an import or a legacy-API create can never
 * silently enrol a VM into the host's boot sequence.
 */
function repo_vm_autostart_flag(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (bool) (VIRTUSPHERE_VM_AUTOSTART_DEFAULTS[$key] ?? false) ? 1 : 0;
    }

    return in_array(strtolower((string) $vmData[$key]), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * Normalizes a per-VM autostart delay into the column's integer domain.
 *
 * An absent key (legacy API, a mission export written before this feature) and
 * an empty string (the editor's "inherit" state) both mean inherit and become
 * VIRTUSPHERE_AUTOSTART_DELAY_INHERIT. Never the empty string: the column is
 * INT NOT NULL and MySQL would reject it, or in a lax mode store 0.
 *
 * Storing 0 there would be worse than an error. 0 is a legal, meaningful value
 * ("start without waiting") and -1 means "use the mission's value"; collapsing
 * one into the other silently changes when a VM boots after a host restart.
 */
function repo_vm_delay_value(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (int) (VIRTUSPHERE_VM_AUTOSTART_DEFAULTS[$key] ?? VIRTUSPHERE_AUTOSTART_DELAY_INHERIT);
    }

    $raw = $vmData[$key];
    // Untrusted imports can carry a non-scalar here; casting it would raise an
    // "array to string" warning that the global handler turns into a 500.
    if ($raw === null || !is_scalar($raw) || trim((string) $raw) === '') {
        return VIRTUSPHERE_AUTOSTART_DELAY_INHERIT;
    }

    $value = (int) $raw;
    if ($value < VIRTUSPHERE_AUTOSTART_DELAY_MIN) {
        return VIRTUSPHERE_AUTOSTART_DELAY_INHERIT;
    }

    return min(VIRTUSPHERE_AUTOSTART_DELAY_MAX, $value);
}

function getVMs($connection, $missionId)
{
    $missionId = repo_id($missionId);
    $stmt = $connection->prepare('SELECT * FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());
    foreach ($vms as &$vm) {
        $vmId = (int) $vm['id'];
        $vm['packages'] = repo_fetch_related($connection, 'SELECT dp.* FROM deploy_packages dp INNER JOIN deploy_vm_packages dvp ON dp.id = dvp.package_id WHERE dvp.vm_id = ? ORDER BY dp.package_name', $vmId);
        $vm['interfaces'] = repo_fetch_related($connection, 'SELECT * FROM deploy_interfaces WHERE vm_id = ? ORDER BY id', $vmId);
        $vm['disks'] = repo_fetch_related($connection, 'SELECT * FROM deploy_disks WHERE vm_id = ? ORDER BY id', $vmId);
    }

    return $vms;
}

function repo_fetch_related(mysqli $connection, string $sql, int $vmId): array
{
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_source_to_array(object|array $source): array
{
    return is_array($source) ? $source : get_object_vars($source);
}

function deleteVM($vmList, $connection)
{
    return vmListToDelete($vmList, $connection);
}

function vmListToCreate($missionId, $vmList, $mysqli)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return 0;
    }

    // Legacy wire contract: any failure rolls the whole batch back and answers
    // with 0, never with an exception (the desktop client checks the count).
    try {
        return repo_transaction($mysqli, static function () use ($mysqli, $missionId, $vmList): int {
            $successCount = 0;
            foreach ($vmList as $vm) {
                $vmMissionId = repo_id(repo_object_get($vm, 'mission_id', $missionId));
                if ($vmMissionId === 0) {
                    throw new RuntimeException('VM create skipped: missing mission_id.');
                }

                $values = repo_validate_vm_payload($mysqli, $vmMissionId, repo_source_to_array($vm));
                $values['mission_id'] = $vmMissionId;
                $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
                $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
                $values['mecm_sync_state'] = VIRTUSPHERE_MECM_NOT_READY;
                $values['updated'] = 0;

                $vmId = repo_insert_from_values($mysqli, 'deploy_vms', $values);
                repo_replace_interfaces($mysqli, $vmId, repo_object_get($vm, 'interfaces', []), false);
                repo_replace_packages($mysqli, $vmId, repo_object_get($vm, 'packages', []));
                repo_replace_disks($mysqli, $vmId, repo_object_get($vm, 'Disks', repo_object_get($vm, 'disks', [])));
                repo_record_vm_status_event($mysqli, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'created');
                $successCount++;
            }

            return $successCount;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToCreate rollback: ' . $exception->getMessage());
        return 0;
    }
}

function vmListToUpdate($vmList, $connection)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return 0;
    }

    // Legacy wire contract: batch-or-nothing, failures answer 0 (see create).
    try {
        return repo_transaction($connection, static function () use ($connection, $vmList): int {
            $successCount = 0;
            foreach ($vmList as $vm) {
                $vmId = repo_id(repo_object_get($vm, 'Id', repo_object_get($vm, 'id')));
                if ($vmId === 0) {
                    throw new RuntimeException('VM update skipped: missing Id.');
                }

                $values = repo_allowed_columns($vm, REPO_VM_COLUMNS);
                if ($values !== []) {
                    $currentVm = repo_fetch_one($connection, 'SELECT * FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$vmId]);
                    if ($currentVm === null) {
                        throw new RuntimeException('VM update skipped: VM not found.');
                    }
                    $values = repo_validate_vm_payload($connection, (int) $currentVm['mission_id'], array_merge($currentVm, $values), $vmId);
                    repo_update_from_values($connection, 'deploy_vms', $values, 'id = ?', 'i', [$vmId]);
                }
                if (repo_object_has($vm, 'interfaces')) {
                    repo_replace_interfaces($connection, $vmId, repo_object_get($vm, 'interfaces', []), true);
                }
                if (repo_object_has($vm, 'packages')) {
                    repo_replace_packages($connection, $vmId, repo_object_get($vm, 'packages', []));
                }
                if (repo_object_has($vm, 'Disks') || repo_object_has($vm, 'disks')) {
                    repo_replace_disks($connection, $vmId, repo_object_get($vm, 'Disks', repo_object_get($vm, 'disks', [])));
                }
                $successCount++;
            }

            return $successCount;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToUpdate rollback: ' . $exception->getMessage());
        return 0;
    }
}

function vmListToDelete($vmList, $connection)
{
    if (empty($vmList) || !is_iterable($vmList)) {
        return false;
    }

    // Legacy wire contract: batch-or-nothing, failures answer false (see create).
    try {
        return repo_transaction($connection, static function () use ($connection, $vmList): bool {
            foreach ($vmList as $vm) {
                $id = repo_id(repo_object_get($vm, 'Id', repo_object_get($vm, 'id')));
                if ($id > 0) {
                    repo_execute($connection, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$id]);
                }
            }

            return true;
        });
    } catch (Throwable $exception) {
        repo_log_failure('vmListToDelete rollback: ' . $exception->getMessage());
        return false;
    }
}

function repo_validate_interfaces(mixed $interfaces): array
{
    if (!is_iterable($interfaces)) {
        return [];
    }

    $validated = [];
    $index = 0;
    foreach ($interfaces as $interface) {
        if (!is_array($interface) && !is_object($interface)) {
            throw new ValidationException(['interfaces.' . $index => validator_text('validate.interface_entry_invalid', 'Interface entry is invalid.')]);
        }

        $validator = new Validator();
        $mode = $validator->enum('interfaces.' . $index . '.mode', repo_object_get($interface, 'mode', VIRTUSPHERE_VM_DEFAULTS['interface_mode']), validator_label('interface_mode', 'Interface mode'), VIRTUSPHERE_INTERFACE_MODES, VIRTUSPHERE_VM_DEFAULTS['interface_mode']);
        $static = $mode === 'static';
        $row = [
            'id' => repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id'))),
            'ip' => $validator->ipv4('interfaces.' . $index . '.ip', repo_object_get($interface, 'ip', ''), validator_label('interface_ip', 'Interface IP'), $static),
            'subnet' => $validator->ipv4OrCidrMask('interfaces.' . $index . '.subnet', repo_object_get($interface, 'subnet', ''), validator_label('interface_subnet', 'Interface subnet'), $static),
            'gateway' => $validator->ipv4('interfaces.' . $index . '.gateway', repo_object_get($interface, 'gateway', ''), validator_label('interface_gateway', 'Interface gateway'), $static),
            'dns1' => $validator->ipv4('interfaces.' . $index . '.dns1', repo_object_get($interface, 'dns1', ''), validator_label('interface_dns1', 'Interface DNS 1')),
            'dns2' => $validator->ipv4('interfaces.' . $index . '.dns2', repo_object_get($interface, 'dns2', ''), validator_label('interface_dns2', 'Interface DNS 2')),
            'vlan' => $validator->optionalString('interfaces.' . $index . '.vlan', repo_object_get($interface, 'vlan', ''), validator_label('interface_vlan', 'Interface VLAN'), 255),
            'mode' => $mode,
            // vNIC type is an enum, like disk_type and the interface mode: a value
            // outside VIRTUSPHERE_INTERFACE_TYPES fails the create playbook at ESXi,
            // so it is rejected here (import/legacy-API included) instead of silently
            // reaching the hypervisor. enum() applies the default for an empty value
            // and lower-cases, so a stored type is always a canonical known value.
            'type' => $validator->enum('interfaces.' . $index . '.type', repo_object_get($interface, 'type', VIRTUSPHERE_VM_DEFAULTS['interface_type']), validator_label('interface_type', 'Interface type'), VIRTUSPHERE_INTERFACE_TYPES, VIRTUSPHERE_VM_DEFAULTS['interface_type']),
            'mac' => $validator->mac('interfaces.' . $index . '.mac', repo_object_get($interface, 'mac', ''), validator_label('interface_mac', 'Interface MAC')),
        ];
        $validator->throwIfInvalid();
        $validated[] = $row;
        $index++;
    }

    return $validated;
}

function repo_validate_disks(mixed $disks): array
{
    if (!is_iterable($disks)) {
        return [];
    }

    $validated = [];
    $index = 0;
    foreach ($disks as $disk) {
        if (!is_array($disk) && !is_object($disk)) {
            throw new ValidationException(['disks.' . $index => validator_text('validate.disk_entry_invalid', 'Disk entry is invalid.')]);
        }

        $validator = new Validator();
        $row = [
            'disk_name' => $validator->optionalString('disks.' . $index . '.disk_name', repo_object_get($disk, 'disk_name', VIRTUSPHERE_VM_DEFAULTS['disk_name']), validator_label('disk_name', 'Disk name'), 255),
            'disk_size' => $validator->intRange(
                'disks.' . $index . '.disk_size',
                repo_object_get($disk, 'disk_size', VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']),
                validator_label('disk_size', 'Disk size'),
                (int) VIRTUSPHERE_VM_LIMITS['disk_size_gb_min'],
                (int) VIRTUSPHERE_VM_LIMITS['disk_size_gb_max'],
                (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']
            ),
            'disk_type' => $validator->enum('disks.' . $index . '.disk_type', repo_object_get($disk, 'disk_type', VIRTUSPHERE_VM_DEFAULTS['disk_type']), validator_label('disk_type', 'Disk type'), VIRTUSPHERE_DISK_TYPES, VIRTUSPHERE_VM_DEFAULTS['disk_type']),
        ];
        if ($row['disk_name'] === '') {
            $row['disk_name'] = VIRTUSPHERE_VM_DEFAULTS['disk_name'];
        }
        $validator->throwIfInvalid();
        $validated[] = $row;
        $index++;
    }

    return $validated;
}

function repo_replace_interfaces(mysqli $db, int $vmId, mixed $interfaces, bool $preserveExistingMacs): void
{
    if (!is_iterable($interfaces)) {
        return;
    }

    $interfaces = repo_validate_interfaces($interfaces);
    $seenIds = [];
    foreach ($interfaces as $interface) {
        $interfaceId = repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id')));
        $values = repo_allowed_columns($interface, ['ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type']);
        $values['mac'] = repo_interface_mac_value($db, $vmId, $interfaceId, $interface, $preserveExistingMacs);

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
    $macValue = repo_object_get($interface, 'mac');
    if ($macValue !== null && trim((string) $macValue) !== '') {
        // Canonicalize on write (E2); unparseable values pass through so the
        // interface validator can flag them.
        return virtusphere_normalize_mac((string) $macValue) ?? (string) $macValue;
    }
    if ($preserveExistingMacs && $interfaceId > 0) {
        $stmt = $db->prepare('SELECT mac FROM deploy_interfaces WHERE id = ? AND vm_id = ?');
        $stmt->bind_param('ii', $interfaceId, $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (string) ($row['mac'] ?? '');
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

// MECM device names are global, so a VM name may exist at most once across
// all non-template missions (templates intentionally duplicate names when
// cloning). Application-level check only - no DB unique index is possible.
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
 * Bulk delete of VM DB records (never the hypervisor). VMs are skipped while
 * their mission has an active deploy job. Returns counts + skip reasons.
 *
 * @param int[] $vmIds
 * @return array{deleted:int, skipped:array<int,array{vm_name:string,reason:string}>}
 */
function repo_bulk_delete_vms(mysqli $db, int $missionId, array $vmIds): array
{
    $result = ['deleted' => 0, 'skipped' => []];
    $missionHasActiveJob = (int) repo_scalar(
        $db,
        "SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ? AND status IN ('queued', 'running') AND cancelled_at IS NULL",
        'i',
        [$missionId]
    ) > 0;

    foreach ($vmIds as $vmId) {
        $vmId = (int) $vmId;
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        if ($name === '') {
            continue; // not in this mission; silently ignore
        }
        if ($missionHasActiveJob) {
            $result['skipped'][] = ['vm_name' => $name, 'reason' => 'active_job'];
            continue;
        }
        if (repo_delete_vm_by_id($db, $missionId, $vmId)) {
            $result['deleted']++;
        }
    }

    return $result;
}

/**
 * Bulk MECM-ID reset. Reuses repo_reset_vm_mecm_id per VM; VMs without an
 * imported MAC (or otherwise not resettable) are skipped with a reason.
 *
 * @param int[] $vmIds
 * @return array{done:int, skipped:array<int,array{vm_name:string,reason:string}>}
 */
function repo_bulk_reset_mecm_ids(mysqli $db, int $missionId, array $vmIds, ?int $userId = null): array
{
    $result = ['done' => 0, 'skipped' => []];
    foreach ($vmIds as $vmId) {
        $vmId = (int) $vmId;
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        if ($name === '') {
            continue;
        }
        try {
            repo_reset_vm_mecm_id($db, $missionId, $vmId, $userId);
            $result['done']++;
        } catch (Throwable $exception) {
            $reason = str_contains($exception->getMessage(), 'imported MAC') ? 'no_mac' : 'error';
            $result['skipped'][] = ['vm_name' => $name, 'reason' => $reason];
        }
    }

    return $result;
}

function repo_delete_vm_by_id(mysqli $db, int $missionId, int $vmId): bool
{
    if ($missionId <= 0 || $vmId <= 0) {
        return false;
    }

    return repo_execute($db, 'DELETE FROM deploy_vms WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
}

function repo_vm_has_imported_mac(mysqli $db, int $vmId): bool
{
    if ($vmId <= 0) {
        return false;
    }

    return (int) repo_scalar($db, "SELECT COUNT(*) FROM deploy_interfaces WHERE vm_id = ? AND mac IS NOT NULL AND mac <> ''", 'i', [$vmId]) > 0;
}

function repo_reset_vm_mecm_id(mysqli $db, int $missionId, int $vmId, ?int $userId = null): void
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM are required.');
    }

    repo_transaction($db, static function () use ($db, $missionId, $vmId, $userId): void {
        $current = repo_fetch_one($db, 'SELECT id FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]);
        if ($current === null) {
            throw new RuntimeException('VM not found.');
        }
        if (!repo_vm_has_imported_mac($db, $vmId)) {
            throw new RuntimeException('VM needs an imported MAC address before MECM ID reset.');
        }

        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = 1, mecm_id = NULL, updated_at = NOW() WHERE id = ? AND mission_id = ?');
        $lifecycleState = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
        $mecmSyncState = VIRTUSPHERE_MECM_PENDING;
        $legacyStatus = VIRTUSPHERE_STATUS_DEPLOYED;
        $stmt->bind_param('sssii', $lifecycleState, $mecmSyncState, $legacyStatus, $vmId, $missionId);
        $stmt->execute();

        repo_record_vm_status_event($db, $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, 'mecm id reset from portal', $userId);
    });
}

function repo_validate_vm_payload(mysqli $db, int $missionId, array $vmData, int $excludeVmId = 0): array
{
    $validator = new Validator();
    $name = $validator->requireString('vm_name', $vmData['vm_name'] ?? '', validator_label('vm_name', 'VM name'), 16);
    if ($name !== '' && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,14}[A-Za-z0-9])?$/', $name) !== 1) {
        $validator->add('vm_name', validator_text('validate.vm_name_charset', 'VM name may use only letters, numbers and internal hyphens.'));
    }

    $values = [];
    $values['vm_name'] = $name;

    // NetBIOS-safe hostname rule (E2): the MECM hostname phase truncates to
    // 15 chars and strips everything outside [A-Za-z0-9-] on the client, so
    // looser names silently diverge. Grandfathering: an UNCHANGED legacy
    // hostname keeps the old lax rule so unrelated edits are not blocked;
    // any change (and every new VM) must pass the strict rule.
    $hostnameInput = trim((string) ($vmData['vm_hostname'] ?? ''));
    if ($hostnameInput === '') {
        $hostnameInput = $name;
    }
    $storedHostname = $excludeVmId > 0
        ? (string) (repo_scalar($db, 'SELECT vm_hostname FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$excludeVmId, $missionId]) ?? '')
        : '';
    $hostnameLabel = validator_label('hostname', 'Hostname');
    if ($excludeVmId > 0 && $storedHostname !== '' && $hostnameInput === $storedHostname) {
        $values['vm_hostname'] = $validator->hostname('vm_hostname', $hostnameInput, $hostnameLabel, 64);
    } else {
        $values['vm_hostname'] = $validator->netbiosHostname('vm_hostname', $hostnameInput, $hostnameLabel);
    }
    if ($values['vm_hostname'] === '') {
        $values['vm_hostname'] = $name;
    }
    $values['vm_domain'] = $validator->fqdn('vm_domain', $vmData['vm_domain'] ?? '', validator_label('domain', 'Domain'));
    $values['vm_os'] = $validator->requireString('vm_os', $vmData['vm_os'] ?? '', validator_label('operating_system', 'Operating system'), 255);
    $values['vm_ram'] = (string) $validator->intRange(
        'vm_ram',
        $vmData['vm_ram'] ?? VIRTUSPHERE_VM_DEFAULTS['ram_mb'],
        validator_label('ram_mb', 'RAM MB'),
        (int) VIRTUSPHERE_VM_LIMITS['ram_mb_min'],
        (int) VIRTUSPHERE_VM_LIMITS['ram_mb_max'],
        (int) VIRTUSPHERE_VM_DEFAULTS['ram_mb']
    );
    $values['vm_cpu'] = (string) $validator->intRange(
        'vm_cpu',
        $vmData['vm_cpu'] ?? VIRTUSPHERE_VM_DEFAULTS['cpu_count'],
        validator_label('cpu_count', 'CPU count'),
        (int) VIRTUSPHERE_VM_LIMITS['cpu_count_min'],
        (int) VIRTUSPHERE_VM_LIMITS['cpu_count_max'],
        (int) VIRTUSPHERE_VM_DEFAULTS['cpu_count']
    );
    $values['vm_disk'] = $validator->optionalString('vm_disk', $vmData['vm_disk'] ?? '', validator_label('legacy_disk_summary', 'Legacy disk summary'), 64);
    $values['vm_datastore'] = $validator->optionalString('vm_datastore', $vmData['vm_datastore'] ?? '', validator_label('datastore', 'Datastore'), 255);
    $values['vm_datacenter'] = $validator->optionalString('vm_datacenter', $vmData['vm_datacenter'] ?? '', validator_label('datacenter', 'Datacenter'), 255);
    $values['vm_guest_id'] = $validator->optionalString('vm_guest_id', $vmData['vm_guest_id'] ?? VIRTUSPHERE_VM_DEFAULTS['guest_id'], validator_label('guest_id', 'Guest ID'), 255);
    if ($values['vm_guest_id'] === '') {
        $values['vm_guest_id'] = VIRTUSPHERE_VM_DEFAULTS['guest_id'];
    }
    if (!in_array($values['vm_guest_id'], virtusphere_guest_os_ids(), true)) {
        $existingGuestId = $excludeVmId > 0
            ? (string) (repo_scalar($db, 'SELECT vm_guest_id FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$excludeVmId, $missionId]) ?? '')
            : '';
        if ($existingGuestId === '' || $values['vm_guest_id'] !== $existingGuestId) {
            $validator->add('vm_guest_id', validator_text('validate.enum', ':field has an invalid value.', ['field' => validator_label('guest_id', 'Guest ID')]));
        }
    }
    $values['vm_creator'] = $validator->optionalString('vm_creator', $vmData['vm_creator'] ?? '', validator_label('creator', 'Creator'), 255);
    $values['vm_notes'] = $validator->optionalString('vm_notes', $vmData['vm_notes'] ?? '', validator_label('notes', 'Notes'), 65535);
    // Hot-add flags (Paket F): only applied at ESXi creation time, default on.
    $values['cpu_hotplug'] = repo_vm_flag_value($vmData, 'cpu_hotplug');
    $values['ram_hotplug'] = repo_vm_flag_value($vmData, 'ram_hotplug');
    // Autostart override (ADR-0025). Normalized rather than validated: an out of
    // range delay from the legacy API or an import is clamped, not rejected, in
    // the same spirit as the hot-add flags. The editor validates its own input.
    $values['autostart_enabled'] = repo_vm_autostart_flag($vmData, 'autostart_enabled');
    $values['autostart_start_delay'] = repo_vm_delay_value($vmData, 'autostart_start_delay');
    $values['autostart_stop_delay'] = repo_vm_delay_value($vmData, 'autostart_stop_delay');

    $validator->throwIfInvalid();
    if (repo_vm_name_exists($db, $missionId, $name, $excludeVmId)) {
        $message = validator_text('validate.vm_name_taken_in_mission', 'VM name already exists in this mission.');
        throw new ValidationException(['vm_name' => $message], $message);
    }

    // Global uniqueness across non-template missions (E2): only enforced when
    // the target mission itself is not a template - template VMs deliberately
    // mirror names of the missions they were captured from.
    $missionName = (string) (repo_scalar($db, 'SELECT mission_name FROM deploy_missions WHERE id = ? LIMIT 1', 'i', [$missionId]) ?? '');
    if (!mission_name_is_template($missionName)) {
        $conflict = repo_vm_name_conflict_global($db, $name, $excludeVmId);
        if ($conflict !== null && (int) $conflict['mission_id'] !== $missionId) {
            $message = validator_text('validate.vm_name_taken_global', 'VM name is already used in mission ":mission" - MECM device names must be unique.', ['mission' => (string) $conflict['mission_name']]);
            throw new ValidationException(['vm_name' => $message], $message);
        }
    }

    return $values;
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
        if ($vmId > 0) {
            $current = repo_fetch_one($db, 'SELECT id, updated_at FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]);
            if ($current === null) {
                throw new RuntimeException('VM not found.');
            }
            if ($expectedUpdatedAt !== '' && (string) $current['updated_at'] !== $expectedUpdatedAt) {
                throw new RuntimeException('VM was changed by another user. Reload before saving.');
            }
            repo_update_from_values($db, 'deploy_vms', $values, 'id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
            repo_replace_interfaces($db, $vmId, $interfaces, true);
            repo_replace_disks($db, $vmId, $disks);
            repo_replace_packages($db, $vmId, $packages);
            repo_execute($db, 'UPDATE deploy_vms SET updated_at = NOW() WHERE id = ? AND mission_id = ?', 'ii', [$vmId, $missionId]);
        } else {
            $values['mission_id'] = $missionId;
            $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
            $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
            $values['mecm_sync_state'] = VIRTUSPHERE_MECM_NOT_READY;
            $values['updated'] = 0;
            $vmId = repo_insert_from_values($db, 'deploy_vms', $values);
            repo_replace_interfaces($db, $vmId, $interfaces, false);
            repo_replace_disks($db, $vmId, $disks);
            repo_replace_packages($db, $vmId, $packages);
            repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'created from portal', $userId);
        }

        return $vmId;
    });
}
