<?php

declare(strict_types=1);

require_once __DIR__ . '/machine_api_roles.php';
require_once __DIR__ . '/repo/api_access.php';

/**
 * The machine-API allowlist actions of the settings page (K20 moved them out
 * of the dispatcher, which stays the owner of the action-to-tab map). A new
 * entry needs a role; an entry without one can be given one. The page shell
 * already checked system.config and CSRF.
 */
const VIRTUSPHERE_SETTINGS_ALLOWLIST_ACTIONS = ['allow_create', 'allow_set_role', 'allow_delete'];

/** @param array<string,mixed> $user */
function settings_allowlist_handle_action(mysqli $connection, array $user, string $action): void
{
    if ($action === 'allow_create') {
        $ip = request_trimmed($_POST, 'ip_address');
        $description = request_trimmed($_POST, 'description');
        $role = request_string($_POST, 'role');
        $conflict = filter_var($ip, FILTER_VALIDATE_IP) === false ? null : repo_api_access_conflict($connection, $ip, $role);
        $errors = [];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $errors['ip_address'] = __t('settings.allowlist_ip_invalid');
        } elseif ($conflict !== null) {
            $errors['ip_address'] = __t($conflict === 'legacy_present' ? 'settings.allowlist_ip_legacy_present' : 'settings.allowlist_ip_exists');
        }
        if (!in_array($role, VIRTUSPHERE_MACHINE_API_ROLES, true)) {
            $errors['role'] = __t('settings.allowlist_role_required');
        }
        if (mb_strlen($description) > 255) {
            $errors['description'] = __t('settings.allowlist_description_too_long');
        }
        if ($errors !== []) {
            form_remember('allowlist', $_POST, $errors);
            flash_set('error', (string) reset($errors));

            return;
        }
        try {
            repo_api_access_add($connection, $ip, $description, $role);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, 'client_ip', $ip, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'allowlisted', 'role' => $role], (int) $user['id']);
            flash_set('success', __t('settings.allowlist_added'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }

        return;
    }

    if ($action === 'allow_set_role') {
        $role = request_string($_POST, 'role');
        try {
            $entry = repo_fetch_one($connection, 'SELECT id, ipAddress, role FROM deploy_accessToWebAPI WHERE id = ? LIMIT 1', 'i', [request_int($_POST, 'id')]);
            if ($entry === null) {
                flash_set('error', __t('settings.allowlist_not_found'));
            } elseif (!in_array($role, VIRTUSPHERE_MACHINE_API_ROLES, true)) {
                flash_set('error', __t('settings.allowlist_role_required'));
            } elseif ($entry['role'] === $role) {
                // The entry has this role already: no write and no role_assigned audit row.
                flash_set('info', __t('settings.allowlist_role_unchanged'));
            } elseif (($conflict = repo_api_access_conflict($connection, (string) $entry['ipAddress'], $role, (int) $entry['id'])) !== null) {
                flash_set('error', __t($conflict === 'legacy_present' ? 'settings.allowlist_ip_legacy_present' : 'settings.allowlist_ip_exists'));
            } else {
                $before = repo_api_access_set_role($connection, (int) $entry['id'], $role);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, 'client_ip', (string) $entry['ipAddress'], VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'role_assigned',
                    'role' => $role,
                    'previous_role' => (string) ($before['role'] ?? 'none'),
                ], (int) $user['id']);
                flash_set('success', __t('settings.allowlist_role_saved'));
            }
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }

        return;
    }

    try {
        $entry = repo_api_access_delete($connection, request_int($_POST, 'id'));
        if ($entry === null) {
            flash_set('error', __t('settings.allowlist_not_found'));
        } else {
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, 'client_ip', (string) $entry['ipAddress'], VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'removed'], (int) $user['id']);
            flash_set('success', __t('settings.allowlist_removed'));
        }
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
}
