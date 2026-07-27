<?php

declare(strict_types=1);

require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Canonicalizes a free-text catalog status to one of the two stored values.
 * 'aktiv'/'active' (any case) become VIRTUSPHERE_CATALOG_STATUS_DEFAULT,
 * 'retired' becomes VIRTUSPHERE_CATALOG_STATUS_RETIRED. Anything else passes
 * through unchanged: rows written before the retired token API fell (ADR-0035)
 * may hold arbitrary status text, and rewriting stored data is a migration
 * decision, not a validator side effect. Deliberately not Validator::enum(),
 * which lowercases and would fight the capitalized canonical values and their
 * case-sensitive SQL comparisons.
 */
function catalog_normalize_status(string $status): string
{
    return match (strtolower(trim($status))) {
        'aktiv', 'active' => VIRTUSPHERE_CATALOG_STATUS_DEFAULT,
        'retired' => VIRTUSPHERE_CATALOG_STATUS_RETIRED,
        default => $status,
    };
}

function repo_os_name_exists(mysqli $db, string $name, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_os WHERE os_name = ? AND id <> ? LIMIT 1', 'si', [$name, $excludeId]);
    } else {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_os WHERE os_name = ? LIMIT 1', 's', [$name]);
    }

    return $row !== null;
}

function repo_vlan_name_exists(mysqli $db, string $name, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_vlan WHERE vlan_name = ? AND id <> ? LIMIT 1', 'si', [$name, $excludeId]);
    } else {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [$name]);
    }

    return $row !== null;
}

function repo_validate_os_values(mysqli $db, mixed $name, mixed $status, int $excludeId = 0): array
{
    $validator = new Validator();
    $values = [
        'os_name' => $validator->requireString('os_name', $name, validator_label('os_name', 'OS name'), 255),
        'os_status' => $validator->requireString('os_status', $status, validator_label('os_status', 'OS status'), 255),
    ];
    $validator->throwIfInvalid();
    $values['os_status'] = catalog_normalize_status($values['os_status']);

    if (repo_os_name_exists($db, $values['os_name'], $excludeId)) {
        $message = validator_text('validate.name_taken', ':field already exists.', ['field' => validator_label('os_name', 'OS name')]);
        throw new ValidationException(['os_name' => $message], $message);
    }

    return $values;
}

function repo_validate_vlan_values(mysqli $db, mixed $name, int $excludeId = 0): array
{
    $validator = new Validator();
    $values = [
        'vlan_name' => $validator->requireString('vlan_name', $name, validator_label('vlan_name', 'VLAN name'), 255),
    ];
    $validator->throwIfInvalid();

    if (repo_vlan_name_exists($db, $values['vlan_name'], $excludeId)) {
        $message = validator_text('validate.name_taken', ':field already exists.', ['field' => validator_label('vlan_name', 'VLAN name')]);
        throw new ValidationException(['vlan_name' => $message], $message);
    }

    return $values;
}

// No package validator here on purpose: the package catalog is MECM-owned and
// read-only in the portal (ADR-0020), so the only writer is mecm_packages.php,
// which stores VIRTUSPHERE_CATALOG_STATUS_DEFAULT rather than operator input.
// A portal write path would need one, built like repo_validate_os_values()
// above, including its catalog_normalize_status() call.

// Default excludes retired rows: before E3 those rows were hard-DELETEd, so
// filtering matches the previous effective behavior of every caller
// (including the legacy token API).
function getPackages($connection, string $statusFilter = 'active')
{
    $sql = match ($statusFilter) {
        'all' => 'SELECT * FROM deploy_packages ORDER BY package_name, package_version',
        'retired' => 'SELECT * FROM deploy_packages WHERE package_status = \'' . VIRTUSPHERE_CATALOG_STATUS_RETIRED . '\' ORDER BY package_name, package_version',
        default => 'SELECT * FROM deploy_packages WHERE package_status <> \'' . VIRTUSPHERE_CATALOG_STATUS_RETIRED . '\' ORDER BY package_name, package_version',
    };
    $stmt = $connection->prepare($sql);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function getOS($connection, bool $includeRetired = false)
{
    $sql = $includeRetired
        ? 'SELECT * FROM deploy_os ORDER BY os_name'
        : 'SELECT * FROM deploy_os WHERE os_status <> \'' . VIRTUSPHERE_CATALOG_STATUS_RETIRED . '\' ORDER BY os_name';
    $stmt = $connection->prepare($sql);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

// "Name-Version" split at the LAST hyphen (autoimporter convention; versions
// are hyphen-free). No hyphen => basename = full name, empty version.
function repo_package_split_name(string $name): array
{
    $pos = strrpos($name, '-');
    if ($pos === false || $pos === 0 || $pos === strlen($name) - 1) {
        return ['basename' => $name, 'version' => ''];
    }

    return ['basename' => substr($name, 0, $pos), 'version' => substr($name, $pos + 1)];
}

// Picker source for vm_edit: active packages plus retired ones the VM is
// already linked to (so existing links stay visible/editable).
function repo_packages_for_picker(mysqli $db, array $linkedIds): array
{
    $packages = getPackages($db, 'active');
    $activeIds = array_map(static fn (array $p): int => (int) $p['id'], $packages);
    $missing = array_diff(array_map('intval', $linkedIds), $activeIds);
    if ($missing !== []) {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('i', count($missing));
        $stmt = $db->prepare("SELECT * FROM deploy_packages WHERE id IN ({$placeholders})");
        $stmt->bind_param($types, ...array_values($missing));
        $stmt->execute();
        foreach (repo_fetch_all($stmt->get_result()) as $row) {
            $packages[] = $row;
        }
    }

    return $packages;
}

// OS picker source: active OS rows plus the VM's current value when retired.
function repo_os_for_picker(mysqli $db, string $currentOsName): array
{
    $oses = getOS($db);
    foreach ($oses as $os) {
        if ((string) $os['os_name'] === $currentOsName) {
            return $oses;
        }
    }
    if ($currentOsName !== '') {
        $current = repo_fetch_one($db, 'SELECT * FROM deploy_os WHERE os_name = ? LIMIT 1', 's', [$currentOsName]);
        if ($current !== null) {
            $oses[] = $current;
        }
    }

    return $oses;
}

/**
 * The one rule for "which of several versions is the successor": the highest
 * package_version by version_compare(), never the newest row id. B12: this
 * pick and the relink in mecm_packages.php answered the question differently,
 * and a re-imported OLDER build (highest id, lower version) became the
 * recommended upgrade - the operator was told to update onto a downgrade.
 * packages_pick_successor() applies its own eligibility filters first and then
 * picks through this same helper.
 *
 * @param array<int, array<string, mixed>> $candidates rows carrying package_version
 * @return array<string, mixed>|null
 */
function catalog_pick_highest_version(array $candidates): ?array
{
    $best = null;
    foreach ($candidates as $candidate) {
        if ($best === null || version_compare((string) $candidate['package_version'], (string) $best['package_version'], '>')) {
            $best = $candidate;
        }
    }

    return $best;
}

// Update-available hints: linked retired packages whose basename has an
// active (unlinked) successor - covers cases the automatic relink skipped.
// Per retired package the successor is picked by catalog_pick_highest_version,
// the same rule as the automatic relink (B12).
function repo_vm_package_upgrade_hints(mysqli $db, int $vmId): array
{
    $stmt = $db->prepare("SELECT old.package_name AS old_name, new.package_name AS new_name, new.package_version
        FROM deploy_vm_packages link
        INNER JOIN deploy_packages old ON old.id = link.package_id AND old.package_status = ?
        INNER JOIN deploy_packages new ON new.package_basename = old.package_basename AND new.package_status <> ? AND new.id <> old.id
        WHERE link.vm_id = ?
        ORDER BY old.package_name");
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $stmt->bind_param('ssi', $retired, $retired, $vmId);
    $stmt->execute();

    $candidates = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $candidates[(string) $row['old_name']][] = $row;
    }

    $hints = [];
    foreach ($candidates as $oldName => $rows) {
        $best = catalog_pick_highest_version($rows);
        if ($best !== null) {
            $hints[$oldName] = (string) $best['new_name'];
        }
    }

    return $hints;
}

/**
 * Purge (maintenance worker): retired > N days AND never assigned. Deleting a
 * row that carries assignments would cascade them away, which is why ADR-0020
 * calls the deletion safe.
 *
 * "Never assigned" and not "not currently assigned": the second is what the
 * clause `id NOT IN (SELECT package_id FROM deploy_vm_packages)` asks, and the
 * version relink in mecm_packages.php removes exactly that reference when it
 * moves assignments to a successor. So the protection was lifted by the one
 * mechanism that made the row worth protecting: the rows that HAD assignments
 * were precisely the ones the purge could delete, a re-import then created a
 * fresh id, and the old row's history was gone. assignments_relinked_at is the
 * durable record of that, and it keeps the row.
 */
function repo_purge_retired_packages(mysqli $db, int $afterDays = VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS): int
{
    $stmt = $db->prepare('DELETE FROM deploy_packages WHERE package_status = ? AND retired_at IS NOT NULL AND retired_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND assignments_relinked_at IS NULL AND id NOT IN (SELECT package_id FROM deploy_vm_packages)');
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $stmt->bind_param('si', $retired, $afterDays);
    $stmt->execute();

    return $stmt->affected_rows;
}

function repo_purge_retired_os(mysqli $db, int $afterDays = VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS): int
{
    $stmt = $db->prepare('DELETE FROM deploy_os WHERE os_status = ? AND retired_at IS NOT NULL AND retired_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND os_name NOT IN (SELECT DISTINCT vm_os FROM deploy_vms WHERE vm_os IS NOT NULL AND vm_os <> \'\')');
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $stmt->bind_param('si', $retired, $afterDays);
    $stmt->execute();

    return $stmt->affected_rows;
}

// VM usage per OS name (vm_os is a free-text string, not an FK), so the
// read-only OS page can warn before a delete affects assigned VMs. Keyed by
// os_name; missing names mean zero VMs.
function repo_os_vm_counts(mysqli $db): array
{
    $stmt = $db->prepare("SELECT vm_os, COUNT(*) AS c FROM deploy_vms WHERE vm_os IS NOT NULL AND vm_os <> '' GROUP BY vm_os");
    $stmt->execute();

    $counts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $counts[(string) $row['vm_os']] = (int) $row['c'];
    }

    return $counts;
}

function createOS($osName, $osStatus, $connection)
{
    $values = repo_validate_os_values($connection, $osName, $osStatus);

    return repo_execute($connection, 'INSERT INTO deploy_os (os_name, os_status) VALUES (?, ?)', 'ss', [$values['os_name'], $values['os_status']]);
}

function deleteOS($osId, $connection)
{
    $id = repo_id($osId);
    if ($id <= 0) {
        throw new InvalidArgumentException('OS id is required.');
    }

    $stmt = $connection->prepare('DELETE FROM deploy_os WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new RuntimeException('OS entry not found.');
    }

    return true;
}

function updateOS($osId, $osName, $osStatus, $connection)
{
    $id = repo_id($osId);
    if ($id <= 0) {
        throw new InvalidArgumentException('OS id is required.');
    }

    $values = repo_validate_os_values($connection, $osName, $osStatus, $id);
    $stmt = $connection->prepare('UPDATE deploy_os SET os_name = ?, os_status = ? WHERE id = ?');
    $stmt->bind_param('ssi', $values['os_name'], $values['os_status'], $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0 && repo_fetch_one($connection, 'SELECT id FROM deploy_os WHERE id = ? LIMIT 1', 'i', [$id]) === null) {
        throw new RuntimeException('OS entry not found.');
    }

    return true;
}

function getVLAN($connection)
{
    $stmt = $connection->prepare('SELECT * FROM deploy_vlan ORDER BY vlan_name');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Active (retired_at IS NULL) VLAN catalog rows ordered by name: the option
 * source for every VLAN select and the reassign target list. getVLAN stays for
 * the catalog page, which lists retired rows too.
 *
 * @return array<int, array<string, mixed>>
 */
function repo_active_vlans(mysqli $db): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_vlan WHERE retired_at IS NULL ORDER BY vlan_name');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function deleteVLAN($vlanId, $connection)
{
    $id = repo_id($vlanId);
    if ($id <= 0) {
        throw new InvalidArgumentException('VLAN id is required.');
    }

    $stmt = $connection->prepare('DELETE FROM deploy_vlan WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new RuntimeException('VLAN entry not found.');
    }

    return true;
}

function updateVlan($vlanId, $vlanName, $connection)
{
    $id = repo_id($vlanId);
    if ($id <= 0) {
        throw new InvalidArgumentException('VLAN id is required.');
    }

    $values = repo_validate_vlan_values($connection, $vlanName, $id);
    $stmt = $connection->prepare('UPDATE deploy_vlan SET vlan_name = ? WHERE id = ?');
    $stmt->bind_param('si', $values['vlan_name'], $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0 && repo_fetch_one($connection, 'SELECT id FROM deploy_vlan WHERE id = ? LIMIT 1', 'i', [$id]) === null) {
        throw new RuntimeException('VLAN entry not found.');
    }

    return true;
}

function createVLAN($vlanName, $connection)
{
    $values = repo_validate_vlan_values($connection, $vlanName);

    return repo_execute($connection, 'INSERT INTO deploy_vlan (vlan_name) VALUES (?)', 's', [$values['vlan_name']]);
}
