<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_schema.php';
require_once __DIR__ . '/repo/directory.php';
require_once __DIR__ . '/users_page.php';

function system_status_render_directory(mysqli $db, array $user): void
{
    if (!directory_schema_available($db)) {
        return;
    }
    $config = repo_directory_config($db);
    $controllers = $config === null ? [] : repo_directory_controllers($db);
    $revision = (int) ($config['revision'] ?? 0);
    $usable = array_values(array_filter($controllers, static fn (array $controller): bool =>
        (int) $controller['enabled'] === 1 && (int) ($controller['validated_revision'] ?? 0) === $revision
    ));
    $blocked = $config !== null
        && (int) ($config['automatic_bind_blocked_revision'] ?? 0) === $revision;
    $enabled = $config !== null && (int) $config['enabled'] === 1;
    $variant = !$enabled ? 'neutral' : (($usable === [] || $blocked) ? 'danger' : 'success');
    $state = !$enabled
        ? __t('system_status.directory_disabled')
        : ($blocked ? __t('system_status.directory_bind_blocked') : ($usable === [] ? __t('system_status.directory_no_controller') : __t('system_status.directory_ready')));
    $lastSuccess = '';
    foreach ($controllers as $controller) {
        if ((string) ($controller['last_success_at'] ?? '') > $lastSuccess) {
            $lastSuccess = (string) $controller['last_success_at'];
        }
    }
    ?>
    <section class="panel status-section" id="directory-status">
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.directory_heading')); ?></h2><p><?php echo h(__t('system_status.directory_hint')); ?></p></div>
            <?php echo portal_badge($variant, $state); ?>
        </div>
        <div class="table-wrap"><table><tbody>
            <tr><th><?php echo h(__t('system_status.directory_revision')); ?></th><td><?php echo $config === null ? '&mdash;' : h((string) $revision); ?></td></tr>
            <tr><th><?php echo h(__t('system_status.directory_controllers')); ?></th><td><?php echo h(__t('system_status.directory_controller_count', ['usable' => count($usable), 'total' => count($controllers)])); ?></td></tr>
            <tr><th><?php echo h(__t('system_status.directory_last_success')); ?></th><td><?php echo $lastSuccess === '' ? '&mdash;' : h(portal_format_timestamp($lastSuccess)); ?></td></tr>
        </tbody></table></div>
        <?php if (can('users.manage', $user)) { ?><div class="actions"><a class="button button-secondary" href="<?php echo h(users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY)); ?>"><?php echo h(__t('system_status.directory_open')); ?></a></div><?php } ?>
    </section>
    <?php
}
