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
require_once __DIR__ . '/lib/repo/package_sync.php';

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
// of silently emptying the catalog. AV-R14: a package name that the same payload
// replaces with a strictly higher version is a version change and is not counted.
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
        $stmt = $db->prepare("SELECT {$nameColumn} FROM {$table} WHERE {$statusColumn} <> ? AND {$nameColumn} NOT IN ({$placeholders})");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $names = array_map(static fn (array $row): string => (string) $row[$nameColumn], repo_fetch_all($stmt->get_result()));
        $wouldRetire = count($names);
        if ($table === 'deploy_packages') {
            // AV-R14: a name the same sync replaces with a higher version of its package is no loss.
            $wouldRetire -= count(catalog_names_replaced_by_higher_version($names, $keepNames));
        }
    }

    $threshold = (int) repo_setting_value($db, VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD, (string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT);
    $threshold = max(5, min(90, $threshold));
    if ($wouldRetire * 100 > $active * $threshold) {
        $catalog = match ($table) {
            'deploy_packages' => 'packages',
            'deploy_os' => 'task_sequences',
            default => throw new InvalidArgumentException('Unknown MECM catalog owner'),
        };
        machine_api_audit_warning(
            $db,
            VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_REJECTED,
            'catalog',
            $catalog,
            VIRTUSPHERE_AUDIT_RESULT_DENIED,
            ['retire_count' => $wouldRetire, 'active_count' => $active, 'threshold_percent' => $threshold],
            $clientIp
        );
        machine_api_json(['error' => 'Katalog-Sync abgelehnt: zu viele Eintraege wuerden zurueckgezogen'], 409);
    }
}

/**
 * AV-R08 (15.10.2026): the template half of the version rule. Each collected
 * template assignment moves to the highest version of its package that this
 * payload created, if that version is strictly higher. Real VMs are never in
 * the scope (packages_lock_template_follow_scope), so the sync cannot move an
 * installed system onto a version nobody chose for it.
 *
 * $newPackageIds are the ids this payload inserted (not updated), keyed by id:
 * a re-appearing or re-confirmed row is not a first appearance.
 *
 * @param list<array{vm_id:int,package_id:int,basename:string,version:string}> $templateScope
 * @param array<int, true> $newPackageIds
 */
function packages_follow_first_versions_in_templates(mysqli $db, array $templateScope, array $newPackageIds, string $clientIp): int
{
    if ($templateScope === [] || $newPackageIds === []) {
        return 0;
    }

    $ids = array_map('intval', array_keys($newPackageIds));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT id, package_name, package_basename, package_version FROM deploy_packages WHERE id IN (' . $placeholders . ')');
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $newByBasename = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $newByBasename[(string) $row['package_basename']][] = $row;
    }

    $oldNames = [];
    $move = $db->prepare('UPDATE IGNORE deploy_vm_packages SET package_id = ? WHERE package_id = ? AND vm_id = ?');
    $holdsTarget = $db->prepare('SELECT 1 FROM deploy_vm_packages WHERE vm_id = ? AND package_id = ? LIMIT 1');
    $collapse = $db->prepare('DELETE FROM deploy_vm_packages WHERE package_id = ? AND vm_id = ?');
    $markMoved = $db->prepare('UPDATE deploy_packages SET assignments_relinked_at = NOW() WHERE id = ?');
    $lookup = $db->prepare('SELECT package_name FROM deploy_packages WHERE id = ?');
    $moves = [];
    foreach ($templateScope as $entry) {
        // The shared successor rule (B12): highest version by version_compare.
        $target = catalog_pick_highest_version($newByBasename[$entry['basename']] ?? []);
        if ($target === null || !version_compare((string) $target['package_version'], $entry['version'], '>')) {
            continue;
        }
        $oldId = $entry['package_id'];
        $newId = (int) $target['id'];
        $vmId = $entry['vm_id'];
        $move->bind_param('iii', $newId, $oldId, $vmId);
        $move->execute();
        $changed = $move->affected_rows;
        if ($changed === 0) {
            // UPDATE IGNORE skips the row when this VM already holds the target.
            // Delete only that proven duplicate; never lose an assignment.
            $holdsTarget->bind_param('ii', $vmId, $newId);
            $holdsTarget->execute();
            if ($holdsTarget->get_result()->fetch_row() !== null) {
                $collapse->bind_param('ii', $oldId, $vmId);
                $collapse->execute();
                $changed = $collapse->affected_rows;
            }
        }
        if ($changed <= 0) {
            continue;
        }
        repo_advance_vm_edit_version($db, $vmId);
        // A retired row whose assignment moved away keeps its purge protection.
        $markMoved->bind_param('i', $oldId);
        $markMoved->execute();
        if (!isset($oldNames[$oldId])) {
            $lookup->bind_param('i', $oldId);
            $lookup->execute();
            $oldNames[$oldId] = (string) ($lookup->get_result()->fetch_row()[0] ?? $oldId);
        }
        $key = $oldNames[$oldId] . ' -> ' . (string) $target['package_name'];
        $moves[$key] = ($moves[$key] ?? 0) + 1;
    }

    $items = [];
    foreach ($moves as $key => $count) {
        $items[] = $key . ' (' . $count . ' Vorlagen-VM(s))';
    }
    // LG-04: inside the sync transaction. A failed row rolls the sync back, so a
    // move nobody can see is never committed. The list is cut to fit (LG-05).
    if ($items !== []) {
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, 'catalog', 'packages', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
            'items' => audit_fit_string_list($items),
            'item_count' => count($items),
        ], null, $clientIp);
    }

    return array_sum($moves);
}

/**
 * AV-R08: a version leaving the catalog while VMs still hold it is said out
 * loud once, at the moment it is retired. The entry names how many real VMs
 * and template VMs still hold it and the higher active version an
 * administrator can move them to, so the log is the work list. Nothing is
 * moved here.
 *
 * @param list<array<string,mixed>> $retiredRows rows this payload retired
 */
function packages_audit_retired_assignments(mysqli $db, array $retiredRows, string $clientIp): void
{
    $items = [];
    $retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
    $holders = $db->prepare('SELECT m.mission_name FROM deploy_vm_packages link
        INNER JOIN deploy_vms v ON v.id = link.vm_id
        INNER JOIN deploy_missions m ON m.id = v.mission_id
        WHERE link.package_id = ?');
    $successors = $db->prepare('SELECT id, package_name, package_version FROM deploy_packages WHERE package_basename = ? AND package_status <> ? AND id <> ?');
    foreach ($retiredRows as $row) {
        $id = (int) $row['id'];
        $holders->bind_param('i', $id);
        $holders->execute();
        $realVms = 0;
        $templateVms = 0;
        foreach ($holders->get_result()->fetch_all(MYSQLI_ASSOC) as $holder) {
            if (mission_name_is_template((string) $holder['mission_name'])) {
                $templateVms++;
            } else {
                $realVms++;
            }
        }
        if ($realVms === 0 && $templateVms === 0) {
            continue;
        }
        $basename = (string) $row['package_basename'];
        $higher = [];
        if ($basename !== '') {
            $successors->bind_param('ssi', $basename, $retired, $id);
            $successors->execute();
            foreach ($successors->get_result()->fetch_all(MYSQLI_ASSOC) as $candidate) {
                if (version_compare((string) $candidate['package_version'], (string) $row['package_version'], '>')) {
                    $higher[] = $candidate;
                }
            }
        }
        $best = catalog_pick_highest_version($higher);
        $items[] = sprintf(
            '%s: %d VM(s), %d Vorlagen-VM(s), %s',
            (string) $row['package_name'],
            $realVms,
            $templateVms,
            $best === null ? 'keine hoehere Version aktiv' : 'hoehere Version ' . (string) $best['package_name']
        );
    }
    if ($items === []) {
        return;
    }
    // LG-04 / LG-05: as the relink row above, inside the sync transaction and cut to fit.
    audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, 'catalog', 'packages', VIRTUSPHERE_AUDIT_RESULT_WARNING, [
        'items' => audit_fit_string_list($items),
        'item_count' => count($items),
    ], null, $clientIp);
}

/**
 * LG-28: a sync that created or retired catalog entries says so in one row per
 * catalog, with both counters. A run that changed neither writes nothing. The
 * row is written inside the sync transaction, so a failed insert rolls the sync
 * back instead of committing a change nobody can see.
 */
function packages_audit_catalog_changes(mysqli $db, string $catalog, int $created, int $retired, string $clientIp): void
{
    if ($created === 0 && $retired === 0) {
        return;
    }
    audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_CHANGED, 'catalog', $catalog, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
        'created_count' => $created,
        'retired_count' => $retired,
    ], null, $clientIp);
}

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp, 'package_catalog')) {
    machine_api_forbidden($clientIp, $connection);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Methode nicht erlaubt'], 405);
}

try {
    $raw = (string) file_get_contents('php://input', false, null, 0, VIRTUSPHERE_MECM_PACKAGES_MAX_BODY_BYTES + 1);
    if (strlen($raw) > VIRTUSPHERE_MECM_PACKAGES_MAX_BODY_BYTES) {
        machine_api_json(['error' => 'Payload too large'], 413);
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        machine_api_json(['error' => 'Keine Daten empfangen'], 400);
    }
    if ($data === []) {
        machine_api_json(['error' => 'Leerer Payload wird abgelehnt; Katalog wurde nicht geaendert'], 400);
    }

    $packageNames = [];
    $osNames = [];
    $hasPackagePayload = false;
    $hasTaskSequencePayload = false;
    foreach ($data as $entry) {
        // A name is a string of 1 to 255 characters (MA-04). An array made the
        // (string) cast throw into a 500; a longer name failed the INSERT. The
        // type needs no cast: it is compared strictly and an array falls through
        // to the existing 'Unbekannter Daten Typ' answer.
        if (!is_array($entry) || !isset($entry['type'], $entry['name'])
            || !is_string($entry['name']) || trim($entry['name']) === ''
            || mb_strlen($entry['name']) > VIRTUSPHERE_CATALOG_NAME_MAX_CHARS) {
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

    // LG-04: the write runs in repo_transaction(). The former raw begin_transaction()
    // was invisible to the depth tracker, and audit_event_required() refuses to run
    // outside a tracked transaction. So every required audit row of the sync shares
    // the fate of the catalog write: a row that cannot be stored rolls the sync back.
    repo_transaction($connection, static function () use ($connection, $hasPackagePayload, $hasTaskSequencePayload, $packageNames, $osNames, $clientIp): void {
        $retiredPackageRows = [];
        $templateFollowScope = [];
        $retiredPackages = 0;
        $retiredOs = 0;
        if ($hasPackagePayload) {
            // Capture the soon-retired rows first so the retirement of a version
            // that VMs still hold can be logged after the upsert (AV-R08).
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
            // Template VMs only; real VM assignments are never part of a sync.
            $templateFollowScope = packages_lock_template_follow_scope($connection, $packageNames);

            $retiredPackages = catalog_retire_missing($connection, 'deploy_packages', 'package_name', 'package_status', $packageNames);
        } else {
            machine_api_log_warning('mecm_packages', 'Package payload absent; leaving deploy_packages untouched.');
        }
        if ($hasTaskSequencePayload) {
            $retiredOs = catalog_retire_missing($connection, 'deploy_os', 'os_name', 'os_status', $osNames);
        } else {
            machine_api_log_warning('mecm_packages', 'TaskSequence payload absent; leaving deploy_os untouched.');
        }

        $packageStatus = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
        // The ids this payload CREATED, as opposed to re-confirmed. Only they are a
        // first appearance that template VMs follow: a re-appearing or re-confirmed
        // row is not new, and a transient MECM gap must not move anything.
        //
        // affected_rows after INSERT ... ON DUPLICATE KEY UPDATE is 1 for an insert
        // and 2 (or 0) for an update, so it distinguishes the two exactly. insert_id
        // is only meaningful in the insert case.
        $newPackageIds = [];
        $createdPackages = 0;
        foreach ($packageNames as $name) {
            $split = repo_package_split_name($name);
            // Re-appearing names automatically un-retire (retired_at = NULL).
            $stmt = $connection->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status, retired_at) VALUES (?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE package_basename = VALUES(package_basename), package_version = VALUES(package_version), package_status = VALUES(package_status), retired_at = NULL');
            $stmt->bind_param('ssss', $name, $split['basename'], $split['version'], $packageStatus);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $newPackageIds[(int) $connection->insert_id] = true;
                $createdPackages++;
            }
        }

        $createdOs = 0;
        foreach ($osNames as $name) {
            $stmt = $connection->prepare('INSERT INTO deploy_os (os_name, os_status, retired_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE os_status = VALUES(os_status), retired_at = NULL');
            $stmt->bind_param('ss', $name, $packageStatus);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $createdOs++;
            }
        }

        if ($templateFollowScope !== []) {
            packages_follow_first_versions_in_templates($connection, $templateFollowScope, $newPackageIds, $clientIp);
        }
        // After the template step, so the counts name what is really left to do.
        if ($retiredPackageRows !== []) {
            packages_audit_retired_assignments($connection, $retiredPackageRows, $clientIp);
        }
        // LG-28: one summary row per catalog that changed; none for a run without change.
        packages_audit_catalog_changes($connection, 'packages', $createdPackages, $retiredPackages, $clientIp);
        packages_audit_catalog_changes($connection, 'task_sequences', $createdOs, $retiredOs, $clientIp);
    });
    machine_api_json(['success' => 'Daten erfolgreich empfangen', 'packages' => count($packageNames), 'task_sequences' => count($osNames)]);
} catch (JsonException) {
    machine_api_json(['error' => 'Ungueltiger JSON-Body'], 400);
} catch (Throwable $exception) {
    $connection->rollback();
    machine_api_audit_warning($connection, VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE, 'machine_endpoint', 'mecm_packages.php', VIRTUSPHERE_AUDIT_RESULT_FAILURE, ['error_class' => $exception::class], $clientIp);
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
