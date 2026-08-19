<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/directory_identity.php';
require_once __DIR__ . '/directory_result.php';
require_once __DIR__ . '/directory_tls.php';

function directory_ldap_available(): bool
{
    return extension_loaded('ldap') && function_exists('ldap_connect');
}

function directory_ldap_require_option(mixed $connection, int $option, mixed $value): void
{
    if (!@ldap_set_option($connection, $option, $value)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
}

function directory_ldap_apply_deadline(mixed $connection, ?float $deadline): void
{
    directory_ldap_require_option($connection, LDAP_OPT_NETWORK_TIMEOUT, directory_deadline_remaining_seconds($deadline, VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS));
    directory_ldap_require_option($connection, LDAP_OPT_TIMELIMIT, directory_deadline_remaining_seconds($deadline, VIRTUSPHERE_DIRECTORY_OPERATION_TIMEOUT_SECONDS));
}

/** @return mixed LDAP\Connection when ext-ldap is available */
function directory_ldap_connect(string $host, int $port, string $caFile, ?float $deadline = null): mixed
{
    if (!directory_ldap_available()) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING);
    }
    directory_ldap_require_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caFile);
    directory_ldap_require_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
    if (defined('LDAP_OPT_X_TLS_PROTOCOL_MIN') && defined('LDAP_OPT_X_TLS_PROTOCOL_TLS1_2')) {
        directory_ldap_require_option(null, LDAP_OPT_X_TLS_PROTOCOL_MIN, LDAP_OPT_X_TLS_PROTOCOL_TLS1_2);
    }

    $connection = @ldap_connect('ldaps://' . $host . ':' . $port);
    if ($connection === false) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, true);
    }
    directory_ldap_require_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    directory_ldap_require_option($connection, LDAP_OPT_REFERRALS, 0);
    directory_ldap_apply_deadline($connection, $deadline);
    directory_ldap_require_option($connection, LDAP_OPT_SIZELIMIT, VIRTUSPHERE_DIRECTORY_SEARCH_RESULT_LIMIT + 1);
    if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
        directory_ldap_require_option($connection, LDAP_OPT_X_TLS_NEWCTX, 0);
    }

    return $connection;
}

function directory_ldap_failure(
    mixed $connection,
    string $credentialRejection = VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED
): DirectoryLdapException
{
    $number = is_object($connection) ? ldap_errno($connection) : 0;
    if ($number === 49) {
        return new DirectoryLdapException($credentialRejection);
    }
    // -1 (no libldap result code available) is what the linked OpenLDAP client
    // actually reports for every connect-level failure: refused/unroutable
    // TCP, unresolvable DNS, a rejected TLS handshake (untrue CA, expired,
    // wrong name) and a fired LDAP_OPT_NETWORK_TIMEOUT all surface as errno
    // -1 "Can't contact LDAP server", not the protocol-level 81/85/91 this
    // used to check alone. Proven against the hermetic fixture
    // (DirectoryLdapFixtureTest): without -1 here, a controller with any of
    // those failures was misclassified as VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED
    // with transportFailure=false, so directory_read_with_failover() never
    // tried the next controller for the single most common real outage.
    if (in_array($number, [-1, 81, 85, 91], true)) {
        return new DirectoryLdapException(
            $number === 85 ? VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT : VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE,
            true
        );
    }

    return new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED);
}

function directory_ldap_bind(mixed $connection, string $identity, string $password, string $credentialRejection, ?float $deadline = null): void
{
    if ($password === '') {
        throw new DirectoryLdapException($credentialRejection);
    }
    directory_ldap_apply_deadline($connection, $deadline);
    if (!@ldap_bind($connection, $identity, $password)) {
        throw directory_ldap_failure($connection, $credentialRejection);
    }
}

/** @return array<string,mixed> */
function directory_ldap_single_entry(mixed $connection, mixed $result): array
{
    $entries = ldap_get_entries($connection, $result);
    $count = (int) ($entries['count'] ?? 0);
    if ($count === 0) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND);
    }
    if ($count !== 1 || !isset($entries[0]) || !is_array($entries[0])) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS);
    }

    return $entries[0];
}

/** @return array<string,mixed> */
function directory_ldap_read_root_dse(mixed $connection, ?float $deadline = null): array
{
    directory_ldap_apply_deadline($connection, $deadline);
    $result = @ldap_read(
        $connection,
        '',
        '(objectClass=*)',
        ['defaultNamingContext', 'dnsHostName', 'dsServiceName', 'supportedLDAPVersion']
    );
    if ($result === false) {
        throw directory_ldap_failure($connection);
    }

    return directory_ldap_single_entry($connection, $result);
}

function directory_ldap_first_text(array $entry, string $attribute): string
{
    $key = strtolower($attribute);
    $values = $entry[$key] ?? null;
    if (!is_array($values) || (int) ($values['count'] ?? 0) < 1) {
        return '';
    }

    return trim((string) ($values[0] ?? ''));
}

function directory_ldap_first_binary(array $entry, string $attribute): string
{
    $key = strtolower($attribute);
    $values = $entry[$key] ?? null;
    if (!is_array($values) || (int) ($values['count'] ?? 0) < 1) {
        return '';
    }

    return (string) ($values[0] ?? '');
}

function directory_ldap_assert_writable_dc(mixed $connection, array $rootDse, ?float $deadline = null): void
{
    $serviceDn = directory_ldap_first_text($rootDse, 'dsServiceName');
    if ($serviceDn === '') {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
    }
    directory_ldap_apply_deadline($connection, $deadline);
    $result = @ldap_read($connection, $serviceDn, '(objectClass=*)', ['msDS-isRODC']);
    if ($result === false) {
        throw directory_ldap_failure($connection);
    }
    $entry = directory_ldap_single_entry($connection, $result);
    $isRodc = strtoupper(directory_ldap_first_text($entry, 'msDS-isRODC'));
    if ($isRodc === 'TRUE') {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED);
    }
    if ($isRodc !== 'FALSE') {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
    }
}

function directory_ldap_assert_search_base(mixed $connection, string $baseDn, ?float $deadline = null): void
{
    directory_ldap_apply_deadline($connection, $deadline);
    $result = @ldap_read($connection, $baseDn, '(objectClass=*)', ['distinguishedName']);
    if ($result === false) {
        throw directory_ldap_failure($connection);
    }
    directory_ldap_single_entry($connection, $result);
}

/**
 * @param array{host:string,port:int} $controller
 * @param array{bind_upn:string,bind_password:string,ca_certificate_pem:string,user_search_base_dn:string,default_naming_context:string} $config
 * @return array{default_naming_context:string,certificate:array{fingerprint:string,not_after:string}}
 */
function directory_ldap_test_controller(array $controller, array $config): array
{
    $deadline = directory_deadline_now() + VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS;
    $caFile = directory_ca_file($config['ca_certificate_pem']);
    $certificate = directory_tls_probe($controller['host'], $controller['port'], $caFile, $deadline);
    $connection = directory_ldap_connect($controller['host'], $controller['port'], $caFile, $deadline);
    try {
        directory_ldap_bind(
            $connection,
            $config['bind_upn'],
            $config['bind_password'],
            VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
            $deadline
        );
        $root = directory_ldap_read_root_dse($connection, $deadline);
        $namingContext = directory_ldap_first_text($root, 'defaultNamingContext');
        $dnsHostName = strtolower(directory_ldap_first_text($root, 'dnsHostName'));
        if ($namingContext === '' || $dnsHostName !== strtolower($controller['host'])) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
        }
        if ($config['default_naming_context'] !== '' && strcasecmp($namingContext, $config['default_naming_context']) !== 0) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH);
        }
        directory_ldap_assert_writable_dc($connection, $root, $deadline);
        $searchBase = $config['user_search_base_dn'] !== '' ? $config['user_search_base_dn'] : $namingContext;
        directory_ldap_assert_search_base($connection, $searchBase, $deadline);

        return ['default_naming_context' => $namingContext, 'certificate' => $certificate];
    } finally {
        @ldap_unbind($connection);
    }
}

/** @return array{guid_bytes:string,upn:string,sam:string,display_name:string,email:string,dn:string,enabled:bool} */
function directory_ldap_normalize_user(array $entry): array
{
    $guid = directory_ldap_first_binary($entry, 'objectGUID');
    $upn = directory_ldap_first_text($entry, 'userPrincipalName');
    $dn = trim((string) ($entry['dn'] ?? ''));
    if (strlen($guid) !== 16 || !directory_upn_is_valid($upn) || $dn === '') {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
    }
    $uac = (int) directory_ldap_first_text($entry, 'userAccountControl');

    return [
        'guid_bytes' => $guid,
        'upn' => $upn,
        'sam' => directory_ldap_first_text($entry, 'sAMAccountName'),
        'display_name' => directory_ldap_first_text($entry, 'displayName'),
        'email' => directory_ldap_first_text($entry, 'mail'),
        'dn' => $dn,
        'enabled' => directory_account_is_enabled($uac, directory_ldap_first_text($entry, 'accountExpires')),
    ];
}

/** @return array{rows:list<array{guid_bytes:string,upn:string,sam:string,display_name:string,email:string,dn:string,enabled:bool}>,truncated:bool} */
function directory_ldap_search_users(mixed $connection, string $baseDn, string $filter, ?float $deadline = null): array
{
    directory_ldap_apply_deadline($connection, $deadline);
    $attributes = ['objectGUID', 'userPrincipalName', 'sAMAccountName', 'displayName', 'mail', 'userAccountControl', 'accountExpires'];
    $result = @ldap_search(
        $connection,
        $baseDn,
        $filter,
        $attributes,
        0,
        VIRTUSPHERE_DIRECTORY_SEARCH_RESULT_LIMIT + 1,
        directory_deadline_remaining_seconds($deadline, VIRTUSPHERE_DIRECTORY_OPERATION_TIMEOUT_SECONDS)
    );
    if ($result === false) {
        throw directory_ldap_failure($connection);
    }
    $entries = ldap_get_entries($connection, $result);
    $count = (int) ($entries['count'] ?? 0);
    $truncated = $count > VIRTUSPHERE_DIRECTORY_SEARCH_RESULT_LIMIT;
    $visibleCount = min($count, VIRTUSPHERE_DIRECTORY_SEARCH_RESULT_LIMIT);
    $normalized = [];
    for ($index = 0; $index < $visibleCount; $index++) {
        if (isset($entries[$index]) && is_array($entries[$index])) {
            $normalized[] = directory_ldap_normalize_user($entries[$index]);
        }
    }

    return ['rows' => $normalized, 'truncated' => $truncated];
}

/** @return array{guid_bytes:string,upn:string,sam:string,display_name:string,email:string,dn:string,enabled:bool} */
function directory_ldap_find_user_by_upn(mixed $connection, string $baseDn, string $upn, ?float $deadline = null): array
{
    $value = ldap_escape($upn, '', LDAP_ESCAPE_FILTER);
    $filter = '(&(objectCategory=person)(objectClass=user)(sAMAccountType=805306368)(userPrincipalName=' . $value . '))';
    $search = directory_ldap_search_users($connection, $baseDn, $filter, $deadline);
    $rows = $search['rows'];
    if ($rows === []) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND);
    }
    if (count($rows) !== 1) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS);
    }

    return $rows[0];
}

/** @return array{guid_bytes:string,upn:string,sam:string,display_name:string,email:string,dn:string,enabled:bool} */
function directory_ldap_find_user_by_guid(mixed $connection, string $baseDn, string $guidBytes, ?float $deadline = null): array
{
    $filter = '(&(objectCategory=person)(objectClass=user)(sAMAccountType=805306368)(objectGUID=' . directory_guid_filter_value($guidBytes) . '))';
    $search = directory_ldap_search_users($connection, $baseDn, $filter, $deadline);
    $rows = $search['rows'];
    if ($rows === []) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND);
    }
    if (count($rows) !== 1) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS);
    }

    return $rows[0];
}
