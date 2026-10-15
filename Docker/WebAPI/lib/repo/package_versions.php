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
