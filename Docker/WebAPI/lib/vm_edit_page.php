<?php

declare(strict_types=1);

/** VM editor request, action and view-model owner. Loaded by portal/vm_edit.php. */

require_once __DIR__ . '/edit_conflict_view.php';

/** @var mysqli $connection Provided by portal/bootstrap.php. */
/** @var array<string, mixed> $user Authenticated portal user from the shell. */

$workContext = portal_work_context($_GET);
// UX03/F05: a VM opened from the dashboard VM overview returns there. Null when the
// link came from a mission list or from nowhere; the mission parent applies then.
$overviewReturnUrl = vm_overview_return_url($workContext);
$missionId = request_int($_GET, 'mission_id', request_int($_POST, 'mission_id'));
$vmId = request_int($_GET, 'vm_id', request_int($_POST, 'vm_id'));
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to($overviewReturnUrl ?? portal_work_context_mission_list_url($workContext));
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);
if (!isset($workContext['work_list_type'])) {
    $workContext = portal_work_context(array_merge($workContext, [
        'work_list_type' => $isTemplate ? 'templates' : 'missions',
    ]));
}

$vm = $vmId > 0 ? repo_get_vm_bundle($connection, $vmId) : null;
if ($vmId > 0 && ($vm === null || (int) $vm['mission_id'] !== $missionId)) {
    flash_set('error', __t('portal.vm_not_found'));
    redirect_to($overviewReturnUrl ?? portal_work_context_vm_list_url($missionId, $workContext));
}
$vmListUrl = portal_work_context_vm_list_url($missionId, $workContext, $vmId > 0 ? $vmId : null);
$backUrl = vm_overview_return_url($workContext, $vmId > 0 ? $vmId : null) ?? $vmListUrl;
$backLabel = $overviewReturnUrl !== null
    ? vm_overview_back_label($workContext['work_ov_filter'] ?? '')
    : __t('vm_edit.back_to_vms');
$missionDetailsUrl = mission_details_url($missionId, $workContext);
$vmEditorUrl = $vmId > 0
    ? vm_edit_url($missionId, $vmId, null, $workContext)
    : portal_work_context_append_url('vm_edit.php?mission_id=' . $missionId, $workContext);

$clientPhaseSummary = [];
$clientEvents = [];
$packageRuns = [];
if ($vmId > 0 && !$isTemplate) {
    $clientPhaseSummary = repo_client_phase_summary($connection, $vmId);
    $clientEvents = repo_client_events_for_vm($connection, $vmId, 20);
    $packageRuns = repo_package_runs_for_vm($connection, $vmId);
}
// The derived phase, findings and inventory reading of the stored VM (plan ST-2),
// read before the POST merge so a rejected save cannot change what the page explains.
$vmPhase = '';
$vmFindings = [];
$vmInventory = null;
$timelineJobs = [];
if ($vmId > 0 && !$isTemplate) {
    $vmEvidence = repo_vm_evidence_for_vm(repo_vm_evidence($connection, [(array) $vm]), (array) $vm, false);
    $vmPhase = virtusphere_vm_phase((array) $vm, $vmEvidence);
    $vmFindings = virtusphere_vm_findings((array) $vm, $vmEvidence);
    $vmInventory = is_array($vmEvidence['inventory'] ?? null) ? $vmEvidence['inventory'] : null;
    $timelineJobs = repo_vm_timeline_jobs($connection, $vmId);
}

$error = '';
$fieldErrors = [];
// QL-01/QL-03 B: the comparison of a POST that saved nothing (lib/vm_edit_conflict.php).
$editConflict = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    if (!can('vms.write', $user)) {
        portal_forbid($connection, $user, 'vms.write');
    }

    // F5: "continue with my input" adopts the version the comparison showed and
    // saves nothing; only the next ordinary save writes. Never a save fallback.
    $continueVersion = $vmId > 0 ? edit_conflict_continue_version($_POST) : null;
    if ($continueVersion !== null) {
        $vm = vm_edit_sticky_vm($vm ?? [], $_POST, $mission);
        if ($continueVersion !== '') {
            $vm['edit_version'] = $continueVersion;
        }
    } else {
        try {
            $vmData = vm_edit_submitted_values($_POST);
            // One transaction for the save and its audit rows (vm_save_with_audit).
            // $vm is the pre-save bundle on update and the diff base of vm.changed.
            $saved = vm_save_with_audit(
                $connection,
                $missionId,
                $vmId,
                $vmId > 0 ? (array) $vm : [],
                $isTemplate,
                $vmData,
                vm_parse_interfaces(is_array($_POST['interfaces'] ?? null) ? $_POST['interfaces'] : [], $mission),
                vm_parse_disks(is_array($_POST['disks'] ?? null) ? $_POST['disks'] : []),
                vm_parse_packages(is_array($_POST['packages'] ?? null) ? $_POST['packages'] : []),
                request_string($_POST, 'edit_version'),
                (int) $user['id'],
                vm_mecm_save_mode($_POST)
            );
            $savedVmId = $saved['vm_id'];
            $frozenSnapshot = $saved['frozen_snapshot'];

            // What a queued transfer sends is named here (AV-R12); a plain save keeps its sentence.
            flash_set('success', vm_mecm_save_success_message($saved['mecm']));
            // A frozen snapshot means the correction was stored and changes nothing
            // about the machine that is already handed over. Saying so here is the
            // whole point: without it the operator leaves the page believing the
            // rename took effect, and finds out at the next rollout that it did not.
            if ($frozenSnapshot !== '') {
                flash_set('info', __t('vm_edit.flash_rollout_frozen', ['hostname' => $frozenSnapshot]));
            }
            // AV-R12: an OS that deviates from MECM was saved in the portal only, unless
            // the operator chose the transfer button. The flash says so whenever MECM still
            // lacks a difference that this save held back.
            if ($saved['mecm']['held']) {
                flash_set('info', __t('vm_edit.flash_mecm_portal_only'));
            }
            redirect_to(vm_overview_return_url($workContext, $savedVmId)
                ?? portal_work_context_vm_list_url($missionId, $workContext, $savedVmId));
        } catch (Throwable $exception) {
            $error = portal_error_message($exception);
            if ($exception instanceof ValidationException) {
                $fieldErrors = $exception->errors();
            }
            $vm = vm_edit_sticky_vm($vm ?? [], $_POST, $mission);
        }
    }
    if ($vmId > 0) {
        $editConflict = vm_edit_conflict($connection, $vmId, $mission, $_POST, (string) ($vm['edit_version'] ?? ''), $continueVersion !== null);
        if ($editConflict !== null && $editConflict['mode'] === VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT && $error === '') {
            // A continue whose shown version went stale meanwhile: say so like a refused save.
            $error = __t('vm_edit.err_conflict');
        }
    }
}

$vm ??= [];
$canWrite = can('vms.write', $user);
// AV-R12: the MECM state of this VM (two save buttons, or the single one).
$mecmView = vm_mecm_save_view($connection, $vmId, $isTemplate, $vm);
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
$timeline = virtusphere_vm_timeline($statusEvents, $timelineJobs, $packageRuns, $clientEvents);
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
// The same empty-option label names the inherited value in the conflict comparison.
$datastoreInheritLabel = vm_edit_location_inherit_label($mission, 'hypervisor_datastorage');
$datacenterInheritLabel = vm_edit_location_inherit_label($mission, 'hypervisor_datacenter');
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
// One title per page, rendered by layout_header() into both the browser tab and
// the visible heading. An edit names the VM it is about: the mission's VM list
// and every editor opened from it used to carry the same three words, so a row
// of browser tabs said nothing about which machine each one held. Adding has no
// name yet and stays generic.
$editedVmName = trim((string) ($vm['vm_name'] ?? ''));
$title = $vmId > 0 && $editedVmName !== ''
    ? __t('vm_edit.title_edit') . ': ' . $editedVmName
    : ($vmId > 0 ? __t('vm_edit.title_edit') : __t('vm_edit.title_add'));
