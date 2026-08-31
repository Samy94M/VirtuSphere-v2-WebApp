<?php

declare(strict_types=1);

/** VM editor page renderer. The request and layout shell live in portal/vm_edit.php. */

/** @var mysqli $connection Request connection from the portal shell. */
/** @var array<string, mixed> $user Authenticated portal user. */
/** @var int $missionId */
/** @var int $vmId */
/** @var bool $canWrite */
/** @var bool $isTemplate */
/** @var array<string, mixed> $mission */
/** @var array<string, mixed> $vm */
/** @var string $error */
/** @var array<string, string> $fieldErrors */
/** @var array<string, array<string, mixed>|null> $clientPhaseSummary */
/** @var array<int, array<string, mixed>> $clientEvents */
/** @var array<int, array<string, mixed>> $oses */
/** @var array<int, array<string, mixed>> $vlans */
/** @var array<int, array<string, mixed>> $packages */
/** @var array<string, string> $packageUpgradeHints */
/** @var array<int, array<string, mixed>> $statusEvents */
/** @var list<int> $selectedPackages */
/** @var array{credential_count:int, eligible_count:int, exact:bool, buckets:array<int,array<string,mixed>>, groups:array<int,array<string,mixed>>, names:array<int,string>, name_set:array<string,true>, free_by_key:array<string,?int>, unusable_by_key:array<string,bool>} $vmDatacenterOptions */
/** @var array{credential_count:int, eligible_count:int, exact:bool, buckets:array<int,array<string,mixed>>, groups:array<int,array<string,mixed>>, names:array<int,string>, name_set:array<string,true>, free_by_key:array<string,?int>, unusable_by_key:array<string,bool>} $vmDatastoreOptions */
/** @var string $vmDatacenterValue */
/** @var string $vmDatastoreValue */
/** @var string $missionDatastore */
/** @var string $missionDatacenter */
/** @var string $datastoreInheritLabel */
/** @var string $datacenterInheritLabel */
/** @var bool $hideVmDatacenter */
/** @var list<'host_choice'|'buckets'|'never_pulled'> $vmLocationNotes */
/** @var array<int, array<string, mixed>> $interfaces */
/** @var array<int, array<string, mixed>> $disks */
/** @var string|null $progressWatchKind */
/** @var array{kind:string,since:string,age_seconds:int,threshold_seconds:int}|null $progressAttention */
/** @var string $progressWatchSince */
/** @var bool $showProgressWatch */
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

    <?php if ($showProgressWatch) { ?>
        <section class="panel stack" aria-labelledby="vm-progress-watch-heading">
            <div class="actions">
                <h2 id="vm-progress-watch-heading"><?php echo h(__t('vm_edit.progress_heading')); ?></h2>
                <?php echo portal_badge($progressAttention !== null ? 'warning' : 'info', $progressAttention !== null
                    ? __t('vm_edit.progress_overdue_badge')
                    : __t('vm_edit.progress_observation_badge')); ?>
            </div>
            <?php if ($progressWatchKind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING) { ?>
                <p><?php echo h(__t('vm_edit.progress_pending_overdue', [
                    'since' => portal_format_timestamp($progressWatchSince),
                    'hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600),
                ])); ?></p>
            <?php } elseif ($progressWatchSince === '') { ?>
                <p><?php echo h(__t('vm_edit.progress_install_unwatched')); ?></p>
            <?php } elseif ($progressAttention !== null) { ?>
                <p><?php echo h(__t('vm_edit.progress_install_overdue', [
                    'since' => portal_format_timestamp($progressWatchSince),
                    'hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
                ])); ?></p>
            <?php } else { ?>
                <p><?php echo h(__t('vm_edit.progress_install_watched', [
                    'since' => portal_format_timestamp($progressWatchSince),
                    'hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
                ])); ?></p>
            <?php } ?>
            <p class="muted"><?php echo h(__t('vm_edit.progress_no_auto_failure')); ?></p>
            <?php if ($canWrite) { ?>
                <form class="inline-form" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="restart_progress_watch">
                    <input type="hidden" name="vm_id" value="<?php echo h((string) $vmId); ?>">
                    <input type="hidden" name="return_to" value="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?>&vm_id=<?php echo h((string) $vmId); ?>">
                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t($progressWatchKind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING && $progressWatchSince === ''
                        ? 'vm_edit.progress_confirm_start'
                        : 'vm_edit.progress_confirm_restart')); ?>"><?php echo h(__t($progressWatchKind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING && $progressWatchSince === ''
                        ? 'vm_edit.progress_start_button'
                        : 'vm_edit.progress_restart_button')); ?></button>
                </form>
            <?php } ?>
        </section>
    <?php } ?>

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
                <label><?php echo h(__t('common.name')); ?><input name="vm_name"<?php echo form_control_attrs('vm_edit', 'vm_name', null, false, (string) ($fieldErrors['vm_name'] ?? '')); ?> maxlength="16" value="<?php echo h($vm['vm_name'] ?? ''); ?>" required <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_name'); ?></label>
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
                    $hostnameFieldError = (string) ($fieldErrors['vm_hostname'] ?? '');
                    $hostnameLegacyWarning = $hostnameLegacyInvalid
                        ? __t('vm_edit.hostname_legacy_warning', ['preview' => $hostnameClientPreview])
                        : '';
                    $hostnameEffectiveError = $hostnameFieldError !== '' ? $hostnameFieldError : $hostnameLegacyWarning;
                ?>
                <label><?php echo h(__t('vm_edit.label_hostname')); ?><input name="vm_hostname"<?php echo form_control_attrs('vm_edit', 'vm_hostname', null, false, $hostnameEffectiveError); ?> maxlength="15" value="<?php echo h($vm['vm_hostname'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_hostname'); ?>
                    <?php if ($hostnameLegacyInvalid && $hostnameFieldError === '') { ?>
                        <span class="field-error" id="<?php echo h(form_error_id('vm_edit', 'vm_hostname')); ?>"><?php echo h($hostnameLegacyWarning); ?></span>
                    <?php } ?>
                </label>
                <label><?php echo h(__t('vm_edit.label_domain')); ?><input name="vm_domain"<?php echo form_control_attrs('vm_edit', 'vm_domain', null, false, (string) ($fieldErrors['vm_domain'] ?? '')); ?> value="<?php echo h($vm['vm_domain'] ?? ($mission['domain'] ?? '')); ?>" pattern="<?php echo h(VIRTUSPHERE_FQDN_INPUT_PATTERN); ?>" title="<?php echo h(__t('vm_edit.domain_title')); ?>" autocomplete="off" spellcheck="false" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_domain'); ?></label>
                <label><?php echo h(__t('vm_edit.label_os')); ?><select name="vm_os"<?php echo form_control_attrs('vm_edit', 'vm_os', null, false, (string) ($fieldErrors['vm_os'] ?? '')); ?> required <?php echo $canWrite ? '' : 'disabled'; ?>>
                    <option value=""><?php echo h(__t('vm_edit.select_os')); ?></option>
                    <?php foreach ($oses as $os) { ?>
                        <option value="<?php echo h($os['os_name'] ?? ''); ?>" <?php echo (string) ($vm['vm_os'] ?? '') === (string) ($os['os_name'] ?? '') ? 'selected' : ''; ?>><?php echo h($os['os_name'] ?? ''); ?></option>
                    <?php } ?>
                </select><?php echo vm_field_error($fieldErrors, 'vm_os'); ?></label>
                <label><?php echo h(__t('vm_edit.label_ram')); ?><span class="compound-field ram-field"><input name="vm_ram" type="number"<?php echo form_control_attrs('vm_edit', 'vm_ram', null, false, (string) ($fieldErrors['vm_ram'] ?? '')); ?> min="<?php echo h((string) VIRTUSPHERE_VM_LIMITS['ram_mb_min']); ?>" value="<?php echo h($vm['vm_ram'] ?? (string) VIRTUSPHERE_VM_DEFAULTS['ram_mb']); ?>" data-combo-input <?php echo $canWrite ? '' : 'readonly'; ?>><select<?php echo form_control_attrs('vm_edit', 'vm_ram_preset', null, false, ''); ?> data-combo-picker aria-label="<?php echo h(__t('vm_edit.ram_preset')); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><option value=""><?php echo h(__t('vm_edit.ram_custom')); ?></option><?php $ramValue = (string) ($vm['vm_ram'] ?? VIRTUSPHERE_VM_DEFAULTS['ram_mb']); foreach (VIRTUSPHERE_RAM_PRESETS_MB as $ramMb) { ?><option value="<?php echo h((string) $ramMb); ?>" <?php echo $ramValue === (string) $ramMb ? 'selected' : ''; ?>><?php echo h((string) ($ramMb / 1024)); ?> GB</option><?php } ?></select></span><?php echo vm_field_error($fieldErrors, 'vm_ram'); ?></label>
                <label><?php echo h(__t('vm_edit.label_cpu')); ?><input name="vm_cpu" type="number"<?php echo form_control_attrs('vm_edit', 'vm_cpu', null, false, (string) ($fieldErrors['vm_cpu'] ?? '')); ?> min="1" value="<?php echo h($vm['vm_cpu'] ?? (string) VIRTUSPHERE_VM_DEFAULTS['cpu_count']); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_cpu'); ?></label>
                <?php
                    // Fields and hint form one group spanning two grid tracks, so the prose
                    // stays under the controls it explains. Its column cannot be pinned in
                    // CSS: a hidden MECM id or datacenter shifts the pair by a track.
                    $locationHintSubject = [__t('vm_edit.label_datastore')];
                    if (!$hideVmDatacenter) {
                        $locationHintSubject[] = __t('vm_edit.label_datacenter');
                    }
                    $locationHintId = form_hint_id('vm_edit', 'location');
                ?>
                <div class="field-group" role="group"<?php echo form_control_attrs('vm_edit', 'location', null, [$locationHintId], ''); ?>>
                    <label><?php echo h(__t('vm_edit.label_datastore')); ?><?php inventory_select_field($vmDatastoreOptions, [
                        'name' => 'vm_datastore',
                        'value' => $vmDatastoreValue,
                        'empty_label' => $datastoreInheritLabel,
                        'unknown_suffix' => __t('vm_edit.location_not_in_inventory'),
                        'input_placeholder' => $missionDatastore,
                        'disabled' => !$canWrite,
                        'attributes' => form_control_attrs('vm_edit', 'vm_datastore', null, false, ''),
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
                            'attributes' => form_control_attrs('vm_edit', 'vm_datacenter', null, false, ''),
                        ]); ?></label>
                    <?php } ?>
                    <div id="<?php echo h($locationHintId); ?>">
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
                </div>
                <label><?php echo h(__t('portal.vm_guest_os_label')); ?><select name="vm_guest_id"<?php echo form_control_attrs('vm_edit', 'vm_guest_id', null, false, (string) ($fieldErrors['vm_guest_id'] ?? '')); ?> <?php echo $canWrite ? '' : 'disabled'; ?>>
                    <?php $guestIdValue = (string) ($vm['vm_guest_id'] ?? VIRTUSPHERE_VM_DEFAULTS['guest_id']); ?>
                    <?php foreach (vm_guest_os_options_for_value($guestIdValue) as $guestOsOption) { ?>
                        <option value="<?php echo h((string) $guestOsOption['guest_id']); ?>" <?php echo $guestIdValue === (string) $guestOsOption['guest_id'] ? 'selected' : ''; ?>><?php echo h(vm_guest_os_option_label($guestOsOption)); ?></option>
                    <?php } ?>
                </select><?php echo vm_field_error($fieldErrors, 'vm_guest_id'); ?></label>
                <?php $cpuHotplugOn = !isset($vm['cpu_hotplug']) || (string) $vm['cpu_hotplug'] !== '0'; ?>
                <?php $ramHotplugOn = !isset($vm['ram_hotplug']) || (string) $vm['ram_hotplug'] !== '0'; ?>
                <?php $hotplugHintId = form_hint_id('vm_edit', 'hotplug'); ?>
                <div class="form-grid-full" role="group"<?php echo form_control_attrs('vm_edit', 'hotplug', null, [$hotplugHintId], ''); ?>>
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
                    <p class="hint" id="<?php echo h($hotplugHintId); ?>"><?php echo h(__t('vm_edit.hotplug_hint')); ?></p>
                </div>

                <?php
                // Autostart override (ADR-0025). An empty delay field means "inherit
                // the mission default", which is what ESXi itself calls "use
                // defaults"; the placeholder names the value that would apply.
                //
                // repo_vm_delay_value() is the same normalizer the save path uses,
                // so a sticky re-render after a validation error cannot turn a blank
                // (inherit) field into a 0 (start immediately).
                $autostartOn = repo_vm_autostart_flag($vm, 'autostart_enabled') === 1;
                $missionAutostartOn = (int) ($mission['autostart_enabled'] ?? 0) === 1;
                $missionStartDelay = (int) ($mission['autostart_start_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT);
                $missionStopDelay = (int) ($mission['autostart_stop_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT);
                $vmStartDelay = repo_vm_delay_value($vm, 'autostart_start_delay');
                $vmStopDelay = repo_vm_delay_value($vm, 'autostart_stop_delay');
                // A VM cannot opt into an autostart its mission has switched off:
                // the mission switch is what turns the host's autostart manager on,
                // so the control is inert and says so, rather than accepting a
                // setting that would quietly do nothing.
                $autostartLocked = !$canWrite || !$missionAutostartOn;
                $autostartHintId = form_hint_id('vm_edit', 'autostart');
                ?>
                <div class="form-grid-full" role="group"<?php echo form_control_attrs('vm_edit', 'autostart', null, [$autostartHintId], ''); ?>>
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
                        <p class="hint" id="<?php echo h($autostartHintId); ?>"><span class="hint-subject"><?php echo h(__t('vm_edit.autostart_mission_off_subject')); ?>:</span> <?php echo h(__t('vm_edit.autostart_mission_off')); ?>
                            <a href="mission_details.php?id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vm_edit.autostart_mission_link')); ?></a>
                        </p>
                    <?php } else { ?>
                        <p class="hint" id="<?php echo h($autostartHintId); ?>"><?php echo h(__t('vm_edit.autostart_hint')); ?></p>
                    <?php } ?>
                </div>
                <?php // readonly, not disabled: a readonly number input still posts, so a
                      // blank field keeps meaning "inherit" and a set one keeps its value. ?>
                <label><?php echo h(__t('vm_edit.autostart_start_delay')); ?><input type="number" name="autostart_start_delay"<?php echo form_control_attrs('vm_edit', 'autostart_start_delay', null, [$autostartHintId], (string) ($fieldErrors['autostart_start_delay'] ?? '')); ?> min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo $vmStartDelay >= 0 ? h((string) $vmStartDelay) : ''; ?>" placeholder="<?php echo h(__t('vm_edit.autostart_inherit_placeholder', ['seconds' => $missionStartDelay])); ?>" <?php echo $autostartLocked ? 'readonly' : ''; ?>><?php echo vm_field_error($fieldErrors, 'autostart_start_delay'); ?></label>
                <label><?php echo h(__t('vm_edit.autostart_stop_delay')); ?><input type="number" name="autostart_stop_delay"<?php echo form_control_attrs('vm_edit', 'autostart_stop_delay', null, [$autostartHintId], (string) ($fieldErrors['autostart_stop_delay'] ?? '')); ?> min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo $vmStopDelay >= 0 ? h((string) $vmStopDelay) : ''; ?>" placeholder="<?php echo h(__t('vm_edit.autostart_inherit_placeholder', ['seconds' => $missionStopDelay])); ?>" <?php echo $autostartLocked ? 'readonly' : ''; ?>><?php echo vm_field_error($fieldErrors, 'autostart_stop_delay'); ?></label>

                <?php $creatorValue = $vmId > 0 ? (string) ($vm['vm_creator'] ?? '') : (string) $user['name']; ?>
                <label><?php echo h(__t('vm_edit.label_creator')); ?><input value="<?php echo h($creatorValue); ?>" placeholder="<?php echo h(__t('common.creator_unknown')); ?>" readonly></label>
            </div>

            <label><?php echo h(__t('vm_edit.label_notes')); ?><textarea name="vm_notes" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo h($vm['vm_notes'] ?? ''); ?></textarea></label>
        </section>

        <section class="panel stack" role="group"<?php echo form_control_attrs('vm_edit', 'interfaces', null, true, ''); ?>>
            <div class="actions"><h2><?php echo h(__t('vm_edit.heading_interfaces')); ?></h2><?php if ($canWrite) { ?><button class="button button-secondary" type="button" data-add-row="interfaces"><?php echo h(__t('vm_edit.add_interface')); ?></button><?php } ?></div>
            <div class="stack" data-repeat-target="interfaces">
                <?php foreach (array_values($interfaces) as $index => $interface) { render_interface_row($interface, $index, $vlans, $canWrite); } ?>
            </div>
            <template data-template="interfaces"><?php render_interface_row(vm_default_interfaces($mission)[0], '__INDEX__', $vlans, true, true); ?></template>
            <?php render_interface_gateway_hint(); ?>
        </section>

        <section class="panel stack" role="group"<?php echo form_control_attrs('vm_edit', 'disks', null, true, ''); ?>>
            <div class="actions"><h2><?php echo h(__t('vm_edit.heading_disks')); ?></h2><?php if ($canWrite) { ?><button class="button button-secondary" type="button" data-add-row="disks"><?php echo h(__t('vm_edit.add_disk')); ?></button><?php } ?></div>
            <div class="stack" data-repeat-target="disks">
                <?php foreach (array_values($disks) as $index => $disk) { render_disk_row($disk, $index, $canWrite); } ?>
            </div>
            <template data-template="disks"><?php render_disk_row(vm_default_disks()[0], '__INDEX__', true, true); ?></template>
            <?php render_disk_type_hint(); ?>
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
