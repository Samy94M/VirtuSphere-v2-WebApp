<?php

declare(strict_types=1);

/**
 * VM editor side of the conflict comparison (QL-01/QL-03 B). It reads the
 * stored bundle after a POST that saved nothing and hands both sides to the
 * pure owner lib/edit_conflict.php. The field list is the save path's own
 * (vm_edit_submitted_values()); only the labels and display rules live here.
 * Loaded through the lib/vm_edit_form.php facade; the repository readers come
 * from the page's closure (portal/vm_edit.php).
 */

require_once __DIR__ . '/edit_conflict.php';
require_once __DIR__ . '/vm_edit_values.php';
require_once __DIR__ . '/vm_edit_status.php';
require_once __DIR__ . '/vm_ram.php';

/** The empty option of a location override: what the VM inherits from its mission. */
function vm_edit_location_inherit_label(array $mission, string $missionColumn): string
{
    $value = trim((string) ($mission[$missionColumn] ?? ''));

    // An empty mission datacenter is the derived case, so the plain label is the
    // truthful one: there is no concrete value to promise.
    return $value !== ''
        ? __t('vm_edit.location_inherit_value', ['value' => $value])
        : __t('vm_edit.location_inherit');
}

/**
 * Labels and display rules for every value vm_edit_submitted_values() returns,
 * except the hidden legacy disk text, which the disk rows already cover. RAM
 * and guest OS are shown by their picker labels, prepared for the two compared
 * values (the owner takes labels, not callbacks).
 *
 * @param array<string, mixed> $mission
 * @param array<string, mixed> $submitted
 * @param array<string, mixed> $stored
 * @return array<string, array{label:string, kind:'text'|'int'|'bool', options?:array<int|string, string>, empty?:string}>
 */
function vm_edit_conflict_fields(array $mission, array $submitted = [], array $stored = []): array
{
    $ramLabels = [];
    $guestLabels = [];
    foreach ([$submitted, $stored] as $side) {
        $ram = edit_conflict_normalize($side['vm_ram'] ?? '', 'int');
        if (preg_match('/^[0-9]+$/D', $ram) === 1) {
            $ramLabels[$ram] = vm_ram_format($ram);
        }
        $guestId = edit_conflict_normalize($side['vm_guest_id'] ?? '', 'text');
        if ($guestId !== '') {
            $guestLabels[$guestId] = vm_edit_conflict_guest_label($guestId);
        }
    }
    $delay = static fn (string $key): array => [
        'label' => __t('vm_edit.' . $key),
        'kind' => 'int',
        'options' => [(string) VIRTUSPHERE_AUTOSTART_DELAY_INHERIT => __t('vm_edit.autostart_inherit_placeholder', [
            'seconds' => (int) ($mission[$key] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT),
        ])],
    ];

    return [
        'vm_name' => ['label' => __t('vm_edit.label_vm_name'), 'kind' => 'text'],
        'vm_hostname' => ['label' => __t('vm_edit.label_hostname'), 'kind' => 'text'],
        'vm_domain' => ['label' => __t('vm_edit.label_domain'), 'kind' => 'text'],
        'vm_os' => ['label' => __t('vm_edit.label_os'), 'kind' => 'text'],
        'vm_ram' => ['label' => __t('vm_edit.label_ram'), 'kind' => 'int', 'options' => $ramLabels],
        'vm_cpu' => ['label' => __t('vm_edit.label_cpu'), 'kind' => 'int'],
        'vm_datastore' => ['label' => __t('vm_edit.label_datastore'), 'kind' => 'text', 'empty' => vm_edit_location_inherit_label($mission, 'hypervisor_datastorage')],
        'vm_datacenter' => ['label' => __t('vm_edit.label_datacenter'), 'kind' => 'text', 'empty' => vm_edit_location_inherit_label($mission, 'hypervisor_datacenter')],
        'vm_guest_id' => ['label' => __t('portal.vm_guest_os_label'), 'kind' => 'text', 'options' => $guestLabels],
        'vm_notes' => ['label' => __t('vm_edit.label_notes'), 'kind' => 'text'],
        'cpu_hotplug' => ['label' => __t('vm_edit.cpu_hotplug'), 'kind' => 'bool'],
        'ram_hotplug' => ['label' => __t('vm_edit.ram_hotplug'), 'kind' => 'bool'],
        'autostart_enabled' => ['label' => __t('vm_edit.autostart_enabled'), 'kind' => 'bool'],
        'autostart_start_delay' => $delay('autostart_start_delay'),
        'autostart_stop_delay' => $delay('autostart_stop_delay'),
    ];
}

/** The guest OS by the label its picker shows, not by its VMware id. */
function vm_edit_conflict_guest_label(string $guestId): string
{
    foreach (vm_guest_os_options_for_value($guestId) as $option) {
        if ((string) $option['guest_id'] === $guestId) {
            return vm_guest_os_option_label($option);
        }
    }

    return $guestId;
}

/**
 * Interface rows as the save path reads them (vm_parse_interfaces(): a row
 * without any data is dropped), without row ids and MACs, which the editor
 * does not change. A CIDR mask and its dotted form compare equal.
 *
 * @param array<int|string, mixed> $rows
 * @return list<array<string, string>>
 */
function vm_edit_conflict_interface_rows(array $rows): array
{
    $canonical = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $text = static fn (string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
        $hasData = false;
        foreach (['id', 'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type'] as $key) {
            $hasData = $hasData || $text($key) !== '';
        }
        if (!$hasData) {
            continue;
        }
        $canonical[] = [
            'ip' => $text('ip'),
            'subnet' => vm_subnet_input_value($text('subnet')),
            'gateway' => $text('gateway'),
            'dns1' => $text('dns1'),
            'dns2' => $text('dns2'),
            'vlan' => $text('vlan'),
            'mode' => strtolower($text('mode') !== '' ? $text('mode') : VIRTUSPHERE_VM_DEFAULTS['interface_mode']),
            'type' => strtolower($text('type') !== '' ? $text('type') : VIRTUSPHERE_VM_DEFAULTS['interface_type']),
        ];
    }

    return $canonical;
}

/**
 * Disk rows as vm_parse_disks() saves them: empty rows after the first
 * dropped, names through vm_disk_stored_name(), no rows at all as the default
 * disk.
 *
 * @param array<int|string, mixed> $rows
 * @return list<array{disk_name:string, disk_size:int, disk_type:string}>
 */
function vm_edit_conflict_disk_rows(array $rows): array
{
    $canonical = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = is_scalar($row['disk_name'] ?? null) ? trim((string) $row['disk_name']) : '';
        $size = is_scalar($row['disk_size'] ?? null) ? (int) $row['disk_size'] : 0;
        $type = is_scalar($row['disk_type'] ?? null) ? strtolower(trim((string) $row['disk_type'])) : '';
        if ($name === '' && $size <= 0 && $canonical !== []) {
            continue;
        }
        $canonical[] = [
            // DN2: a stored legacy 'System' and a submitted empty field are the same value.
            'disk_name' => vm_disk_stored_name($name, count($canonical) + 1),
            'disk_size' => max(1, $size),
            'disk_type' => $type !== '' ? $type : VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ];
    }

    return $canonical !== [] ? $canonical : vm_edit_conflict_disk_rows(vm_default_disks());
}

/**
 * Package assignments as a sorted id list.
 *
 * @param array<int|string, mixed> $ids
 * @return list<array{id:int}>
 */
function vm_edit_conflict_package_rows(array $ids): array
{
    $unique = [];
    foreach ($ids as $id) {
        $value = is_array($id) ? ($id['id'] ?? 0) : $id;
        $number = is_scalar($value) ? (int) $value : 0;
        if ($number > 0) {
            $unique[$number] = true;
        }
    }
    ksort($unique);

    return array_map(static fn (int $id): array => ['id' => $id], array_keys($unique));
}

/**
 * Display rows for one VM editor POST against the stored bundle.
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $stored repo_get_vm_bundle() row
 * @param array<string, mixed> $mission
 * @return list<array{label:string, submitted:string, current:string, rows:bool}>
 */
function vm_edit_conflict_rows(array $post, array $stored, array $mission): array
{
    $submitted = vm_edit_submitted_values($post, false);
    // The save path fills an empty hostname from the VM name; so does the comparison.
    $submitted['vm_hostname'] = repo_vm_hostname_input($submitted, trim($submitted['vm_name']));
    foreach (['autostart_start_delay', 'autostart_stop_delay'] as $key) {
        $submitted[$key] = (string) repo_vm_delay_value($submitted, $key);
        $stored[$key] = (string) repo_vm_delay_value($stored, $key);
    }
    $rows = edit_conflict_rows($submitted, $stored, vm_edit_conflict_fields($mission, $submitted, $stored));
    $children = [
        edit_conflict_child_row(
            __t('vm_edit.heading_interfaces'),
            vm_edit_conflict_interface_rows(is_array($post['interfaces'] ?? null) ? $post['interfaces'] : []),
            vm_edit_conflict_interface_rows((array) ($stored['interfaces'] ?? []))
        ),
        edit_conflict_child_row(
            __t('vm_edit.heading_disks'),
            vm_edit_conflict_disk_rows(is_array($post['disks'] ?? null) ? $post['disks'] : []),
            vm_edit_conflict_disk_rows((array) ($stored['disks'] ?? []))
        ),
        edit_conflict_child_row(
            __t('vm_edit.heading_packages'),
            vm_edit_conflict_package_rows(is_array($post['packages'] ?? null) ? $post['packages'] : []),
            vm_edit_conflict_package_rows((array) ($stored['packages'] ?? []))
        ),
    ];

    return array_merge($rows, array_values(array_filter($children, static fn (?array $row): bool => $row !== null)));
}

/**
 * The comparison after a VM editor POST that saved nothing, or null when the
 * form is current and the operator did not just continue. Reads the stored
 * bundle again, so it compares with the row the refusal actually saw.
 *
 * @param array<string, mixed> $mission
 * @param array<string, mixed> $post
 * @return array{mode:string, version:string, rows:list<array{label:string, submitted:string, current:string, rows:bool}>}|null
 */
function vm_edit_conflict(mysqli $db, int $vmId, array $mission, array $post, string $formVersion, bool $continued): ?array
{
    $stored = repo_get_vm_bundle($db, $vmId);
    if ($stored === null || (int) $stored['mission_id'] !== (int) ($mission['id'] ?? 0)) {
        return null;
    }
    $storedVersion = (string) $stored['edit_version'];
    $mode = edit_conflict_mode($formVersion, $storedVersion, $continued);
    if ($mode === '') {
        return null;
    }

    return ['mode' => $mode, 'version' => $storedVersion, 'rows' => vm_edit_conflict_rows($post, $stored, $mission)];
}
