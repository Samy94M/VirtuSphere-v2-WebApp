<?php

declare(strict_types=1);

require_once __DIR__ . '/defaults.php';

/**
 * The disks of one VM as the create playbook builds them, and the Windows
 * volume label each new data disk receives (DN1) and the Windows drive takes
 * when the system disk carries a name of its own (DN2). Three facts live here
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
 * DN2: what the empty system disk name field shows as placeholder. It is the
 * label the task sequence usually gives the Windows drive; the portal does not
 * know the actual one, so the value is shown and never saved (decisions F6).
 */
const VIRTUSPHERE_SYSTEM_DISK_LABEL_PLACEHOLDER = 'Windows';

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
        $rows[] = [
            'name' => vm_disk_stored_name((string) ($disk['disk_name'] ?? ''), $position + 1),
            'size_gb' => $size > 0 ? $size : (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
            'type' => $type !== '' ? $type : VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ];
    }

    if ($rows === []) {
        $rows[] = [
            'name' => vm_disk_stored_name('', 1),
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
 * What the VM editor says next to a disk name field (NZ1): the Windows label
 * only where it differs from the name as saved. `$position` is 1-based in the
 * order of the editor rows, which is the order the validator saves them.
 *
 *  - none: the label equals the name, or the system disk name is a default
 *    (empty, 'System', 'Disk <n>'): the task sequence label stays and the
 *    field says so with its placeholder (DN2, F7: the empty field is silent);
 *  - default: a data disk field is empty, the validator saves
 *    vm_disk_default_name() and that name becomes the label;
 *  - fallback: no usable character is left, the client keeps VM_Disk_<number>;
 *  - system_fallback: the same on the system disk, where the Windows drive
 *    keeps the task sequence label instead;
 *  - label: the label is cut or has characters replaced (both disk kinds).
 *
 * portal/assets/vm-disk-label.js mirrors this and vm_disk_volume_label() for
 * live typing; both run the vectors in tests/fixtures/disk-label-vectors.json.
 *
 * @return array{kind: 'none'|'default'|'fallback'|'system_fallback'|'label', label: ?string}
 */
function vm_disk_label_notice(string $name, int $position): array
{
    // The validator trims with trim() (ASCII white space), so does vm_disk_rows().
    $saved = trim($name);
    if ($position <= 1 && vm_disk_name_is_default($saved, 1)) {
        return ['kind' => 'none', 'label' => null];
    }
    if ($saved === '') {
        return ['kind' => 'default', 'label' => vm_disk_volume_label(vm_disk_default_name($position))];
    }
    $label = vm_disk_volume_label($saved);
    if ($label === null) {
        return ['kind' => $position <= 1 ? 'system_fallback' : 'fallback', 'label' => null];
    }

    return ['kind' => $label === $saved ? 'none' : 'label', 'label' => $label];
}

/**
 * The `disks` field of getDeviceInfos (DN1, additive): per row its list
 * position, SCSI unit, size and volume label, in the order the playbook
 * creates the disks.
 *
 * DN2 (ADR-0019 amendment 7, decision F1) adds `label_custom` per entry: true
 * when the row carries a name of the operator's own (vm_disk_name_is_default()
 * is false). The client renames the Windows drive only on position 0 with
 * label_custom true; a portal without the key (older release) never causes a
 * rename, even though it sends 'System' as volume_label there. volume_label
 * stays a key on every entry, null when nothing is to be set: the client
 * refuses a list with an entry that lacks it.
 *
 * @return list<array{position: int, scsi_unit: ?int, size_gb: int, volume_label: ?string, label_custom: bool}>
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
            'label_custom' => !vm_disk_name_is_default($row['name'], $position + 1),
        ];
    }

    return $layout;
}
