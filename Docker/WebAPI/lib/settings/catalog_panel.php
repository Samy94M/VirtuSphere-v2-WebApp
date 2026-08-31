<?php

declare(strict_types=1);

/** @var string $retireThreshold */
/** @var string $esxiIntervalHours */
/** @var array{state:string,credential_id:?int,configured_id:int,credentials:array<int,array<string,mixed>>} $esxiAnsibleResolution */
/** @var string $esxiSelectedAnsible */

?>
    <div class="stack" id="panel-catalog" role="tabpanel" aria-labelledby="tab-catalog" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.retire_threshold_title')); ?></h2>
            <p class="muted" id="<?php echo h(form_hint_id('retire', 'retire_threshold')); ?>"><?php echo h(__t('settings.retire_threshold_hint', [
                'default' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT,
                'min' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN,
                'max' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX,
            ])); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_retire_threshold">
                <label><?php echo h(__t('settings.retire_threshold_label')); ?>
                    <input name="retire_threshold" type="number" min="<?php echo h((string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX); ?>" value="<?php echo h(form_old('retire', 'retire_threshold', $retireThreshold)); ?>"<?php echo form_control_attrs('retire', 'retire_threshold', null, true); ?>>
                    <?php echo form_error_html('retire', 'retire_threshold'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.esxi_title')); ?></h2>
            <?php $esxiHintId = form_hint_id('esxi', 'inventory_group'); ?>
            <p class="muted" id="<?php echo h($esxiHintId); ?>"><?php echo h(__t('settings.esxi_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php"<?php echo form_control_attrs('esxi', 'inventory_group', null, [$esxiHintId], ''); ?> role="group" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_esxi_inventory">
                <label><?php echo h(__t('settings.esxi_interval_label')); ?>
                    <input name="esxi_inventory_interval_hours" type="number" min="<?php echo h((string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX); ?>" value="<?php echo h(form_old('esxi', 'esxi_inventory_interval_hours', $esxiIntervalHours)); ?>"<?php echo form_control_attrs('esxi', 'esxi_inventory_interval_hours'); ?>>
                    <?php echo form_error_html('esxi', 'esxi_inventory_interval_hours'); ?>
                </label>
                <?php if ($esxiAnsibleResolution['state'] === 'none') { ?>
                    <div class="alert alert-warning form-grid-full"><?php echo h(__t('settings.esxi_ansible_none')); ?> <a href="credentials.php"><?php echo h(__t('settings.esxi_ansible_manage')); ?></a></div>
                <?php } elseif ($esxiAnsibleResolution['state'] === 'automatic') { ?>
                    <div class="form-grid-full">
                        <strong><?php echo h(__t('settings.esxi_ansible_label')); ?></strong>
                        <p><?php echo portal_badge('info', __t('settings.esxi_ansible_automatic')); ?> <?php echo h((string) $esxiAnsibleResolution['credentials'][0]['name']); ?></p>
                    </div>
                <?php } else { ?>
                    <label><?php echo h(__t('settings.esxi_ansible_label')); ?>
                        <select name="esxi_inventory_ansible_credential_id"<?php echo form_control_attrs('esxi', 'esxi_inventory_ansible_credential_id'); ?> required>
                            <option value=""><?php echo h(__t('settings.esxi_ansible_choose')); ?></option>
                            <?php foreach ($esxiAnsibleResolution['credentials'] as $ansibleCredential) { ?>
                                <option value="<?php echo h((string) $ansibleCredential['id']); ?>"<?php echo (string) $ansibleCredential['id'] === $esxiSelectedAnsible ? ' selected' : ''; ?>><?php echo h((string) $ansibleCredential['name']); ?> — <?php echo h((string) $ansibleCredential['host']); ?></option>
                            <?php } ?>
                        </select>
                        <?php echo form_error_html('esxi', 'esxi_inventory_ansible_credential_id'); ?>
                    </label>
                <?php } ?>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
            <p class="muted"><?php echo h(__t('settings.esxi_ansible_note')); ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('settings.esxi_status_link')); ?></a></p>
        </section>
    </div>
