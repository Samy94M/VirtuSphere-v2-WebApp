<?php

declare(strict_types=1);

// K21-F: the one renderer of the ESXi access files that are still open on an
// Ansible host. The warning box above the System status overview and the line
// under an Ansible credential on the credentials page share this form, so the
// two places cannot disagree about what a confirmation closes. Nothing here
// writes: the POST action is credentials_handle_post() in
// lib/credentials_actions.php, behind the page's own permission and CSRF guards.
require_once __DIR__ . '/repo/deploy_remote_secret_notice.php';

/**
 * The warning box of the System status page. Renders nothing without a group, so
 * a clean installation shows no notice at all.
 *
 * @param list<array<string, mixed>> $groups repo_remote_secret_notice_groups()
 * @param array<string, mixed> $user
 */
function system_status_render_remote_secret_notice(array $groups, array $user): void
{
    if ($groups === []) {
        return;
    }
    $canAcknowledge = can('credentials.manage', $user);
    ?>
    <div class="alert alert-warning" data-remote-secret-files>
        <p><strong><?php echo h(__t('system_status.remote_secret_notice_title')); ?></strong></p>
        <?php foreach ($groups as $group) { ?>
            <div data-remote-secret-group>
                <p><?php echo h(remote_secret_notice_line($group)); ?></p>
                <?php if ($canAcknowledge) { remote_secret_render_form($group, 'system_status'); } ?>
            </div>
        <?php } ?>
        <?php if (!$canAcknowledge) { ?><p class="muted"><?php echo h(__t('credentials.remote_secret_no_permission')); ?></p><?php } ?>
        <p><?php echo h(__t('system_status.remote_secret_remedy')); ?> <?php echo h(__t('system_status.remote_secret_password_hint')); ?></p>
    </div>
    <?php
}

/**
 * The compact line and the acknowledge form under one Ansible credential, one
 * per target that still has open rows. Used by the credentials page.
 *
 * @param list<array{target_host: string, target_port: int, count: int, oldest: string}> $targets
 * @param array<string, mixed> $user
 */
function remote_secret_render_open_line(int $credentialId, array $targets, array $user): void
{
    if ($targets === [] || !can('credentials.manage', $user)) {
        return;
    }
    foreach ($targets as $target) {
        $group = [
            'credential_id' => $credentialId,
            'credential_name' => null,
            'target_host' => $target['target_host'],
            'target_port' => $target['target_port'],
            'count' => $target['count'],
            'oldest' => $target['oldest'],
            'reason' => null,
        ];
        ?>
        <div data-remote-secret-open>
            <p class="muted"><?php echo h(__t('credentials.remote_secret_open_line', [
                'target' => remote_secret_target_label($group),
                'files' => remote_secret_count_text((int) $group['count']),
                'oldest' => portal_format_timestamp((string) $group['oldest']),
            ])); ?></p>
            <?php remote_secret_render_form($group, 'credentials'); ?>
        </div>
        <?php
    }
}

/**
 * The acknowledge form of one target. The confirmation asks before it writes. The
 * server checks the reason again: required here is a convenience, because the
 * browser runs it after the confirmation.
 *
 * @param array<string, mixed> $group
 */
function remote_secret_render_form(array $group, string $returnTo): void
{
    $credentialId = $group['credential_id'] ?? null;
    ?>
    <form class="form-grid" method="post" action="credentials.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="remote_secret_acknowledge">
        <input type="hidden" name="credential_id" value="<?php echo h($credentialId === null ? '' : (string) $credentialId); ?>">
        <input type="hidden" name="target_host" value="<?php echo h((string) $group['target_host']); ?>">
        <input type="hidden" name="target_port" value="<?php echo h((string) $group['target_port']); ?>">
        <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
        <label><?php echo h(__t('credentials.remote_secret_reason_label')); ?>
            <textarea name="reason" rows="2" required></textarea>
        </label>
        <p class="hint"><?php echo h(__t('credentials.remote_secret_reason_hint')); ?></p>
        <div class="actions"><button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('credentials.remote_secret_acknowledge_confirm', ['name' => remote_secret_target_label($group)])); ?>"><?php echo h(__t('credentials.remote_secret_acknowledge_submit')); ?></button></div>
    </form>
    <?php
}

/** One sentence of the notice: credential, target, count, oldest finding and the reason. @param array<string, mixed> $group */
function remote_secret_notice_line(array $group): string
{
    $reason = match ($group['reason']) {
        'orphaned' => __t('system_status.remote_secret_reason_orphaned'),
        'moved' => __t('system_status.remote_secret_reason_moved'),
        default => __t('system_status.remote_secret_reason_overdue', ['hours' => intdiv(VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS, 3600)]),
    };

    $parts = [
        'target' => remote_secret_target_label($group),
        'files' => remote_secret_count_text((int) $group['count']),
        'oldest' => portal_format_timestamp((string) $group['oldest']),
        'reason' => $reason,
    ];
    if ($group['credential_name'] === null) {
        return __t('system_status.remote_secret_group_line_orphan', $parts);
    }

    return __t('system_status.remote_secret_group_line', ['name' => (string) $group['credential_name']] + $parts);
}

/** @param array<string, mixed> $group */
function remote_secret_target_label(array $group): string
{
    return (string) $group['target_host'] . ':' . (int) $group['target_port'];
}

/** The count with its own sentence: __t() does not pluralize. */
function remote_secret_count_text(int $count): string
{
    return $count === 1
        ? __t('credentials.remote_secret_count_one')
        : __t('credentials.remote_secret_count_other', ['count' => $count]);
}
