<?php

declare(strict_types=1);

// The read half of portal/credentials.php: the page view model and its two
// panels. Nothing here writes; the POST dispatch and the ESXi side effects live
// in lib/credentials_actions.php (ADR-0006).
//
// The moved markup keeps its original indentation on purpose. Re-indenting an
// HTML template changes the bytes it emits, and the point of a structural hunk
// is that the rendered page cannot have changed.
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/credentials_status.php';
require_once __DIR__ . '/credentials_test_message.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/integration_health.php';

/** @param array<string,mixed> $user */
function credentials_render_page(mysqli $connection, array $user): void
{

$credentials = repo_credentials($connection);
// Pointer badges only: the credentials page answers "is this account healthy",
// the System status page answers "what does this host report". One page per
// question (ADR-0023), so the detail lives there and this links to it.
$inventoryIntervalHours = esxi_inventory_interval_hours($connection);
// Global, so it is resolved once: without a usable Ansible host the scheduler
// has nothing to run the pull over and enqueues nothing for any credential.
$ansibleHostSelected = esxi_inventory_ansible_resolution($connection)['credential_id'] !== null;
// The cadence line on this page and the one on System status must name the same
// blocker, so both read the same fourth input: without a live deploy worker the
// inventory pull is enqueued and never executed.
$deployWorkerAlive = integration_deploy_worker_alive_now($connection);
// One clock for the whole table, like the System status snapshot: both badges
// are age-derived, so two rows recorded at the same instant must not land on
// opposite sides of a threshold because their time() calls differed by a second.
$renderedAt = time();
$esxiStates = repo_esxi_inventory_states($connection);
$ansiblePreflightStates = repo_ansible_preflight_states($connection);
layout_header(__t('credentials.title'), $user, 'credentials', 'credentials');
?>
<div class="stack">
    <section class="panel">
        <h2><?php echo h(__t('credentials.create_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('credentials.scope_hint')); ?></p>
        <p class="muted"><?php echo h(__t('credentials.mecm_scope_hint')); ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('credentials.mecm_scope_link')); ?></a></p>
        <p class="muted"><?php echo h(__t('credentials.ansible_scope_hint')); ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_DEPLOY)); ?>"><?php echo h(__t('credentials.ansible_scope_link')); ?></a></p>
        <p class="muted"><?php echo h(__t('credentials.trust_why')); ?></p>
        <p class="muted"><?php echo h(__t('credentials.trust_what')); ?></p>
        <p class="muted"><?php echo h(__t('credentials.trust_how')); ?></p>
        <form class="form-grid" method="post" action="credentials.php" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <label><?php echo h(__t('credentials.label_type')); ?>
                <select name="type" required>
                    <?php $createType = form_old('create', 'type', VIRTUSPHERE_CREDENTIAL_TYPE_ESXI); ?>
                    <?php foreach (VIRTUSPHERE_CREDENTIAL_LABELS as $typeValue => $typeLabel) { ?>
                        <option value="<?php echo h($typeValue); ?>" <?php echo $createType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
                    <?php } ?>
                </select>
                <?php echo form_error_html('create', 'type'); ?>
            </label>
            <label><?php echo h(__t('common.name')); ?><input name="name" value="<?php echo h(form_old('create', 'name')); ?>"<?php echo form_input_class('create', 'name'); ?> required><?php echo form_error_html('create', 'name'); ?></label>
            <label><?php echo h(__t('credentials.label_host')); ?><input name="host" value="<?php echo h(form_old('create', 'host')); ?>"<?php echo form_input_class('create', 'host'); ?> required placeholder="<?php echo h(__t('credentials.host_placeholder')); ?>"><?php echo form_error_html('create', 'host'); ?></label>
            <label><?php echo h(__t('credentials.label_port')); ?><input name="port" type="number" min="1" max="65535" value="<?php echo h(form_old('create', 'port')); ?>"<?php echo form_input_class('create', 'port'); ?> placeholder="<?php echo h(__t('credentials.port_placeholder')); ?>"><?php echo form_error_html('create', 'port'); ?></label>
            <label><?php echo h(__t('credentials.label_username')); ?><input name="username" value="<?php echo h(form_old('create', 'username')); ?>"<?php echo form_input_class('create', 'username'); ?> required autocomplete="off"><?php echo form_error_html('create', 'username'); ?></label>
            <label><?php echo h(__t('credentials.label_secret')); ?><input name="secret" type="password" required autocomplete="new-password"><?php echo form_error_html('create', 'secret'); ?></label>
            <label><?php echo h(__t('credentials.label_cert_kind')); ?>
                <select name="esxi_cert_kind">
                    <option value="<?php echo h(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE); ?>"><?php echo h(__t('credentials.cert_kind_ca')); ?></option>
                    <option value="<?php echo h(VIRTUSPHERE_ESXI_CERT_SERVER); ?>"><?php echo h(__t('credentials.cert_kind_server')); ?></option>
                </select>
                <?php echo form_error_html('create', 'esxi_cert_kind'); ?>
            </label>
            <label><?php echo h(__t('credentials.label_certificate')); ?><textarea name="esxi_certificate_pem" rows="6" placeholder="-----BEGIN CERTIFICATE-----"><?php echo h(form_old('create', 'esxi_certificate_pem')); ?></textarea><?php echo form_error_html('create', 'esxi_certificate_pem'); ?></label>
            <p class="muted"><?php echo h(__t('credentials.new_strict_hint')); ?></p>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.create')); ?></button></div>
        </form>
    </section>

    <section class="panel">
        <h2><?php echo h(__t('credentials.stored_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('credentials.test_hint')); ?> <a href="system_status.php"><?php echo h(__t('credentials.test_hint_link')); ?></a></p>
        <p class="muted"><?php echo h(__t('credentials.test_checks')); ?></p>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('credentials.label_type')); ?></th><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('credentials.label_host')); ?></th><th><?php echo h(__t('credentials.label_port')); ?></th><th><?php echo h(__t('credentials.label_username')); ?></th><th><?php echo h(__t('credentials.th_status')); ?></th><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($credentials as $row) {
                $rowId = (int) $row['id'];
                $rowKey = 'row-' . $rowId;
                $rowType = form_old($rowKey, 'type', (string) ($row['type'] ?? ''));
                $editorId = 'credential-editor-' . $rowId;
                // A failed save reopens exactly the editor that was submitted, so
                // the field errors and sticky values are visible without a click.
                $editorOpen = form_has_state($rowKey);
                $isEsxi = (string) ($row['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
                $isAnsible = (string) ($row['type'] ?? '') === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
                $strictProbeReady = $isEsxi
                    && credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE
                    && trim((string) ($row['esxi_certificate_pem'] ?? '')) !== '';
            ?>
                <tr>
                    <td><?php echo h(credential_type_label((string) ($row['type'] ?? ''))); ?></td>
                    <td><?php echo h((string) ($row['name'] ?? '')); ?></td>
                    <td><?php echo h((string) ($row['host'] ?? '')); ?></td>
                    <td><?php echo ($row['port'] ?? null) !== null && (string) $row['port'] !== '' ? h((string) $row['port']) : '<span class="muted">&mdash;</span>'; ?></td>
                    <td><?php echo h((string) ($row['username'] ?? '')); ?></td>
                    <td class="status-cell">
                        <?php if ($isEsxi) {
                            $esxiState = $esxiStates[$rowId] ?? null;
                            $esxiAmpel = esxi_credential_state($esxiState, $inventoryIntervalHours, $renderedAt);
                            ?>
                            <a href="<?php echo h(system_status_url('credential-' . $rowId, ['inventory' => $rowId])); ?>" title="<?php echo h(__t('credentials.esxi_state_link_title')); ?>"><?php echo esxi_state_badge($esxiAmpel); ?></a>
                            <small class="status-time"><?php echo $esxiState !== null && !empty($esxiState['last_attempt_at']) ? h(portal_format_timestamp($esxiState['last_attempt_at'])) : h(__t('credentials.status_never')); ?></small>
                            <small class="status-cadence"><?php echo h(credential_cadence_esxi($inventoryIntervalHours, $esxiState, $ansibleHostSelected)); ?></small>
                            <?php if (credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_STRICT) { ?>
                                <small class="status-trust"><?php echo portal_badge('info', __t('credentials.trust_strict')); ?></small>
                            <?php } else { ?>
                                <small class="status-trust"><?php echo portal_badge('warning', __t('credentials.trust_legacy')); ?></small>
                            <?php } ?>
                        <?php } elseif ($isAnsible) {
                            $pfState = $ansiblePreflightStates[$rowId] ?? null;
                            $pfTitle = $pfState !== null && !empty($pfState['last_checked_at'])
                                ? __t('credentials.ansible_state_link_title', ['when' => portal_format_timestamp((string) $pfState['last_checked_at'])])
                                : __t('credentials.ansible_state_untested_title');
                            ?>
                            <a href="<?php echo h(system_status_url('credential-' . $rowId)); ?>" title="<?php echo h($pfTitle); ?>"><?php echo ansible_preflight_badge($pfState, $renderedAt); ?></a>
                            <small class="status-time"><?php echo $pfState !== null && !empty($pfState['last_checked_at']) ? h(portal_format_timestamp($pfState['last_checked_at'])) : h(__t('credentials.status_never')); ?></small>
                            <small class="status-cadence"><?php echo h(credential_cadence_ansible()); ?></small>
                        <?php } else { ?>
                            <span class="muted">&mdash;</span>
                        <?php } ?>
                    </td>
                    <td class="nowrap"><?php echo h(portal_format_timestamp($row['updated_at'] ?? '')); ?></td>
                    <td class="actions">
                        <button class="button button-secondary" type="button" data-row-toggle="<?php echo h($editorId); ?>" aria-expanded="<?php echo $editorOpen ? 'true' : 'false'; ?>"><?php echo h(__t('common.edit')); ?></button>
                        <form class="inline-form" method="post" action="credentials.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <?php // action in a hidden field, not on the button: the busy handler
                                  // disables the button on submit, which would drop a button-borne
                                  // name/value from the POST. ?>
                            <input type="hidden" name="action" value="test">
                            <button class="button button-secondary" type="submit" data-busy-label="<?php echo h($isEsxi ? __t('credentials.btn_inventory_busy') : __t('credentials.btn_testing')); ?>"><?php echo h($strictProbeReady ? __t('credentials.btn_test_strict') : ($isEsxi ? __t('credentials.btn_inventory') : __t('credentials.btn_test_ansible'))); ?></button>
                        </form>
                        <?php if ($isEsxi && credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE && !empty($row['esxi_strict_tested_at'])) { ?>
                            <form class="inline-form" method="post" action="credentials.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                                <button class="button" type="submit" name="action" value="activate_strict" data-confirm="<?php echo h(__t('credentials.confirm_activate_strict', ['name' => (string) ($row['name'] ?? '')])); ?>"><?php echo h(__t('credentials.btn_activate_strict')); ?></button>
                            </form>
                        <?php } elseif ($isEsxi && credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_STRICT) { ?>
                            <form class="inline-form" method="post" action="credentials.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                                <button class="button button-secondary" type="submit" name="action" value="use_legacy" data-confirm="<?php echo h(__t('credentials.confirm_use_legacy', ['name' => (string) ($row['name'] ?? '')])); ?>"><?php echo h(__t('credentials.btn_use_legacy')); ?></button>
                            </form>
                        <?php } ?>
                        <form class="inline-form" method="post" action="credentials.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <button class="button button-danger" type="submit" name="action" value="delete" data-confirm="<?php echo h(__t('credentials.confirm_delete', ['name' => (string) ($row['name'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                        </form>
                    </td>
                </tr>
                <tr class="row-editor" id="<?php echo h($editorId); ?>"<?php echo $editorOpen ? '' : ' hidden'; ?>>
                    <td colspan="8">
                        <form class="form-grid" method="post" action="credentials.php" autocomplete="off">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $rowId); ?>">
                            <label><?php echo h(__t('credentials.label_type')); ?>
                                <select name="type" required>
                                    <?php foreach (VIRTUSPHERE_CREDENTIAL_LABELS as $typeValue => $typeLabel) { ?>
                                        <option value="<?php echo h($typeValue); ?>" <?php echo $rowType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
                                    <?php } ?>
                                </select>
                                <?php echo form_error_html($rowKey, 'type'); ?>
                            </label>
                            <label><?php echo h(__t('common.name')); ?><input name="name" value="<?php echo h(form_old($rowKey, 'name', (string) ($row['name'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'name'); ?> required><?php echo form_error_html($rowKey, 'name'); ?></label>
                            <label><?php echo h(__t('credentials.label_host')); ?><input name="host" value="<?php echo h(form_old($rowKey, 'host', (string) ($row['host'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'host'); ?> required><?php echo form_error_html($rowKey, 'host'); ?></label>
                            <label><?php echo h(__t('credentials.label_port')); ?><input name="port" type="number" min="1" max="65535" value="<?php echo h(form_old($rowKey, 'port', (string) ($row['port'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'port'); ?>><?php echo form_error_html($rowKey, 'port'); ?></label>
                            <label><?php echo h(__t('credentials.label_username')); ?><input name="username" value="<?php echo h(form_old($rowKey, 'username', (string) ($row['username'] ?? ''))); ?>"<?php echo form_input_class($rowKey, 'username'); ?> required autocomplete="off"><?php echo form_error_html($rowKey, 'username'); ?></label>
                            <label><?php echo h(__t('credentials.label_new_secret')); ?><input name="secret" type="password" placeholder="<?php echo h(__t('credentials.secret_keep_placeholder')); ?>"<?php echo form_input_class($rowKey, 'secret'); ?> autocomplete="new-password"><?php echo form_error_html($rowKey, 'secret'); ?></label>
                            <?php if ($isEsxi) { ?>
                                <?php $certKind = form_old($rowKey, 'esxi_cert_kind', (string) ($row['esxi_cert_kind'] ?? VIRTUSPHERE_ESXI_CERT_CA_BUNDLE)); ?>
                                <label><?php echo h(__t('credentials.label_cert_kind')); ?>
                                    <select name="esxi_cert_kind">
                                        <option value="<?php echo h(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE); ?>" <?php echo $certKind === VIRTUSPHERE_ESXI_CERT_CA_BUNDLE ? 'selected' : ''; ?>><?php echo h(__t('credentials.cert_kind_ca')); ?></option>
                                        <option value="<?php echo h(VIRTUSPHERE_ESXI_CERT_SERVER); ?>" <?php echo $certKind === VIRTUSPHERE_ESXI_CERT_SERVER ? 'selected' : ''; ?>><?php echo h(__t('credentials.cert_kind_server')); ?></option>
                                    </select>
                                    <?php echo form_error_html($rowKey, 'esxi_cert_kind'); ?>
                                </label>
                                <label><?php echo h(__t('credentials.label_certificate')); ?><textarea name="esxi_certificate_pem" rows="6" placeholder="-----BEGIN CERTIFICATE-----"><?php echo h(form_old($rowKey, 'esxi_certificate_pem', (string) ($row['esxi_certificate_pem'] ?? ''))); ?></textarea><?php echo form_error_html($rowKey, 'esxi_certificate_pem'); ?></label>
                                <p class="muted"><?php echo h(__t(credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE ? 'credentials.legacy_upgrade_hint' : 'credentials.strict_active_hint')); ?></p>
                            <?php } ?>
                            <div class="actions">
                                <button class="button" type="submit"><?php echo h(__t('common.save')); ?></button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($credentials === []) { ?><tr><td colspan="8"><?php echo h(__t('credentials.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
<?php
}
