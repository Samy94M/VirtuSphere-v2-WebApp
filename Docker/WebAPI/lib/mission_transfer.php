<?php

declare(strict_types=1);

/**
 * Mission export/import as portable JSON (Paket A2 / ADR-0021).
 *
 * Export produces a self-contained JSON document of one mission and its VMs,
 * interfaces (WITHOUT MAC addresses), disks and package references (by name).
 * Import re-creates the mission under a new name in one transaction, reusing the
 * same validation and write helpers as the portal editor and the template clone
 * path. It never carries MAC addresses, MECM ids or workflow state across: VMs
 * come in fresh (Registered / not_ready), exactly like a template clone.
 *
 * This is a data-transfer feature, not a backup/restore path: it never touches
 * the dumps and never writes primary keys from the file.
 */

require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/catalog.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';

const VIRTUSPHERE_MISSION_EXPORT_VERSION = 1;

// Upload/parse guards for import (A4). ~500 VMs is roughly 1 MB; 2 MB leaves
// headroom and can be raised centrally if a customer needs bigger missions.
const VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const VIRTUSPHERE_MISSION_IMPORT_JSON_DEPTH = 32;
// Server-side dry-run hand-off lifetime (A4): the parsed payload is kept in the
// session between preview and confirm so the file is not re-uploaded.
const VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS = 600;

/**
 * Mission columns carried by the transfer format (no id/timestamps).
 *
 * Adding a field here does NOT bump VIRTUSPHERE_MISSION_EXPORT_VERSION: the
 * import reads keys it finds and lets the validator default the rest, so an
 * export written before a field existed still imports. Bumping the version would
 * make every file already on disk unimportable, since the check is an equality.
 */
const VIRTUSPHERE_MISSION_TRANSFER_MISSION_FIELDS = [
    'mission_status',
    'mission_notes',
    'wds_vlan',
    'hypervisor_datastorage',
    'hypervisor_datacenter',
    'domain',
    ...REPO_MISSION_AUTOSTART_COLUMNS,
];

/** Interface columns carried by the transfer format (MAC deliberately excluded). */
const VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS = [
    'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type',
];

/**
 * Builds the export payload for one mission.
 *
 * @return array<string, mixed>
 */
function mission_export_payload(mysqli $db, int $missionId): array
{
    $mission = repo_get_mission($db, $missionId);
    if ($mission === null) {
        throw new RuntimeException('Mission not found.');
    }

    $missionOut = ['mission_name' => (string) $mission['mission_name']];
    foreach (VIRTUSPHERE_MISSION_TRANSFER_MISSION_FIELDS as $field) {
        $missionOut[$field] = (string) ($mission[$field] ?? '');
    }

    $vmsOut = [];
    foreach (getVMs($db, $missionId) as $vm) {
        $vmOut = [];
        foreach (REPO_VM_COLUMNS as $column) {
            $vmOut[$column] = (string) ($vm[$column] ?? '');
        }

        $interfaces = [];
        foreach ($vm['interfaces'] as $interface) {
            $row = [];
            foreach (VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS as $field) {
                $row[$field] = (string) ($interface[$field] ?? '');
            }
            $interfaces[] = $row; // MAC intentionally omitted.
        }

        $disks = [];
        foreach ($vm['disks'] as $disk) {
            $disks[] = [
                'disk_name' => (string) ($disk['disk_name'] ?? ''),
                'disk_size' => (int) ($disk['disk_size'] ?? 0),
                'disk_type' => (string) ($disk['disk_type'] ?? ''),
            ];
        }

        $packages = [];
        foreach ($vm['packages'] as $package) {
            $packages[] = [
                'name' => (string) ($package['package_name'] ?? ''),
                'version' => (string) ($package['package_version'] ?? ''),
            ];
        }

        $vmOut['interfaces'] = $interfaces;
        $vmOut['disks'] = $disks;
        $vmOut['packages'] = $packages;
        $vmsOut[] = $vmOut;
    }

    return [
        'format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION,
        'exported_at' => date('c'),
        'mission' => $missionOut,
        'vms' => $vmsOut,
    ];
}

/**
 * Resolves a package reference (name + optional version) to an id, or 0.
 */
function mission_transfer_resolve_package_id(mysqli $db, string $name, string $version): int
{
    if ($name === '') {
        return 0;
    }

    return (int) repo_scalar(
        $db,
        'SELECT id FROM deploy_packages WHERE package_name = ? AND (? = "" OR package_version = ?) LIMIT 1',
        'sss',
        [$name, $version, $version]
    );
}

/**
 * Dry-run analysis and (when $dryRun is false) commit of a mission import.
 *
 * The report lists counts, resolved vs missing package references, missing
 * VLANs and colliding VM names so the confirm step can show it before writing.
 * Missing VLANs and VM-name collisions and a name conflict BLOCK the import
 * (report['blocked'] = true); missing packages are a warning (skipped on write).
 *
 * @param array<string, mixed> $payload Parsed JSON document.
 * @return array<string, mixed> Import report.
 */
function mission_import(mysqli $db, array $payload, string $newName, bool $dryRun, ?int $userId = null): array
{
    $formatVersion = (int) ($payload['format_version'] ?? 0);
    if ($formatVersion !== VIRTUSPHERE_MISSION_EXPORT_VERSION) {
        throw new RuntimeException('Unsupported export format version.');
    }
    if (!isset($payload['mission']) || !is_array($payload['mission']) || !isset($payload['vms']) || !is_array($payload['vms'])) {
        throw new RuntimeException('Malformed export document.');
    }

    $newName = trim($newName);
    $missionSrc = $payload['mission'];
    $vmsSrc = $payload['vms'];

    $report = [
        'format_version' => $formatVersion,
        'mission_name' => $newName,
        'name_conflict' => false,
        'counts' => ['vms' => 0, 'interfaces' => 0, 'disks' => 0, 'packages' => 0],
        'resolved_packages' => [],
        'missing_packages' => [],
        'missing_vlans' => [],
        'vm_name_conflicts' => [],
        'mac_note' => true,
        'blocked' => false,
        'imported' => false,
        'mission_id' => null,
    ];

    // Mission name: required, no spaces, unique.
    if ($newName === '' || preg_match('/\s/', $newName) === 1 || mb_strlen($newName) > 255) {
        $message = validator_text('validate.mission_name_invalid', 'Enter a valid mission name (no spaces, max 255 characters).');
        throw new ValidationException(['mission_name' => $message], $message);
    }
    if (mission_name_is_template($newName)) {
        $message = validator_text('validate.mission_import_no_template', 'Imported missions must not start with the template prefix.');
        throw new ValidationException(['mission_name' => $message], $message);
    }
    if (repo_mission_name_exists($db, $newName)) {
        $report['name_conflict'] = true;
        $report['blocked'] = true;
    }

    // Collect referenced VLANs (mission WDS + per-interface) and check presence.
    $vlanRefs = [];
    $missionVlan = trim((string) ($missionSrc['wds_vlan'] ?? ''));
    if ($missionVlan !== '') {
        $vlanRefs[$missionVlan] = true;
    }

    foreach ($vmsSrc as $vm) {
        if (!is_array($vm)) {
            throw new RuntimeException('Malformed VM entry in export document.');
        }
        $report['counts']['vms']++;

        $vmName = trim((string) ($vm['vm_name'] ?? ''));
        if ($vmName !== '') {
            $conflict = repo_vm_name_conflict_global($db, $vmName);
            if ($conflict !== null) {
                $report['vm_name_conflicts'][] = $vmName . ' (' . (string) $conflict['mission_name'] . ')';
            }
        }

        foreach ((array) ($vm['interfaces'] ?? []) as $interface) {
            $report['counts']['interfaces']++;
            $ifVlan = trim((string) ($interface['vlan'] ?? ''));
            if ($ifVlan !== '') {
                $vlanRefs[$ifVlan] = true;
            }
        }
        $report['counts']['disks'] += count((array) ($vm['disks'] ?? []));

        foreach ((array) ($vm['packages'] ?? []) as $package) {
            $name = trim((string) ($package['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $report['counts']['packages']++;
            $version = trim((string) ($package['version'] ?? ''));
            if (mission_transfer_resolve_package_id($db, $name, $version) > 0) {
                $report['resolved_packages'][$name] = true;
            } else {
                $report['missing_packages'][$name] = true;
            }
        }
    }

    foreach (array_keys($vlanRefs) as $vlanName) {
        if (!repo_vlan_name_exists($db, (string) $vlanName)) {
            $report['missing_vlans'][] = (string) $vlanName;
        }
    }

    $report['resolved_packages'] = array_keys($report['resolved_packages']);
    $report['missing_packages'] = array_keys($report['missing_packages']);

    if ($report['missing_vlans'] !== [] || $report['vm_name_conflicts'] !== []) {
        $report['blocked'] = true;
    }

    if ($dryRun) {
        return $report;
    }

    if ($report['blocked']) {
        // Belt-and-suspenders: the page disables confirm when blocked, but never
        // rely on the client to enforce it.
        throw new RuntimeException('Import is blocked; resolve the reported issues first.');
    }

    return repo_transaction($db, static function () use ($db, $newName, $missionSrc, $vmsSrc, $userId, $report): array {
        // repo_mission_copyable_values() omits an autostart key the file does not
        // carry, so a v1 export written before this feature lands on the column
        // defaults instead of pushing '' into an INT NOT NULL column.
        $missionValues = [
            'mission_name' => $newName,
            'mission_status' => VIRTUSPHERE_MISSION_STATUS_DEFAULT,
        ] + repo_mission_copyable_values($missionSrc);
        $missionValues = repo_validate_mission_values($db, $missionValues, 0, true, false);
        // The mission row is created here, by the importer - a mission_creator in
        // the transfer file is untrusted external data and is never copied. The VM
        // rows below keep their own vm_creator, which is part of the exported spec.
        $missionValues['mission_creator'] = repo_creator_name($db, $userId);
        $missionId = repo_insert_from_values($db, 'deploy_missions', $missionValues);

        foreach ($vmsSrc as $vm) {
            $vmData = [];
            foreach (REPO_VM_COLUMNS as $column) {
                if (array_key_exists($column, $vm)) {
                    $vmData[$column] = $vm[$column];
                }
            }
            // Untrusted external data: full validation (defaults fill gaps,
            // NetBIOS + global-uniqueness rules enforced).
            $values = repo_validate_vm_payload($db, $missionId, $vmData, 0);
            $values['mission_id'] = $missionId;
            $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
            $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
            $values['mecm_sync_state'] = VIRTUSPHERE_MECM_NOT_READY;
            $values['updated'] = 0;
            $vmId = repo_insert_from_values($db, 'deploy_vms', $values);

            // Interfaces without MAC (false = do not preserve, no mac field set).
            $interfaces = [];
            foreach ((array) ($vm['interfaces'] ?? []) as $interface) {
                $row = [];
                foreach (VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS as $field) {
                    $row[$field] = (string) ($interface[$field] ?? '');
                }
                $interfaces[] = $row;
            }
            repo_replace_interfaces($db, $vmId, $interfaces, false);
            repo_replace_disks($db, $vmId, (array) ($vm['disks'] ?? []));

            // Map {name,version} -> resolvable package rows; unknown ones are
            // silently skipped by repo_replace_packages (reported as missing).
            $packages = [];
            foreach ((array) ($vm['packages'] ?? []) as $package) {
                $packages[] = [
                    'package_name' => (string) ($package['name'] ?? ''),
                    'package_version' => (string) ($package['version'] ?? ''),
                ];
            }
            repo_replace_packages($db, $vmId, $packages);
            repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'imported from file', $userId);
        }

        $report['imported'] = true;
        $report['mission_id'] = $missionId;

        return $report;
    });
}
