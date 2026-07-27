<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/constants.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/settings.php';
require_once __DIR__ . '/lib/repo/log.php';
require_once __DIR__ . '/lib/repo/catalog.php';

header('Content-Type: application/json; charset=utf-8');

// E3 hardening: catalog entries missing from a payload are RETIRED instead of
// deleted. The previous DELETE cascaded into deploy_vm_packages and silently
// destroyed VM package assignments on every regular version bump
// (removeOldVersion) and on partial WMI reads.

function catalog_retire_missing(mysqli $db, string $table, string $nameColumn, string $statusColumn, array $names): int
{
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    if ($names === []) {
        $stmt = $db->prepare("UPDATE {$table} SET {$statusColumn} = ?, retired_at = NOW() WHERE {$statusColumn} <> ?");
        $stmt->bind_param('ss', $retired, $retired);
        $stmt->execute();

        return $stmt->affected_rows;
    }

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $types = 'ss' . str_repeat('s', count($names));
    $params = array_merge([$retired, $retired], $names);
    $stmt = $db->prepare("UPDATE {$table} SET {$statusColumn} = ?, retired_at = NOW() WHERE {$statusColumn} <> ? AND {$nameColumn} NOT IN ({$placeholders})");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return $stmt->affected_rows;
}

// Threshold brake: refuse mass retirement (partial WMI reads, wrong folder)
// BEFORE any write. Loud on purpose - the sync loop retries visibly instead
// of silently emptying the catalog.
function packages_retire_guard(mysqli $db, string $table, string $nameColumn, string $statusColumn, array $keepNames, string $clientIp): void
{
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $active = (int) repo_scalar($db, "SELECT COUNT(*) FROM {$table} WHERE {$statusColumn} <> ?", 's', [$retired]);
    if ($active < VIRTUSPHERE_PACKAGE_RETIRE_MIN_ACTIVE) {
        return;
    }

    if ($keepNames === []) {
        $wouldRetire = $active;
    } else {
        $placeholders = implode(',', array_fill(0, count($keepNames), '?'));
        $types = 's' . str_repeat('s', count($keepNames));
        $params = array_merge([$retired], $keepNames);
        $wouldRetire = (int) repo_scalar($db, "SELECT COUNT(*) FROM {$table} WHERE {$statusColumn} <> ? AND {$nameColumn} NOT IN ({$placeholders})", $types, $params);
    }

    $threshold = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD, (string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT);
    $threshold = max(5, min(90, $threshold));
    if ($wouldRetire * 100 > $active * $threshold) {
        machine_api_audit_warning($db, 'mecm_packages_guard', sprintf(
            'Catalog sync rejected for %s: payload would retire %d of %d active entries (threshold %d%%).',
            $table,
            $wouldRetire,
            $active,
            $threshold
        ), $clientIp);
        machine_api_json(['error' => 'Katalog-Sync abgelehnt: zu viele Eintraege wuerden zurueckgezogen'], 409);
    }
}

/**
 * Moves a retired row's VM assignments to its successor, but ONLY on a real
 * version bump: the successor has to be a row this very payload created for the
 * first time, and its version has to be higher than the retired one's.
 *
 * Both conditions are the fix for a defect that could lose an assignment for
 * good, in three composing steps:
 *
 *  - the successor was chosen with `ORDER BY id DESC`, i.e. by ROW ID and not by
 *    version. On any catalog where the versions were not imported in ascending
 *    order, the assignment moved to the wrong, possibly older package.
 *  - it ran for every retired row, so a package that was merely missing from one
 *    payload (a MECM hiccup, an admin mid-edit) had its assignments rewritten to
 *    whatever else shared its basename. A transient catalog outage must not
 *    rewrite assignments at all, which is what the "new in this payload"
 *    condition guarantees: nothing new appeared, so nothing is an upgrade.
 *  - it then removed the old reference, and repo_purge_retired_packages()
 *    protects a retired row exactly while that reference exists. The relink
 *    therefore lifted the protection itself, the row was deleted after the purge
 *    window, and a re-import created a fresh id. ADR-0020 justifies the deletion
 *    as safe *because* linked rows are kept - and that held for every row except
 *    the ones that had assignments. assignments_relinked_at is the durable
 *    marker the purge now also reads.
 *
 * $newPackageIds are the ids this payload inserted (not updated), keyed by id.
 *
 * @param array<int, true> $newPackageIds
 */
function packages_relink_upgrades(mysqli $db, array $retiredRows, array $newPackageIds, string $clientIp): int
{
    $relinked = 0;
    $summary = [];
    $skipped = [];
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;

    foreach ($retiredRows as $row) {
        $basename = (string) $row['package_basename'];
        if ($basename === '') {
            continue;
        }

        $oldId = (int) $row['id'];
        $successor = packages_pick_successor($db, $basename, (string) $row['package_version'], $oldId, $newPackageIds, $retired);
        if ($successor === null) {
            // Deliberate no-op: without a newer row created by THIS payload there
            // is no upgrade to follow, so the assignment stays where the operator
            // put it. The row is retired, the picker keeps it selectable for the
            // VMs that hold it, and the VM editor shows the upgrade hint.
            $skipped[] = (string) $row['package_name'];
            continue;
        }

        $newId = (int) $successor['id'];
        // UPDATE IGNORE skips the rows whose VM already holds the successor
        // (composite PK collision); those are then deleted, because keeping them
        // would leave the VM with two links for one package. Scoped to exactly
        // those VMs, not "everything still pointing at the old id": an unscoped
        // DELETE also removed rows the UPDATE had failed on for any other reason.
        $stmt = $db->prepare('UPDATE IGNORE deploy_vm_packages SET package_id = ? WHERE package_id = ?');
        $stmt->bind_param('ii', $newId, $oldId);
        $stmt->execute();
        $moved = $stmt->affected_rows;

        $stmt = $db->prepare('DELETE FROM deploy_vm_packages WHERE package_id = ? AND vm_id IN (SELECT vm_id FROM (SELECT vm_id FROM deploy_vm_packages WHERE package_id = ?) AS held)');
        $stmt->bind_param('ii', $oldId, $newId);
        $stmt->execute();
        $collapsed = $stmt->affected_rows;

        if ($moved > 0 || $collapsed > 0) {
            // The marker is what keeps the purge from deleting a row whose only
            // protection this relink just removed.
            $stmt = $db->prepare('UPDATE deploy_packages SET assignments_relinked_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $oldId);
            $stmt->execute();
        }

        if ($moved > 0) {
            $relinked += $moved;
            $summary[] = (string) $row['package_name'] . ' -> ' . (string) $successor['package_name'] . ' (' . $moved . ' VM(s))';
        }
    }

    try {
        if ($summary !== []) {
            audit($db, VIRTUSPHERE_LOG_CATEGORY_MECM, '[mecm_packages] package relink: ' . implode('; ', $summary), null, $clientIp);
        }
        if ($skipped !== []) {
            // Said out loud, because "nothing happened" is the new behaviour and
            // an operator who expected a relink needs to see that it was a
            // decision and not a failure.
            audit($db, VIRTUSPHERE_LOG_CATEGORY_MECM, '[mecm_packages] retired without relink (no newer version in this payload): ' . implode(', ', $skipped), null, $clientIp);
        }
    } catch (Throwable $exception) {
        machine_api_log_warning('mecm_packages', 'relink audit failed: ' . $exception->getMessage());
    }

    return $relinked;
}

/**
 * The successor of one retired row, or null when there is none to follow.
 *
 * Two conditions, both necessary: the candidate must be one of the ids this
 * payload created (so a transient disappearance cannot move anything), and its
 * version must be strictly higher than the retired row's, compared with
 * version_compare() rather than by row id. Among several new candidates the
 * highest version wins.
 *
 * @param array<int, true> $newPackageIds
 * @return array<string, mixed>|null
 */
function packages_pick_successor(mysqli $db, string $basename, string $retiredVersion, int $retiredId, array $newPackageIds, string $retired): ?array
{
    if ($newPackageIds === []) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, package_name, package_version FROM deploy_packages WHERE package_basename = ? AND package_status <> ? AND id <> ?');
    $stmt->bind_param('ssi', $basename, $retired, $retiredId);
    $stmt->execute();

    $eligible = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $candidate) {
        if (!isset($newPackageIds[(int) $candidate['id']])) {
            continue;
        }
        if (version_compare((string) $candidate['package_version'], $retiredVersion, '<=')) {
            continue;
        }
        $eligible[] = $candidate;
    }

    // The best-of pick is the shared rule (B12): the same helper feeds the
    // portal's update hint, so relink and hint can never disagree again.
    return catalog_pick_highest_version($eligible);
}

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp, $connection);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Methode nicht erlaubt'], 405);
}

try {
    $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        machine_api_json(['error' => 'Keine Daten empfangen'], 400);
    }
    if ($data === []) {
        machine_api_log_warning('mecm_packages', 'Rejected empty payload from ' . $clientIp . '; refusing to delete deploy package catalog.');
        machine_api_json(['error' => 'Leerer Payload wird abgelehnt; Katalog wurde nicht geaendert'], 400);
    }

    $packageNames = [];
    $osNames = [];
    $hasPackagePayload = false;
    $hasTaskSequencePayload = false;
    foreach ($data as $entry) {
        if (!is_array($entry) || !isset($entry['type'], $entry['name']) || trim((string) $entry['name']) === '') {
            machine_api_json(['error' => 'Ungueltiger Eintrag'], 400);
        }
        if ($entry['type'] === 'Package') {
            $hasPackagePayload = true;
            $packageNames[] = (string) $entry['name'];
        } elseif ($entry['type'] === 'TaskSequence') {
            $hasTaskSequencePayload = true;
            $osNames[] = (string) $entry['name'];
        } else {
            machine_api_json(['error' => 'Unbekannter Daten Typ'], 400);
        }
    }

    $packageNames = array_values(array_unique($packageNames));
    $osNames = array_values(array_unique($osNames));

    // Guards run BEFORE any write, outside the transaction.
    if ($hasPackagePayload) {
        packages_retire_guard($connection, 'deploy_packages', 'package_name', 'package_status', $packageNames, $clientIp);
    }
    if ($hasTaskSequencePayload) {
        packages_retire_guard($connection, 'deploy_os', 'os_name', 'os_status', $osNames, $clientIp);
    }

    $connection->begin_transaction();

    $retiredPackageRows = [];
    if ($hasPackagePayload) {
        // Capture the soon-retired rows first so assignments can be relinked
        // to their successors after the upsert.
        $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
        if ($packageNames === []) {
            $stmt = $connection->prepare('SELECT id, package_name, package_basename, package_version FROM deploy_packages WHERE package_status <> ?');
            $stmt->bind_param('s', $retired);
        } else {
            $placeholders = implode(',', array_fill(0, count($packageNames), '?'));
            $types = 's' . str_repeat('s', count($packageNames));
            $params = array_merge([$retired], $packageNames);
            $stmt = $connection->prepare("SELECT id, package_name, package_basename, package_version FROM deploy_packages WHERE package_status <> ? AND package_name NOT IN ({$placeholders})");
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $retiredPackageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        catalog_retire_missing($connection, 'deploy_packages', 'package_name', 'package_status', $packageNames);
    } else {
        machine_api_log_warning('mecm_packages', 'Package payload absent; leaving deploy_packages untouched.');
    }
    if ($hasTaskSequencePayload) {
        catalog_retire_missing($connection, 'deploy_os', 'os_name', 'os_status', $osNames);
    } else {
        machine_api_log_warning('mecm_packages', 'TaskSequence payload absent; leaving deploy_os untouched.');
    }

    $packageStatus = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
    // The ids this payload CREATED, as opposed to re-confirmed. Only they can be
    // the successor of a version bump: without something new, a package missing
    // from the payload is a gap and not an upgrade, and rewriting assignments on
    // a gap is how a transient MECM outage used to move them.
    //
    // affected_rows after INSERT ... ON DUPLICATE KEY UPDATE is 1 for an insert
    // and 2 (or 0) for an update, so it distinguishes the two exactly. insert_id
    // is only meaningful in the insert case.
    $newPackageIds = [];
    foreach ($packageNames as $name) {
        $split = repo_package_split_name($name);
        // Re-appearing names automatically un-retire (retired_at = NULL).
        $stmt = $connection->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status, retired_at) VALUES (?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE package_basename = VALUES(package_basename), package_version = VALUES(package_version), package_status = VALUES(package_status), retired_at = NULL');
        $stmt->bind_param('ssss', $name, $split['basename'], $split['version'], $packageStatus);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            $newPackageIds[(int) $connection->insert_id] = true;
        }
    }

    foreach ($osNames as $name) {
        $stmt = $connection->prepare('INSERT INTO deploy_os (os_name, os_status, retired_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE os_status = VALUES(os_status), retired_at = NULL');
        $stmt->bind_param('ss', $name, $packageStatus);
        $stmt->execute();
    }

    if ($retiredPackageRows !== []) {
        packages_relink_upgrades($connection, $retiredPackageRows, $newPackageIds, $clientIp);
    }

    $connection->commit();
    machine_api_json(['success' => 'Daten erfolgreich empfangen', 'packages' => count($packageNames), 'task_sequences' => count($osNames)]);
} catch (JsonException) {
    machine_api_json(['error' => 'Ungueltiger JSON-Body'], 400);
} catch (Throwable $exception) {
    $connection->rollback();
    machine_api_log_warning('mecm_packages', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
