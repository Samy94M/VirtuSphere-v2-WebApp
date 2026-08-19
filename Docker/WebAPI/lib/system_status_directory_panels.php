<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/auth_schema.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/directory_status.php';
require_once __DIR__ . '/repo/directory.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/users_page.php';

/**
 * Read-only AD overview (plan section 15.1). Gated on users.manage like the
 * "Active Directory" admin view itself: controller FQDNs are internal
 * infrastructure detail, not something every system-status viewer needs.
 * Without a saved configuration nothing has ever been set up, so no
 * permanently grey card is rendered at all.
 */
function system_status_render_directory(mysqli $db, array $user): void
{
    if (!directory_schema_available($db) || !can('users.manage', $user)) {
        return;
    }
    $config = repo_directory_config($db);
    if ($config === null) {
        return;
    }
    $controllers = repo_directory_controllers($db);
    $snapshot = directory_health_snapshot($config, $controllers);
    $revision = (int) $config['revision'];
    $lastSuccess = '';
    foreach ($controllers as $controller) {
        if ((string) ($controller['last_success_at'] ?? '') > $lastSuccess) {
            $lastSuccess = (string) $controller['last_success_at'];
        }
    }
    $state = match (true) {
        (int) $config['enabled'] !== 1 => __t('system_status.directory_disabled'),
        (int) ($config['automatic_bind_blocked_revision'] ?? 0) === $revision => __t('system_status.directory_bind_blocked'),
        $snapshot['overall'] === 'danger' => __t('system_status.directory_no_controller'),
        default => __t('system_status.directory_ready'),
    };
    $usableCount = count(array_filter(
        $controllers,
        static fn (array $controller): bool => (int) $controller['enabled'] === 1 && (int) ($controller['validated_revision'] ?? 0) === $revision
    ));
    ?>
    <section class="panel status-section" id="directory-status">
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.directory_heading')); ?></h2><p><?php echo h(__t('system_status.directory_hint')); ?></p></div>
            <?php echo directory_state_badge($snapshot['overall']); ?>
        </div>
        <div class="table-wrap"><table><tbody>
            <tr><th><?php echo h(__t('system_status.directory_state')); ?></th><td><?php echo h($state); ?></td></tr>
            <tr><th><?php echo h(__t('system_status.directory_revision')); ?></th><td><?php echo h((string) $revision); ?></td></tr>
            <tr><th><?php echo h(__t('system_status.directory_controllers')); ?></th><td><?php echo h(__t('system_status.directory_controller_count', ['usable' => $usableCount, 'total' => count($controllers)])); ?></td></tr>
            <tr><th><?php echo h(__t('system_status.directory_last_success')); ?></th><td><?php echo $lastSuccess === '' ? '&mdash;' : h(portal_format_timestamp($lastSuccess)); ?></td></tr>
        </tbody></table></div>
        <p class="hint"><?php echo h(__t('system_status.directory_cadence')); ?></p>
        <?php if ($controllers !== []): ?>
        <div class="table-wrap"><table>
            <thead><tr>
                <th><?php echo h(__t('system_status.directory_th_priority')); ?></th>
                <th><?php echo h(__t('system_status.directory_th_endpoint')); ?></th>
                <th><?php echo h(__t('system_status.directory_th_state')); ?></th>
                <th><?php echo h(__t('system_status.directory_th_last_test')); ?></th>
                <th><?php echo h(__t('system_status.directory_th_last_success')); ?></th>
                <th><?php echo h(__t('system_status.directory_th_cert_expiry')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($snapshot['controllers'] as $row): $controller = $row['controller']; ?>
                <tr>
                    <td><?php echo h((string) $controller['priority']); ?></td>
                    <td><?php echo h($controller['host'] . ':' . $controller['port']); ?></td>
                    <td><?php echo directory_controller_state_badge($row['state']); ?></td>
                    <td><?php echo empty($controller['validated_at']) ? '&mdash;' : h(portal_format_timestamp((string) $controller['validated_at'])); ?></td>
                    <td><?php echo empty($controller['last_success_at']) ? '&mdash;' : h(portal_format_timestamp((string) $controller['last_success_at'])); ?></td>
                    <td><?php echo empty($controller['certificate_not_after']) ? '&mdash;' : h(portal_format_timestamp((string) $controller['certificate_not_after'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <div class="actions">
            <a class="button button-secondary" href="<?php echo h(users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY)); ?>"><?php echo h(__t('system_status.directory_open')); ?></a>
            <a class="button button-secondary" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY)); ?>"><?php echo h(__t('system_status.directory_open_log')); ?></a>
        </div>
    </section>
    <?php
}
