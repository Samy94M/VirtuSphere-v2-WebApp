<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/copy_control.php';
require_once __DIR__ . '/ssh_host_key_format.php';

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

/** The states in which someone has to act; the row opens by itself there (UX-HK). */
function credential_host_identity_needs_action(string $state): bool
{
    return in_array($state, ['provisional', 'unconfirmed', 'mismatch'], true);
}

/**
 * Whether the host answered with the confirmed key after the confirmation (HK-7).
 * The last observation is the last connection's, so nothing is measured here.
 * Null in every state but "confirmed".
 *
 * @return array{matching: bool, when: string}|null
 */
function credential_host_identity_contact(array $credential): ?array
{
    if (credential_host_identity_state($credential) !== 'confirmed') {
        return null;
    }
    $observedAt = (string) ($credential['ansible_host_observed_at'] ?? '');
    $confirmedAt = (string) ($credential['ansible_host_confirmed_at'] ?? '');
    $matching = $observedAt !== '' && (int) strtotime($observedAt) > (int) strtotime($confirmedAt);

    return ['matching' => $matching, 'when' => $matching ? $observedAt : ''];
}

function credential_render_host_identity(array $credential, bool $canManage = false): void
{
    $state = credential_host_identity_state($credential);
    $variant = match ($state) { 'confirmed' => 'success', 'mismatch' => 'danger', default => 'warning' };
    $fingerprint = (string) ($credential['ansible_host_fingerprint'] ?? '');
    $contact = credential_host_identity_contact($credential);
    ?>
    <div class="stack" data-host-identity>
        <span><?php echo portal_badge($variant, __t('credentials.host_identity_' . $state)); ?></span>
        <?php if ($fingerprint !== '') { ?><small><code><?php echo h((string) $credential['ansible_host_key_type'] . ' ' . $fingerprint); ?></code></small><?php } ?>
        <?php if ($state === 'mismatch' || $state === 'unconfirmed') {
            $observed = (string) ($credential['ansible_host_observed_fingerprint'] ?? '');
            if ($observed !== '') { ?><small><?php echo h(__t('credentials.host_identity_observed', ['identity' => (string) $credential['ansible_host_observed_type'] . ' ' . $observed, 'when' => portal_format_timestamp($credential['ansible_host_observed_at'] ?? '')])); ?></small><?php }
        } ?>
        <?php if (!empty($credential['ansible_host_confirmed_at'])) { ?><small><?php echo h(__t('credentials.host_identity_confirmation', ['when' => portal_format_timestamp($credential['ansible_host_confirmed_at']), 'user' => (int) ($credential['ansible_host_confirmed_by'] ?? 0)])); ?></small><?php } ?>
        <?php if ($contact !== null) { ?><small data-host-identity-contact="<?php echo $contact['matching'] ? 'matching' : 'idle'; ?>"><?php echo h($contact['matching'] ? __t('credentials.host_identity_last_matching', ['when' => portal_format_timestamp($contact['when'])]) : __t('credentials.host_identity_idle')); ?></small><?php } ?>
        <?php if ($canManage) { ?><small><a href="<?php echo h(credential_host_identity_url((int) $credential['id'])); ?>"><?php echo h(__t('credentials.host_identity_manage')); ?></a></small><?php } ?>
    </div>
    <?php
}

/**
 * The full-width row under an Ansible credential (HK-3, HK-5): the identity status,
 * then the confirmation form. It is open by itself while someone has to act, and
 * closed once the key is confirmed (HK-4), where the summary reads "Host-Schlüssel ändern".
 */
function credential_render_host_identity_row(array $credential, bool $deviationPending = false): void
{
    $id = (int) $credential['id'];
    $state = credential_host_identity_state($credential);
    $needsAction = credential_host_identity_needs_action($state);
    $open = $needsAction || $deviationPending || form_has_state(credential_host_identity_anchor($id));
    ?>
    <tr class="row-host-identity" id="<?php echo h(credential_host_identity_anchor($id)); ?>" data-host-identity-row data-host-identity-state="<?php echo h($state); ?>"<?php echo $needsAction ? ' data-host-identity-attention' : ''; ?>>
        <td colspan="8">
            <?php credential_render_host_identity($credential); ?>
            <details data-host-identity-details<?php echo $open ? ' open' : ''; ?>>
                <summary class="button button-secondary"><?php echo h(__t($state === 'confirmed' ? 'credentials.host_identity_change' : 'credentials.host_identity_button')); ?></summary>
                <?php credential_render_host_identity_form($credential, $deviationPending); ?>
            </details>
        </td>
    </tr>
    <?php
}

/**
 * The confirmation form. Its type select is offered only while no key has been
 * observed or pinned, the one case in which the type cannot come from the host.
 * After a deviating value the second step follows as its own form (never nested).
 */
function credential_render_host_identity_form(array $credential, bool $deviationPending = false): void
{
    $id = (int) $credential['id'];
    $scope = credential_host_identity_anchor($id);
    $pinnedFingerprint = (string) ($credential['ansible_host_fingerprint'] ?? '');
    $askType = $pinnedFingerprint === '' && (string) ($credential['ansible_host_observed_fingerprint'] ?? '') === '';
    $selectedType = form_old($scope, 'host_key_type', '');
    $entered = form_old($scope, 'host_fingerprint', '');
    ?>
    <form method="post" action="credentials.php" class="stack" autocomplete="off" data-host-identity-form>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="confirm_host_identity">
        <input type="hidden" name="credential_id" value="<?php echo h((string) $id); ?>">
        <input type="hidden" name="config_revision" value="<?php echo h((string) $credential['config_revision']); ?>">
        <input type="hidden" name="old_host_fingerprint" value="<?php echo h($pinnedFingerprint); ?>">
        <p class="hint"><?php echo h(__t('credentials.host_identity_hint')); ?></p>
        <p class="hint"><?php echo h(__t('credentials.host_identity_commands')); ?></p>
        <ul class="host-identity-commands">
            <?php foreach (ssh_host_key_commands() as $command) { $label = __t('credentials.host_identity_command_' . $command['family']); ?>
                <li><span><?php echo h($label); ?></span> <?php echo portal_copy_value($command['command'], $label, true); ?></li>
            <?php } ?>
        </ul>
        <p class="hint"><?php echo h(__t('credentials.host_identity_tool')); ?></p>
        <label><?php echo h(__t('credentials.host_identity_fingerprint')); ?><input name="host_fingerprint" required spellcheck="false" value="<?php echo h($entered); ?>"<?php echo form_control_attrs($scope, 'host_fingerprint'); ?>><?php echo form_error_html($scope, 'host_fingerprint'); ?></label>
        <?php if ($askType) { ?>
            <label><?php echo h(__t('credentials.host_identity_type')); ?>
                <select name="host_key_type"<?php echo form_control_attrs($scope, 'host_key_type'); ?>>
                    <option value=""><?php echo h(__t('credentials.host_identity_type_choose')); ?></option>
                    <?php foreach (SSH_HOST_KEY_PINNABLE_TYPES as $typeOption) { ?>
                        <option value="<?php echo h($typeOption); ?>"<?php echo $selectedType === $typeOption ? ' selected' : ''; ?>><?php echo h($typeOption); ?></option>
                    <?php } ?>
                </select>
                <?php echo form_error_html($scope, 'host_key_type'); ?>
            </label>
        <?php } ?>
        <div class="actions"><button class="button" type="submit" data-confirm="<?php echo h(__t('credentials.host_identity_confirm', ['name' => (string) $credential['name']])); ?>"><?php echo h(__t('credentials.host_identity_button')); ?></button></div>
    </form>
    <?php if ($deviationPending) { credential_render_host_identity_deviation($credential, $entered, $selectedType); } ?>
    <?php
}

/**
 * The second step after a deviating value (UX-HK): both values, the consequence
 * and one explicit confirmation. It is its own form and carries the refused values
 * and the pin it replaces, so a stale page cannot replace a newer identity.
 */
function credential_render_host_identity_deviation(array $credential, string $entered, string $selectedType): void
{
    $id = (int) $credential['id'];
    $pinned = (string) ($credential['ansible_host_fingerprint'] ?? '') !== '';
    $stored = $pinned
        ? (string) ($credential['ansible_host_key_type'] ?? '') . ' ' . (string) $credential['ansible_host_fingerprint']
        : (string) ($credential['ansible_host_observed_type'] ?? '') . ' ' . (string) ($credential['ansible_host_observed_fingerprint'] ?? '');
    ?>
    <div class="alert alert-warning" data-host-identity-deviation>
        <p><strong><?php echo h(__t($pinned ? 'credentials.host_identity_deviation_title_pinned' : 'credentials.host_identity_deviation_title_observed')); ?></strong></p>
        <p><?php echo h(__t($pinned ? 'credentials.host_identity_deviation_stored_pinned' : 'credentials.host_identity_deviation_stored_observed')); ?> <code><?php echo h($stored); ?></code></p>
        <p><?php echo h(__t('credentials.host_identity_deviation_entered')); ?> <code><?php echo h($entered); ?></code></p>
        <p><?php echo h(__t('credentials.host_identity_deviation_consequence')); ?></p>
        <form method="post" action="credentials.php" class="stack host-identity-replace">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="confirm_host_identity">
            <input type="hidden" name="credential_id" value="<?php echo h((string) $id); ?>">
            <input type="hidden" name="config_revision" value="<?php echo h((string) $credential['config_revision']); ?>">
            <input type="hidden" name="old_host_fingerprint" value="<?php echo h((string) ($credential['ansible_host_fingerprint'] ?? '')); ?>">
            <input type="hidden" name="host_fingerprint" value="<?php echo h($entered); ?>">
            <input type="hidden" name="host_key_type" value="<?php echo h($selectedType); ?>">
            <input type="hidden" name="replace_pin" value="1">
            <p class="hint"><?php echo h(__t('credentials.host_identity_deviation_keep')); ?></p>
            <div class="actions"><button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('credentials.host_identity_replace_confirm', ['name' => (string) $credential['name']])); ?>"><?php echo h(__t($pinned ? 'credentials.host_identity_deviation_replace' : 'credentials.host_identity_deviation_accept')); ?></button></div>
        </form>
    </div>
    <?php
}
