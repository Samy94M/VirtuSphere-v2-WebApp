<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/forms.php';

/** Presentation reads observations; it never establishes trust. */
function credential_host_identity_state(array $credential): string
{
    $fingerprint = (string) ($credential['ansible_host_fingerprint'] ?? '');
    $observed = (string) ($credential['ansible_host_observed_fingerprint'] ?? '');
    if ($fingerprint !== '' && $observed !== '' && (!hash_equals($fingerprint, $observed)
        || (string) ($credential['ansible_host_key_type'] ?? '') !== (string) ($credential['ansible_host_observed_type'] ?? ''))) {
        return 'mismatch';
    }
    if ($fingerprint === '') {
        return (int) ($credential['ansible_host_accept_new'] ?? 0) === 1 ? 'upgrade_pending' : 'unconfirmed';
    }
    return empty($credential['ansible_host_confirmed_at']) ? 'provisional' : 'confirmed';
}

function credential_render_host_identity(array $credential, bool $canManage = false): void
{
    $state = credential_host_identity_state($credential);
    $variant = match ($state) { 'confirmed' => 'success', 'mismatch' => 'danger', default => 'warning' };
    $fingerprint = (string) ($credential['ansible_host_fingerprint'] ?? '');
    ?>
    <div class="stack" data-host-identity>
        <span><?php echo portal_badge($variant, __t('credentials.host_identity_' . $state)); ?></span>
        <?php if ($fingerprint !== '') { ?><small><code><?php echo h((string) $credential['ansible_host_key_type'] . ' ' . $fingerprint); ?></code></small><?php } ?>
        <?php if ($state === 'mismatch' || $state === 'unconfirmed') {
            $observed = (string) ($credential['ansible_host_observed_fingerprint'] ?? '');
            if ($observed !== '') { ?><small><?php echo h(__t('credentials.host_identity_observed', ['identity' => (string) $credential['ansible_host_observed_type'] . ' ' . $observed, 'when' => portal_format_timestamp($credential['ansible_host_observed_at'] ?? '')])); ?></small><?php }
        } ?>
        <?php if (!empty($credential['ansible_host_confirmed_at'])) { ?><small><?php echo h(__t('credentials.host_identity_confirmation', ['when' => portal_format_timestamp($credential['ansible_host_confirmed_at']), 'user' => (int) ($credential['ansible_host_confirmed_by'] ?? 0)])); ?></small><?php } ?>
        <?php if ($canManage) { ?><small><a href="credentials.php"><?php echo h(__t('credentials.host_identity_manage')); ?></a></small><?php } ?>
    </div>
    <?php
}

function credential_render_host_identity_form(array $credential): void
{
    $id = (int) $credential['id'];
    $scope = 'host-identity-' . $id;
    ?>
    <form method="post" action="credentials.php" class="stack">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="confirm_host_identity">
        <input type="hidden" name="credential_id" value="<?php echo h((string) $id); ?>">
        <input type="hidden" name="config_revision" value="<?php echo h((string) $credential['config_revision']); ?>">
        <input type="hidden" name="old_host_fingerprint" value="<?php echo h((string) ($credential['ansible_host_fingerprint'] ?? '')); ?>">
        <p class="hint"><?php echo h(__t('credentials.host_identity_hint')); ?></p>
        <label><?php echo h(__t('credentials.host_identity_fingerprint')); ?><input name="host_fingerprint" required value="<?php echo h(form_old($scope, 'host_fingerprint', '')); ?>"<?php echo form_control_attrs($scope, 'host_fingerprint'); ?>><?php echo form_error_html($scope, 'host_fingerprint'); ?></label>
        <label><?php echo h(__t('credentials.host_identity_type')); ?><input name="host_key_type" required value="<?php echo h(form_old($scope, 'host_key_type', '')); ?>"<?php echo form_control_attrs($scope, 'host_key_type'); ?>><?php echo form_error_html($scope, 'host_key_type'); ?></label>
        <div class="actions"><button class="button" type="submit" data-confirm="<?php echo h(__t('credentials.host_identity_confirm', ['name' => (string) $credential['name']])); ?>"><?php echo h(__t('credentials.host_identity_button')); ?></button></div>
    </form>
    <?php
}
