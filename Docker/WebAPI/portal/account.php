<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/password_policy.php';

$user = portal_require_user($connection, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    $currentPassword = request_string($_POST, 'current_password');
    $newPassword = request_string($_POST, 'new_password');
    $confirmPassword = request_string($_POST, 'confirm_password');

    $policyError = password_policy_error($newPassword, password_policy_min_length($connection), 'account.err_new_password_min');
    if ($policyError !== null) {
        flash_set('error', $policyError);
    } elseif ($newPassword !== $confirmPassword) {
        flash_set('error', __t('account.err_confirm_mismatch'));
    } elseif (!change_own_password($connection, (int) $user['id'], $currentPassword, $newPassword)) {
        // A wrong current password is a failed authentication, not a form typo.
        audit_auth($connection, 'own password change rejected: current password is wrong', (int) $user['id']);
        flash_set('error', __t('account.err_current_wrong'));
    } else {
        // Admin resets are already logged under `users`; this closes the other
        // half, so "every account change is recorded" finally holds.
        audit_auth($connection, 'changed own password', (int) $user['id']);
        $_SESSION['must_change_password'] = false;
        flash_set('success', __t('account.flash_changed'));
        redirect_to('dashboard.php');
    }
    redirect_to('account.php');
}

layout_header(__t('account.title'), $user, 'account');
$roleLabel = role_label((string) ($user['role'] ?? VIRTUSPHERE_ROLE_USER));
$summary = trim((string) ($user['email'] ?? '')) !== ''
    ? (string) $user['email'] . ' / ' . $roleLabel
    : $roleLabel;
?>
<div class="stack">
    <section class="panel">
        <h2><?php echo h((string) $user['name']); ?></h2>
        <p class="muted"><?php echo h($summary); ?></p>
        <?php if ((int) $user['must_change_password'] === 1) { ?><div class="alert alert-error"><?php echo h(__t('account.must_change')); ?></div><?php } ?>
        <form class="stack narrow-form" method="post" action="account.php">
            <?php echo csrf_field(); ?>
            <label for="current_password"><?php echo h(__t('account.current_password')); ?><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></label>
            <label for="new_password"><?php echo h(__t('account.new_password')); ?><input id="new_password" name="new_password" type="password" autocomplete="new-password" required></label>
            <label for="confirm_password"><?php echo h(__t('account.confirm_password')); ?><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required></label>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('account.change_password')); ?></button></div>
        </form>
    </section>
</div>
<?php layout_footer(); ?>