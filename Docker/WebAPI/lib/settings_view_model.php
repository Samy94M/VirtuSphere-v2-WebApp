<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/api_access.php';
require_once __DIR__ . '/repo/settings.php';

/** @return array<string,mixed> */
function settings_build_view_model(mysqli $connection): array
{
    $apiBaseUrlConfiguration = ansible_api_base_url_configuration($connection);
    $storedApiBaseUrl = $apiBaseUrlConfiguration['source'] === 'portal'
        ? $apiBaseUrlConfiguration['value']
        : '';
    $apiBaseUrlSource = $apiBaseUrlConfiguration['source'];
    $apiBaseUrlSourceLabel = match ($apiBaseUrlSource) {
        'portal' => __t('settings.api_base_url_source_portal'),
        'env' => __t('settings.api_base_url_source_env'),
        default => __t('settings.api_base_url_source_none'),
    };
    $apiBaseUrlSourceBadge = match ($apiBaseUrlSource) {
        'portal' => 'badge-info',
        'env' => 'badge-neutral',
        default => 'badge-warning',
    };
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
    $esxiAnsibleResolution = esxi_inventory_ansible_resolution($connection);
    $esxiSelectedAnsible = form_old(
        'esxi',
        'esxi_inventory_ansible_credential_id',
        (string) $esxiAnsibleResolution['configured_id']
    );
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

    return compact(
        'apiBaseUrlConfiguration',
        'storedApiBaseUrl',
        'apiBaseUrlSource',
        'apiBaseUrlSourceLabel',
        'apiBaseUrlSourceBadge',
        'effectiveApiBaseUrl',
        'effectiveApiBaseUrlError',
        'reportTokenSet',
        'retireThreshold',
        'reportTokenOnce',
        'allowlistEntries',
        'serverEpoch',
        'backup',
        'backupState',
        'backupLast',
        'backupTs',
        'backupAgeHours',
        'backupNextTs',
        'backupNextHours',
        'backupOverdueAt',
        'backupIsOverdue',
        'currentTimezone',
        'timezoneGroups',
        'esxiIntervalHours',
        'esxiAnsibleResolution',
        'esxiSelectedAnsible',
        'sessionLifetimeMinutes',
        'passwordMinLength',
        'retentionRows',
        'httpsEnabled',
        'httpsRedirectEnabled',
        'httpsHstsEnabled',
        'httpsMeta',
        'httpsListenerLive',
        'httpsPort',
        'settingsTabs'
    );
}
