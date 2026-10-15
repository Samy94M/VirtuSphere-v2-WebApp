<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/catalog.php';

/**
 * AV-R08 (15.10.2026): the package sync never moves a real VM's assignment.
 * A newer version would run its install.ps1 again on an installed system and
 * overwrite what the administrator configured after the old version; only a
 * person decides that, per VM, in the editor.
 *
 * Template VMs are not installed systems (they are never transferred to MECM),
 * so they follow a newer version exactly once: when that version appears in
 * the catalog for the first time. Afterwards the template keeps whatever the
 * administrator sets, so a template can deliberately stay on an older version.
 *
 * This collects the template assignments that a first-time version in this
 * payload will move, and locks their VM parents in numeric order before any
 * package row changes. The VM editor takes VM before package assignment/FK
 * locks too, so the sync must not introduce the inverse Package -> VM order.
 * The template check runs again after the lock, so a mission renamed between
 * the read and the lock is judged by its locked name.
 *
 * @param list<string> $payloadNames package names of this payload
 * @return list<array{vm_id:int,package_id:int,basename:string,version:string}>
 */
function packages_lock_template_follow_scope(mysqli $db, array $payloadNames): array
{
    $payloadNames = array_values(array_unique($payloadNames));
    if ($payloadNames === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($payloadNames), '?'));
    $stmt = $db->prepare('SELECT package_name FROM deploy_packages WHERE package_name IN (' . $placeholders . ')');
    $stmt->bind_param(str_repeat('s', count($payloadNames)), ...$payloadNames);
    $stmt->execute();
    $known = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $known[(string) $row['package_name']] = true;
    }

    // Highest first-time version per basename. Only these can be followed.
    $firstTime = [];
    foreach ($payloadNames as $name) {
        if (isset($known[$name])) {
            continue;
        }
        $split = repo_package_split_name($name);
        $basename = (string) $split['basename'];
        $version = (string) $split['version'];
        if ($basename === '' || $version === '') {
            continue;
        }
        if (!isset($firstTime[$basename]) || version_compare($version, $firstTime[$basename], '>')) {
            $firstTime[$basename] = $version;
        }
    }
    if ($firstTime === []) {
        return [];
    }

    $basenames = array_keys($firstTime);
    $placeholders = implode(',', array_fill(0, count($basenames), '?'));
    $stmt = $db->prepare(
        'SELECT link.vm_id, link.package_id, p.package_basename, p.package_version, m.mission_name
        FROM deploy_vm_packages link
        INNER JOIN deploy_packages p ON p.id = link.package_id
        INNER JOIN deploy_vms v ON v.id = link.vm_id
        INNER JOIN deploy_missions m ON m.id = v.mission_id
        WHERE p.package_basename IN (' . $placeholders . ')
        ORDER BY link.vm_id, link.package_id'
    );
    $stmt->bind_param(str_repeat('s', count($basenames)), ...$basenames);
    $stmt->execute();

    $candidates = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $basename = (string) $row['package_basename'];
        if (!mission_name_is_template((string) $row['mission_name'])) {
            continue;
        }
        if (!version_compare((string) $row['package_version'], $firstTime[$basename], '<')) {
            continue;
        }
        $candidates[] = [
            'vm_id' => (int) $row['vm_id'],
            'package_id' => (int) $row['package_id'],
            'basename' => $basename,
            'version' => (string) $row['package_version'],
        ];
    }
    if ($candidates === []) {
        return [];
    }

    $vmIds = array_values(array_unique(array_map(static fn (array $c): int => $c['vm_id'], $candidates)));
    sort($vmIds, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($vmIds), '?'));
    $stmt = $db->prepare(
        'SELECT v.id, m.mission_name FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id
        WHERE v.id IN (' . $placeholders . ') ORDER BY v.id FOR UPDATE'
    );
    $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
    $stmt->execute();
    $stillTemplate = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (mission_name_is_template((string) $row['mission_name'])) {
            $stillTemplate[(int) $row['id']] = true;
        }
    }

    return array_values(array_filter(
        $candidates,
        static fn (array $c): bool => isset($stillTemplate[$c['vm_id']])
    ));
}
