<?php

declare(strict_types=1);

/** VM editor request, action and view-model owner. Loaded by portal/vm_edit.php. */

/** @var mysqli $connection Provided by portal/bootstrap.php. */
/** @var array<string, mixed> $user Authenticated portal user from the shell. */

$missionId = request_int($_GET, 'mission_id', request_int($_POST, 'mission_id'));
$vmId = request_int($_GET, 'vm_id', request_int($_POST, 'vm_id'));
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to('missions.php?type=missions');
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);

$vm = $vmId > 0 ? repo_get_vm_bundle($connection, $vmId) : null;
if ($vmId > 0 && ($vm === null || (int) $vm['mission_id'] !== $missionId)) {
    flash_set('error', __t('portal.vm_not_found'));
    redirect_to('vms.php?mission_id=' . $missionId);
}

$clientPhaseSummary = [];
$clientEvents = [];
if ($vmId > 0 && !$isTemplate) {
    $clientPhaseSummary = repo_client_phase_summary($connection, $vmId);
    $clientEvents = repo_client_events_for_vm($connection, $vmId, 20);
}

$error = '';
$fieldErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    if (!can('vms.write', $user)) {
        portal_forbid($connection, $user, 'vms.write');
    }

    try {
        $vmData = [
            'vm_name' => request_string($_POST, 'vm_name'),
            'vm_hostname' => request_string($_POST, 'vm_hostname'),
            'vm_domain' => request_string($_POST, 'vm_domain'),
            'vm_os' => request_string($_POST, 'vm_os'),
            'vm_ram' => request_string($_POST, 'vm_ram'),
            'vm_cpu' => request_string($_POST, 'vm_cpu'),
            'vm_disk' => request_string($_POST, 'vm_disk'),
            'vm_datastore' => request_string($_POST, 'vm_datastore'),
            'vm_datacenter' => request_string($_POST, 'vm_datacenter'),
            'vm_guest_id' => request_string($_POST, 'vm_guest_id'),
            // vm_creator is deliberately absent: repo_save_vm() stamps it from the
            // acting user on create and preserves it on update. A posted value is
            // ignored, and the rendered field carries no name attribute.
            'vm_notes' => request_string($_POST, 'vm_notes'),
            'cpu_hotplug' => request_string($_POST, 'cpu_hotplug', '0'),
            'ram_hotplug' => request_string($_POST, 'ram_hotplug', '0'),
            // Autostart override (ADR-0025). An empty delay field is the editor's
            // "inherit" state and repo_vm_delay_value() turns it into -1; it must
            // never reach the INT NOT NULL column as ''.
            'autostart_enabled' => request_string($_POST, 'autostart_enabled', '0'),
            'autostart_start_delay' => request_string($_POST, 'autostart_start_delay'),
            'autostart_stop_delay' => request_string($_POST, 'autostart_stop_delay'),
        ];
        $savedVmId = repo_save_vm(
            $connection,
            $missionId,
            $vmId > 0 ? $vmId : null,
            $vmData,
            vm_parse_interfaces(is_array($_POST['interfaces'] ?? null) ? $_POST['interfaces'] : [], $mission),
            vm_parse_disks(is_array($_POST['disks'] ?? null) ? $_POST['disks'] : []),
            vm_parse_packages(is_array($_POST['packages'] ?? null) ? $_POST['packages'] : []),
            request_string($_POST, 'updated_at'),
            (int) $user['id']
        );
        // On update, $vm is the pre-save bundle: diff the scalar columns so the
        // entry names the change (a datastore override, a renamed hostname). The
        // diff compares stored row against stored row, NOT the raw POST values:
        // repo_save_vm normalizes on the way in (an emptied hostname falls back
        // to the VM name, an empty guest id to the default), and the audit trail
        // must never claim a change that was not persisted. The legacy vm_disk
        // summary and the free-text notes are withheld; interfaces, disks and
        // packages are child rows and out of this scalar diff.
        $auditContext = ['action' => $vmId > 0 ? 'updated' : 'created', 'mission_id' => $missionId];
        if ($vmId > 0) {
            $savedVm = repo_get_vm_bundle($connection, $savedVmId) ?? [];
            $auditColumns = array_diff_key($vmData, array_flip(['vm_disk', 'vm_notes']));
            $changes = audit_change_summary((array) $vm, array_intersect_key($savedVm, $auditColumns));
            // An edit that changed nothing still gets a row (with optimistic
            // locking a no-op save is a real event), it just carries no diff:
            // an empty `changes` string is not a value the registry accepts.
            if ($changes !== '') {
                $auditContext['changes'] = $changes;
            }
        }
        audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, 'vm', $savedVmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $auditContext, (int) $user['id']);
        flash_set('success', __t('vm_edit.flash_saved'));
        // A registered VM is one MECM already holds, and the device-sync only
        // looks at VMs it does not. So a package or OS change made here is stored
        // and goes no further until somebody transfers it. Saying that is the
        // whole point: the change used to be silently portal-only, and the
        // operator believed the VM would get the package.
        if ($vmId > 0 && !$isTemplate && (string) ($vm['mecm_sync_state'] ?? '') === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
            $before = array_map(static fn (array $row): int => (int) ($row['id'] ?? $row['package_id'] ?? 0), (array) ($vm['packages'] ?? []));
            $after = array_map(static fn (array $row): int => (int) $row['id'], vm_parse_packages(is_array($_POST['packages'] ?? null) ? $_POST['packages'] : []));
            sort($before);
            sort($after);
            $osChanged = (string) ($vm['vm_os'] ?? '') !== $vmData['vm_os'];
            if ($before !== $after || $osChanged) {
                flash_set('info', __t('vm_edit.flash_mecm_transfer_pending'), '', [
                    'url' => 'vm_edit.php?mission_id=' . $missionId . '&vm_id=' . $savedVmId,
                    'label' => __t('portal.vm_mecm_transfer_button'),
                ]);
            }
        }
        redirect_to('vms.php?mission_id=' . $missionId);
    } catch (Throwable $exception) {
        $error = portal_error_message($exception);
        if ($exception instanceof ValidationException) {
            $fieldErrors = $exception->errors();
        }
        $vm = array_merge($vm ?? [], $_POST);
        $vm['interfaces'] = is_array($_POST['interfaces'] ?? null) ? $_POST['interfaces'] : vm_default_interfaces($mission);
        $vm['disks'] = is_array($_POST['disks'] ?? null) ? $_POST['disks'] : vm_default_disks();
        $vm['packages'] = vm_parse_packages(is_array($_POST['packages'] ?? null) ? $_POST['packages'] : []);
    }
}

$vm ??= [];
$canWrite = can('vms.write', $user);
$selectedPackages = array_map(static fn (array $row): int => (int) ($row['id'] ?? $row['package_id'] ?? 0), $vm['packages'] ?? []);
// Pickers exclude retired catalog entries but keep values this VM already
// uses (retired entries render with a suffix, E3).
$oses = repo_os_for_picker($connection, (string) ($vm['vm_os'] ?? ''));
// ESXi-owned VLAN catalog: only active entries are offered; a value an interface
// already stores stays selectable via the per-row unknown option (E4b).
$vlans = repo_active_vlans($connection);
$packages = repo_packages_for_picker($connection, $selectedPackages);
$packageUpgradeHints = $vmId > 0 ? repo_vm_package_upgrade_hints($connection, $vmId) : [];
// The transition history (B11 rest): nine writers had no reader until Etappe 8.
$statusEvents = $vmId > 0 ? repo_vm_status_events($connection, $vmId) : [];
// Location overrides: empty means "inherit from the mission" (ansible_effective_*).
// The mission value is only shown, never prefilled: a prefill would be saved back
// as a real override and pin the VM to a value that later goes stale. A <select>
// has no placeholder, so the inherited value rides along in the empty option.
$vmDatacenterOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATACENTER);
$vmDatastoreOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATASTORE);
$vmDatacenterValue = (string) ($vm['vm_datacenter'] ?? '');
$vmDatastoreValue = (string) ($vm['vm_datastore'] ?? '');
$missionDatastore = trim((string) ($mission['hypervisor_datastorage'] ?? ''));
$missionDatacenter = trim((string) ($mission['hypervisor_datacenter'] ?? ''));
// An empty mission datacenter is the derived case, so the plain label is the
// truthful one: there is no concrete value to promise.
$datastoreInheritLabel = $missionDatastore !== ''
    ? __t('vm_edit.location_inherit_value', ['value' => $missionDatastore])
    : __t('vm_edit.location_inherit');
$datacenterInheritLabel = $missionDatacenter !== ''
    ? __t('vm_edit.location_inherit_value', ['value' => $missionDatacenter])
    : __t('vm_edit.location_inherit');
// A standalone ESXi exposes exactly one (implicit) datacenter, so an override
// could not point anywhere else. Hide the control, but keep posting the value:
// an unrendered field would come back as '' and wipe an existing override.
$hideVmDatacenter = $vmDatacenterValue === ''
    && esxi_inventory_options_are_exact($vmDatacenterOptions)
    && count($vmDatacenterOptions['names']) === 1;
// The same notes the mission editor renders, over the same pickers. This page
// carried none at all, so an override chosen here could pin the VM to a value
// that exists on one host of a mixed fleet without anything saying so.
$vmLocationNotes = esxi_inventory_location_notes(
    $hideVmDatacenter ? [$vmDatastoreOptions] : [$vmDatastoreOptions, $vmDatacenterOptions]
);
$interfaces = $vm['interfaces'] ?? vm_default_interfaces($mission);
$disks = $vm['disks'] ?? vm_default_disks();
$progressWatchKind = $vmId > 0 && !$isTemplate ? virtusphere_vm_progress_watch_kind((array) $vm) : null;
$progressAttention = $vmId > 0 && !$isTemplate ? virtusphere_vm_progress_attention((array) $vm) : null;
$progressWatchSince = $progressWatchKind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
    ? (string) ($vm['mecm_pending_since'] ?? '')
    : (string) ($vm['os_install_watch_started_at'] ?? '');
$showProgressWatch = $progressAttention !== null || $progressWatchKind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING;
$title = $vmId > 0 ? __t('vm_edit.title_edit') : __t('vm_edit.title_add');
