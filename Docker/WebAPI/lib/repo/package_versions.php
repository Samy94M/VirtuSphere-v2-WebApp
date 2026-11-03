<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

// Version choice for one package basename, split from catalog.php (ADR-0006
// size budget). catalog.php requires this file, so every existing caller keeps
// its require path.

/**
 * The one rule for "which of several versions is the successor": the highest
 * package_version by version_compare(), never the newest row id. B12: this
 * pick and the relink in mecm_packages.php answered the question differently,
 * and a re-imported OLDER build (highest id, lower version) became the
 * recommended upgrade - the operator was told to update onto a downgrade.
 * Callers filter to strictly higher versions first: the update hint and the
 * template rule of the package sync (AV-R08/AV-R09).
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

/**
 * AV-R14 (15.10.2026): a name that vanishes because the SAME payload carries a
 * strictly higher version of the same package is a version change, not a
 * catalog loss, so the retire guard does not count it. Both sides split at the
 * last hyphen with repo_package_split_name and compare like
 * catalog_pick_highest_version (version_compare). A vanishing name without a
 * version is never a replacement. Only a version of digits and dots takes part
 * (AV-R03): any other version text never replaces and is never replaced.
 *
 * @param list<string> $vanishing active names the payload no longer carries
 * @param list<string> $payloadNames every package name of the payload
 * @return list<string> the vanishing names a strictly higher version of the same basename replaces
 */
function catalog_names_replaced_by_higher_version(array $vanishing, array $payloadNames): array
{
    // AV-R03: a version is digits and dots only (ConvertTo-VsPackageVersionParts, MECM side).
    $canonicalVersion = '/^[0-9]+(\.[0-9]+)*$/';
    $highestByBasename = [];
    foreach ($payloadNames as $payloadName) {
        $split = repo_package_split_name((string) $payloadName);
        if (preg_match($canonicalVersion, $split['version']) !== 1) {
            continue;
        }
        $basename = $split['basename'];
        if (!isset($highestByBasename[$basename]) || version_compare($split['version'], $highestByBasename[$basename], '>')) {
            $highestByBasename[$basename] = $split['version'];
        }
    }

    $replaced = [];
    $seen = [];
    foreach ($vanishing as $name) {
        $name = (string) $name;
        $split = repo_package_split_name($name);
        if (preg_match($canonicalVersion, $split['version']) !== 1 || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $basename = $split['basename'];
        if (isset($highestByBasename[$basename]) && version_compare($highestByBasename[$basename], $split['version'], '>')) {
            $replaced[] = $name;
        }
    }

    return $replaced;
}

// Update-available hints (AV-R09, 15.10.2026). The package sync never moves a
// real VM onto a newer version (AV-R08), so this hint is how an administrator
// learns about one: for every linked package, active or retired, whose
// basename has a strictly higher active version. The successor is picked by
// catalog_pick_highest_version (B12). No hint when the VM already holds that
// version or a higher one of the same package.
function repo_vm_package_upgrade_hints(mysqli $db, int $vmId): array
{
    $stmt = $db->prepare('SELECT p.package_name, p.package_basename, p.package_version
        FROM deploy_vm_packages link
        INNER JOIN deploy_packages p ON p.id = link.package_id
        WHERE link.vm_id = ?
        ORDER BY p.package_name');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
    $linked = repo_fetch_all($stmt->get_result());

    $heldByBasename = [];
    foreach ($linked as $row) {
        $heldByBasename[(string) $row['package_basename']][] = (string) $row['package_version'];
    }

    $active = $db->prepare('SELECT package_name AS new_name, package_version FROM deploy_packages WHERE package_basename = ? AND package_status <> ?');
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $hints = [];
    foreach ($linked as $row) {
        $basename = (string) $row['package_basename'];
        if ($basename === '') {
            continue;
        }
        $active->bind_param('ss', $basename, $retired);
        $active->execute();
        $higher = [];
        foreach (repo_fetch_all($active->get_result()) as $candidate) {
            if (version_compare((string) $candidate['package_version'], (string) $row['package_version'], '>')) {
                $higher[] = $candidate;
            }
        }
        $best = catalog_pick_highest_version($higher);
        if ($best === null) {
            continue;
        }
        $alreadyHeld = false;
        foreach ($heldByBasename[$basename] as $heldVersion) {
            if (version_compare($heldVersion, (string) $best['package_version'], '>=')) {
                $alreadyHeld = true;
                break;
            }
        }
        if (!$alreadyHeld) {
            $hints[(string) $row['package_name']] = (string) $best['new_name'];
        }
    }

    return $hints;
}

/**
 * AV-R10 (15.10.2026): two versions of one package on one VM are allowed. MECM
 * installs every ticked version, so the VM editor only warns about a basename
 * whose two or more versions are selected. Rows are grouped by their
 * package_basename column; rows without a basename are ignored.
 *
 * @param list<array<string,mixed>> $packages picker rows (id, package_name, package_basename, package_version)
 * @param list<int> $selectedIds package ids the editor ticks
 * @return array<string, list<string>> basename => package_name of each selected version, ordered by version ascending (version_compare); only basenames with two or more selected versions, ordered by basename
 */
function catalog_selected_version_groups(array $packages, array $selectedIds): array
{
    $selected = array_flip(array_map('intval', $selectedIds));
    $byBasename = [];
    foreach ($packages as $package) {
        $basename = (string) ($package['package_basename'] ?? '');
        if ($basename === '' || !isset($selected[(int) ($package['id'] ?? 0)])) {
            continue;
        }
        $byBasename[$basename][] = $package;
    }

    $groups = [];
    foreach ($byBasename as $basename => $rows) {
        if (count($rows) < 2) {
            continue;
        }
        usort($rows, static fn (array $a, array $b): int => version_compare((string) ($a['package_version'] ?? ''), (string) ($b['package_version'] ?? '')));
        $groups[(string) $basename] = array_map(static fn (array $row): string => (string) ($row['package_name'] ?? ''), $rows);
    }
    ksort($groups, SORT_STRING);

    return $groups;
}
