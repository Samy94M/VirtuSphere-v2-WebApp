<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/directory_config.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/api_access.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/settings_page.php';

/**
 * Guarding and permission checks stay in the page shell. This dispatcher owns
 * the closed action-to-tab map and every settings mutation, including the
 * transactional HTTPS/directory coupling required by ADR-0039.
 *
 * @param array<string,mixed> $user
 */
function settings_handle_post(mysqli $connection, array $user): never
{
    $action = request_string($_POST, 'action', 'save_api');

    // Which tab each action's form lives in. The redirect below carries it as
    // URL fragment so core.js re-opens that tab; without the anchor every save
    // lands on the first tab, sticky field errors render in a hidden panel and
    // the one-time report token can go unseen. Pinned by
    // tests/Static/SettingsTabRedirectContractTest.php.
    $actionTabs = [
        'save_api' => 'deploy',
        'clear_api' => 'deploy',
        'allow_create' => 'machine-api',
        'allow_delete' => 'machine-api',
        'generate_token' => 'machine-api',
        'clear_token' => 'machine-api',
        'save_retire_threshold' => 'catalog',
        'save_esxi_inventory' => 'catalog',
        'save_timezone' => 'system',
        'save_session' => 'system',
        'save_password_policy' => 'system',
        'upload_https_cert' => 'https',
        'save_https_enabled' => 'https',
        'save_https_redirect' => 'https',
        'save_https_hsts' => 'https',
    ];

    if (!array_key_exists($action, $actionTabs)) {
        http_response_code(400);
        echo h(__t('common.unknown_action'));
        exit;
    }

    if ($action === 'save_api') {
        try {
            $apiBaseUrl = ansible_normalize_api_base_url(request_string($_POST, 'api_base_url'));
            repo_set_setting($connection, VIRTUSPHERE_SETTING_API_BASE_URL, $apiBaseUrl);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'deploy_api_base_url', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated'], (int) $user['id']);
            flash_set('success', __t('settings.saved'));
        } catch (InvalidArgumentException $exception) {
            $message = __t('settings.api_base_url_invalid');
            form_remember('settings', $_POST, ['api_base_url' => $message]);
            flash_set('error', $message);
        } catch (Throwable $exception) {
            $message = portal_error_message($exception);
            form_remember('settings', $_POST, ['api_base_url' => $message]);
            flash_set('error', $message);
        }
    } elseif ($action === 'clear_api') {
        try {
            repo_delete_setting($connection, VIRTUSPHERE_SETTING_API_BASE_URL);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'deploy_api_base_url', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'cleared'], (int) $user['id']);
            flash_set('success', __t('settings.api_base_url_reset_done'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'save_timezone') {
        $timezone = request_trimmed($_POST, 'timezone');
        if (!portal_timezone_is_valid($timezone)) {
            $message = __t('settings.timezone_invalid');
            form_remember('timezone', $_POST, ['timezone' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_PORTAL_TIMEZONE, $timezone);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'portal_timezone', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'new_value' => $timezone,
                ], (int) $user['id']);
                flash_set('success', __t('settings.timezone_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_esxi_inventory') {
        $hoursRaw = request_trimmed($_POST, 'esxi_inventory_interval_hours');
        $hours = preg_match('/^[0-9]+$/', $hoursRaw) === 1 ? (int) $hoursRaw : -1;
        $resolution = esxi_inventory_ansible_resolution($connection);
        $selectionRaw = request_trimmed($_POST, 'esxi_inventory_ansible_credential_id');
        $selection = preg_match('/^[0-9]+$/', $selectionRaw) === 1 ? (int) $selectionRaw : 0;
        $errors = [];
        if ($hours < VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN || $hours > VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX) {
            $errors['esxi_inventory_interval_hours'] = __t('settings.esxi_interval_invalid', [
                'min' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN,
                'max' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX,
            ]);
        }
        if (count($resolution['credentials']) > 1) {
            $validIds = array_map(static fn (array $credential): int => (int) $credential['id'], $resolution['credentials']);
            if ($selection <= 0 || !in_array($selection, $validIds, true)) {
                $errors['esxi_inventory_ansible_credential_id'] = __t('settings.esxi_ansible_invalid');
            }
        }
        if ($errors !== []) {
            form_remember('esxi', $_POST, $errors);
            flash_set('error', (string) reset($errors));
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) $hours);
                $oldSelection = (int) repo_setting_value($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0');
                if (count($resolution['credentials']) > 1) {
                    repo_set_setting($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $selection);
                } else {
                    repo_delete_setting($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
                    $selection = 0;
                }
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'esxi_inventory_interval_hours', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'new_value' => (string) $hours,
                ], (int) $user['id']);
                if ($oldSelection !== $selection) {
                    audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'esxi_inventory_ansible_credential', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                        'action' => 'updated',
                        'old_value' => (string) $oldSelection,
                        'new_value' => (string) $selection,
                    ], (int) $user['id']);
                }
                flash_set('success', __t('settings.esxi_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_session') {
        $minutes = request_int($_POST, 'session_lifetime_minutes', VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT);
        if ($minutes < VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN || $minutes > VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX) {
            $message = __t('settings.session_invalid', ['min' => VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN, 'max' => VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX]);
            form_remember('session', $_POST, ['session_lifetime_minutes' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES, (string) $minutes);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'session_lifetime_minutes', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'new_value' => (string) $minutes,
                ], (int) $user['id']);
                flash_set('success', __t('settings.session_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_password_policy') {
        $minLength = request_int($_POST, 'password_min_length', VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT);
        if ($minLength < VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN || $minLength > VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX) {
            $message = __t('settings.password_invalid', ['min' => VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN, 'max' => VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX]);
            form_remember('password_policy', $_POST, ['password_min_length' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH, (string) $minLength);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'password_min_length', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'new_value' => (string) $minLength,
                ], (int) $user['id']);
                flash_set('success', __t('settings.password_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'upload_https_cert') {
        try {
            $certFile = $_FILES['cert_file'] ?? null;
            if (!is_array($certFile) || (int) ($certFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $certFile['tmp_name'])) {
                throw new ValidationException(['cert_file' => __t('settings.https_err_no_file')]);
            }
            if ((int) $certFile['size'] > VIRTUSPHERE_HTTPS_UPLOAD_MAX_BYTES) {
                throw new ValidationException(['cert_file' => __t('settings.https_err_too_large')]);
            }
            $rawKey = '';
            $keyFile = $_FILES['key_file'] ?? null;
            if (is_array($keyFile) && (int) ($keyFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $keyFile['tmp_name'])) {
                if ((int) $keyFile['size'] > VIRTUSPHERE_HTTPS_UPLOAD_MAX_BYTES) {
                    throw new ValidationException(['key_file' => __t('settings.https_err_too_large')]);
                }
                $rawKey = (string) file_get_contents((string) $keyFile['tmp_name']);
            }
            $material = https_parse_upload(
                (string) file_get_contents((string) $certFile['tmp_name']),
                $rawKey,
                request_string($_POST, 'pfx_password')
            );
            https_write_material($material['cert_pem'], $material['chain_pem'], $material['key_pem']);
            // Re-renders the server block when HTTPS is already enabled, so a
            // renewal goes live on the watcher's next pass.
            https_apply_state($connection);
            $meta = https_cert_metadata($material['cert_pem']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED, 'setting', 'https_certificate', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'subject' => (string) $meta['subject'],
                'valid_to' => gmdate('Y-m-d', (int) $meta['valid_to']),
            ], (int) $user['id']);
            flash_set('success', __t('settings.https_uploaded'));
        } catch (ValidationException $exception) {
            form_remember('https_upload', $_POST, $exception->errors());
            flash_set('error', portal_error_message($exception));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'save_https_enabled') {
        $enable = ($_POST['https_enabled'] ?? '') === '1';
        try {
            if ($enable && !https_material_present()) {
                throw new ValidationException(['https_enabled' => __t('settings.https_err_no_material')]);
            }
            $redirectWasOn = false;
            repo_transaction($connection, function () use ($connection, $enable, &$redirectWasOn): void {
                if (directory_schema_available($connection)) {
                    directory_lock_activation_state($connection);
                    if (!$enable && directory_is_enabled($connection)) {
                        throw new ValidationException(['https_enabled' => __t('settings.https_err_directory_active')]);
                    }
                }
                $redirectWasOn = repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0') === '1';
                repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_ENABLED, $enable ? '1' : '0');
                if (!$enable && $redirectWasOn) {
                    // Never leave a redirect pointing at a listener that is gone.
                    repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0');
                }
            });
            https_apply_state($connection);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'https', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'enabled_state_changed',
                'enabled' => $enable,
                'redirect_disabled' => $redirectWasOn,
            ], (int) $user['id']);
            flash_set('success', $enable ? __t('settings.https_enabled_on') : (!$redirectWasOn ? __t('settings.https_enabled_off') : __t('settings.https_enabled_off_redirect')));
        } catch (ValidationException $exception) {
            flash_set('error', portal_error_message($exception));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'save_https_redirect') {
        $enable = ($_POST['https_redirect_enabled'] ?? '') === '1';
        try {
            repo_transaction($connection, function () use ($connection, $enable): void {
                if (directory_schema_available($connection)) {
                    directory_lock_activation_state($connection);
                    if (!$enable && directory_is_enabled($connection)) {
                        throw new ValidationException(['https_redirect_enabled' => __t('settings.https_err_directory_active')]);
                    }
                }
                if ($enable && repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0') !== '1') {
                    throw new ValidationException(['https_redirect_enabled' => __t('settings.https_err_redirect_requires')]);
                }
                repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, $enable ? '1' : '0');
            });
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'http_to_https_redirect', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'enabled_state_changed',
                'enabled' => $enable,
            ], (int) $user['id']);
            flash_set('success', $enable ? __t('settings.https_redirect_on') : __t('settings.https_redirect_off'));
        } catch (ValidationException $exception) {
            flash_set('error', portal_error_message($exception));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'save_https_hsts') {
        $enable = ($_POST['https_hsts_enabled'] ?? '') === '1';
        try {
            repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED, $enable ? '1' : '0');
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'hsts', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'enabled_state_changed',
                'enabled' => $enable,
            ], (int) $user['id']);
            flash_set('success', $enable ? __t('settings.https_hsts_on') : __t('settings.https_hsts_off'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'generate_token') {
        try {
            // Plaintext is shown exactly once and never stored (ADR-0018).
            $token = bin2hex(random_bytes(16));
            repo_set_setting($connection, VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH, hash('sha256', $token));
            $_SESSION['machine_report_token_once'] = $token;
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN, 'setting', 'machine_report_token', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'generated'], (int) $user['id']);
            flash_set('success', __t('settings.report_token_generated'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'clear_token') {
        try {
            repo_set_setting($connection, VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH, '');
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN, 'setting', 'machine_report_token', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'cleared'], (int) $user['id']);
            flash_set('success', __t('settings.report_token_cleared'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'allow_create') {
        $ip = request_trimmed($_POST, 'ip_address');
        $description = request_trimmed($_POST, 'description');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $message = __t('settings.allowlist_ip_invalid');
            form_remember('allowlist', $_POST, ['ip_address' => $message]);
            flash_set('error', $message);
        } elseif (repo_api_access_exists($connection, $ip)) {
            $message = __t('settings.allowlist_ip_exists');
            form_remember('allowlist', $_POST, ['ip_address' => $message]);
            flash_set('error', $message);
        } elseif (mb_strlen($description) > 255) {
            $message = __t('settings.allowlist_description_too_long');
            form_remember('allowlist', $_POST, ['description' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_api_access_add($connection, $ip, $description);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, 'client_ip', $ip, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'allowlisted'], (int) $user['id']);
                flash_set('success', __t('settings.allowlist_added'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_retire_threshold') {
        $threshold = request_int($_POST, 'retire_threshold');
        if ($threshold < VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN || $threshold > VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX) {
            $message = __t('settings.retire_threshold_invalid', [
                'min' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN,
                'max' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX,
            ]);
            form_remember('retire', $_POST, ['retire_threshold' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD, (string) $threshold);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'package_retire_threshold_percent', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'updated',
                    'new_value' => (string) $threshold,
                ], (int) $user['id']);
                flash_set('success', __t('settings.retire_threshold_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'allow_delete') {
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

    // Through the builder, so a map entry naming a tab the page does not render
    // is loud instead of silently landing every save on the first tab. It throws
    // after the write, which is the right way round: the save is done and the
    // flash is queued, and SettingsDeepLinkContractTest keeps it unreachable.
    redirect_to(settings_url($actionTabs[$action]));
}
