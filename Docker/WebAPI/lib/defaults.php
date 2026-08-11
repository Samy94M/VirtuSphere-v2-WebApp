<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

const VIRTUSPHERE_VM_DEFAULTS = [
    'ram_mb' => 4096,
    'cpu_count' => 2,
    'guest_id' => 'windows2019srv_64Guest',
    'disk_name' => 'System',
    'disk_size_gb' => 50,
    // Eager zeroed: blocks are pre-zeroed at creation, so the first write to a
    // block carries no zeroing penalty. Creation itself takes correspondingly
    // longer unless the array offloads it via VAAI Block Zero.
    'disk_type' => 'eagerzeroedthick',
    'interface_mode' => 'dhcp',
    'interface_type' => 'vmxnet3',
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

// Seconds the start step waits before it powers the VMs on, so the MECM side is
// ready for the PXE boot that follows. How long that takes depends on the MECM
// cadence of the environment, which is why this is a form field and not only a
// default.
//
// It is emitted in SECONDS and the playbook pauses in seconds. Its predecessor
// was a bare 60 written into accounts.yml as `WaitingTime` and read by
// `pause: minutes:`, i.e. 3600 seconds of silence, while the SSH layer aborts a
// command after VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS of it: mode `start` could
// never power a VM on, and `full` died in its last step with every VM off. The
// desktop client shipped 5 here, so the unit was always minutes, and 300 s is
// that field-proven value.
//
// The durable rule is the invariant, not the number: no configured playbook
// pause may reach the idle budget of the layer above it. MAX therefore keeps a
// margin below VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS (deploy_constants.php,
// which requires this file, so the bound cannot be written as an expression of
// it); tests/Static/AnsiblePauseBudgetContractTest.php walks every pause
// directive in Ansible/*.yml against the emitting constant and that budget.
//
// There are TWO layers above a pause, and this is the looser one. The
// stale-heartbeat reaper gives up after VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
// well below MAX, so a long pause survives only because the transport's silence
// tick keeps the job heartbeat fresh through a step that prints nothing. Raising
// MAX is therefore not a decision about the SSH timeout alone: past the reap
// window the pause rides on that one mechanism, and when it stops the job is
// marked failed while the playbook keeps mutating the ESXi host.
const VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT = 300;
const VIRTUSPHERE_START_WAIT_SECONDS_MIN = 1;
const VIRTUSPHERE_START_WAIT_SECONDS_MAX = 1500;

// Seconds the create step waits for ESXi to register the VMs it just created,
// before the next playbook addresses them by name. Not operator-tunable: it is a
// property of the hypervisor, not of the MECM environment, so there is no form
// field and the value is fixed. It lives here rather than as a literal in the
// playbook so it is inside the same pause budget as the two configurable waits:
// a pause nobody owns is a pause nothing checks.
const VIRTUSPHERE_CREATE_SETTLE_SECONDS = 60;

/**
 * The operator-facing name of one provisioning type. The stored value is the
 * token vmware_guest expects, and a bare `eagerzeroedthick` in a select tells a
 * reader nothing about what the choice costs. Exhaustive match without a
 * default, so a type added to VIRTUSPHERE_DISK_TYPES fails here loudly instead
 * of reaching the form as a raw token again; DiskTypeLabelTest walks the
 * constant against it.
 *
 * Reachable before the portal bootstrap (the workers load this file), so __t()
 * is optional and the token itself is the fallback.
 */
function disk_type_label(string $type): string
{
    $key = match ($type) {
        'thin' => 'vm_edit.disk_type_thin',
        'thick' => 'vm_edit.disk_type_thick',
        'eagerzeroedthick' => 'vm_edit.disk_type_eagerzeroedthick',
    };
    $label = function_exists('__t') ? __t($key) : $key;

    return $label === $key ? $type : $label;
}

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
