<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_config.php';
require_once __DIR__ . '/directory_service.php';
require_once __DIR__ . '/repo/directory.php';
require_once __DIR__ . '/users_page.php';

/** @param list<array<string,mixed>> $searchRows */
function users_render_directory(mysqli $db, array $actor, array $searchRows): void
{
    if (!directory_schema_available($db)) {
        ?><section class="panel"><h1><?php echo h(__t('directory.heading')); ?></h1><div class="alert alert-warning"><?php echo h(__t('directory.schema_pending')); ?></div></section><?php
        return;
    }
    $config = repo_directory_config($db);
    $controllers = $config === null ? [] : repo_directory_controllers($db);
    $blockers = directory_activation_blockers($db, $actor);
    $enabled = $config !== null && (int) $config['enabled'] === 1;
    $actionUrl = users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY);
    ?>
    <section class="panel">
        <h1><?php echo h(__t('directory.heading')); ?></h1>
        <p><?php echo h(__t('directory.intro')); ?></p>
        <p class="muted"><?php echo h(__t('directory.setup_steps')); ?></p>
        <?php echo portal_badge($enabled ? 'success' : 'neutral', $enabled ? __t('directory.state_enabled') : __t('directory.state_disabled')); ?>
    </section>

    <section class="panel" id="directory-config">
        <h2><?php echo h(__t('directory.config_heading')); ?></h2>
        <?php if (!$enabled && $blockers !== []) { ?>
        <div class="alert alert-warning">
            <strong><?php echo h(__t('directory.blockers_heading')); ?></strong>
            <ul>
                <?php foreach ($blockers as $blocker) { ?><li><?php echo h($blocker['message']); ?><?php if ($blocker['url'] !== '') { ?> <a href="<?php echo h($blocker['url']); ?>"><?php echo h($blocker['label']); ?></a><?php } ?></li><?php } ?>
            </ul>
        </div>
        <?php } ?>
        <form class="form-grid" method="post" action="<?php echo h($actionUrl); ?>" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="directory_save_config">
            <input type="hidden" name="expected_revision" value="<?php echo h((string) ($config['revision'] ?? 0)); ?>">
            <label><?php echo h(__t('directory.field_bind_upn')); ?><input name="bind_upn" value="<?php echo h(form_old('directory', 'bind_upn', (string) ($config['bind_upn'] ?? ''))); ?>" required><?php echo form_error_html('directory', 'bind_upn'); ?></label>
            <label><?php echo h(__t('directory.field_bind_password')); ?><input name="bind_password" type="password" autocomplete="new-password"<?php echo $config === null ? ' required' : ''; ?>><span class="muted"><?php echo h(__t('directory.password_keep')); ?></span><?php echo form_error_html('directory', 'bind_password'); ?></label>
            <label><?php echo h(__t('directory.field_ca')); ?><textarea name="ca_certificate_pem" rows="8"<?php echo $config === null ? ' required' : ''; ?>></textarea><span class="muted"><?php echo h(__t('directory.ca_keep')); ?></span><?php echo form_error_html('directory', 'ca_certificate_pem'); ?></label>
            <label><?php echo h(__t('directory.field_search_base')); ?><input name="user_search_base_dn" value="<?php echo h(form_old('directory', 'user_search_base_dn', (string) ($config['user_search_base_dn'] ?? ''))); ?>"><span class="muted"><?php echo h(__t('directory.search_base_hint')); ?></span><?php echo form_error_html('directory', 'user_search_base_dn'); ?></label>
            <?php if ($enabled) { ?>
            <label><?php echo h(__t('directory.field_test_controller')); ?><select name="controller_id" required><option value=""><?php echo h(__t('common.please_select')); ?></option><?php foreach ($controllers as $controller) { ?><option value="<?php echo h((string) $controller['id']); ?>"><?php echo h((string) $controller['host'] . ':' . (string) $controller['port']); ?></option><?php } ?></select><?php echo form_error_html('directory', 'controller_id'); ?></label>
            <?php } ?>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('directory.save_config')); ?></button></div>
        </form>
        <?php if ($config !== null) { ?>
        <p class="muted"><?php echo h(__t('directory.naming_context')); ?>: <?php echo h((string) ($config['default_naming_context'] ?? '') !== '' ? (string) $config['default_naming_context'] : __t('directory.not_discovered')); ?></p>
        <div class="actions">
            <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_set_enabled"><input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>"><button class="button <?php echo $enabled ? 'button-secondary' : ''; ?>" type="submit"<?php echo $enabled ? ' data-confirm="' . h(__t('directory.confirm_disable')) . '"' : ''; ?>><?php echo h($enabled ? __t('directory.disable') : __t('directory.enable')); ?></button></form>
            <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_delete_config"><button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('directory.confirm_delete_config')); ?>"><?php echo h(__t('directory.delete_config')); ?></button></form>
        </div>
        <?php } ?>
    </section>

    <?php if ($config !== null) { users_render_directory_controllers($controllers, (int) $config['revision'], $enabled, $actionUrl); } ?>
    <?php if ($enabled) { users_render_directory_search($searchRows, $actionUrl); } ?>
    <?php
}

/** @param list<array<string,mixed>> $controllers */
function users_render_directory_controllers(array $controllers, int $revision, bool $directoryEnabled, string $actionUrl): void
{
    ?>
    <section class="panel" id="directory-controllers">
        <h2><?php echo h(__t('directory.controllers_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('directory.controllers_hint')); ?></p>
        <form class="form-grid" method="post" action="<?php echo h($actionUrl); ?>">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_add_controller">
            <label><?php echo h(__t('directory.field_host')); ?><input name="host" required></label>
            <label><?php echo h(__t('directory.field_port')); ?><input name="port" type="number" min="1" max="65535" value="<?php echo VIRTUSPHERE_DIRECTORY_DEFAULT_PORT; ?>" required></label>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('directory.add_controller')); ?></button></div>
        </form>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th><?php echo h(__t('directory.th_priority')); ?></th><th><?php echo h(__t('directory.th_controller')); ?></th><th><?php echo h(__t('directory.th_validation')); ?></th><th><?php echo h(__t('directory.th_last_attempt')); ?></th><th><?php echo h(__t('directory.th_last_success')); ?></th><th><?php echo h(__t('directory.th_certificate')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead><tbody>
        <?php foreach ($controllers as $controller) {
            $validated = (int) ($controller['validated_revision'] ?? 0) === $revision;
            $active = (int) $controller['enabled'] === 1;
            $host = (string) $controller['host'];
            ?>
            <tr><td><?php echo h((string) $controller['priority']); ?></td><td><?php echo h($host . ':' . (string) $controller['port']); ?><br><?php echo portal_badge($active ? 'success' : 'neutral', $active ? __t('directory.enabled') : __t('directory.disabled')); ?></td><td><?php echo portal_badge($validated ? 'success' : 'warning', $validated ? __t('directory.validated') : __t('directory.retest')); ?></td><td class="nowrap"><?php echo users_directory_timestamp($controller['last_attempt_at'] ?? null); ?></td><td class="nowrap"><?php echo users_directory_timestamp($controller['last_success_at'] ?? null); ?></td><td class="nowrap"><?php echo users_directory_timestamp($controller['certificate_not_after'] ?? null); ?></td><td class="actions actions-stack">
                <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_test_controller"><input type="hidden" name="controller_id" value="<?php echo h((string) $controller['id']); ?>"><button class="button button-secondary" type="submit"><?php echo h(__t('directory.test_controller')); ?></button></form>
                <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_set_controller_enabled"><input type="hidden" name="controller_id" value="<?php echo h((string) $controller['id']); ?>"><input type="hidden" name="enabled" value="<?php echo $active ? '0' : '1'; ?>"><button class="button button-secondary" type="submit"<?php echo $active ? ' data-confirm="' . h(__t('directory.confirm_deactivate_controller', ['name' => $host])) . '"' : ''; ?>><?php echo h($active ? __t('directory.deactivate_controller') : __t('directory.activate_controller')); ?></button></form>
                <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_move_controller"><input type="hidden" name="controller_id" value="<?php echo h((string) $controller['id']); ?>"><button class="button button-secondary" name="direction" value="up" type="submit"><?php echo h(__t('directory.move_up')); ?></button><button class="button button-secondary" name="direction" value="down" type="submit"><?php echo h(__t('directory.move_down')); ?></button></form>
                <form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_delete_controller"><input type="hidden" name="controller_id" value="<?php echo h((string) $controller['id']); ?>"><button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('directory.confirm_delete_controller', ['name' => $host])); ?>"><?php echo h(__t('directory.delete_controller')); ?></button></form>
            </td></tr>
        <?php } ?>
        </tbody></table></div>
    </section>
    <?php
}

/** @param list<array<string,mixed>> $rows */
function users_render_directory_search(array $rows, string $actionUrl): void
{
    ?>
    <section class="panel" id="directory-search">
        <h2><?php echo h(__t('directory.search_heading')); ?></h2><p class="muted"><?php echo h(__t('directory.search_hint')); ?></p>
        <form class="form-grid" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_search"><label><?php echo h(__t('directory.field_search')); ?><input name="directory_search" minlength="<?php echo VIRTUSPHERE_DIRECTORY_SEARCH_MIN_CHARS; ?>" maxlength="<?php echo VIRTUSPHERE_DIRECTORY_SEARCH_MAX_CHARS; ?>" required></label><div class="actions"><button class="button" type="submit"><?php echo h(__t('directory.search')); ?></button></div></form>
        <?php if ($rows === []) { ?><p class="muted"><?php echo h(__t('directory.search_empty')); ?></p><?php } else { ?><div class="table-wrap" tabindex="0"><table><thead><tr><th><?php echo h(__t('directory.th_display_name')); ?></th><th><?php echo h(__t('directory.th_upn')); ?></th><th><?php echo h(__t('directory.th_sam')); ?></th><th><?php echo h(__t('directory.th_ad_state')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead><tbody><?php foreach ($rows as $row) { $accountEnabled = !empty($row['enabled']); ?><tr><td><?php echo h((string) $row['display_name']); ?></td><td><?php echo h((string) $row['upn']); ?></td><td><?php echo h((string) $row['sam']); ?></td><td><?php echo portal_badge($accountEnabled ? 'success' : 'warning', $accountEnabled ? __t('directory.ad_enabled') : __t('directory.ad_disabled')); ?></td><td><?php if ($accountEnabled) { ?><form class="inline-form" method="post" action="<?php echo h($actionUrl); ?>"><?php echo csrf_field(); ?><input type="hidden" name="action" value="directory_import"><input type="hidden" name="import_token" value="<?php echo h((string) $row['import_token']); ?>"><select name="role" aria-label="<?php echo h(__t('users.field_role')); ?>"><?php foreach (role_options() as $role) { ?><option value="<?php echo h($role); ?>"><?php echo h(role_label($role)); ?></option><?php } ?></select><button class="button button-secondary" type="submit"><?php echo h(__t('directory.import')); ?></button></form><?php } else { ?><span class="muted"><?php echo h(__t('directory.import_disabled_hint')); ?></span><?php } ?></td></tr><?php } ?></tbody></table></div><?php } ?>
    </section>
    <?php
}

function users_directory_timestamp(mixed $value): string
{
    $timestamp = trim((string) $value);

    return $timestamp === '' ? '<span class="muted">' . h(__t('directory.never')) . '</span>' : h(portal_format_timestamp($timestamp));
}
