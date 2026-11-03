<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $allowlistEntries */
/** @var string $reportTokenOnce */
/** @var bool $reportTokenSet */

require_once __DIR__ . '/../machine_api_roles.php';

?>
    <div class="stack" id="panel-machine-api" role="tabpanel" aria-labelledby="tab-machine-api" tabindex="0" data-tab-panel hidden>
        <?php
        // The machine API is inbound only: MECM, Ansible and the deploy clients
        // call the portal. The portal never connects out to the MECM server, so
        // there is no MECM host or port here. Provider, site code and the report
        // interval are configured in the Windows installer's registry.
        ?>
        <div class="alert alert-info"><?php echo h(__t('settings.machine_api_directions')); ?></div>
        <section class="panel">
            <h2><?php echo h(__t('settings.allowlist_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.allowlist_hint')); ?></p>
            <?php
            // An empty allowlist is a total outage, not an unset option: every
            // machine endpoint answers 403, so no sync and no MAC upload work.
            // It used to render as a grey "no entries" row that reads like a
            // feature nobody switched on.
            if ($allowlistEntries === []) { ?>
                <div class="alert alert-warning"><?php echo h(__t('settings.allowlist_empty_warning')); ?></div>
            <?php } ?>
            <?php
            // K20: entries without a role still open every machine endpoint.
            $allowlistWithoutRole = count(array_filter($allowlistEntries, static fn (array $entry): bool => $entry['role'] === null));
            if ($allowlistWithoutRole > 0) { ?>
                <div class="alert alert-warning" data-allowlist-without-role><?php echo h(__t('settings.allowlist_without_role_warning', ['count' => $allowlistWithoutRole])); ?></div>
            <?php } ?>
            <div class="table-wrap" tabindex="0">
                <table>
                    <thead><tr><th><?php echo h(__t('settings.allowlist_th_ip')); ?></th><th><?php echo h(__t('settings.allowlist_th_role')); ?></th><th><?php echo h(__t('settings.allowlist_th_description')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($allowlistEntries as $entry) { ?>
                        <tr>
                            <td><code><?php echo h((string) $entry['ipAddress']); ?></code></td>
                            <td><?php echo $entry['role'] === null
                                ? portal_badge('warning', __t('settings.allowlist_role_none'))
                                : h(__t('settings.allowlist_role_' . (string) $entry['role'])); ?></td>
                            <td><?php echo h((string) ($entry['description'] ?? '')); ?></td>
                            <td class="actions">
                                <form class="inline-form" method="post" action="settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="allow_set_role">
                                    <input type="hidden" name="id" value="<?php echo h((string) $entry['id']); ?>">
                                    <select name="role" aria-label="<?php echo h(__t('settings.allowlist_th_role') . ' ' . (string) $entry['ipAddress']); ?>">
                                        <?php
                                        // K20-A1: without a role no option is picked, and a browser would send the
                                        // first role on "Set role". The empty choice is preselected instead, and the
                                        // handler refuses it with allowlist_role_required.
                                        if ($entry['role'] === null) { ?>
                                            <option value="" selected><?php echo h(__t('settings.allowlist_role_select')); ?></option>
                                        <?php } ?>
                                        <?php foreach (VIRTUSPHERE_MACHINE_API_ROLES as $role) { ?>
                                            <option value="<?php echo h($role); ?>" <?php echo $entry['role'] === $role ? 'selected' : ''; ?>><?php echo h(__t('settings.allowlist_role_' . $role)); ?></option>
                                        <?php } ?>
                                    </select>
                                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('settings.allowlist_confirm_role', ['name' => (string) ($entry['ipAddress'] ?? '')])); ?>"><?php echo h(__t('settings.allowlist_set_role')); ?></button>
                                </form>
                                <form method="post" action="settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="allow_delete">
                                    <input type="hidden" name="id" value="<?php echo h((string) $entry['id']); ?>">
                                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('settings.allowlist_confirm_delete', ['name' => (string) ($entry['ipAddress'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if ($allowlistEntries === []) { ?>
                        <tr><td colspan="4"><?php echo h(__t('settings.allowlist_empty')); ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="allow_create">
                <label><?php echo h(__t('settings.allowlist_th_ip')); ?>
                    <input name="ip_address" value="<?php echo h(form_old('allowlist', 'ip_address', '')); ?>"<?php echo form_control_attrs('allowlist', 'ip_address'); ?> placeholder="10.0.0.10" required>
                    <?php echo form_error_html('allowlist', 'ip_address'); ?>
                </label>
                <label><?php echo h(__t('settings.allowlist_th_role')); ?>
                    <select name="role"<?php echo form_control_attrs('allowlist', 'role'); ?>>
                        <option value=""><?php echo h(__t('settings.allowlist_role_select')); ?></option>
                        <?php foreach (VIRTUSPHERE_MACHINE_API_ROLES as $role) { ?>
                            <option value="<?php echo h($role); ?>" <?php echo form_old('allowlist', 'role', '') === $role ? 'selected' : ''; ?>><?php echo h(__t('settings.allowlist_role_' . $role)); ?></option>
                        <?php } ?>
                    </select>
                    <?php echo form_error_html('allowlist', 'role'); ?>
                </label>
                <label><?php echo h(__t('settings.allowlist_th_description')); ?>
                    <input name="description" value="<?php echo h(form_old('allowlist', 'description', '')); ?>"<?php echo form_control_attrs('allowlist', 'description'); ?> maxlength="255" placeholder="<?php echo h(__t('settings.allowlist_description_placeholder')); ?>">
                    <?php echo form_error_html('allowlist', 'description'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('settings.allowlist_add')); ?></button></div>
            </form>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.report_token_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.report_token_hint')); ?></p>
            <?php if ($reportTokenOnce !== '') { ?>
                <div class="alert alert-info">
                    <strong><?php echo h(__t('settings.report_token_once')); ?></strong>
                    <p><code><?php echo h($reportTokenOnce); ?></code></p>
                </div>
            <?php } ?>
            <p>
                <?php echo h(__t('settings.report_token_status')); ?>
                <?php echo portal_badge($reportTokenSet ? 'success' : 'neutral', $reportTokenSet ? __t('settings.report_token_set') : __t('settings.report_token_unset')); ?>
            </p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_token">
                    <?php
                    // Generating the first token is harmless. Generating over an existing one
                    // invalidates the token deployed in the MECM server's registry, so that
                    // branch asks first. The attribute is omitted, never rendered empty: the
                    // [data-confirm] selector matches a blank value too.
                    ?>
                    <button class="button" type="submit"<?php echo $reportTokenSet ? ' data-confirm="' . h(__t('settings.report_token_confirm_regenerate')) . '"' : ''; ?>><?php echo h($reportTokenSet ? __t('settings.report_token_regenerate') : __t('settings.report_token_generate')); ?></button>
                </form>
                <?php if ($reportTokenSet) { ?>
                    <form method="post" action="settings.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_token">
                        <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('settings.report_token_confirm_clear')); ?>"><?php echo h(__t('settings.report_token_clear')); ?></button>
                    </form>
                <?php } ?>
            </div>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.machine_api_status_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.machine_api_status_hint')); ?></p>
            <div class="actions"><a class="button button-secondary" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM)); ?>"><?php echo h(__t('settings.machine_api_status_link')); ?></a></div>
        </section>
    </div>
