<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_actionable_error.php';

// Display-only timestamp formatter (SSoT). DB values are UTC (db() pins the
// session to +00:00); portal_format_datetime() converts them to the configured
// portal timezone (ADR-0022, lib/portal_time.php).
function portal_format_timestamp(?string $value): string
{
    return portal_format_datetime($value);
}

function portal_error_message(Throwable $exception): string
{
    if ($exception instanceof ValidationException) {
        return $exception->getMessage();
    }

    $message = $exception->getMessage();
    if (str_contains($message, 'APP_PUBLIC_BASE_URL') || str_contains($message, 'deploy_settings.api_base_url')) {
        return __t('settings.api_base_url_missing');
    }
    // Repo diagnostics an operator can hit without a crafted POST, i.e. by
    // clicking a button the portal renders for them. The RuntimeException texts
    // stay English (operator diagnostics, machine layer); only the portal
    // rendering is localized. Crafted-only conditions (mission not found,
    // template, credential type) fall through to the raw message on purpose.
    //
    // A reachable condition missing from this map renders raw English in the
    // German portal, so add the key here whenever a repo grows a guard that a
    // rendered button can trip.
    $operatorReachableErrors = [
        'Mission has no VMs to deploy.' => 'deploy.err_mission_no_vms',
        'This mission already has an active deploy job.' => 'layout.err_mission_active_job',
        'Mission datastore is required before deployment.' => 'deploy.err_datastore_required',
        'None of the selected VMs belong to this mission.' => 'deploy.err_selection_gone',
        'Strict ESXi certificate verification requires HTTPS.' => 'credentials.err_strict_requires_https',
        'Strict ESXi certificate verification must pass a connection test before activation.' => 'credentials.err_strict_test_required',
        'ESXi certificate is required.' => 'credentials.err_certificate_required',
        // The same class of guard on the two paths that would delete the state a
        // running deploy works on: missions.php renders Delete for every mission
        // and vms.php for every row, both regardless of a running job.
        'Mission has an active deploy job.' => 'layout.err_mission_active_job',
        // Two operators editing the same VM: the optimistic-locking guard in
        // repo_save_vm rejects the second save. Reachable by construction, so it
        // must speak the operator's language, not raw English.
        'VM was changed by another user. Reload before saving.' => 'vm_edit.err_conflict',
    ];
    if (isset($operatorReachableErrors[$message])) {
        return __t($operatorReachableErrors[$message]);
    }
    if ($exception instanceof mysqli_sql_exception) {
        if (str_contains($message, 'user_name_unique') || str_contains($message, 'deploy_users.user_name_unique')) {
            return __t('layout.err_user_name_taken');
        }
        if (str_contains($message, 'mission_name_unique')) {
            return __t('layout.err_mission_name_taken');
        }
        if (str_contains($message, 'Duplicate entry')) {
            return __t('layout.err_entry_exists');
        }

        return __t('layout.err_db_generic');
    }

    if ($message === '') {
        return __t('layout.err_action_failed');
    }

    return $message;
}

/** @return array{url:string,label:string}|null */
function portal_error_action(Throwable $exception, ?array $user = null): ?array
{
    if (!$exception instanceof PortalActionableError) {
        return null;
    }
    $action = $exception->portalAction();
    if ($action === null || !can($action['permission'], $user)) {
        return null;
    }

    return flash_action_normalize(['url' => $action['url'], 'label' => __t($action['label_key'])]);
}

function flash_portal_error(Throwable $exception, ?array $user = null): void
{
    flash_set('error', portal_error_message($exception), '', portal_error_action($exception, $user));
}

function role_label(string $role): string
{
    return match ($role) {
        VIRTUSPHERE_ROLE_ADMIN => __t('layout.role_admin'),
        VIRTUSPHERE_ROLE_USER => __t('layout.role_user'),
        default => $role,
    };
}

/**
 * Queues a flash for the next render. $detail carries operator diagnostics
 * (exception text, command output) that the alert shows behind a collapsed
 * details element; it is never the message itself.
 */
function flash_set(string $type, string $message, string $detail = '', ?array $action = null): void
{
    $safeAction = flash_action_normalize($action);
    $queue = $_SESSION['_flash'] ?? [];
    if (!is_array($queue)) {
        $queue = [];
    }

    foreach ($queue as $existing) {
        if (($existing['type'] ?? '') === $type
            && ($existing['message'] ?? '') === $message
            && ($existing['detail'] ?? '') === $detail
            && ($existing['action'] ?? null) === $safeAction
        ) {
            return;
        }
    }

    if (count($queue) >= VIRTUSPHERE_FLASH_MAX) {
        array_shift($queue);
    }

    $queue[] = ['type' => $type, 'message' => $message, 'detail' => $detail, 'action' => $safeAction];
    $_SESSION['_flash'] = $queue;
}

/**
 * Flash actions are structured data, never caller-provided HTML. Only a
 * relative portal URL is accepted.
 *
 * @return array{url:string,label:string}|null
 */
function flash_action_normalize(?array $action): ?array
{
    if ($action === null) {
        return null;
    }
    $url = trim((string) ($action['url'] ?? ''));
    $label = trim((string) ($action['label'] ?? ''));
    $hasWhitespace = str_contains($url, ' ') || str_contains($url, chr(9))
        || str_contains($url, chr(10)) || str_contains($url, chr(13));
    if ($url === '' || $label === '' || $hasWhitespace
        || str_contains($url, chr(92)) || str_starts_with($url, '//')
    ) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if (isset($parts['scheme']) || isset($parts['host'])
        || str_starts_with($path, '/') || str_contains($path, '..')
    ) {
        return null;
    }

    return ['url' => $url, 'label' => $label];
}

function flash_messages(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($messages) ? $messages : [];
}

/**
 * Single source of the alert markup, shared by the portal shell and the login
 * page. 'detail' is optional so flashes queued before a deploy still render.
 *
 * @param array{type?: string, message?: string, detail?: string, action?: array<string, mixed>} $flash
 */
function flash_alert_html(array $flash): string
{
    // data-flash separates a one-shot flash from the static .alert info boxes;
    // core.js uses it to counter-scroll the tab anchor jump only after a POST.
    $html = '<div class="alert alert-' . h((string) ($flash['type'] ?? 'info')) . '" data-flash>'
        . h((string) ($flash['message'] ?? ''));

    $detail = trim((string) ($flash['detail'] ?? ''));
    if ($detail !== '') {
        $html .= '<details class="alert-details">'
            . '<summary>' . h(__t('common.technical_details')) . '</summary>'
            . '<pre>' . h($detail) . '</pre>'
            . '</details>';
    }

    $action = flash_action_normalize(is_array($flash['action'] ?? null) ? $flash['action'] : null);
    if ($action !== null) {
        $quote = chr(34);
        $html .= '<div class=' . $quote . 'alert-actions' . $quote . '><a class='
            . $quote . 'button button-secondary' . $quote . ' href=' . $quote
            . h($action['url']) . $quote . '>' . h($action['label']) . '</a></div>';
    }

    return $html . '</div>';
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function portal_require_user(mysqli $connection, bool $allowPasswordChange = false): array
{
    $user = require_login($connection);
    if (!$allowPasswordChange && (int) ($user['must_change_password'] ?? 0) === 1) {
        redirect_to('account.php');
    }

    return $user;
}
