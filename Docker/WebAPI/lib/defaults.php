<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

const VIRTUSPHERE_VM_DEFAULTS = [
    'ram_mb' => 4096,
    'cpu_count' => 2,
    'guest_id' => 'windows2019srv_64Guest',
    'disk_name' => 'System',
    'disk_size_gb' => 50,
    'disk_type' => 'thick',
    'interface_mode' => 'dhcp',
    'interface_type' => 'vmxnet3',
    'waiting_time' => 60,
    // CPU/RAM hot-add, enabled by default; only applied when a VM is created
    // (Paket F). Existing ESXi VMs are never reconfigured.
    'cpu_hotplug' => true,
    'ram_hotplug' => true,
];

const VIRTUSPHERE_GUEST_OS_OPTIONS = [
    [
        'guest_id' => 'windows2019srv_64Guest',
        'label_key' => 'portal.vm_guest_os_windows_server_2019',
    ],
    [
        'guest_id' => 'windows11_64Guest',
        'label_key' => 'portal.vm_guest_os_windows_11',
    ],
    [
        'guest_id' => 'windows2019srvNext_64Guest',
        'label_key' => 'portal.vm_guest_os_windows_server_2022',
    ],
    [
        'guest_id' => 'windows2022srvNext_64Guest',
        'label_key' => 'portal.vm_guest_os_windows_server_2025',
    ],
];

const VIRTUSPHERE_RAM_PRESETS_MB = [1024, 2048, 4096, 8192, 16384, 32768];
const VIRTUSPHERE_VM_LIMITS = [
    'ram_mb_min' => 128,
    'ram_mb_max' => 1048576,
    'cpu_count_min' => 1,
    'cpu_count_max' => 256,
    'disk_size_gb_min' => 1,
    'disk_size_gb_max' => 65536,
];

const VIRTUSPHERE_DISK_TYPES = ['thin', 'thick', 'eagerzeroedthick'];
// DHCP mode disables the manual IP fields in the VM editor (see forms.js); the
// value is surfaced to JS via the data-mode-select attribute so the front-end
// does not hardcode its own copy of the string.
const VIRTUSPHERE_INTERFACE_MODE_DHCP = 'dhcp';
const VIRTUSPHERE_INTERFACE_MODES = [VIRTUSPHERE_INTERFACE_MODE_DHCP, 'static'];
// The vNIC types the portal offers and the repo validator accepts. A subset of
// what community.vmware.vmware_guest supports on purpose: these three cover every
// supported guest, and the module hard-fails on an unknown device_type, so the
// list is the SSoT for both the vm_edit select and repo_validate_interfaces().
const VIRTUSPHERE_INTERFACE_TYPES = ['vmxnet3', 'e1000', 'e1000e'];

const VIRTUSPHERE_PLAYBOOKS = [
    'create' => 'createVMs-ESXi_playbook.yml',
    'powercycle' => 'powercycleVMs-ESXi_playbook.yml',
    'export' => 'exportVMs-Informations-ESXi_playbook.yml',
    'start' => 'startVMs-ESXi_playbook.yml',
    'autostart' => 'autostartVMs-ESXi_playbook.yml',
];

// Mission-level autostart defaults (ADR-0025). These mirror the ESXi host's
// "Default VM Settings"; a VM inherits them unless it stores an own value.
const VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS = [
    'autostart_enabled' => false,
    'autostart_start_delay' => 120,
    'autostart_stop_delay' => 120,
    'autostart_stop_action' => 'guestShutdown',
    'autostart_wait_for_heartbeat' => false,
];

// Per-VM autostart overrides. -1 delays mean "inherit the mission default",
// which is exactly what vmware_host_auto_start's power_info reads as "use
// system defaults". A VM does not participate until autostart_enabled is on.
const VIRTUSPHERE_VM_AUTOSTART_DEFAULTS = [
    'autostart_enabled' => false,
    'autostart_start_delay' => -1,
    'autostart_stop_delay' => -1,
];

// Default seconds a power-cycle keeps a VM on so ESXi assigns NIC MACs.
const VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT = 5;
const VIRTUSPHERE_POWERCYCLE_WAIT_MIN = 1;
const VIRTUSPHERE_POWERCYCLE_WAIT_MAX = 300;

function virtusphere_guest_os_ids(): array
{
    return array_map(static fn (array $option): string => (string) $option['guest_id'], VIRTUSPHERE_GUEST_OS_OPTIONS);
}

/**
 * Missions and templates share deploy_missions; the template prefix is the only
 * marker separating them (ADR: template naming). This is the single predicate
 * for that rule, so the ~two dozen call sites cannot drift or disagree on
 * whitespace: the name is trimmed first, matching how it is stored
 * (requireString trims on save).
 */
function mission_name_is_template(?string $missionName): bool
{
    return str_starts_with(trim((string) $missionName), VIRTUSPHERE_TEMPLATE_PREFIX);
}
