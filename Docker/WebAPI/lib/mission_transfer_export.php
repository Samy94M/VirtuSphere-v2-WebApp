<?php

declare(strict_types=1);

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
