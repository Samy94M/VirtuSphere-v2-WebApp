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
    if (($userAccountControl & 0x0002) !== 0) {
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
