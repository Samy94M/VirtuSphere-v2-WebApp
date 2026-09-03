<?php

declare(strict_types=1);

/**
 * The identity fields of a VM in the editor: the MECM ResourceID it is bound to,
 * the ESXi/portal name, and the Windows hostname (Etappe 14D, ADR-0043).
 *
 * They are one module because they answer one question together, and because
 * getting that question wrong is the defect this stage exists to fix: two name
 * fields sat next to each other labelled "Name" and "Hostname", and which of
 * them MECM receives was not guessable from either. The block also owns the two
 * things that only make sense next to those fields, the grandfathered-hostname
 * warning and the current-versus-next rollout pair.
 *
 * Loaded through the lib/vm_edit_form.php facade, like vm_edit_values.php,
 * vm_edit_rows.php and vm_edit_status.php.
 */

require_once __DIR__ . '/mecm_rollout_display.php';

/**
 * Renders the three identity controls into the VM editor's form grid.
 *
 * @param array<string, mixed> $vm the stored VM bundle (empty for a new VM)
 * @param array<string, string> $fieldErrors sticky field errors of a failed save
 */
function vm_edit_identity_fields(array $vm, int $vmId, bool $isTemplate, bool $canWrite, array $fieldErrors): void
{
    // Legacy warning (E2): stored hostnames that violate the NetBIOS rule are
    // grandfathered but visibly flagged with the name the MECM client phase
    // would actually produce.
    $storedHostnameValue = (string) ($vm['vm_hostname'] ?? '');
    $hostnameLegacyInvalid = $storedHostnameValue !== '' && !mecm_hostname_is_rollout_valid($storedHostnameValue);
    $hostnameClientPreview = $hostnameLegacyInvalid
        ? (string) preg_replace('/[^A-Za-z0-9-]/', '', substr($storedHostnameValue, 0, VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH))
        : '';
    $hostnameFieldError = (string) ($fieldErrors['vm_hostname'] ?? '');
    $hostnameLegacyWarning = $hostnameLegacyInvalid
        ? __t('vm_edit.hostname_legacy_warning', ['preview' => $hostnameClientPreview])
        : '';
    $hostnameEffectiveError = $hostnameFieldError !== '' ? $hostnameFieldError : $hostnameLegacyWarning;

    // Current versus next rollout name (Etappe 14D).
    //
    // Deliberately ONLY while the two diverge: as long as the desired value and
    // the snapshot name the same machine, the compact display is the truth and a
    // second read-only line would be noise. When it appears it answers the one
    // question the page otherwise does not: this rollout will NOT pick the
    // correction up.
    $showRolloutDivergence = $vmId > 0 && !$isTemplate && mecm_rollout_shows_divergence($vm);
    $mecmIdValue = (string) ($vm['mecm_id'] ?? '');
    ?>
    <?php if ($vmId > 0 && !$isTemplate) { ?>
        <label><?php echo h(__t('vm_edit.diagnostics_mecm_id')); ?><input value="<?php echo h($mecmIdValue !== '' ? $mecmIdValue : __t('vm_edit.mecm_id_none')); ?>" readonly></label>
    <?php } ?>
    <label><?php echo h(__t('vm_edit.label_vm_name')); ?><input name="vm_name"<?php echo form_control_attrs('vm_edit', 'vm_name', null, false, (string) ($fieldErrors['vm_name'] ?? '')); ?> maxlength="16" value="<?php echo h($vm['vm_name'] ?? ''); ?>" required <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_name'); ?></label>
    <?php // The group exists only in the divergence case. It is the established
          // way to put an explanation under ITS field (a full grid row would
          // start at the far left, columns away from the hostname), and
          // rendering it always would rebuild the grid in the normal case too. ?>
    <?php if ($showRolloutDivergence) { ?><div class="field-group"><?php } ?>
    <label><?php echo h(__t('vm_edit.label_hostname')); ?><input name="vm_hostname"<?php echo form_control_attrs('vm_edit', 'vm_hostname', null, false, $hostnameEffectiveError); ?> maxlength="<?php echo h((string) VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH); ?>" value="<?php echo h($vm['vm_hostname'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>><?php echo vm_field_error($fieldErrors, 'vm_hostname'); ?>
        <?php if ($hostnameLegacyInvalid && $hostnameFieldError === '') { ?>
            <span class="field-error" id="<?php echo h(form_error_id('vm_edit', 'vm_hostname')); ?>"><?php echo h($hostnameLegacyWarning); ?></span>
        <?php } ?>
    </label>
    <?php // Read-only and without a form control: a hidden input would be a
          // second desired value, and the snapshot belongs to the reset alone. ?>
    <?php if ($showRolloutDivergence) { ?>
        <div class="alert alert-info">
            <p><strong><?php echo h(__t('portal.vm_rollout_current_label')); ?>:</strong> <?php echo h((string) $vm['mecm_rollout_hostname']); ?>
               &middot; <strong><?php echo h(__t('portal.vm_rollout_next_label')); ?>:</strong> <?php echo h((string) ($vm['vm_hostname'] ?? '')); ?></p>
            <p><?php echo h(__t('portal.vm_rollout_frozen_hint', ['current' => (string) $vm['mecm_rollout_hostname']])); ?></p>
        </div>
        </div>
    <?php } ?>
    <?php // The one step a reset leaves to a person. It is a state and not a
          // refusal: VirtuSphere deletes nothing in MECM, so this stays true
          // until somebody acts on it, and it has to be visible without
          // clicking anything. The device sync is fail-closed on the same fact.
          //
          // A full-width row rather than a field group: it belongs to the VM,
          // not to one control, and it names a ResourceID nothing else shows. ?>
    <?php if ($vmId > 0 && !$isTemplate && mecm_rollout_awaits_device_deletion($vm)) { ?>
        <div class="field-group">
            <div class="alert alert-warning"><?php echo h(__t('portal.vm_mecm_reset_previous_device', ['resource_id' => (string) $vm['mecm_previous_id']])); ?></div>
        </div>
    <?php } ?>
    <?php
}
