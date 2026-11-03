<?php

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth_schema.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/directory_ldap.php';
require_once __DIR__ . '/https_config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/repo/directory.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/users_page.php';
require_once __DIR__ . '/validate.php';

/** @return array{bind_upn:string,bind_password:string,bind_secret_ciphertext:string,ca_certificate_pem:string,user_search_base_dn:string,default_naming_context:string} */
function directory_config_candidate(array $input, ?array $existing): array
{
    $errors = [];
    $bindUpn = trim((string) ($input['bind_upn'] ?? ''));
    if (!directory_upn_is_valid($bindUpn)) {
        $errors['bind_upn'] = __t('directory.err_bind_upn');
    }

    $password = (string) ($input['bind_password'] ?? '');
    $ciphertext = '';
    if ($password === '') {
        $ciphertext = trim((string) ($existing['bind_secret_ciphertext'] ?? ''));
        if ($ciphertext === '') {
            $errors['bind_password'] = __t('directory.err_bind_password_required');
        } else {
            try {
                $password = crypto_decrypt_secret($ciphertext);
            } catch (Throwable) {
                $errors['bind_password'] = __t('directory.err_bind_secret_unreadable');
            }
        }
    } else {
        $ciphertext = crypto_encrypt_secret($password);
    }

    $caInput = (string) ($input['ca_certificate_pem'] ?? '');
    if (trim($caInput) === '' && $existing !== null) {
        $caInput = (string) ($existing['ca_certificate_pem'] ?? '');
    }
    try {
        $caBundle = directory_normalize_ca_bundle($caInput);
    } catch (InvalidArgumentException $exception) {
        $errors['ca_certificate_pem'] = $exception->getMessage() === 'private_key_not_allowed'
            ? __t('directory.err_ca_private_key')
            : __t('directory.err_ca_invalid');
        $caBundle = '';
    }

    $searchBase = trim((string) ($input['user_search_base_dn'] ?? ''));
    if (strlen($searchBase) > 1024 || str_contains($searchBase, "\0") || ($searchBase !== '' && !str_contains($searchBase, '='))) {
        $errors['user_search_base_dn'] = __t('directory.err_search_base');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }

    return [
        'bind_upn' => $bindUpn,
        'bind_password' => $password,
        'bind_secret_ciphertext' => $ciphertext,
        'ca_certificate_pem' => $caBundle,
        'user_search_base_dn' => $searchBase,
        'default_naming_context' => trim((string) ($existing['default_naming_context'] ?? '')),
    ];
}

/** @return array{bind_upn:string,bind_password:string,ca_certificate_pem:string,user_search_base_dn:string,default_naming_context:string} */
function directory_runtime_config(array $stored): array
{
    return [
        'bind_upn' => (string) $stored['bind_upn'],
        'bind_password' => crypto_decrypt_secret((string) $stored['bind_secret_ciphertext']),
        'ca_certificate_pem' => (string) $stored['ca_certificate_pem'],
        'user_search_base_dn' => trim((string) ($stored['user_search_base_dn'] ?? '')),
        'default_naming_context' => trim((string) ($stored['default_naming_context'] ?? '')),
    ];
}

function directory_is_enabled(mysqli $db): bool
{
    if (!directory_schema_available($db)) {
        return false;
    }
    $config = repo_directory_config($db);

    return $config !== null && (int) $config['enabled'] === 1;
}

function directory_active_local_admin_count(mysqli $db): int
{
    return (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_users WHERE auth_source = ? AND role = ? AND is_active = 1',
        'ss',
        [VIRTUSPHERE_AUTH_SOURCE_LOCAL, VIRTUSPHERE_ROLE_ADMIN]
    );
}

/** Locks every database-backed activation prerequisite in one stable order. */
function directory_lock_activation_state(mysqli $db): void
{
    repo_directory_config($db, true);
    $db->query("SELECT setting_key FROM deploy_settings WHERE setting_key IN ('https_enabled','https_redirect_enabled') ORDER BY setting_key FOR UPDATE");
    $db->query("SELECT id FROM deploy_users WHERE auth_source = 'local' AND role = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE");
    $db->query('SELECT id FROM deploy_ad_controllers WHERE config_id = 1 ORDER BY id FOR UPDATE');
}

/**
 * One blocker list owns both the UI notices and the server-side activation
 * gate. Links are included only where the current operator can act on them.
 *
 * @return list<array{code:string,message:string,url:string,label:string}>
 */
function directory_activation_blockers(mysqli $db, ?array $user = null): array
{
    $config = repo_directory_config($db);
    $blockers = [];
    if (!directory_ldap_available()) {
        $blockers[] = ['code' => 'extension', 'message' => __t('directory.block_extension'), 'url' => '', 'label' => ''];
    }
    $httpsEnabled = repo_setting_value($db, VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0') === '1';
    $redirectEnabled = repo_setting_value($db, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0') === '1';
    if (!$httpsEnabled || !$redirectEnabled || !https_listener_live()) {
        $canConfigure = $user !== null && can('system.config', $user);
        $blockers[] = [
            'code' => 'https',
            'message' => __t('directory.block_https'),
            'url' => $canConfigure ? settings_url(VIRTUSPHERE_SETTINGS_TAB_HTTPS) : '',
            'label' => $canConfigure ? __t('directory.open_https_settings') : '',
        ];
    }
    if (directory_active_local_admin_count($db) < 1) {
        $canUsers = $user !== null && can('users.manage', $user);
        $blockers[] = [
            'code' => 'local_admin',
            'message' => __t('directory.block_local_admin'),
            'url' => $canUsers ? users_url(VIRTUSPHERE_USERS_VIEW_ACCOUNTS) : '',
            'label' => $canUsers ? __t('directory.open_users') : '',
        ];
    }
    if ($config === null) {
        $blockers[] = [
            'code' => 'config',
            'message' => __t('directory.block_config'),
            'url' => users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'directory-config'),
            'label' => __t('directory.open_config'),
        ];

        return $blockers;
    }
    if (trim((string) ($config['default_naming_context'] ?? '')) === '') {
        $blockers[] = [
            'code' => 'naming_context',
            'message' => __t('directory.block_test'),
            'url' => users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'directory-controllers'),
            'label' => __t('directory.open_controllers'),
        ];
    }
    if ((int) ($config['automatic_bind_blocked_revision'] ?? 0) === (int) $config['revision']) {
        $blockers[] = [
            'code' => 'bind_blocked',
            'message' => __t('directory.block_bind_rejected'),
            'url' => users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'directory-controllers'),
            'label' => __t('directory.open_controllers'),
        ];
    }
    $usable = repo_directory_login_controllers($db, (int) $config['revision']);
    if ($usable === []) {
        $blockers[] = [
            'code' => 'controller',
            'message' => __t('directory.block_controller'),
            'url' => users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'directory-controllers'),
            'label' => __t('directory.open_controllers'),
        ];
    }

    return $blockers;
}
