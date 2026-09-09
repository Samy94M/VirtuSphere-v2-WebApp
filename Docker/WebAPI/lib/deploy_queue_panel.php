<?php

declare(strict_types=1);

/** @var array<string,mixed> $serviceSnapshot */
/** @var array<string,mixed>|null $deployPreview */
/** @var string $redirectBase */
/** @var list<array<string,mixed>> $deployBlockers */
/** @var list<array<string,mixed>> $deployWarnings */
/** @var array<string,mixed> $user */
/** @var bool $selectedMissionDeviates */
/** @var int $selectedMissionId */
/** @var list<array<string,mixed>> $missions */
/** @var list<array<string,mixed>> $esxiCredentials */
/** @var string $selectedEsxiId */
/** @var list<array<string,mixed>> $ansibleCredentials */
/** @var array<string,string> $hostWarnings */
/** @var string $initialHostWarning */
/** @var array<string,string> $capabilityWarnings */
/** @var string $initialCapabilityWarning */
/** @var array<string,mixed>|null $selectedMission */
/** @var list<array<string,mixed>> $missionVms */
/** @var array<string,array{name:string,bytes:int,vm_count:int,per_vm:array<int,int>}> $storageRows */
/** @var array<string,array{free:?int,capacity:?int}> $initialCapacity */
/** @var array<string,mixed>|null $storageIsland */
/** @var string $scheduleMinLocal */
/** @var bool $canQueue */

if ($deployPreview !== null) { ?>
    <section class="panel">
        <h2><?php echo h(__t('deploy.preview_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.preview_hint', ['tz' => portal_timezone()])); ?></p>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('deploy.preview_when')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($deployPreview['rows'] as $row) { ?>
                <tr><td><?php echo h((string) $row['vm_name']); ?></td><td><?php echo h(portal_format_epoch((int) $row['epoch'])); ?></td></tr>
            <?php } ?>
            <?php if ($deployPreview['rows'] === []) { ?><tr><td colspan="2"><?php echo h(__t('deploy.vms_empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
        <?php if ($deployPreview['storage'] !== []) { ?>
            <h3><?php echo h(__t('deploy.storage_heading')); ?></h3>
            <?php deploy_render_storage_table($deployPreview['storage'], $deployPreview['capacity']); ?>
            <p class="hint"><?php echo h(__t('deploy.storage_hint')); ?></p>
        <?php } ?>
        <form method="post" action="<?php echo h($redirectBase); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="confirmed" value="1">
            <input type="hidden" name="vm_selection_mission_id" value="<?php echo h((string) $selectedMissionId); ?>">
            <?php foreach (VIRTUSPHERE_DEPLOY_QUEUE_FIELDS as $field) { ?>
                <input type="hidden" name="<?php echo h($field); ?>" value="<?php echo h(deploy_form_value($field)); ?>">
            <?php } ?>
            <?php if (deploy_form_value('verbose') === '1') { ?><input type="hidden" name="verbose" value="1"><?php } ?>
            <?php foreach (array_keys(deploy_form_vm_selection() ?? []) as $vid) { ?><input type="hidden" name="vm_ids[]" value="<?php echo h((string) (int) $vid); ?>"><?php } ?>
            <div class="actions">
                <button class="button" type="submit"><?php echo h(__t('deploy.preview_confirm')); ?></button>
                <a class="button button-secondary" href="<?php echo h($redirectBase); ?>"><?php echo h(__t('common.cancel')); ?></a>
            </div>
        </form>
    </section>
<?php } ?>

<section class="panel">
    <h2><?php echo h(__t('deploy.queue_heading')); ?></h2>
    <?php // What happens after Queue (Etappe 13R). It is deliberately NOT a
          // blocker: a service that is offline or paused does not make the job
          // invalid, it makes it wait, and refusing to save it would lose work
          // an operator has already entered. The sentence says which of the two
          // it will be. ?>
    <p class="muted"><?php echo h(deploy_service_queue_expectation($serviceSnapshot)); ?></p>
    <?php deploy_render_blockers($deployBlockers, $user, $deployWarnings); ?>
    <?php if ($selectedMissionDeviates) { ?>
        <div class="alert alert-warning"><strong><?php echo h(__t('deploy.warning_prefix')); ?></strong> <?php echo h(__t('deploy.inventory_deviation_warn')); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('deploy.inventory_deviation_link')); ?></a></div>
    <?php } ?>
    <form class="form-grid" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="start">
        <input type="hidden" name="vm_selection_mission_id" value="<?php echo h((string) $selectedMissionId); ?>">
        <label><?php echo h(__t('deploy.label_mission')); ?>
            <select name="mission_id" required data-deploy-mission <?php echo $missions === [] ? 'disabled' : ''; ?>>
                <option value=""><?php echo h(__t('deploy.select_mission')); ?></option>
                <?php foreach ($missions as $mission) { ?>
                    <option value="<?php echo h((string) $mission['id']); ?>" <?php echo (int) $mission['id'] === $selectedMissionId ? 'selected' : ''; ?>><?php echo h($mission['mission_name'] ?? ''); ?></option>
                <?php } ?>
            </select>
        </label>
        <label><?php echo h(__t('deploy.label_esxi')); ?>
            <select name="credential_esxi_id"<?php echo form_control_attrs('schedule', 'credential_esxi_id'); ?> required data-deploy-esxi <?php echo $esxiCredentials === [] ? 'disabled' : ''; ?>>
                <option value=""><?php echo h(__t('deploy.select_esxi')); ?></option>
                <?php foreach ($esxiCredentials as $credential) { ?>
                    <option value="<?php echo h((string) $credential['id']); ?>" <?php echo $selectedEsxiId === (string) $credential['id'] ? 'selected' : ''; ?>><?php echo h($credential['name'] ?? ''); ?></option>
                <?php } ?>
            </select>
            <?php echo form_error_html('schedule', 'credential_esxi_id'); ?>
        </label>
        <label><?php echo h(__t('deploy.label_ansible')); ?>
            <?php $selectedAnsibleId = deploy_form_value('credential_ansible_id'); ?>
            <select name="credential_ansible_id" required <?php echo $ansibleCredentials === [] ? 'disabled' : ''; ?>>
                <option value=""><?php echo h(__t('deploy.select_ansible')); ?></option>
                <?php foreach ($ansibleCredentials as $credential) { ?>
                    <option value="<?php echo h((string) $credential['id']); ?>" <?php echo $selectedAnsibleId === (string) $credential['id'] ? 'selected' : ''; ?>><?php echo h($credential['name'] ?? ''); ?></option>
                <?php } ?>
            </select>
        </label>
        <?php if ($hostWarnings !== []) { ?>
            <script type="application/json" data-deploy-host-warnings nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php echo json_encode($hostWarnings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
            <p class="alert alert-warning form-grid-full" role="status" data-deploy-host-warning <?php echo $initialHostWarning === '' ? 'hidden' : ''; ?>><strong><?php echo h(__t('deploy.warning_prefix')); ?></strong> <span data-deploy-warning-text><?php echo h($initialHostWarning); ?></span></p>
        <?php } ?>
        <?php if ($capabilityWarnings !== []) { ?>
            <script type="application/json" data-deploy-capability-warnings nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php echo json_encode($capabilityWarnings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
            <p class="alert alert-warning form-grid-full" role="status" data-deploy-capability-warning <?php echo $initialCapabilityWarning === '' ? 'hidden' : ''; ?>><strong><?php echo h(__t('deploy.warning_prefix')); ?></strong> <span data-deploy-warning-text><?php echo h($initialCapabilityWarning); ?></span></p>
        <?php } ?>
        <?php
        $selectedMode = deploy_form_value('mode', VIRTUSPHERE_DEPLOY_MODE_FULL);
        $staggerLockHintId = form_hint_id('schedule', 'mode_stagger_lock');
        $staggerLockActive = (int) deploy_form_value('stagger_minutes') > 0;
        ?>
        <label><?php echo h(__t('deploy.label_mode')); ?>
            <select name="mode"<?php echo form_control_attrs('schedule', 'mode', null, $staggerLockActive ? [$staggerLockHintId] : false, ''); ?> required
                    data-stagger-modes="<?php echo h(implode(',', VIRTUSPHERE_DEPLOY_STAGGER_MODES)); ?>"
                    data-powercycle-modes="<?php echo h(implode(',', ansible_modes_using_powercycle())); ?>"
                    data-start-wait-modes="<?php echo h(implode(',', ansible_modes_using_start())); ?>">
                <?php foreach (virtusphere_deploy_mode_labels() as $modeValue => $modeLabel) { ?>
                    <option value="<?php echo h($modeValue); ?>" <?php echo $selectedMode === (string) $modeValue ? 'selected' : ''; ?>><?php echo h($modeLabel); ?></option>
                <?php } ?>
            </select>
            <small class="hint" id="<?php echo h($staggerLockHintId); ?>" data-stagger-lock<?php echo $staggerLockActive ? '' : ' hidden'; ?>><?php echo h(__t('deploy.stagger_lock_hint')); ?></small>
        </label>
        <div class="field-group">
            <?php
            $powercycleLockHintId = form_hint_id('schedule', 'powercycle_wait_lock');
            $powercycleModeActive = in_array($selectedMode, ansible_modes_using_powercycle(), true);
            $startWaitHintId = form_hint_id('schedule', 'start_wait');
            $startWaitLockHintId = form_hint_id('schedule', 'start_wait_lock');
            $startModeActive = in_array($selectedMode, ansible_modes_using_start(), true);
            ?>
            <label><?php echo h(__t('deploy.label_powercycle_wait')); ?>
                <input type="number" name="powercycle_wait"<?php echo form_control_attrs('schedule', 'powercycle_wait', null, $powercycleModeActive ? false : [$powercycleLockHintId], ''); ?> value="<?php echo h(deploy_form_value('powercycle_wait', (string) VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT)); ?>" min="<?php echo h((string) VIRTUSPHERE_POWERCYCLE_WAIT_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_POWERCYCLE_WAIT_MAX); ?>" data-powercycle-input>
                <small class="hint" id="<?php echo h($powercycleLockHintId); ?>" data-powercycle-lock<?php echo $powercycleModeActive ? ' hidden' : ''; ?>><span class="hint-subject"><?php echo h(__t('deploy.label_powercycle_wait')); ?>:</span> <?php echo h(__t('deploy.powercycle_lock_hint')); ?></small>
            </label>
            <label><?php echo h(__t('deploy.label_start_wait')); ?>
                <input type="number" name="start_wait"<?php echo form_control_attrs('schedule', 'start_wait', null, $startModeActive ? [$startWaitHintId] : [$startWaitHintId, $startWaitLockHintId], ''); ?> value="<?php echo h(deploy_form_value('start_wait', (string) VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT)); ?>" min="<?php echo h((string) VIRTUSPHERE_START_WAIT_SECONDS_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_START_WAIT_SECONDS_MAX); ?>" data-start-wait-input>
                <small class="hint" id="<?php echo h($startWaitHintId); ?>"><span class="hint-subject"><?php echo h(__t('deploy.label_start_wait')); ?>:</span> <?php echo h(__t('deploy.start_wait_hint', ['max' => VIRTUSPHERE_START_WAIT_SECONDS_MAX])); ?></small>
                <small class="hint" id="<?php echo h($startWaitLockHintId); ?>" data-start-wait-lock<?php echo $startModeActive ? ' hidden' : ''; ?>><span class="hint-subject"><?php echo h(__t('deploy.label_start_wait')); ?>:</span> <?php echo h(__t('deploy.start_wait_lock_hint')); ?></small>
            </label>
            <?php $verboseHintId = form_hint_id('schedule', 'verbose_group'); ?>
            <div class="field-stack" role="group"<?php echo form_control_attrs('schedule', 'verbose_group', null, [$verboseHintId], ''); ?>>
                <span class="field-label"><?php echo h(__t('deploy.verbose_heading')); ?></span>
                <label class="checkbox-item"><input type="checkbox" name="verbose" value="1" <?php echo deploy_form_value('verbose') === '1' ? 'checked' : ''; ?>> <?php echo h(__t('deploy.label_verbose')); ?></label>
            </div>
            <p class="hint" id="<?php echo h($verboseHintId); ?>"><span class="hint-subject"><?php echo h(__t('deploy.label_verbose')); ?>:</span> <?php echo h(__t('deploy.verbose_hint')); ?></p>
        </div>
        <?php $vmSelectionHintId = form_hint_id('schedule', 'vm_selection'); ?>
        <div class="form-grid-full" role="group"<?php echo form_control_attrs('schedule', 'vm_selection', null, [$vmSelectionHintId], ''); ?>>
            <span class="field-label"><?php echo h(__t('deploy.label_vms')); ?></span>
            <?php if ($selectedMission === null) { ?>
                <p class="hint" id="<?php echo h($vmSelectionHintId); ?>"><?php echo h(__t('deploy.vms_select_mission_first')); ?></p>
            <?php } elseif ($missionVms === []) { ?>
                <p class="hint" id="<?php echo h($vmSelectionHintId); ?>"><?php echo h(__t('deploy.vms_empty')); ?><?php if (can('vms.write', $user)) { ?> <a href="vms.php?mission_id=<?php echo h((string) $selectedMissionId); ?>"><?php echo h(__t('deploy.vms_empty_link')); ?></a><?php } ?></p>
            <?php } else {
                $vmSelection = deploy_form_vm_selection();
                $vmIsChecked = static fn (int $id): bool => $vmSelection === null || isset($vmSelection[$id]);
                $allVmsChecked = true;
                foreach ($missionVms as $vmCheck) {
                    if (!$vmIsChecked((int) ($vmCheck['id'] ?? 0))) { $allVmsChecked = false; break; }
                }
                ?>
                <p class="hint" id="<?php echo h($vmSelectionHintId); ?>"><?php echo h(__t('deploy.vms_hint')); ?></p>
                <label class="checkbox-item"><input type="checkbox" data-vm-select-all <?php echo $allVmsChecked ? 'checked' : ''; ?>> <?php echo h(__t('deploy.vms_toggle_all')); ?></label>
                <div class="checkbox-grid">
                    <?php foreach ($missionVms as $vm) { $hasMac = !ansible_vm_needs_mac($selectedMission, $vm); ?>
                        <label class="checkbox-item">
                            <input type="checkbox" name="vm_ids[]" value="<?php echo h((string) $vm['id']); ?>" <?php echo $vmIsChecked((int) $vm['id']) ? 'checked' : ''; ?>>
                            <?php echo h((string) ($vm['vm_name'] ?? '')); ?>
                            <?php echo portal_badge($hasMac ? 'success' : 'warning', $hasMac ? __t('deploy.mac_present') : __t('deploy.mac_missing')); ?>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
        <?php if ($storageRows !== []) { ?>
            <?php $storageHintId = form_hint_id('schedule', 'storage_group'); ?>
            <div class="form-grid-full" data-storage-live role="group"<?php echo form_control_attrs('schedule', 'storage_group', null, [$storageHintId], ''); ?>>
                <span class="field-label"><?php echo h(__t('deploy.storage_heading')); ?></span>
                <?php deploy_render_storage_table($storageRows, $initialCapacity, true); ?>
                <?php if ($storageIsland !== null) { ?>
                    <script type="application/json" data-deploy-storage nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php echo json_encode($storageIsland, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
                <?php } ?>
                <p class="hint" id="<?php echo h($storageHintId); ?>"><?php echo h(__t('deploy.storage_hint')); ?></p>
            </div>
        <?php } ?>
        <?php $scheduleHintId = form_hint_id('schedule', 'schedule_group'); ?>
        <div class="field-group" role="group"<?php echo form_control_attrs('schedule', 'schedule_group', null, [$scheduleHintId], ''); ?>>
            <?php $isScheduled = deploy_form_value('start_mode', 'now') === 'scheduled'; ?>
            <div class="field-stack">
                <span class="field-label"><?php echo h(__t('deploy.schedule_heading')); ?></span>
                <label class="checkbox-item"><input type="radio" name="start_mode" value="now" data-schedule-mode <?php echo $isScheduled ? '' : 'checked'; ?>> <?php echo h(__t('deploy.schedule_now')); ?></label>
                <label class="checkbox-item"><input type="radio" name="start_mode" value="scheduled" data-schedule-mode <?php echo $isScheduled ? 'checked' : ''; ?>> <?php echo h(__t('deploy.schedule_at')); ?></label>
                <label data-schedule-at <?php echo $isScheduled ? '' : 'hidden'; ?>><?php echo h(__t('deploy.schedule_at_label')); ?>
                    <input type="datetime-local" name="scheduled_at"<?php echo form_control_attrs('schedule', 'scheduled_at'); ?> min="<?php echo h($scheduleMinLocal); ?>" value="<?php echo h(deploy_form_value('scheduled_at')); ?>">
                    <?php echo form_error_html('schedule', 'scheduled_at'); ?>
                </label>
            </div>
            <label><?php echo h(__t('deploy.stagger_label')); ?>
                <input type="number" name="stagger_minutes"<?php echo form_control_attrs('schedule', 'stagger_minutes', null, true); ?> min="<?php echo h((string) VIRTUSPHERE_DEPLOY_STAGGER_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_DEPLOY_STAGGER_MAX); ?>" value="<?php echo h(deploy_form_value('stagger_minutes')); ?>" data-stagger-input placeholder="<?php echo h(__t('deploy.stagger_placeholder')); ?>">
                <small class="hint" id="<?php echo h(form_hint_id('schedule', 'stagger_minutes')); ?>"><?php echo h(__t('deploy.stagger_hint')); ?></small>
                <?php echo form_error_html('schedule', 'stagger_minutes'); ?>
            </label>
            <p class="hint" id="<?php echo h($scheduleHintId); ?>"><?php echo h(__t('deploy.schedule_tz_hint', ['tz' => portal_timezone()])); ?></p>
        </div>
        <div class="actions actions-row"><button class="button" type="submit" data-deploy-queue-button <?php echo $canQueue ? '' : 'disabled'; ?>><?php echo h(__t('deploy.queue_button')); ?></button></div>
    </form>
</section>
