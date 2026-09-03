<?php

declare(strict_types=1);

/** VM value and child-row validation. Loaded through repo/vms.php only. */

/**
 * Which value a VM payload actually offers as its Windows hostname, before any
 * validation. The empty field falls back to the ESXi/portal VM name, which is
 * the behaviour every create path has had since E2.
 *
 * It is a named function rather than two lines inline because a second reader
 * needs the SAME answer: the mission import preview has to know which hostnames
 * an uploaded file would claim, and a preview deriving the effective value one
 * way while the write derives it another is exactly how a dry run comes back
 * clean for an import that then fails (Etappe 14D).
 *
 * @param array<string, mixed> $vmData
 */
function repo_vm_hostname_input(array $vmData, string $vmName): string
{
    $hostname = trim((string) ($vmData['vm_hostname'] ?? ''));

    return $hostname !== '' ? $hostname : $vmName;
}

/**
 * Normalizes a VM boolean flag (cpu_hotplug/ram_hotplug). An absent key uses the
 * VIRTUSPHERE_VM_DEFAULTS value (true), so legacy-API creates and field-tolerant
 * imports get the default; a present value is read as a checkbox/bool.
 *
 * @param 'cpu_hotplug'|'ram_hotplug' $key
 */
function repo_vm_flag_value(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (int) VIRTUSPHERE_VM_DEFAULTS[$key];
    }

    return in_array(strtolower((string) $vmData[$key]), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * Same shape as repo_vm_flag_value(), but autostart defaults to OFF: a VM has to
 * be opted in, never opted out, so an import or a legacy-API create can never
 * silently enrol a VM into the host's boot sequence.
 *
 * @param 'autostart_enabled' $key
 */
function repo_vm_autostart_flag(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (int) VIRTUSPHERE_VM_AUTOSTART_DEFAULTS[$key];
    }

    return in_array(strtolower((string) $vmData[$key]), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/**
 * Normalizes a per-VM autostart delay into the column's integer domain.
 *
 * An absent key (legacy API, a mission export written before this feature) and
 * an empty string (the editor's "inherit" state) both mean inherit and become
 * VIRTUSPHERE_AUTOSTART_DELAY_INHERIT. Never the empty string: the column is
 * INT NOT NULL and MySQL would reject it, or in a lax mode store 0.
 *
 * Storing 0 there would be worse than an error. 0 is a legal, meaningful value
 * ("start without waiting") and -1 means "use the mission's value"; collapsing
 * one into the other silently changes when a VM boots after a host restart.
 */
function repo_vm_delay_value(array $vmData, string $key): int
{
    if (!array_key_exists($key, $vmData)) {
        return (int) (VIRTUSPHERE_VM_AUTOSTART_DEFAULTS[$key] ?? VIRTUSPHERE_AUTOSTART_DELAY_INHERIT);
    }

    $raw = $vmData[$key];
    // Untrusted imports can carry a non-scalar here; casting it would raise an
    // "array to string" warning that the global handler turns into a 500.
    if ($raw === null || !is_scalar($raw) || trim((string) $raw) === '') {
        return VIRTUSPHERE_AUTOSTART_DELAY_INHERIT;
    }

    $value = (int) $raw;
    if ($value < VIRTUSPHERE_AUTOSTART_DELAY_MIN) {
        return VIRTUSPHERE_AUTOSTART_DELAY_INHERIT;
    }

    return min(VIRTUSPHERE_AUTOSTART_DELAY_MAX, $value);
}

function repo_validate_interfaces(mixed $interfaces): array
{
    if (!is_iterable($interfaces)) {
        return [];
    }

    $validated = [];
    $index = 0;
    foreach ($interfaces as $interface) {
        if (!is_array($interface) && !is_object($interface)) {
            throw new ValidationException(['interfaces.' . $index => validator_text('validate.interface_entry_invalid', 'Interface entry is invalid.')]);
        }

        $validator = new Validator();
        $mode = $validator->enum('interfaces.' . $index . '.mode', repo_object_get($interface, 'mode', VIRTUSPHERE_VM_DEFAULTS['interface_mode']), validator_label('interface_mode', 'Interface mode'), VIRTUSPHERE_INTERFACE_MODES, VIRTUSPHERE_VM_DEFAULTS['interface_mode']);
        $static = $mode === 'static';
        $row = [
            'id' => repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id'))),
            'ip' => $validator->ipv4('interfaces.' . $index . '.ip', repo_object_get($interface, 'ip', ''), validator_label('interface_ip', 'Interface IP'), $static),
            'subnet' => $validator->ipv4OrCidrMask('interfaces.' . $index . '.subnet', repo_object_get($interface, 'subnet', ''), validator_label('interface_subnet', 'Interface subnet'), $static),
            // Unlike the IP and the mask, the gateway stays OPTIONAL in static
            // mode. client_staticip.ps1 sets exactly one default route per VM
            // (the first static adapter carrying a gateway wins, every further
            // one is discarded there with a WARN), and a segment without a
            // router has no gateway to name at all. Requiring it therefore
            // forced the operator to invent a value the client is guaranteed to
            // throw away, or to put a router address on a routerless segment,
            // where Windows would then point its default route at something
            // that never answers. The format is still validated; only empty is
            // allowed through.
            'gateway' => $validator->ipv4('interfaces.' . $index . '.gateway', repo_object_get($interface, 'gateway', ''), validator_label('interface_gateway', 'Interface gateway')),
            'dns1' => $validator->ipv4('interfaces.' . $index . '.dns1', repo_object_get($interface, 'dns1', ''), validator_label('interface_dns1', 'Interface DNS 1')),
            'dns2' => $validator->ipv4('interfaces.' . $index . '.dns2', repo_object_get($interface, 'dns2', ''), validator_label('interface_dns2', 'Interface DNS 2')),
            'vlan' => $validator->optionalString('interfaces.' . $index . '.vlan', repo_object_get($interface, 'vlan', ''), validator_label('interface_vlan', 'Interface VLAN'), 255),
            'mode' => $mode,
            // vNIC type is an enum, like disk_type and the interface mode: a value
            // outside VIRTUSPHERE_INTERFACE_TYPES fails the create playbook at ESXi,
            // so it is rejected here (import/legacy-API included) instead of silently
            // reaching the hypervisor. enum() applies the default for an empty value
            // and lower-cases, so a stored type is always a canonical known value.
            'type' => $validator->enum('interfaces.' . $index . '.type', repo_object_get($interface, 'type', VIRTUSPHERE_VM_DEFAULTS['interface_type']), validator_label('interface_type', 'Interface type'), VIRTUSPHERE_INTERFACE_TYPES, VIRTUSPHERE_VM_DEFAULTS['interface_type']),
            'mac' => $validator->mac('interfaces.' . $index . '.mac', repo_object_get($interface, 'mac', ''), validator_label('interface_mac', 'Interface MAC')),
        ];
        $validator->throwIfInvalid();
        $validated[] = $row;
        $index++;
    }

    return $validated;
}

function repo_validate_disks(mixed $disks): array
{
    if (!is_iterable($disks)) {
        return [];
    }

    $validated = [];
    $index = 0;
    foreach ($disks as $disk) {
        if (!is_array($disk) && !is_object($disk)) {
            throw new ValidationException(['disks.' . $index => validator_text('validate.disk_entry_invalid', 'Disk entry is invalid.')]);
        }

        $validator = new Validator();
        $row = [
            'disk_name' => $validator->optionalString('disks.' . $index . '.disk_name', repo_object_get($disk, 'disk_name', vm_disk_default_name($index + 1)), validator_label('disk_name', 'Disk name'), 255),
            'disk_size' => $validator->intRange(
                'disks.' . $index . '.disk_size',
                repo_object_get($disk, 'disk_size', VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']),
                validator_label('disk_size', 'Disk size'),
                (int) VIRTUSPHERE_VM_LIMITS['disk_size_gb_min'],
                (int) VIRTUSPHERE_VM_LIMITS['disk_size_gb_max'],
                (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']
            ),
            'disk_type' => $validator->enum('disks.' . $index . '.disk_type', repo_object_get($disk, 'disk_type', VIRTUSPHERE_VM_DEFAULTS['disk_type']), validator_label('disk_type', 'Disk type'), VIRTUSPHERE_DISK_TYPES, VIRTUSPHERE_VM_DEFAULTS['disk_type']),
        ];
        if ($row['disk_name'] === '') {
            $row['disk_name'] = vm_disk_default_name($index + 1);
        }
        $validator->throwIfInvalid();
        $validated[] = $row;
        $index++;
    }

    return $validated;
}

function repo_validate_vm_payload(mysqli $db, int $missionId, array $vmData, int $excludeVmId = 0): array
{
    $validator = new Validator();
    $name = $validator->requireString('vm_name', $vmData['vm_name'] ?? '', validator_label('vm_name', 'VM name'), 16);
    if ($name !== '' && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,14}[A-Za-z0-9])?$/', $name) !== 1) {
        $validator->add('vm_name', validator_text('validate.vm_name_charset', 'VM name may use only letters, numbers and internal hyphens.'));
    }

    $values = [];
    $values['vm_name'] = $name;

    // NetBIOS-safe hostname rule (E2): the MECM hostname phase truncates to
    // 15 chars and strips everything outside [A-Za-z0-9-] on the client, so
    // looser names silently diverge. Grandfathering: an UNCHANGED legacy
    // hostname keeps the old lax rule so unrelated edits are not blocked;
    // any change (and every new VM) must pass the strict rule.
    $hostnameInput = repo_vm_hostname_input($vmData, $name);
    $storedHostname = $excludeVmId > 0
        ? (string) (repo_scalar($db, 'SELECT vm_hostname FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$excludeVmId, $missionId]) ?? '')
        : '';
    $hostnameLabel = validator_label('hostname', 'Hostname');
    if ($excludeVmId > 0 && $storedHostname !== '' && $hostnameInput === $storedHostname) {
        $values['vm_hostname'] = $validator->hostname('vm_hostname', $hostnameInput, $hostnameLabel, 64);
    } else {
        $values['vm_hostname'] = $validator->netbiosHostname('vm_hostname', $hostnameInput, $hostnameLabel);
    }
    if ($values['vm_hostname'] === '') {
        $values['vm_hostname'] = $name;
    }
    $values['vm_domain'] = $validator->fqdn('vm_domain', $vmData['vm_domain'] ?? '', validator_label('domain', 'Domain'));
    $values['vm_os'] = $validator->requireString('vm_os', $vmData['vm_os'] ?? '', validator_label('operating_system', 'Operating system'), 255);
    $values['vm_ram'] = (string) $validator->intRange(
        'vm_ram',
        $vmData['vm_ram'] ?? VIRTUSPHERE_VM_DEFAULTS['ram_mb'],
        validator_label('ram_mb', 'RAM MB'),
        (int) VIRTUSPHERE_VM_LIMITS['ram_mb_min'],
        (int) VIRTUSPHERE_VM_LIMITS['ram_mb_max'],
        (int) VIRTUSPHERE_VM_DEFAULTS['ram_mb']
    );
    $values['vm_cpu'] = (string) $validator->intRange(
        'vm_cpu',
        $vmData['vm_cpu'] ?? VIRTUSPHERE_VM_DEFAULTS['cpu_count'],
        validator_label('cpu_count', 'CPU count'),
        (int) VIRTUSPHERE_VM_LIMITS['cpu_count_min'],
        (int) VIRTUSPHERE_VM_LIMITS['cpu_count_max'],
        (int) VIRTUSPHERE_VM_DEFAULTS['cpu_count']
    );
    $values['vm_disk'] = $validator->optionalString('vm_disk', $vmData['vm_disk'] ?? '', validator_label('legacy_disk_summary', 'Legacy disk summary'), 64);
    $values['vm_datastore'] = $validator->optionalString('vm_datastore', $vmData['vm_datastore'] ?? '', validator_label('datastore', 'Datastore'), 255);
    $values['vm_datacenter'] = $validator->optionalString('vm_datacenter', $vmData['vm_datacenter'] ?? '', validator_label('datacenter', 'Datacenter'), 255);
    $values['vm_guest_id'] = $validator->optionalString('vm_guest_id', $vmData['vm_guest_id'] ?? VIRTUSPHERE_VM_DEFAULTS['guest_id'], validator_label('guest_id', 'Guest ID'), 255);
    if ($values['vm_guest_id'] === '') {
        $values['vm_guest_id'] = VIRTUSPHERE_VM_DEFAULTS['guest_id'];
    }
    if (!in_array($values['vm_guest_id'], virtusphere_guest_os_ids(), true)) {
        $existingGuestId = $excludeVmId > 0
            ? (string) (repo_scalar($db, 'SELECT vm_guest_id FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$excludeVmId, $missionId]) ?? '')
            : '';
        if ($existingGuestId === '' || $values['vm_guest_id'] !== $existingGuestId) {
            $validator->add('vm_guest_id', validator_text('validate.enum', ':field has an invalid value.', ['field' => validator_label('guest_id', 'Guest ID')]));
        }
    }
    $values['vm_creator'] = $validator->optionalString('vm_creator', $vmData['vm_creator'] ?? '', validator_label('creator', 'Creator'), 255);
    $values['vm_notes'] = $validator->optionalString('vm_notes', $vmData['vm_notes'] ?? '', validator_label('notes', 'Notes'), 65535);
    // Hot-add flags (Paket F): only applied at ESXi creation time, default on.
    $values['cpu_hotplug'] = repo_vm_flag_value($vmData, 'cpu_hotplug');
    $values['ram_hotplug'] = repo_vm_flag_value($vmData, 'ram_hotplug');
    // Autostart override (ADR-0025). Normalized rather than validated: an out of
    // range delay from the legacy API or an import is clamped, not rejected, in
    // the same spirit as the hot-add flags. The editor validates its own input.
    $values['autostart_enabled'] = repo_vm_autostart_flag($vmData, 'autostart_enabled');
    $values['autostart_start_delay'] = repo_vm_delay_value($vmData, 'autostart_start_delay');
    $values['autostart_stop_delay'] = repo_vm_delay_value($vmData, 'autostart_stop_delay');

    $validator->throwIfInvalid();
    if (repo_vm_name_exists($db, $missionId, $name, $excludeVmId)) {
        $message = validator_text('validate.vm_name_taken_in_mission', 'VM name already exists in this mission.');
        throw new ValidationException(['vm_name' => $message], $message);
    }

    // Global uniqueness across non-template missions (E2): only enforced when
    // the target mission itself is not a template - template VMs deliberately
    // mirror names of the missions they were captured from.
    $missionName = (string) (repo_scalar($db, 'SELECT mission_name FROM deploy_missions WHERE id = ? LIMIT 1', 'i', [$missionId]) ?? '');
    if (!mission_name_is_template($missionName)) {
        $conflict = repo_vm_name_conflict_global($db, $name, $excludeVmId);
        if ($conflict !== null && (int) $conflict['mission_id'] !== $missionId) {
            $message = validator_text('validate.vm_name_taken_global', 'VM name is already used in mission ":mission". The VM name in ESXi has to be unique across the portal.', ['mission' => (string) $conflict['mission_name']]);
            throw new ValidationException(['vm_name' => $message], $message);
        }

        // The Windows rollout name (Etappe 14D). This is a REPORT, not the
        // enforcement: `deploy_vm_hostname_claims` decides under the write's own
        // lock, and this unlocked read exists so a dry run (the mission import
        // preview, ADR-0006's "a preview reports on what the write persists")
        // names the holder instead of letting the write fail behind a preview
        // that promised nothing was wrong.
        //
        // Only a value that could actually start a rollout is checked: a
        // grandfathered illegal hostname holds no claim, so it can neither
        // collide nor be blocked by one.
        if (mecm_hostname_is_rollout_valid($values['vm_hostname'])) {
            $owner = repo_vm_hostname_claim_owner($db, mecm_hostname_key($values['vm_hostname']), $excludeVmId);
            if ($owner !== null) {
                throw repo_vm_hostname_claim_conflict($owner);
            }
        }
    }

    return $values;
}
