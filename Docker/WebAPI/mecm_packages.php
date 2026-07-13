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

// After a version bump the retired row's assignments move to the newest
// active row of the same basename. UPDATE IGNORE + DELETE covers VMs that
// were linked to both old and new version (composite PK collision).
function packages_relink_upgrades(mysqli $db, array $retiredRows, string $clientIp): int
{
    $relinked = 0;
    $summary = [];
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;

    foreach ($retiredRows as $row) {
        $basename = (string) $row['package_basename'];
        if ($basename === '') {
            continue;
        }
        $successor = repo_fetch_one($db, 'SELECT id, package_name FROM deploy_packages WHERE package_basename = ? AND package_status <> ? AND id <> ? ORDER BY id DESC LIMIT 1', 'ssi', [$basename, $retired, (int) $row['id']]);
        if ($successor === null) {
            continue;
        }

        $oldId = (int) $row['id'];
        $newId = (int) $successor['id'];
        $stmt = $db->prepare('UPDATE IGNORE deploy_vm_packages SET package_id = ? WHERE package_id = ?');
        $stmt->bind_param('ii', $newId, $oldId);
        $stmt->execute();
        $moved = $stmt->affected_rows;
        $stmt = $db->prepare('DELETE FROM deploy_vm_packages WHERE package_id = ?');
        $stmt->bind_param('i', $oldId);
        $stmt->execute();

        if ($moved > 0) {
            $relinked += $moved;
            $summary[] = (string) $row['package_name'] . ' -> ' . (string) $successor['package_name'] . ' (' . $moved . ' VM(s))';
        }
    }

    if ($summary !== []) {
        try {
            audit($db, VIRTUSPHERE_LOG_CATEGORY_MECM, '[mecm_packages] package relink: ' . implode('; ', $summary), null, $clientIp);
        } catch (Throwable $exception) {
            machine_api_log_warning('mecm_packages', 'relink audit failed: ' . $exception->getMessage());
        }
    }

    return $relinked;
}

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp);
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
            $stmt = $connection->prepare('SELECT id, package_name, package_basename FROM deploy_packages WHERE package_status <> ?');
            $stmt->bind_param('s', $retired);
        } else {
            $placeholders = implode(',', array_fill(0, count($packageNames), '?'));
            $types = 's' . str_repeat('s', count($packageNames));
            $params = array_merge([$retired], $packageNames);
            $stmt = $connection->prepare("SELECT id, package_name, package_basename FROM deploy_packages WHERE package_status <> ? AND package_name NOT IN ({$placeholders})");
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
    foreach ($packageNames as $name) {
        $split = repo_package_split_name($name);
        // Re-appearing names automatically un-retire (retired_at = NULL).
        $stmt = $connection->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status, retired_at) VALUES (?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE package_basename = VALUES(package_basename), package_version = VALUES(package_version), package_status = VALUES(package_status), retired_at = NULL');
        $stmt->bind_param('ssss', $name, $split['basename'], $split['version'], $packageStatus);
        $stmt->execute();
    }

    foreach ($osNames as $name) {
        $stmt = $connection->prepare('INSERT INTO deploy_os (os_name, os_status, retired_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE os_status = VALUES(os_status), retired_at = NULL');
        $stmt->bind_param('ss', $name, $packageStatus);
        $stmt->execute();
    }

    if ($retiredPackageRows !== []) {
        packages_relink_upgrades($connection, $retiredPackageRows, $clientIp);
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
