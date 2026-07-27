<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/client_events.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/inventory_field.php';
require_once __DIR__ . '/../lib/vm_edit_form.php';
require_once __DIR__ . '/../lib/mecm_plan.php';
// For the deep link to the ESXi card of a credential that was never pulled.
require_once __DIR__ . '/../lib/system_status.php';

$user = portal_require_user($connection);
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
        $vmNote = '';
        if ($vmId > 0) {
            $savedVm = repo_get_vm_bundle($connection, $savedVmId) ?? [];
            $auditColumns = array_diff_key($vmData, array_flip(['vm_disk', 'vm_notes']));
            $vmNote = audit_change_note(audit_change_summary((array) $vm, array_intersect_key($savedVm, $auditColumns)));
        }
        audit($connection, VIRTUSPHERE_LOG_CATEGORY_VMS, ($vmId > 0 ? 'updated' : 'created') . ' vm id ' . $savedVmId . ' in mission id ' . $missionId . $vmNote, (int) $user['id']);
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
$title = $vmId > 0 ? __t('vm_edit.title_edit') : __t('vm_edit.title_add');
layout_header($title, $user, $isTemplate ? 'templates' : 'missions', 'missions');
?>
<div class="stack">
    <section class="panel">
        <div class="actions">
            <a class="button button-secondary" href="vms.php?mission_id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vm_edit.back_to_vms')); ?></a>
            <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vm_edit.mission_details')); ?></a>
            <?php if ($canWrite && $vmId > 0 && !$isTemplate) { ?>
                <form class="inline-form" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reset_mecm_id">
                    <input type="hidden" name="vm_id" value="<?php echo h((string) $vmId); ?>">
                    <input type="hidden" name="return_to" value="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?>&vm_id=<?php echo h((string) $vmId); ?>">
                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('portal.vm_mecm_reset_confirm', ['name' => (string) ($vm['vm_name'] ?? '')])); ?>"><?php echo h(__t('portal.vm_mecm_reset_button')); ?></button>
                </form>
                <?php // Only for a VM MECM already knows: before the registration the
                      // portal selection travels with the next sync on its own, so the
                      // action would promise work it does not do (the repo refuses it
                      // there too). ?>
                <?php if ((string) ($vm['mecm_sync_state'] ?? '') === VIRTUSPHERE_MECM_SYNC_REGISTERED) { ?>
                    <?php
                    // Transfer preview (ADR-0034): what the next device-sync run
                    // does to OUR rules, from the same loader the POST handler
                    // re-checks. Removals need saying out loud - the transfer
                    // used to be purely additive, and an operator who deselects
                    // a package must see the removal before confirming it.
                    $transferState = mecm_transfer_state($connection, $missionId, $vmId);
                    $transferAdds = array_column($transferState['plan']['add'], 'name');
                    $transferRemoves = array_column($transferState['plan']['remove'], 'collection_name');
                    ?>
                    <div class="stack">
                        <?php if ($transferAdds !== []) { ?>
                            <p class="muted"><?php echo h(__t('portal.vm_mecm_preview_add', ['names' => implode(', ', $transferAdds)])); ?></p>
                        <?php } ?>
                        <?php if ($transferRemoves !== []) { ?>
                            <p class="muted"><?php echo h(__t('portal.vm_mecm_preview_remove', ['names' => implode(', ', $transferRemoves)])); ?></p>
                        <?php } ?>
                        <?php if ($transferAdds === [] && $transferRemoves === []) { ?>
                            <p class="muted"><?php echo h(__t('portal.vm_mecm_preview_none')); ?></p>
                        <?php } ?>
                        <form class="inline-form" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="transfer_mecm">
                            <input type="hidden" name="vm_id" value="<?php echo h((string) $vmId); ?>">
                            <input type="hidden" name="assignment_revision" value="<?php echo h($transferState['revision']); ?>">
                            <input type="hidden" name="return_to" value="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?>&vm_id=<?php echo h((string) $vmId); ?>">
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('portal.vm_mecm_transfer_confirm', ['name' => (string) ($vm['vm_name'] ?? '')])); ?>"><?php echo h(__t('portal.vm_mecm_transfer_button')); ?></button>
                        </form>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
    </section>

    <?php if ($vmId > 0 && !$isTemplate) { vm_edit_render_status_panel($vm, $clientPhaseSummary, $clientEvents); } ?>

    <?php if ($error !== '') { ?>
        <?php
        // Errors for the fields below render inline next to their input; anything
        // else (interface/disk rows) has no inline anchor and is listed here.
        $inlineVmFields = ['vm_name', 'vm_hostname', 'vm_domain', 'vm_os', 'vm_ram', 'vm_cpu', 'vm_guest_id'];
        // A single-issue failure passes the same sentence as the field error and
        // as the exception message; listing it under itself reads as two faults.
        $extraErrors = array_filter(
            array_diff_key($fieldErrors, array_flip($inlineVmFields)),
            static fn (string $message): bool => $message !== $error
        );
        ?>
        <div class="alert alert-error">
            <?php echo h($error); ?>
            <?php if ($extraErrors !== []) { ?>
                <ul>
                    <?php foreach ($extraErrors as $message) { ?><li><?php echo h($message); ?></li><?php } ?>
                </ul>
            <?php } ?>
        </div>
    <?php } ?>

    <form class="stack" method="post" action="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?><?php echo $vmId > 0 ? '&vm_id=' . h((string) $vmId) : ''; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="mission_id" value="<?php echo h((string) $missionId); ?>">
        <input type="hidden" name="vm_id" value="<?php echo h((string) $vmId); ?>">
        <input type="hidden" name="updated_at" value="<?php echo h($vm['updated_at'] ?? ''); ?>">
        <input type="hidden" name="vm_disk" value="<?php echo h($vm['vm_disk'] ?? ''); ?>">

        <section class="panel">
            <h2><?php echo h(__t('vm_edit.heading_vm')); ?></h2>
            <div class="form-grid vm-form-grid">
                <?php if ($vmId > 0 && !$isTemplate) { ?>
                    <?php $mecmIdValue = (string) ($vm['mecm_id'] ?? ''); ?>
                    <label><?php echo h(__t('vm_edit.diagnostics_mecm_id')); ?><input value="<?php echo h($mecmIdValue !== '' ? $mecmIdValue : __t('vm_edit.mecm_id_none')); ?>" readonly></label>
                <?php } ?>
                <label><?php echo h(__t('common.name')); ?><input name="vm_name" maxlength="16" value="<?php echo h($vm['vm_name'] ?? ''); ?>" required <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_name'); ?></label>
                <?php
                    // Legacy warning (E2): stored hostnames that violate the
                    // NetBIOS rule are grandfathered but visibly flagged with
                    // the name the MECM client phase would actually produce.
                    $storedHostnameValue = (string) ($vm['vm_hostname'] ?? '');
                    $hostnameLegacyInvalid = $storedHostnameValue !== ''
                        && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,13}[A-Za-z0-9])?$/', $storedHostnameValue) !== 1;
                    $hostnameClientPreview = $hostnameLegacyInvalid
                        ? (string) preg_replace('/[^A-Za-z0-9-]/', '', substr($storedHostnameValue, 0, 15))
                        : '';
                ?>
                <label><?php echo h(__t('vm_edit.label_hostname')); ?><input name="vm_hostname" maxlength="15" value="<?php echo h($vm['vm_hostname'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_hostname'); ?>
                    <?php if ($hostnameLegacyInvalid) { ?>
                        <span class="field-error"><?php echo h(__t('vm_edit.hostname_legacy_warning', ['preview' => $hostnameClientPreview])); ?></span>
                    <?php } ?>
                </label>
                <label><?php echo h(__t('vm_edit.label_domain')); ?><input name="vm_domain" value="<?php echo h($vm['vm_domain'] ?? ($mission['domain'] ?? '')); ?>" pattern="<?php echo h(VIRTUSPHERE_FQDN_INPUT_PATTERN); ?>" title="<?php echo h(__t('vm_edit.domain_title')); ?>" autocomplete="off" spellcheck="false" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_domain'); ?></label>
                <label><?php echo h(__t('vm_edit.label_os')); ?><select name="vm_os" required <?php echo $canWrite ? '' : 'disabled'; ?>>
                    <option value=""><?php echo h(__t('vm_edit.select_os')); ?></option>
                    <?php foreach ($oses as $os) { ?>
                        <option value="<?php echo h($os['os_name'] ?? ''); ?>" <?php echo (string) ($vm['vm_os'] ?? '') === (string) ($os['os_name'] ?? '') ? 'selected' : ''; ?>><?php echo h($os['os_name'] ?? ''); ?></option>
                    <?php } ?>
                </select><?php echo vm_field_error($fieldErrors, 'vm_os'); ?></label>
                <label><?php echo h(__t('vm_edit.label_ram')); ?><span class="compound-field ram-field"><input name="vm_ram" type="number" min="<?php echo h((string) VIRTUSPHERE_VM_LIMITS['ram_mb_min']); ?>" value="<?php echo h($vm['vm_ram'] ?? (string) VIRTUSPHERE_VM_DEFAULTS['ram_mb']); ?>" data-combo-input <?php echo $canWrite ? '' : 'readonly'; ?>><select data-combo-picker aria-label="<?php echo h(__t('vm_edit.ram_preset')); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><option value=""><?php echo h(__t('vm_edit.ram_custom')); ?></option><?php $ramValue = (string) ($vm['vm_ram'] ?? VIRTUSPHERE_VM_DEFAULTS['ram_mb']); foreach (VIRTUSPHERE_RAM_PRESETS_MB as $ramMb) { ?><option value="<?php echo h((string) $ramMb); ?>" <?php echo $ramValue === (string) $ramMb ? 'selected' : ''; ?>><?php echo h((string) ($ramMb / 1024)); ?> GB</option><?php } ?></select></span><?php echo vm_field_error($fieldErrors, 'vm_ram'); ?></label>
                <label><?php echo h(__t('vm_edit.label_cpu')); ?><input name="vm_cpu" type="number" min="1" value="<?php echo h($vm['vm_cpu'] ?? (string) VIRTUSPHERE_VM_DEFAULTS['cpu_count']); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_cpu'); ?></label>
                <?php
                    // Fields and hint form one group spanning two grid tracks, so the prose
                    // stays under the controls it explains. Its column cannot be pinned in
                    // CSS: a hidden MECM id or datacenter shifts the pair by a track.
                    $locationHintSubject = [__t('vm_edit.label_datastore')];
                    if (!$hideVmDatacenter) {
                        $locationHintSubject[] = __t('vm_edit.label_datacenter');
                    }
                ?>
                <div class="field-group">
                    <label><?php echo h(__t('vm_edit.label_datastore')); ?><?php inventory_select_field($vmDatastoreOptions, [
                        'name' => 'vm_datastore',
                        'value' => $vmDatastoreValue,
                        'empty_label' => $datastoreInheritLabel,
                        'unknown_suffix' => __t('vm_edit.location_not_in_inventory'),
                        'input_placeholder' => $missionDatastore,
                        'disabled' => !$canWrite,
                    ]); ?></label>
                    <?php if ($hideVmDatacenter) { ?>
                        <input type="hidden" name="vm_datacenter" value="<?php echo h($vmDatacenterValue); ?>">
                    <?php } else { ?>
                        <label><?php echo h(__t('vm_edit.label_datacenter')); ?><?php inventory_select_field($vmDatacenterOptions, [
                            'name' => 'vm_datacenter',
                            'value' => $vmDatacenterValue,
                            'empty_label' => $datacenterInheritLabel,
                            'unknown_suffix' => __t('vm_edit.location_not_in_inventory'),
                            'input_placeholder' => $missionDatacenter,
                            'disabled' => !$canWrite,
                        ]); ?></label>
                    <?php } ?>
                    <p class="hint"><span class="hint-subject"><?php echo h(implode(' / ', $locationHintSubject)); ?>:</span> <?php echo h(__t('vm_edit.location_hint')); ?></p>
                    <?php if ($vmLocationNotes !== []) { ?>
                        <?php // Exhaustive match, no default: a new note token has to be
                              // given a sentence rather than disappearing silently. ?>
                        <p class="hint"><?php echo h(implode(' ', array_map(static fn (string $note): string => match ($note) {
                            'host_choice' => __t('vm_edit.location_host_choice_hint'),
                            'buckets' => __t('vm_edit.location_bucket_hint'),
                            'never_pulled' => __t('vm_edit.location_never_pulled_hint'),
                        }, $vmLocationNotes))); ?></p>
                    <?php } ?>
                    <?php if (in_array('never_pulled', $vmLocationNotes, true)) { ?>
                        <p class="hint"><a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('vm_edit.location_status_link')); ?></a></p>
                    <?php } ?>
                </div>
                <label><?php echo h(__t('portal.vm_guest_os_label')); ?><select name="vm_guest_id" <?php echo $canWrite ? '' : 'disabled'; ?>>
                    <?php $guestIdValue = (string) ($vm['vm_guest_id'] ?? VIRTUSPHERE_VM_DEFAULTS['guest_id']); ?>
                    <?php foreach (vm_guest_os_options_for_value($guestIdValue) as $guestOsOption) { ?>
                        <option value="<?php echo h((string) $guestOsOption['guest_id']); ?>" <?php echo $guestIdValue === (string) $guestOsOption['guest_id'] ? 'selected' : ''; ?>><?php echo h(vm_guest_os_option_label($guestOsOption)); ?></option>
                    <?php } ?>
                </select><?php echo vm_field_error($fieldErrors, 'vm_guest_id'); ?></label>
                <?php $cpuHotplugOn = !isset($vm['cpu_hotplug']) || (string) $vm['cpu_hotplug'] !== '0'; ?>
                <?php $ramHotplugOn = !isset($vm['ram_hotplug']) || (string) $vm['ram_hotplug'] !== '0'; ?>
                <div class="form-grid-full">
                    <span class="field-label"><?php echo h(__t('vm_edit.hotplug_heading')); ?></span>
                    <div class="checkbox-grid checkbox-grid-aligned">
                        <label class="checkbox-item">
                            <input type="hidden" name="cpu_hotplug" value="0">
                            <input type="checkbox" name="cpu_hotplug" value="1" <?php echo $cpuHotplugOn ? 'checked' : ''; ?> <?php echo $canWrite ? '' : 'disabled'; ?>>
                            <?php echo h(__t('vm_edit.cpu_hotplug')); ?>
                        </label>
                        <label class="checkbox-item">
                            <input type="hidden" name="ram_hotplug" value="0">
                            <input type="checkbox" name="ram_hotplug" value="1" <?php echo $ramHotplugOn ? 'checked' : ''; ?> <?php echo $canWrite ? '' : 'disabled'; ?>>
                            <?php echo h(__t('vm_edit.ram_hotplug')); ?>
                        </label>
                    </div>
                    <p class="hint"><?php echo h(__t('vm_edit.hotplug_hint')); ?></p>
                </div>

                <?php
                // Autostart override (ADR-0025). An empty delay field means "inherit
                // the mission default", which is what ESXi itself calls "use
                // defaults"; the placeholder names the value that would apply.
                //
                // repo_vm_delay_value() is the same normalizer the save path uses,
                // so a sticky re-render after a validation error cannot turn a blank
                // (inherit) field into a 0 (start immediately).
                $autostartOn = repo_vm_autostart_flag($vm ?? [], 'autostart_enabled') === 1;
                $missionAutostartOn = (int) ($mission['autostart_enabled'] ?? 0) === 1;
                $missionStartDelay = (int) ($mission['autostart_start_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT);
                $missionStopDelay = (int) ($mission['autostart_stop_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT);
                $vmStartDelay = repo_vm_delay_value($vm ?? [], 'autostart_start_delay');
                $vmStopDelay = repo_vm_delay_value($vm ?? [], 'autostart_stop_delay');
                // A VM cannot opt into an autostart its mission has switched off:
                // the mission switch is what turns the host's autostart manager on,
                // so the control is inert and says so, rather than accepting a
                // setting that would quietly do nothing.
                $autostartLocked = !$canWrite || !$missionAutostartOn;
                ?>
                <div class="form-grid-full">
                    <span class="field-label"><?php echo h(__t('vm_edit.autostart_heading')); ?></span>
                    <div class="checkbox-grid checkbox-grid-aligned">
                        <label class="checkbox-item">
                            <?php if ($autostartLocked) { ?>
                                <?php // A disabled input posts nothing, so the stored value travels in
                                      // a hidden field. Without it, saving the VM while the mission has
                                      // autostart off would silently clear the VM's own setting - the
                                      // same trap the adaptively hidden datacenter field avoids. ?>
                                <input type="hidden" name="autostart_enabled" value="<?php echo $autostartOn ? '1' : '0'; ?>">
                                <input type="checkbox" <?php echo $autostartOn ? 'checked' : ''; ?> disabled>
                            <?php } else { ?>
                                <input type="hidden" name="autostart_enabled" value="0">
                                <input type="checkbox" name="autostart_enabled" value="1" <?php echo $autostartOn ? 'checked' : ''; ?>>
                            <?php } ?>
                            <?php echo h(__t('vm_edit.autostart_enabled')); ?>
                        </label>
                    </div>
                    <?php if (!$missionAutostartOn) { ?>
                        <p class="hint"><span class="hint-subject"><?php echo h(__t('vm_edit.autostart_mission_off_subject')); ?>:</span> <?php echo h(__t('vm_edit.autostart_mission_off')); ?>
                            <a href="mission_details.php?id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vm_edit.autostart_mission_link')); ?></a>
                        </p>
                    <?php } else { ?>
                        <p class="hint"><?php echo h(__t('vm_edit.autostart_hint')); ?></p>
                    <?php } ?>
                </div>
                <?php // readonly, not disabled: a readonly number input still posts, so a
                      // blank field keeps meaning "inherit" and a set one keeps its value. ?>
                <label><?php echo h(__t('vm_edit.autostart_start_delay')); ?><input type="number" name="autostart_start_delay" min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo $vmStartDelay >= 0 ? h((string) $vmStartDelay) : ''; ?>" placeholder="<?php echo h(__t('vm_edit.autostart_inherit_placeholder', ['seconds' => $missionStartDelay])); ?>" <?php echo $autostartLocked ? 'readonly' : ''; ?>><?php echo vm_field_error($fieldErrors, 'autostart_start_delay'); ?></label>
                <label><?php echo h(__t('vm_edit.autostart_stop_delay')); ?><input type="number" name="autostart_stop_delay" min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo $vmStopDelay >= 0 ? h((string) $vmStopDelay) : ''; ?>" placeholder="<?php echo h(__t('vm_edit.autostart_inherit_placeholder', ['seconds' => $missionStopDelay])); ?>" <?php echo $autostartLocked ? 'readonly' : ''; ?>><?php echo vm_field_error($fieldErrors, 'autostart_stop_delay'); ?></label>

                <?php $creatorValue = $vmId > 0 ? (string) ($vm['vm_creator'] ?? '') : (string) $user['name']; ?>
                <label><?php echo h(__t('vm_edit.label_creator')); ?><input value="<?php echo h($creatorValue); ?>" placeholder="<?php echo h(__t('common.creator_unknown')); ?>" readonly></label>
            </div>

            <label><?php echo h(__t('vm_edit.label_notes')); ?><textarea name="vm_notes" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo h($vm['vm_notes'] ?? ''); ?></textarea></label>
        </section>

        <section class="panel stack">
            <div class="actions"><h2><?php echo h(__t('vm_edit.heading_interfaces')); ?></h2><?php if ($canWrite) { ?><button class="button button-secondary" type="button" data-add-row="interfaces"><?php echo h(__t('vm_edit.add_interface')); ?></button><?php } ?></div>
            <div class="stack" data-repeat-target="interfaces">
                <?php foreach (array_values($interfaces) as $index => $interface) { render_interface_row($interface, $index, $vlans, $canWrite); } ?>
            </div>
            <template data-template="interfaces"><?php render_interface_row(vm_default_interfaces($mission)[0], '__INDEX__', $vlans, true, true); ?></template>
        </section>

        <section class="panel stack">
            <div class="actions"><h2><?php echo h(__t('vm_edit.heading_disks')); ?></h2><?php if ($canWrite) { ?><button class="button button-secondary" type="button" data-add-row="disks"><?php echo h(__t('vm_edit.add_disk')); ?></button><?php } ?></div>
            <div class="stack" data-repeat-target="disks">
                <?php foreach (array_values($disks) as $index => $disk) { render_disk_row($disk, $index, $canWrite); } ?>
            </div>
            <template data-template="disks"><?php render_disk_row(vm_default_disks()[0], '__INDEX__', true, true); ?></template>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('vm_edit.heading_packages')); ?></h2>
            <?php foreach ($packageUpgradeHints as $oldName => $newName) { ?>
                <p class="muted"><?php echo h(__t('vm_edit.package_update_available', ['old' => (string) $oldName, 'new' => (string) $newName])); ?></p>
            <?php } ?>
            <div class="checkbox-grid">
                <?php foreach ($packages as $package) {
                    $packageId = (int) $package['id'];
                    $isRetired = (string) ($package['package_status'] ?? '') === VIRTUSPHERE_CATALOG_STATUS_RETIRED;
                    $label = trim(($package['package_name'] ?? '') . ' ' . ($package['package_version'] ?? ''));
                    if ($isRetired) {
                        $label .= ' ' . __t('vm_edit.package_retired_suffix');
                    }
                    ?>
                    <label class="checkbox-item<?php echo $isRetired ? ' muted' : ''; ?>"><input type="checkbox" name="packages[]" value="<?php echo h((string) $packageId); ?>" <?php echo in_array($packageId, $selectedPackages, true) ? 'checked' : ''; ?> <?php echo $canWrite ? '' : 'disabled'; ?>> <?php echo h($label); ?></label>
                <?php } ?>
                <?php if ($packages === []) { ?><p><?php echo h(__t('vm_edit.no_packages')); ?></p><?php } ?>
            </div>
        </section>

        <?php if ($canWrite) { ?><div class="actions"><button class="button" type="submit"><?php echo h(__t('vm_edit.save_vm')); ?></button></div><?php } ?>
    </form>

    <?php render_vm_status_history($statusEvents); ?>
</div>
<?php layout_footer(); ?>