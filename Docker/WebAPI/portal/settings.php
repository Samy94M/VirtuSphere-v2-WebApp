<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/ansible.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/settings.php';
require_once __DIR__ . '/../lib/repo/api_access.php';

$user = portal_require_user($connection);
if (!can('system.config', $user)) {
    portal_forbid($connection, $user, 'system.config');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

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
        'save_probe' => 'machine-api',
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

    if ($action === 'save_api') {
        try {
            $apiBaseUrl = ansible_normalize_api_base_url(request_string($_POST, 'api_base_url'));
            repo_set_setting($connection, VIRTUSPHERE_SETTING_API_BASE_URL, $apiBaseUrl);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated deploy api_base_url setting', (int) $user['id']);
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
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'cleared deploy api_base_url setting', (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated portal timezone to ' . $timezone, (int) $user['id']);
                flash_set('success', __t('settings.timezone_saved'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_esxi_inventory') {
        $hours = request_int($_POST, 'esxi_inventory_interval_hours', VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT);
        if ($hours < VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN || $hours > VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX) {
            $message = __t('settings.esxi_interval_invalid', [
                'min' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN,
                'max' => VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX,
            ]);
            form_remember('esxi', $_POST, ['esxi_inventory_interval_hours' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) $hours);
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated esxi inventory interval to ' . $hours . 'h', (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated session lifetime to ' . $minutes . ' minutes', (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated password min length to ' . $minLength, (int) $user['id']);
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
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'installed https certificate (CN=' . $meta['subject'] . ', expires ' . gmdate('Y-m-d', $meta['valid_to']) . ' UTC)', (int) $user['id']);
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
            $redirectWasOn = repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0') === '1';
            repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_ENABLED, $enable ? '1' : '0');
            if (!$enable && $redirectWasOn) {
                // Never leave a redirect pointing at a listener that is gone.
                repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0');
            }
            https_apply_state($connection);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, $enable ? 'enabled https' : 'disabled https' . ($redirectWasOn ? ' (redirect auto-disabled)' : ''), (int) $user['id']);
            flash_set('success', $enable ? __t('settings.https_enabled_on') : (!$redirectWasOn ? __t('settings.https_enabled_off') : __t('settings.https_enabled_off_redirect')));
        } catch (ValidationException $exception) {
            flash_set('error', portal_error_message($exception));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'save_https_redirect') {
        $enable = ($_POST['https_redirect_enabled'] ?? '') === '1';
        try {
            if ($enable && repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0') !== '1') {
                throw new ValidationException(['https_redirect_enabled' => __t('settings.https_err_redirect_requires')]);
            }
            repo_set_setting($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, $enable ? '1' : '0');
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ($enable ? 'enabled' : 'disabled') . ' http to https redirect', (int) $user['id']);
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
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ($enable ? 'enabled' : 'disabled') . ' hsts', (int) $user['id']);
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
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'generated machine report token', (int) $user['id']);
            flash_set('success', __t('settings.report_token_generated'));
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    } elseif ($action === 'clear_token') {
        try {
            repo_set_setting($connection, VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH, '');
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'cleared machine report token', (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'allowlisted machine API ip ' . $ip, (int) $user['id']);
                flash_set('success', __t('settings.allowlist_added'));
            } catch (Throwable $exception) {
                flash_set('error', portal_error_message($exception));
            }
        }
    } elseif ($action === 'save_probe') {
        $probeHost = request_trimmed($_POST, 'probe_host');
        $probePort = request_trimmed($_POST, 'probe_port');
        $portValue = $probePort === '' ? VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT : (int) $probePort;
        $hostOk = $probeHost === ''
            || filter_var($probeHost, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $probeHost) === 1;
        if (!$hostOk) {
            $message = __t('settings.probe_host_invalid');
            form_remember('probe', $_POST, ['probe_host' => $message]);
            flash_set('error', $message);
        } elseif ($portValue < 1 || $portValue > 65535) {
            $message = __t('settings.probe_port_invalid');
            form_remember('probe', $_POST, ['probe_port' => $message]);
            flash_set('error', $message);
        } else {
            try {
                repo_set_setting($connection, VIRTUSPHERE_SETTING_MECM_PROBE_HOST, $probeHost);
                repo_set_setting($connection, VIRTUSPHERE_SETTING_MECM_PROBE_PORT, (string) $portValue);
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated mecm probe target setting', (int) $user['id']);
                flash_set('success', __t('settings.probe_saved'));
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'updated package retire threshold to ' . $threshold . '%', (int) $user['id']);
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
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'removed machine API ip ' . (string) $entry['ipAddress'], (int) $user['id']);
                flash_set('success', __t('settings.allowlist_removed'));
            }
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    }

    redirect_to('settings.php#panel-' . ($actionTabs[$action] ?? 'deploy'));
}

$storedApiBaseUrl = repo_setting_value($connection, VIRTUSPHERE_SETTING_API_BASE_URL);
$effectiveApiBaseUrl = '';
$effectiveApiBaseUrlError = '';
try {
    $effectiveApiBaseUrl = ansible_resolve_api_base_url($connection);
} catch (InvalidArgumentException $exception) {
    $effectiveApiBaseUrlError = __t('settings.api_base_url_invalid');
} catch (RuntimeException $exception) {
    $effectiveApiBaseUrlError = __t('settings.api_base_url_missing');
} catch (Throwable $exception) {
    $effectiveApiBaseUrlError = portal_error_message($exception);
}

$reportTokenSet = repo_setting_value($connection, VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH) !== '';
$probeHost = repo_setting_value($connection, VIRTUSPHERE_SETTING_MECM_PROBE_HOST);
$probePort = repo_setting_value($connection, VIRTUSPHERE_SETTING_MECM_PROBE_PORT, (string) VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT);
$retireThreshold = repo_setting_value($connection, VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD, (string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT);
$reportTokenOnce = (string) ($_SESSION['machine_report_token_once'] ?? '');
unset($_SESSION['machine_report_token_once']);
$allowlistEntries = repo_api_access_entries($connection);

// One clock for the whole card, so the badge, the countdown and the overdue row
// can never disagree about "now".
$serverEpoch = time();

$backup = backup_status_read(null, $serverEpoch);
$backupState = (string) $backup['state'];
$backupLast = is_array($backup['last']) ? $backup['last'] : [];
$backupTs = isset($backupLast['ts']) && is_numeric($backupLast['ts']) ? (int) $backupLast['ts'] : 0;
$backupAgeHours = $backup['age_seconds'] !== null ? (int) floor($backup['age_seconds'] / 3600) : null;
$backupNextTs = $backup['next_run_ts'];
$backupNextHours = $backupNextTs !== null ? (int) floor(($backupNextTs - $serverEpoch) / 3600) : null;
$backupOverdueAt = $backup['overdue_at'];
$backupIsOverdue = $backupOverdueAt !== null && $serverEpoch > $backupOverdueAt;

$currentTimezone = portal_timezone();
$timezoneGroups = portal_timezone_choices($currentTimezone);
$esxiIntervalHours = repo_setting_value($connection, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, (string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT);
$sessionLifetimeMinutes = repo_setting_value($connection, VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES, (string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT);
$passwordMinLength = repo_setting_value($connection, VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH, (string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT);

// Read-only view of the ADR-0026 retention windows. They are code constants on
// purpose (no settings option); the card interpolates them so text and
// behavior cannot drift apart.
$retentionRows = [
    'retention_security' => VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS,
    'retention_general' => VIRTUSPHERE_LOG_RETENTION_DAYS,
    'retention_login_attempts' => VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS,
    'retention_job_logs' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS,
    'retention_client_events' => VIRTUSPHERE_CLIENT_EVENT_RETENTION_DAYS,
];

$httpsEnabled = repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0') === '1';
$httpsRedirectEnabled = repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0') === '1';
$httpsHstsEnabled = repo_setting_value($connection, VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED, '0') === '1';
$httpsMeta = https_installed_metadata();
$httpsListenerLive = https_listener_live();
$httpsPort = envboot_optional('WEB_HTTPS_PORT', '8443');

$settingsTabs = [
    'deploy' => __t('settings.tab_deploy'),
    'machine-api' => __t('settings.tab_machine_api'),
    'catalog' => __t('settings.tab_catalog'),
    'https' => __t('settings.tab_https'),
    'system' => __t('settings.tab_system'),
];

layout_header(__t('settings.title'), $user, 'settings', 'settings');
?>
<div class="stack" data-tabs>
    <div class="tab-list" role="tablist" aria-label="<?php echo h(__t('settings.tabs_label')); ?>" data-tab-list hidden>
        <?php foreach ($settingsTabs as $tabKey => $tabLabel): ?>
            <button type="button" class="tab" id="tab-<?php echo h($tabKey); ?>" role="tab"
                    aria-controls="panel-<?php echo h($tabKey); ?>" aria-selected="false"
                    data-tab-target="panel-<?php echo h($tabKey); ?>"><?php echo h($tabLabel); ?></button>
        <?php endforeach; ?>
    </div>

    <div class="stack" id="panel-deploy" role="tabpanel" aria-labelledby="tab-deploy" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('settings.deploy_settings_title')); ?></h2>
            <div class="alert alert-info">
                <strong><?php echo h(__t('settings.why_title')); ?></strong>
                <p><?php echo h(__t('settings.why_body')); ?></p>
                <ul>
                    <li><?php echo h(__t('settings.same_host_hint')); ?> <code><?php echo h(__t('settings.same_host_example')); ?></code></li>
                    <li><?php echo h(__t('settings.other_host_hint')); ?> <code><?php echo h(__t('settings.other_host_example')); ?></code></li>
                    <li><?php echo h(__t('settings.test_hint')); ?> <code><?php echo h(__t('settings.test_command')); ?></code></li>
                </ul>
            </div>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_api">
                <label><?php echo h(__t('settings.api_base_url_label')); ?>
                    <input name="api_base_url" value="<?php echo h(form_old('settings', 'api_base_url', $storedApiBaseUrl)); ?>"<?php echo form_input_class('settings', 'api_base_url'); ?> placeholder="http://virtusphere.local:8021" required>
                    <?php echo form_error_html('settings', 'api_base_url'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
            <?php if ($storedApiBaseUrl !== '') { ?>
                <p class="muted"><?php echo h(__t('settings.api_base_url_reset_hint')); ?></p>
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="clear_api">
                    <div class="actions"><button class="button" type="submit" data-confirm="<?php echo h(__t('settings.api_base_url_reset_confirm')); ?>"><?php echo h(__t('settings.api_base_url_reset')); ?></button></div>
                </form>
            <?php } ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.runtime_title')); ?></h2>
            <?php if ($effectiveApiBaseUrlError !== '') { ?>
                <div class="alert alert-info"><?php echo h($effectiveApiBaseUrlError); ?></div>
            <?php } else { ?>
                <div class="table-wrap" tabindex="0"><table>
                    <tbody>
                        <tr><th><?php echo h(__t('settings.effective_api_url')); ?></th><td><?php echo h($effectiveApiBaseUrl); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.ansible_mode')); ?></th><td><?php echo h(__t('settings.ansible_mode_ssh')); ?></td></tr>
                    </tbody>
                </table></div>
            <?php } ?>
        </section>
    </div>

    <div class="stack" id="panel-machine-api" role="tabpanel" aria-labelledby="tab-machine-api" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.allowlist_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.allowlist_hint')); ?></p>
            <div class="table-wrap" tabindex="0">
                <table>
                    <thead><tr><th><?php echo h(__t('settings.allowlist_th_ip')); ?></th><th><?php echo h(__t('settings.allowlist_th_description')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($allowlistEntries as $entry) { ?>
                        <tr>
                            <td><code><?php echo h((string) $entry['ipAddress']); ?></code></td>
                            <td><?php echo h((string) ($entry['description'] ?? '')); ?></td>
                            <td class="actions">
                                <form method="post" action="settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="allow_delete">
                                    <input type="hidden" name="id" value="<?php echo h((string) $entry['id']); ?>">
                                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('settings.allowlist_confirm_delete', ['name' => (string) ($entry['ipAddress'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if ($allowlistEntries === []) { ?>
                        <tr><td colspan="3"><?php echo h(__t('settings.allowlist_empty')); ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="allow_create">
                <label><?php echo h(__t('settings.allowlist_th_ip')); ?>
                    <input name="ip_address" value="<?php echo h(form_old('allowlist', 'ip_address', '')); ?>"<?php echo form_input_class('allowlist', 'ip_address'); ?> placeholder="10.0.0.10" required>
                    <?php echo form_error_html('allowlist', 'ip_address'); ?>
                </label>
                <label><?php echo h(__t('settings.allowlist_th_description')); ?>
                    <input name="description" value="<?php echo h(form_old('allowlist', 'description', '')); ?>"<?php echo form_input_class('allowlist', 'description'); ?> maxlength="255" placeholder="<?php echo h(__t('settings.allowlist_description_placeholder')); ?>">
                    <?php echo form_error_html('allowlist', 'description'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('settings.allowlist_add')); ?></button></div>
            </form>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.probe_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.probe_hint', ['minutes' => intdiv(VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS, 60)])); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_probe">
                <label><?php echo h(__t('settings.probe_host_label')); ?>
                    <input name="probe_host" value="<?php echo h(form_old('probe', 'probe_host', $probeHost)); ?>"<?php echo form_input_class('probe', 'probe_host'); ?> placeholder="<?php echo h(__t('settings.probe_host_placeholder')); ?>">
                    <?php echo form_error_html('probe', 'probe_host'); ?>
                </label>
                <label><?php echo h(__t('settings.probe_port_label')); ?>
                    <input name="probe_port" type="number" min="1" max="65535" value="<?php echo h(form_old('probe', 'probe_port', $probePort)); ?>"<?php echo form_input_class('probe', 'probe_port'); ?>>
                    <?php echo form_error_html('probe', 'probe_port'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.report_token_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.report_token_hint')); ?></p>
            <?php if ($reportTokenOnce !== '') { ?>
                <div class="alert alert-info">
                    <strong><?php echo h(__t('settings.report_token_once')); ?></strong>
                    <p><code><?php echo h($reportTokenOnce); ?></code></p>
                </div>
            <?php } ?>
            <p>
                <?php echo h(__t('settings.report_token_status')); ?>
                <?php echo portal_badge($reportTokenSet ? 'success' : 'neutral', $reportTokenSet ? __t('settings.report_token_set') : __t('settings.report_token_unset')); ?>
            </p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_token">
                    <?php
                    // Generating the first token is harmless. Generating over an existing one
                    // invalidates the token deployed in the MECM server's registry, so that
                    // branch asks first. The attribute is omitted, never rendered empty: the
                    // [data-confirm] selector matches a blank value too.
                    ?>
                    <button class="button" type="submit"<?php echo $reportTokenSet ? ' data-confirm="' . h(__t('settings.report_token_confirm_regenerate')) . '"' : ''; ?>><?php echo h($reportTokenSet ? __t('settings.report_token_regenerate') : __t('settings.report_token_generate')); ?></button>
                </form>
                <?php if ($reportTokenSet) { ?>
                    <form method="post" action="settings.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_token">
                        <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('settings.report_token_confirm_clear')); ?>"><?php echo h(__t('settings.report_token_clear')); ?></button>
                    </form>
                <?php } ?>
            </div>
        </section>
    </div>

    <div class="stack" id="panel-catalog" role="tabpanel" aria-labelledby="tab-catalog" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.retire_threshold_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.retire_threshold_hint', [
                'default' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT,
                'min' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN,
                'max' => VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX,
            ])); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_retire_threshold">
                <label><?php echo h(__t('settings.retire_threshold_label')); ?>
                    <input name="retire_threshold" type="number" min="<?php echo h((string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX); ?>" value="<?php echo h(form_old('retire', 'retire_threshold', $retireThreshold)); ?>"<?php echo form_input_class('retire', 'retire_threshold'); ?>>
                    <?php echo form_error_html('retire', 'retire_threshold'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.esxi_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.esxi_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_esxi_inventory">
                <label><?php echo h(__t('settings.esxi_interval_label')); ?>
                    <input name="esxi_inventory_interval_hours" type="number" min="<?php echo h((string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX); ?>" value="<?php echo h(form_old('esxi', 'esxi_inventory_interval_hours', $esxiIntervalHours)); ?>"<?php echo form_input_class('esxi', 'esxi_inventory_interval_hours'); ?>>
                    <?php echo form_error_html('esxi', 'esxi_inventory_interval_hours'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
            <p class="muted"><?php echo h(__t('settings.esxi_ansible_note')); ?></p>
        </section>
    </div>

    <div class="stack" id="panel-https" role="tabpanel" aria-labelledby="tab-https" tabindex="0" data-tab-panel hidden>
        <section class="panel">
            <h2><?php echo h(__t('settings.https_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_hint')); ?></p>
            <?php if ($httpsEnabled && !$httpsListenerLive) { ?>
                <?php // The setting says "on" but the web server threw the generated config
                      // out (init.sh quarantine). Without this the card claims HTTPS is live
                      // while nothing listens, and the redirect has already been suppressed. ?>
                <div class="alert alert-warning"><?php echo h(__t('settings.https_quarantined')); ?></div>
            <?php } ?>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr>
                        <th><?php echo h(__t('settings.https_enabled_title')); ?></th>
                        <td><?php echo portal_badge($httpsEnabled ? 'success' : 'neutral', $httpsEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.https_redirect_title')); ?></th>
                        <td><?php echo portal_badge($httpsRedirectEnabled ? 'success' : 'neutral', $httpsRedirectEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.https_hsts_title')); ?></th>
                        <td><?php echo portal_badge($httpsHstsEnabled ? 'success' : 'neutral', $httpsHstsEnabled ? __t('settings.https_state_on') : __t('settings.https_state_off')); ?></td>
                    </tr>
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.https_upload_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_upload_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" enctype="multipart/form-data" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="upload_https_cert">
                <label><?php echo h(__t('settings.https_cert_label')); ?>
                    <input name="cert_file" type="file"<?php echo form_input_class('https_upload', 'cert_file'); ?>>
                    <?php echo form_error_html('https_upload', 'cert_file'); ?>
                </label>
                <label><?php echo h(__t('settings.https_key_label')); ?>
                    <input name="key_file" type="file"<?php echo form_input_class('https_upload', 'key_file'); ?>>
                    <?php echo form_error_html('https_upload', 'key_file'); ?>
                </label>
                <label><?php echo h(__t('settings.https_password_label')); ?>
                    <input name="pfx_password" type="password" autocomplete="off"<?php echo form_input_class('https_upload', 'pfx_password'); ?>>
                    <?php echo form_error_html('https_upload', 'pfx_password'); ?>
                </label>
                <?php
                // The first upload is harmless; overwriting an installed
                // certificate asks first. Omitted, never rendered empty.
                ?>
                <div class="actions"><button class="button" type="submit"<?php echo $httpsMeta !== null ? ' data-confirm="' . h(__t('settings.https_confirm_overwrite')) . '"' : ''; ?>><?php echo h(__t('settings.https_upload_button')); ?></button></div>
            </form>
            <h3><?php echo h(__t('settings.https_meta_title')); ?></h3>
            <?php if ($httpsMeta === null) { ?>
                <p class="muted"><?php echo h(__t('settings.https_meta_none')); ?></p>
            <?php } else { ?>
                <?php if ($httpsMeta['days_remaining'] <= VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS) { ?>
                    <p><?php echo portal_badge('warning', __t('settings.https_meta_expires_soon', ['days' => max(0, $httpsMeta['days_remaining'])])); ?></p>
                <?php } ?>
                <div class="table-wrap" tabindex="0"><table>
                    <tbody>
                        <tr><th><?php echo h(__t('settings.https_meta_subject')); ?></th><td><?php echo h($httpsMeta['subject']); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_sans')); ?></th><td><?php echo $httpsMeta['sans'] !== '' ? h($httpsMeta['sans']) : '<span class="muted">&mdash;</span>'; ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_issuer')); ?></th><td><?php echo h($httpsMeta['issuer']); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_valid_from')); ?></th><td><?php echo h(portal_format_epoch($httpsMeta['valid_from'])); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_valid_to')); ?></th><td><?php echo h(portal_format_epoch($httpsMeta['valid_to'])); ?></td></tr>
                        <tr><th><?php echo h(__t('settings.https_meta_fingerprint')); ?></th><td><code class="wrap-anywhere"><?php echo h($httpsMeta['fingerprint']); ?></code></td></tr>
                    </tbody>
                </table></div>
            <?php } ?>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('settings.https_toggles_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.https_enabled_hint', ['port' => $httpsPort])); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_enabled">
                    <input type="hidden" name="https_enabled" value="<?php echo $httpsEnabled ? '0' : '1'; ?>">
                    <?php
                    // Enabling just adds a listener; disabling drops every
                    // HTTPS session (and the redirect with it), so only that
                    // branch asks. Omitted, never rendered empty.
                    ?>
                    <button class="button" type="submit"<?php echo $httpsEnabled ? ' data-confirm="' . h(__t('settings.https_confirm_disable')) . '"' : ''; ?>><?php echo h($httpsEnabled ? __t('settings.https_disable_button') : __t('settings.https_enable_button')); ?></button>
                </form>
            </div>
            <p class="muted"><?php echo h(__t('settings.https_redirect_hint')); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_redirect">
                    <input type="hidden" name="https_redirect_enabled" value="<?php echo $httpsRedirectEnabled ? '0' : '1'; ?>">
                    <?php
                    // Enabling moves every HTTP browser to the new listener
                    // (lockout risk with an untrusted cert), so that branch
                    // asks; switching it off is the recovery path and stays
                    // one click.
                    ?>
                    <button class="button" type="submit"<?php echo !$httpsRedirectEnabled ? ' data-confirm="' . h(__t('settings.https_confirm_redirect')) . '"' : ''; ?>><?php echo h($httpsRedirectEnabled ? __t('settings.https_redirect_disable_button') : __t('settings.https_redirect_enable_button')); ?></button>
                </form>
            </div>
            <p class="muted"><?php echo h(__t('settings.https_hsts_hint', ['days' => intdiv(VIRTUSPHERE_HTTPS_HSTS_MAX_AGE_SECONDS, 86400)])); ?></p>
            <div class="actions">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_https_hsts">
                    <input type="hidden" name="https_hsts_enabled" value="<?php echo $httpsHstsEnabled ? '0' : '1'; ?>">
                    <button class="button" type="submit"><?php echo h($httpsHstsEnabled ? __t('settings.https_hsts_disable_button') : __t('settings.https_hsts_enable_button')); ?></button>
                </form>
            </div>
        </section>
    </div>

    <div class="stack" id="panel-system" role="tabpanel" aria-labelledby="tab-system" tabindex="0" data-tab-panel hidden>
        <section class="panel" id="panel-time">
            <h2><?php echo h(__t('settings.time_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.time_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_timezone">
                <label><?php echo h(__t('settings.timezone_label')); ?>
                    <select name="timezone"<?php echo form_input_class('timezone', 'timezone'); ?>>
                        <?php $selectedTz = form_old('timezone', 'timezone', $currentTimezone); ?>
                        <?php foreach ($timezoneGroups as $groupKey => $identifiers) { ?>
                            <optgroup label="<?php echo h(__t('settings.timezone_group_' . $groupKey)); ?>">
                                <?php foreach ($identifiers as $tz) { ?>
                                    <option value="<?php echo h($tz); ?>"<?php echo $tz === $selectedTz ? ' selected' : ''; ?>><?php echo h(portal_timezone_option_label($tz)); ?></option>
                                <?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                    <?php echo form_error_html('timezone', 'timezone'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr><th><?php echo h(__t('settings.time_server_now')); ?></th><td><?php echo h(portal_now_label()); ?></td></tr>
                </tbody>
            </table></div>
            <p class="alert alert-warning" data-time-drift hidden></p>
            <p class="muted"><?php echo h(__t('settings.time_ntp_hint')); ?></p>
            <script type="application/json" data-server-time nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
                echo json_encode([
                    'epoch' => $serverEpoch,
                    'warn_seconds' => 120,
                    'drift_message' => __t('settings.time_drift_warn'),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            ?></script>
        </section>

        <section class="panel" id="panel-session">
            <h2><?php echo h(__t('settings.session_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.session_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_session">
                <label><?php echo h(__t('settings.session_label')); ?>
                    <input name="session_lifetime_minutes" type="number" min="<?php echo h((string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX); ?>" value="<?php echo h(form_old('session', 'session_lifetime_minutes', $sessionLifetimeMinutes)); ?>"<?php echo form_input_class('session', 'session_lifetime_minutes'); ?>>
                    <?php echo form_error_html('session', 'session_lifetime_minutes'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel" id="panel-password-policy">
            <h2><?php echo h(__t('settings.password_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.password_hint')); ?></p>
            <form class="form-grid" method="post" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_password_policy">
                <label><?php echo h(__t('settings.password_label')); ?>
                    <input name="password_min_length" type="number" min="<?php echo h((string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX); ?>" value="<?php echo h(form_old('password_policy', 'password_min_length', $passwordMinLength)); ?>"<?php echo form_input_class('password_policy', 'password_min_length'); ?>>
                    <?php echo form_error_html('password_policy', 'password_min_length'); ?>
                </label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div>
            </form>
        </section>

        <section class="panel" id="panel-retention">
            <h2><?php echo h(__t('settings.retention_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.retention_hint')); ?></p>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <?php foreach ($retentionRows as $rowKey => $days) { ?>
                        <tr>
                            <th><?php echo h(__t('settings.' . $rowKey)); ?></th>
                            <td><?php echo h(__t('settings.retention_days', ['days' => $days])); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table></div>
        </section>

        <section class="panel" id="panel-backup">
            <h2><?php echo h(__t('settings.backup_title')); ?></h2>
            <p class="muted"><?php echo h(__t('settings.backup_hint')); ?></p>
            <?php if ($backupState !== VIRTUSPHERE_BACKUP_STATE_OK) { ?>
                <div class="alert <?php echo h(backup_status_alert_class($backupState)); ?>"><?php echo h(backup_status_message($backupState)); ?></div>
            <?php } ?>
            <div class="table-wrap" tabindex="0"><table>
                <tbody>
                    <tr>
                        <th><?php echo h(__t('settings.backup_state')); ?></th>
                        <td><span class="badge <?php echo h(backup_status_badge_class($backupState)); ?>"><?php echo h(backup_status_label($backupState)); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.backup_schedule')); ?></th>
                        <td>
                            <?php if ($backup['schedule'] !== '') { ?>
                                <code><?php echo h($backup['schedule']); ?></code>
                                <span class="muted"><?php echo h(__t('settings.backup_schedule_from', ['source' => $backup['schedule_source']])); ?></span>
                            <?php } elseif ($backup['schedule_source'] !== '') { ?>
                                <code><?php echo h($backup['schedule_source']); ?></code>
                            <?php } else { ?>
                                <?php echo h(__t('settings.backup_schedule_none')); ?>
                                <span class="muted"><?php echo h(__t('settings.backup_schedule_none_hint')); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo h(__t('settings.backup_last_run')); ?></th>
                        <td><?php echo $backupTs > 0 ? h(portal_format_epoch($backupTs)) : h(__t('settings.backup_never')); ?></td>
                    </tr>
                    <?php if ($backupAgeHours !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_age')); ?></th>
                        <td><?php echo h($backupAgeHours >= 1
                            ? __t('settings.backup_age_hours', ['hours' => $backupAgeHours])
                            : __t('settings.backup_age_recent')); ?></td>
                    </tr>
                    <?php } ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_next_run')); ?></th>
                        <td>
                            <?php if ($backupNextTs === null) { ?>
                                &mdash; <span class="muted"><?php echo h(__t('settings.backup_next_unknown')); ?></span>
                            <?php } else { ?>
                                <?php echo h(portal_format_epoch($backupNextTs)); ?>
                                <span class="muted"><?php
                                    echo h($backupNextHours !== null && $backupNextHours >= 1
                                        ? __t('settings.backup_next_in_hours', ['hours' => $backupNextHours])
                                        : __t('settings.backup_next_soon'));
                                ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php if ($backupOverdueAt !== null) { ?>
                    <tr>
                        <th><?php echo h(__t($backupIsOverdue ? 'settings.backup_overdue_since' : 'settings.backup_overdue_at')); ?></th>
                        <td><?php echo h(portal_format_epoch($backupOverdueAt)); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['db_bytes'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_db_size')); ?></th>
                        <td><?php echo h(backup_status_human_bytes((int) $backupLast['db_bytes'])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['config_bytes'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_config_size')); ?></th>
                        <td><?php echo h(backup_status_human_bytes((int) $backupLast['config_bytes'])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['disk_free_pct'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_disk_free')); ?></th>
                        <td><?php echo h((string) (int) $backupLast['disk_free_pct']); ?>&thinsp;%<?php
                            if (($backupLast['disk_free_bytes'] ?? null) !== null) {
                                echo ' (' . h(backup_status_human_bytes((int) $backupLast['disk_free_bytes'])) . ')';
                            }
                        ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['keep'] ?? null) !== null) { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_retention')); ?></th>
                        <td><?php echo h(__t('settings.backup_retention_value', ['count' => (int) $backupLast['keep']])); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (($backupLast['error'] ?? '') !== '') { ?>
                    <tr>
                        <th><?php echo h(__t('settings.backup_error')); ?></th>
                        <td><?php echo h((string) $backupLast['error']); ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table></div>
            <p><?php echo h(__t('settings.backup_ops_where')); ?></p>
            <p><code>sudo sh scripts/install-backup-schedule.sh --schedule "0 6 * * *"</code></p>
            <p class="muted"><a href="help.php#help-backup"><?php echo h(__t('settings.backup_ops_more')); ?></a></p>
        </section>
    </div>
</div>
<?php layout_footer(); ?>
