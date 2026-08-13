<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/users_page.php';

/** @param list<array<string,mixed>> $rows */
function users_render_accounts(array $rows, array $actor): void
{
    $actionUrl = users_url(VIRTUSPHERE_USERS_VIEW_ACCOUNTS);
    ?>
    <section class="panel">
        <h2><?php echo h(__t('users.create_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('users.create_local_hint')); ?></p>
        <form class="form-grid" method="post" action="<?php echo h($actionUrl); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <label><?php echo h(__t('common.name')); ?><input name="name" value="<?php echo h(form_old('create', 'name')); ?>"<?php echo form_input_class('create', 'name'); ?> required><?php echo form_error_html('create', 'name'); ?></label>
            <label><?php echo h(__t('users.field_email')); ?><input name="email" type="email" value="<?php echo h(form_old('create', 'email')); ?>"<?php echo form_input_class('create', 'email'); ?>><?php echo form_error_html('create', 'email'); ?></label>
            <label><?php echo h(__t('users.field_password')); ?><input name="password" type="password" required><?php echo form_error_html('create', 'password'); ?></label>
            <label><?php echo h(__t('users.field_role')); ?><select name="role"><?php $createRole = form_old('create', 'role', VIRTUSPHERE_ROLE_USER); foreach (role_options() as $roleOption) { ?><option value="<?php echo h($roleOption); ?>" <?php echo $createRole === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option><?php } ?></select></label>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.create')); ?></button></div>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('users.th_id')); ?></th><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('users.th_source')); ?></th><th><?php echo h(__t('users.field_email')); ?></th><th><?php echo h(__t('users.field_role')); ?></th><th><?php echo h(__t('users.th_active')); ?></th><th><?php echo h(__t('users.th_must_change')); ?></th><th><?php echo h(__t('users.th_last_seen')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) {
                $source = (string) ($row['auth_source'] ?? VIRTUSPHERE_AUTH_SOURCE_LOCAL);
                $isDirectory = $source === VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
                $lockedUntil = (string) ($row['locked_until'] ?? '');
                $isLocked = !$isDirectory && $lockedUntil !== '' && strtotime($lockedUntil) > time();
                $rowName = (string) $row['name'];
                $isSelf = (int) $row['id'] === (int) $actor['id'];
                $isActive = (int) $row['is_active'] === 1;
                $roleConfirm = $isSelf
                    ? __t('users.confirm_role_self')
                    : __t('users.confirm_role', ['name' => $rowName]);
                ?>
                <tr>
                    <td><?php echo h((string) $row['id']); ?></td>
                    <td><?php echo h($rowName); ?><?php if ($isDirectory && trim((string) ($row['ad_display_name'] ?? '')) !== '') { ?><br><span class="muted"><?php echo h((string) $row['ad_display_name']); ?></span><?php } ?></td>
                    <td><?php echo portal_badge($isDirectory ? 'info' : 'neutral', $isDirectory ? __t('directory.source_directory') : __t('directory.source_local')); ?></td>
                    <td><?php echo h((string) $row['email']); ?></td>
                    <td><?php echo h(role_label((string) $row['role'])); ?></td>
                    <td class="nowrap"><?php echo portal_badge($isActive ? 'success' : 'neutral', $isActive ? __t('common.yes') : __t('common.no')); ?><?php if ($isLocked) { ?> <span class="badge badge-warning" title="<?php echo h(__t('users.locked_until_hint', ['time' => portal_format_timestamp($lockedUntil)])); ?>"><?php echo h(__t('users.badge_locked')); ?></span><?php } ?><?php if ($isDirectory && (int) ($row['ad_account_enabled'] ?? 1) !== 1) { ?> <?php echo portal_badge('warning', __t('directory.ad_disabled')); ?><?php } ?></td>
                    <td><?php echo $isDirectory ? '<span class="muted">&mdash;</span>' : portal_badge((int) $row['must_change_password'] === 1 ? 'warning' : 'neutral', (int) $row['must_change_password'] === 1 ? __t('common.yes') : __t('common.no')); ?></td>
                    <td class="nowrap"><?php $lastSeen = (string) ($row['last_seen_at'] ?? ''); echo $lastSeen !== '' ? h(portal_format_timestamp($lastSeen)) : '<span class="muted">—</span>'; ?></td>
                    <td class="actions actions-stack">
                        <?php if ($isLocked) { ?>
                        <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="clear_lock"><input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <button class="button button-secondary" type="submit"><?php echo h(__t('users.btn_unlock')); ?></button>
                        </form>
                        <?php } ?>
                        <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>"><input type="hidden" name="is_active" value="<?php echo $isActive ? '0' : '1'; ?>">
                            <button class="button button-secondary" type="submit"<?php echo $isActive ? ' data-confirm="' . h(__t('users.confirm_deactivate', ['name' => $rowName])) . '"' : ''; ?>><?php echo h($isActive ? __t('users.btn_deactivate') : __t('users.btn_activate')); ?></button>
                        </form>
                        <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <select name="role" aria-label="<?php echo h(__t('users.aria_role_select', ['name' => $rowName])); ?>"><?php foreach (role_options() as $roleOption) { ?><option value="<?php echo h($roleOption); ?>" <?php echo $row['role'] === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option><?php } ?></select>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h($roleConfirm); ?>"><?php echo h(__t('users.btn_role')); ?></button>
                        </form>
                        <?php if ($isDirectory) { ?>
                        <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_sync_user"><input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <button class="button button-secondary" type="submit"><?php echo h(__t('directory.sync_user')); ?></button>
                        </form>
                        <?php } else { ?>
                        <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <input name="password" type="password" placeholder="<?php echo h(__t('users.new_password_placeholder')); ?>" required>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('users.confirm_reset_password', ['name' => $rowName])); ?>"><?php echo h(__t('users.btn_reset')); ?></button>
                            <?php echo form_error_html('row-' . (int) $row['id'], 'password'); ?>
                        </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
