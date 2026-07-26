<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/password_policy.php';
require_once __DIR__ . '/../lib/repo/helpers.php';
require_once __DIR__ . '/../lib/repo/legacy.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/validate.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('users.manage', $user)) {
    portal_forbid($connection, $user, 'users.manage');
}

function valid_role(string $role): string
{
    return role_normalize($role);
}

function user_is_last_active_admin(mysqli $db, int $targetId): bool
{
    if ($targetId <= 0) {
        return false;
    }

    $stmt = $db->prepare('SELECT role, is_active FROM deploy_users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    if (!$target || (string) $target['role'] !== VIRTUSPHERE_ROLE_ADMIN || (int) $target['is_active'] !== 1) {
        return false;
    }

    $role = VIRTUSPHERE_ROLE_ADMIN;
    $active = 1;
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_users WHERE role = ? AND is_active = ?');
    $stmt->bind_param('si', $role, $active);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['c'] ?? 0) <= 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    try {
        $action = request_string($_POST, 'action');
        if ($action === 'create') {
            $validator = new Validator();
            $name = $validator->requireString('name', $_POST['name'] ?? '', __t('common.name'), 191);
            $email = $validator->optionalString('email', $_POST['email'] ?? '', __t('users.field_email'), 191);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $validator->add('email', __t('users.err_email_invalid'));
            }
            $password = request_string($_POST, 'password');
            $policyError = password_policy_error($password, password_policy_min_length($connection), 'users.err_password_min');
            if ($policyError !== null) {
                $validator->add('password', $policyError);
            }
            $role = valid_role(request_string($_POST, 'role', VIRTUSPHERE_ROLE_USER));
            $validator->throwIfInvalid();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $active = 1;
            $mustChange = 1;
            $stmt = $connection->prepare('INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssii', $name, $hash, $email, $role, $active, $mustChange);
            $stmt->execute();
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_USERS, 'created user ' . $name, (int) $user['id']);
            flash_set('success', __t('users.flash_created'));
        } elseif ($action === 'set_active') {
            $targetId = request_int($_POST, 'user_id');
            $active = request_int($_POST, 'is_active');
            if ($targetId === (int) $user['id'] && $active === 0) {
                throw new RuntimeException(__t('users.err_self_deactivate'));
            }
            if ($active === 0 && user_is_last_active_admin($connection, $targetId)) {
                throw new RuntimeException(__t('users.err_last_admin'));
            }
            // Deactivating also kills the account's legacy API tokens. Without
            // that, the desktop token outlived the account: the portal refuses
            // the user on the next click, the token kept working, and the
            // confirmation the admin just read promised the opposite. One
            // transaction, because a flag flipped without the tokens gone is the
            // state this defect consisted of.
            $revoked = 0;
            repo_transaction($connection, function () use ($connection, $active, $targetId, &$revoked): void {
                $stmt = $connection->prepare('UPDATE deploy_users SET is_active = ?, updated_at = NOW() WHERE id = ?');
                $stmt->bind_param('ii', $active, $targetId);
                $stmt->execute();
                if ($active === 0) {
                    $revoked = repo_legacy_expire_user_tokens($connection, $targetId);
                }
            });
            $note = $revoked > 0 ? ' (legacy tokens revoked: ' . $revoked . ')' : '';
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_USERS, 'changed active state for user id ' . $targetId . $note, (int) $user['id']);
            flash_set('success', __t('users.flash_status'));
        } elseif ($action === 'set_role') {
            $targetId = request_int($_POST, 'user_id');
            $role = valid_role(request_string($_POST, 'role', VIRTUSPHERE_ROLE_USER));
            if ($role !== VIRTUSPHERE_ROLE_ADMIN && user_is_last_active_admin($connection, $targetId)) {
                throw new RuntimeException(__t('users.err_last_admin'));
            }
            $stmt = $connection->prepare('UPDATE deploy_users SET role = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('si', $role, $targetId);
            $stmt->execute();
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_USERS, 'changed role for user id ' . $targetId, (int) $user['id']);
            flash_set('success', __t('users.flash_role'));
        } elseif ($action === 'reset_password') {
            $targetId = request_int($_POST, 'user_id');
            $password = request_string($_POST, 'password');
            $policyError = password_policy_error($password, password_policy_min_length($connection), 'users.err_password_min');
            if ($policyError !== null) {
                throw new ValidationException(['password' => $policyError]);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $mustChange = 1;
            $stmt = $connection->prepare('UPDATE deploy_users SET password = ?, must_change_password = ?, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('sii', $hash, $mustChange, $targetId);
            $stmt->execute();
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_USERS, 'reset password for user id ' . $targetId, (int) $user['id']);
            flash_set('success', __t('users.flash_password_reset'));
        } elseif ($action === 'clear_lock') {
            $targetId = request_int($_POST, 'user_id');
            $stmt = $connection->prepare('UPDATE deploy_users SET locked_until = NULL, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_USERS, 'cleared login lock for user id ' . $targetId, (int) $user['id']);
            flash_set('success', __t('users.flash_lock_cleared'));
        }
    } catch (ValidationException $exception) {
        form_remember(($_POST['action'] ?? '') === 'create' ? 'create' : 'row-' . request_int($_POST, 'user_id'), $_POST, $exception->errors());
        flash_set('error', __t('users.flash_check_input'));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
    redirect_to('users.php');
}

$result = $connection->prepare('SELECT id, name, email, role, is_active, must_change_password, locked_until, last_seen_at, created_at, updated_at FROM deploy_users ORDER BY id');
$result->execute();
$users = $result->get_result()->fetch_all(MYSQLI_ASSOC);
layout_header(__t('users.title'), $user, 'users', 'users');
?>
<div class="stack">
    <section class="panel">
        <h2><?php echo h(__t('users.create_heading')); ?></h2>
        <form class="form-grid" method="post" action="users.php">
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
            <thead><tr><th><?php echo h(__t('users.th_id')); ?></th><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('users.field_email')); ?></th><th><?php echo h(__t('users.field_role')); ?></th><th><?php echo h(__t('users.th_active')); ?></th><th><?php echo h(__t('users.th_must_change')); ?></th><th><?php echo h(__t('users.th_last_seen')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($users as $row) {
                $lockedUntil = (string) ($row['locked_until'] ?? '');
                $isLocked = $lockedUntil !== '' && strtotime($lockedUntil) > time();
            ?>
                <tr>
                    <td><?php echo h((string) $row['id']); ?></td>
                    <td><?php echo h((string) $row['name']); ?></td>
                    <td><?php echo h((string) $row['email']); ?></td>
                    <td><?php echo h(role_label((string) $row['role'])); ?></td>
                    <td class="nowrap"><?php echo portal_badge((int) $row['is_active'] === 1 ? 'success' : 'neutral', (int) $row['is_active'] === 1 ? __t('common.yes') : __t('common.no')); ?><?php if ($isLocked) { ?> <span class="badge badge-warning" title="<?php echo h(__t('users.locked_until_hint', ['time' => portal_format_timestamp($lockedUntil)])); ?>"><?php echo h(__t('users.badge_locked')); ?></span><?php } ?></td>
                    <td><?php echo portal_badge((int) $row['must_change_password'] === 1 ? 'warning' : 'neutral', (int) $row['must_change_password'] === 1 ? __t('common.yes') : __t('common.no')); ?></td>
                    <td class="nowrap"><?php $lastSeen = (string) ($row['last_seen_at'] ?? ''); echo $lastSeen !== '' ? h(portal_format_timestamp($lastSeen)) : '<span class="muted">—</span>'; ?></td>
                    <td class="actions actions-stack">
                        <?php if ($isLocked) { ?>
                        <form class="inline-form" method="post" action="users.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="clear_lock">
                            <input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <button class="button button-secondary" type="submit"><?php echo h(__t('users.btn_unlock')); ?></button>
                        </form>
                        <?php } ?>
                        <?php
                        $isSelf = (int) $row['id'] === (int) $user['id'];
                        $rowName = (string) $row['name'];
                        $isActive = (int) $row['is_active'] === 1;
                        // The server blocks demoting the last active admin, but it permits an
                        // admin to demote themselves while another admin exists: that costs the
                        // actor their own access immediately, and only the other admin can undo it.
                        $roleConfirm = $isSelf
                            ? __t('users.confirm_role_self')
                            : __t('users.confirm_role', ['name' => $rowName]);
                        ?>
                        <form class="inline-form" method="post" action="users.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <input type="hidden" name="is_active" value="<?php echo $isActive ? '0' : '1'; ?>">
                            <?php
                            // Activating needs no prompt; only the destructive branch of the toggle
                            // asks. The attribute is omitted rather than emptied, because the
                            // [data-confirm] selector matches a blank value too.
                            ?>
                            <button class="button button-secondary" type="submit"<?php echo $isActive ? ' data-confirm="' . h(__t('users.confirm_deactivate', ['name' => $rowName])) . '"' : ''; ?>><?php echo h($isActive ? __t('users.btn_deactivate') : __t('users.btn_activate')); ?></button>
                        </form>
                        <form class="inline-form" method="post" action="users.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <?php // A row select has no visible label, so it names the account it
                                  // belongs to: a screen reader otherwise announces a bare combo box
                                  // and the operator cannot tell whose role they are about to change. ?>
                            <select name="role" aria-label="<?php echo h(__t('users.aria_role_select', ['name' => $rowName])); ?>"><?php foreach (role_options() as $roleOption) { ?><option value="<?php echo h($roleOption); ?>" <?php echo $row['role'] === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option><?php } ?></select>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h($roleConfirm); ?>"><?php echo h(__t('users.btn_role')); ?></button>
                        </form>
                        <form class="inline-form" method="post" action="users.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?php echo h((string) $row['id']); ?>">
                            <input name="password" type="password" placeholder="<?php echo h(__t('users.new_password_placeholder')); ?>" required>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('users.confirm_reset_password', ['name' => (string) $row['name']])); ?>"><?php echo h(__t('users.btn_reset')); ?></button>
                            <?php echo form_error_html('row-' . (int) $row['id'], 'password'); ?>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
