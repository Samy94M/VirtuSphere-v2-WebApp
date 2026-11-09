<?php

declare(strict_types=1);

/** VM-editor defaults, submitted repeat-row parsing and subnet conversion. */

function vm_default_interfaces(array $mission): array
{
    return [[
        'id' => 0,
        'ip' => '',
        'subnet' => '',
        'gateway' => '',
        'dns1' => '',
        'dns2' => '',
        'vlan' => (string) ($mission['wds_vlan'] ?? ''),
        'mac' => '',
        'mode' => VIRTUSPHERE_VM_DEFAULTS['interface_mode'],
        'type' => VIRTUSPHERE_VM_DEFAULTS['interface_type'],
    ]];
}

function vm_default_disks(): array
{
    return [[
        'disk_name' => vm_disk_default_name(1),
        'disk_size' => VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
        'disk_type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
    ]];
}

/**
 * The interface rows of a submitted VM form.
 *
 * Removing the LAST row is rejected instead of falling back to a default NIC.
 * repo_save_vm rewrites deploy_interfaces from what this returns, so the empty
 * default replaced the real row: a MAC that Ansible had exported and MECM was
 * waiting for was silently gone, together with the VLAN, the IP and the mode,
 * and the save reported success. There is no honest default for "the operator
 * removed every interface", so the form says so and keeps the stored rows.
 *
 * vm_default_interfaces() stays the RENDER default (a new VM, the sticky
 * re-render): offering a prefilled row is not the same as writing one.
 */
function vm_parse_interfaces(array $rows, array $mission): array
{
    $interfaces = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hasData = false;
        foreach (['id', 'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type'] as $key) {
            $hasData = $hasData || trim((string) ($row[$key] ?? '')) !== '';
        }
        if (!$hasData) {
            continue;
        }
        $interfaces[] = [
            'id' => (int) ($row['id'] ?? 0),
            'ip' => trim((string) ($row['ip'] ?? '')),
            'subnet' => trim((string) ($row['subnet'] ?? '')),
            'gateway' => trim((string) ($row['gateway'] ?? '')),
            'dns1' => trim((string) ($row['dns1'] ?? '')),
            'dns2' => trim((string) ($row['dns2'] ?? '')),
            'vlan' => trim((string) ($row['vlan'] ?? '')),
            'mode' => trim((string) ($row['mode'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_mode'])),
            'type' => trim((string) ($row['type'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_type'])),
        ];
    }

    if ($interfaces === []) {
        throw new ValidationException(
            ['interfaces' => __t('vm_edit.err_interfaces_required')],
            __t('vm_edit.err_interfaces_required')
        );
    }

    return $interfaces;
}

function vm_parse_disks(array $rows): array
{
    $allowed = VIRTUSPHERE_DISK_TYPES;
    $disks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['disk_name'] ?? ''));
        $size = (int) ($row['disk_size'] ?? 0);
        $type = strtolower(trim((string) ($row['disk_type'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_type'])));
        // An empty row is dropped, but never the first one: its name is empty
        // by design since DN2, and dropping it would move a data disk (and its
        // name) up to the system disk.
        if ($name === '' && $size <= 0 && $disks !== []) {
            continue;
        }
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException(__t('vm_edit.err_invalid_disk_type'));
        }
        $disks[] = ['disk_name' => vm_disk_stored_name($name, count($disks) + 1), 'disk_size' => max(1, $size), 'disk_type' => $type];
    }

    return $disks !== [] ? $disks : vm_default_disks();
}

function vm_parse_packages(array $packageIds): array
{
    $packages = [];
    foreach ($packageIds as $packageId) {
        $id = (int) $packageId;
        if ($id > 0) {
            $packages[] = ['id' => $id];
        }
    }

    return $packages;
}

/**
 * The CPU count of the VM editor, trimmed. An emptied field is a missing value
 * and says so: the repository default (2 CPUs) would otherwise store the VM
 * without a word, while the RAM field answers the same gesture with "required"
 * (vm_edit_ram_from_post). The default stays for callers that send no key.
 *
 * @param array<string, mixed> $post
 * @throws ValidationException when the field is empty
 */
function vm_edit_cpu_from_post(array $post): string
{
    $raw = request_trimmed($post, 'vm_cpu');
    if ($raw === '') {
        $message = __t('validate.required', ['field' => __t('vm_edit.label_cpu')]);
        throw new ValidationException(['vm_cpu' => $message], $message);
    }

    return $raw;
}

function vm_cidr_to_netmask(int $prefix): string
{
    $bits = str_repeat('1', $prefix) . str_repeat('0', 32 - $prefix);
    $octets = str_split($bits, 8);

    return implode('.', array_map(static fn (string $octet): string => (string) bindec($octet), $octets));
}

function vm_subnet_input_value(string $subnet): string
{
    if (preg_match('/^\/(?:[0-9]|[12][0-9]|30)$/', $subnet) === 1) {
        return vm_cidr_to_netmask((int) substr($subnet, 1));
    }

    return $subnet;
}

function vm_subnet_picker_value(string $subnet): string
{
    if (preg_match('/^\/(?:[0-9]|[12][0-9]|30)$/', $subnet) === 1) {
        return $subnet;
    }

    for ($mask = 0; $mask <= 30; $mask++) {
        if ($subnet === vm_cidr_to_netmask($mask)) {
            return '/' . $mask;
        }
    }

    return '';
}

/**
 * The scalar VM values one editor POST saves. The save path and the conflict
 * comparison (lib/vm_edit_conflict.php) read the same list. Strict mode is the
 * save path and throws on an unreadable RAM or an empty CPU field; the
 * comparison passes false and keeps such a value as typed.
 *
 * vm_creator is deliberately absent: repo_save_vm() stamps it from the acting
 * user on create and preserves it on update. A posted value is ignored, and the
 * rendered field carries no name attribute.
 *
 * @param array<string, mixed> $post
 * @return array<string, string>
 */
function vm_edit_submitted_values(array $post, bool $strict = true): array
{
    if ($strict) {
        $ram = (string) vm_edit_ram_from_post($post);
    } else {
        $parsed = vm_ram_parse_input($post['vm_ram'] ?? '', array_key_exists('vm_ram_unit', $post) ? $post['vm_ram_unit'] : 'mb');
        $ram = $parsed['ok'] ? (string) $parsed['mb'] : request_trimmed($post, 'vm_ram');
    }

    return [
        'vm_name' => request_string($post, 'vm_name'),
        'vm_hostname' => request_string($post, 'vm_hostname'),
        'vm_domain' => request_string($post, 'vm_domain'),
        'vm_os' => request_string($post, 'vm_os'),
        'vm_ram' => $ram,
        'vm_cpu' => $strict ? vm_edit_cpu_from_post($post) : request_trimmed($post, 'vm_cpu'),
        'vm_disk' => request_string($post, 'vm_disk'),
        'vm_datastore' => request_string($post, 'vm_datastore'),
        'vm_datacenter' => request_string($post, 'vm_datacenter'),
        'vm_guest_id' => request_string($post, 'vm_guest_id'),
        'vm_notes' => request_string($post, 'vm_notes'),
        'cpu_hotplug' => request_string($post, 'cpu_hotplug', '0'),
        'ram_hotplug' => request_string($post, 'ram_hotplug', '0'),
        // Autostart override (ADR-0025). An empty delay field is the editor's
        // "inherit" state and repo_vm_delay_value() turns it into -1; it must
        // never reach the INT NOT NULL column as ''.
        'autostart_enabled' => request_string($post, 'autostart_enabled', '0'),
        'autostart_start_delay' => request_string($post, 'autostart_start_delay'),
        'autostart_stop_delay' => request_string($post, 'autostart_stop_delay'),
    ];
}

/**
 * The editor state re-rendered from a POST that saved nothing: a refused save
 * or the continue step after a conflict. The posted rows replace the stored ones.
 *
 * @param array<string, mixed> $vm
 * @param array<string, mixed> $post
 * @param array<string, mixed> $mission
 * @return array<string, mixed>
 */
function vm_edit_sticky_vm(array $vm, array $post, array $mission): array
{
    $vm = array_merge($vm, $post);
    $vm['interfaces'] = is_array($post['interfaces'] ?? null) ? $post['interfaces'] : vm_default_interfaces($mission);
    $vm['disks'] = is_array($post['disks'] ?? null) ? $post['disks'] : vm_default_disks();
    $vm['packages'] = vm_parse_packages(is_array($post['packages'] ?? null) ? $post['packages'] : []);

    return $vm;
}
