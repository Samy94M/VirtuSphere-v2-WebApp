<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/password_policy.php';
require_once __DIR__ . '/../lib/users_accounts_panels.php';
require_once __DIR__ . '/../lib/users_admin.php';
require_once __DIR__ . '/../lib/users_directory_admin.php';
require_once __DIR__ . '/../lib/users_directory_panels.php';
require_once __DIR__ . '/../lib/users_page.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('users.manage', $user)) {
    portal_forbid($connection, $user, 'users.manage');
}

$view = users_view_normalize(request_string($_GET, 'view', VIRTUSPHERE_USERS_VIEW_ACCOUNTS));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    $action = request_string($_POST, 'action');
    $view = str_starts_with($action, 'directory_')
        ? VIRTUSPHERE_USERS_VIEW_DIRECTORY
        : VIRTUSPHERE_USERS_VIEW_ACCOUNTS;
    try {
        $handled = users_handle_account_action($connection, $user, $action)
            || users_directory_handle_action($connection, $user, $action);
        if (!$handled) {
            throw new ValidationException(['action' => __t('common.unknown_action')]);
        }
    } catch (ValidationException $exception) {
        $formKey = $action === 'create' ? 'create' : ($view === VIRTUSPHERE_USERS_VIEW_DIRECTORY ? 'directory' : 'row-' . request_int($_POST, 'user_id'));
        form_remember($formKey, $_POST, $exception->errors());
        flash_set('error', __t('users.flash_check_input'));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
    redirect_to(users_url($view));
}

$rows = users_admin_rows($connection);
$passwordMinLength = password_policy_min_length($connection);
$searchRows = $view === VIRTUSPHERE_USERS_VIEW_DIRECTORY
    ? users_directory_take_search_results()
    : [];

layout_header(__t('users.title'), $user, 'users', 'users');
?>
<div class="stack">
    <?php // Two views, two addresses: the same page navigation the mission and
          // log pages render, through the one helper. ?>
    <?php echo portal_page_nav(__t('users.tabs_label'), [
        ['href' => users_url(VIRTUSPHERE_USERS_VIEW_ACCOUNTS), 'label' => __t('directory.tab_accounts'), 'current' => $view === VIRTUSPHERE_USERS_VIEW_ACCOUNTS],
        ['href' => users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY), 'label' => __t('directory.tab_directory'), 'current' => $view === VIRTUSPHERE_USERS_VIEW_DIRECTORY],
    ]); ?>
    <?php if ($view === VIRTUSPHERE_USERS_VIEW_DIRECTORY) {
        users_render_directory($connection, $user, $searchRows);
    } else {
        users_render_accounts($rows, $user, $passwordMinLength);
    } ?>
</div>
<?php layout_footer(); ?>
