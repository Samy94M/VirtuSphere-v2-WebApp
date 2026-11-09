<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';

function directory_host_is_valid(string $host): bool
{
    if ($host === '' || strlen($host) > VIRTUSPHERE_DIRECTORY_HOST_MAX_CHARS || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }
    if (str_ends_with($host, '.') || !str_contains($host, '.')) {
        return false;
    }

    return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
}

function directory_upn_is_valid(string $upn): bool
{
    if ($upn === '' || mb_strlen($upn) > VIRTUSPHERE_DIRECTORY_UPN_MAX_CHARS || str_contains($upn, "\0")) {
        return false;
    }

    return preg_match('/^[^@\s]+@[^@\s]+$/u', $upn) === 1;
}

/**
 * N1a A1/A8: the one rule for a password that may reach a controller. Empty is
 * an unauthenticated bind (RFC 4513 5.1.2); a NUL byte makes PHP's ldap_bind()
 * throw a TypeError, whose generic catch turned the answer into "directory
 * unavailable" for imported names only; above the byte bound it is no real
 * Windows password. Every caller treats a refusal exactly like an empty one.
 */
function directory_password_is_acceptable(string $password): bool
{
    return $password !== ''
        && !str_contains($password, "\0")
        && strlen($password) <= VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES;
}

/**
 * N1a A3: userAccountControl as a number, or null when the attribute is
 * missing or not a decimal integer. A search account that may not read it
 * must not make every disabled account look enabled.
 */
function directory_parse_account_control(string $value): ?int
{
    $value = trim($value);
    if (preg_match('/^-?[0-9]{1,10}$/', $value) !== 1) {
        return null;
    }
    $number = (int) $value;

    return $number >= -2147483648 && $number <= 4294967295 ? $number : null;
}

/**
 * N1a F1: pwdLastSet as a canonical decimal FILETIME string ('0' = must change
 * at next logon), or null when missing or malformed.
 */
function directory_parse_password_last_set(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^[0-9]{1,19}$/', $value) !== 1) {
        return null;
    }
    $canonical = ltrim($value, '0');

    return $canonical === '' ? '0' : $canonical;
}

/** True when $current is a later pwdLastSet than $baseline (both canonical). */
function directory_password_changed_since(string $baseline, string $current): bool
{
    if ($current === '0') {
        // An administrator set "must change at next logon": the session's
        // password is no longer the one the account has to use.
        return $baseline !== '0';
    }
    if (strlen($current) !== strlen($baseline)) {
        return strlen($current) > strlen($baseline);
    }

    return strcmp($current, $baseline) > 0;
}

function directory_guid_filter_value(string $guidBytes): string
{
    if (strlen($guidBytes) !== 16) {
        throw new InvalidArgumentException('invalid_guid');
    }
    if (!function_exists('ldap_escape')) {
        throw new RuntimeException('ldap_extension_missing');
    }

    return ldap_escape($guidBytes, '', LDAP_ESCAPE_FILTER);
}

function directory_guid_display(string $guidBytes): string
{
    if (strlen($guidBytes) !== 16) {
        return '';
    }
    $hex = bin2hex($guidBytes);

    return implode('-', [
        implode('', array_reverse(str_split(substr($hex, 0, 8), 2))),
        implode('', array_reverse(str_split(substr($hex, 8, 4), 2))),
        implode('', array_reverse(str_split(substr($hex, 12, 4), 2))),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    ]);
}

function directory_account_is_enabled(int $userAccountControl, string $accountExpires, ?int $now = null): bool
{
    if (($userAccountControl & VIRTUSPHERE_DIRECTORY_UAC_ACCOUNTDISABLE) !== 0) {
        return false;
    }
    $expires = trim($accountExpires);
    if ($expires === '' || $expires === '0' || $expires === '9223372036854775807') {
        return true;
    }
    if (!ctype_digit($expires) || strlen($expires) > 18) {
        return false;
    }
    $unix = intdiv((int) $expires, 10000000) - 11644473600;

    return $unix > ($now ?? time());
}
