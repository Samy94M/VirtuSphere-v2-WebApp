<?php

declare(strict_types=1);

require_once __DIR__ . '/defaults.php';

/**
 * The disks of one VM as the create playbook builds them, and the Windows
 * volume label each new data disk receives (DN1). Three facts live here
 * because they must agree with each other:
 *
 *  - the disk rows: deploy_disks in id order, one default disk for a VM without
 *    rows (the serverlist, the storage estimate and the client read the same);
 *  - the SCSI unit vmware_guest gives the disk at a list position;
 *  - the label rule, applied once here; the client only checks the result.
 *
 * The client matches a new Windows disk to a row by its SCSI target id and
 * confirms the size; see client_VMDisksOnline.ps1 and docs/ai/contracts/machine.md.
 */

/** NTFS keeps at most 32 characters (UTF-16 code units) as a volume label. */
const VIRTUSPHERE_DISK_VOLUME_LABEL_MAX = 32;

/**
 * community.vmware 6.2.0 vmware_guest (configure_disks) puts every disk of the
 * list on the first SCSI controller. Without controller_number/unit_number a
 * NEW disk at list position p gets unit p, and unit 7, the controller's own,
 * is skipped. A SAS or paravirtual controller has units 0 to 15.
 */
const VIRTUSPHERE_DISK_SCSI_RESERVED_UNIT = 7;
const VIRTUSPHERE_DISK_SCSI_MAX_UNIT = 15;

/**
 * @return list<array{name: string, size_gb: int, type: string}>
 */
function vm_disk_rows(array $vm): array
{
    $rows = [];
    foreach (array_values((array) ($vm['disks'] ?? [])) as $position => $disk) {
        $size = (int) trim((string) ($disk['disk_size'] ?? ''));
        $type = strtolower(trim((string) ($disk['disk_type'] ?? '')));
        $name = trim((string) ($disk['disk_name'] ?? ''));
        $rows[] = [
            'name' => $name !== '' ? $name : vm_disk_default_name($position + 1),
            'size_gb' => $size > 0 ? $size : (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
            'type' => $type !== '' ? $type : VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ];
    }

    if ($rows === []) {
        $rows[] = [
            'name' => vm_disk_default_name(1),
            'size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
            'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ];
    }

    return $rows;
}

/** The SCSI unit of the disk at a 0-based list position; null past the controller. */
function vm_disk_scsi_unit(int $position): ?int
{
    if ($position < 0) {
        return null;
    }
    $unit = $position < VIRTUSPHERE_DISK_SCSI_RESERVED_UNIT ? $position : $position + 1;

    return $unit <= VIRTUSPHERE_DISK_SCSI_MAX_UNIT ? $unit : null;
}

/**
 * The volume label for a disk name, or null when nothing usable is left (the
 * client then keeps its own label VM_Disk_<n>).
 *
 * Control and format characters (tab, zero width, bidi overrides) and the
 * characters Windows forbids in file names become a space, runs of white space
 * collapse to one. NTFS itself accepts \ / : * ? " < > |, but Explorer, label.exe
 * and scripts that build paths from a label do not all do so, so they never get
 * there. The rest is cut to 32 UTF-16 code units, so a character outside the
 * Basic Multilingual Plane counts two, the way Windows counts it.
 */
function vm_disk_volume_label(string $name): ?string
{
    $clean = preg_replace('/[\p{Cc}\p{Cf}\\\\\/:*?"<>|]+/u', ' ', $name);
    if (!is_string($clean)) {
        return null; // not valid UTF-8
    }
    $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

    $label = '';
    $units = 0;
    foreach (mb_str_split($clean, 1, 'UTF-8') as $char) {
        $width = mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
        if ($units + $width > VIRTUSPHERE_DISK_VOLUME_LABEL_MAX) {
            break;
        }
        $label .= $char;
        $units += $width;
    }
    $label = rtrim($label);

    return $label !== '' ? $label : null;
}

/**
 * The `disks` field of getDeviceInfos (DN1, additive): per row its list
 * position, SCSI unit, size and volume label, in the order the playbook
 * creates the disks.
 *
 * @return list<array{position: int, scsi_unit: ?int, size_gb: int, volume_label: ?string}>
 */
function vm_disk_client_layout(array $vm): array
{
    $layout = [];
    foreach (vm_disk_rows($vm) as $position => $row) {
        $layout[] = [
            'position' => $position,
            'scsi_unit' => vm_disk_scsi_unit($position),
            'size_gb' => $row['size_gb'],
            'volume_label' => vm_disk_volume_label($row['name']),
        ];
    }

    return $layout;
}
