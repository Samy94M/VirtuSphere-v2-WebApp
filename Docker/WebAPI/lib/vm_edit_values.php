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
        'disk_name' => VIRTUSPHERE_VM_DEFAULTS['disk_name'],
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
        if ($name === '' && $size <= 0) {
            continue;
        }
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException(__t('vm_edit.err_invalid_disk_type'));
        }
        $disks[] = ['disk_name' => $name !== '' ? $name : vm_disk_default_name(count($disks) + 1), 'disk_size' => max(1, $size), 'disk_type' => $type];
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
