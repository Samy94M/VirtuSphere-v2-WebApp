<?php

declare(strict_types=1);

// Backend-only single source of truth for credential types, labels, default
// ports and ESXi endpoint normalization. Holds no database access so it can be
// required from repos, ssh/ansible helpers and portal pages alike.

const VIRTUSPHERE_CREDENTIAL_TYPE_ESXI = 'esxi';
const VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE = 'ansible';

const VIRTUSPHERE_CREDENTIAL_TYPES = [
    VIRTUSPHERE_CREDENTIAL_TYPE_ESXI,
    VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
];

const VIRTUSPHERE_CREDENTIAL_LABELS = [
    VIRTUSPHERE_CREDENTIAL_TYPE_ESXI => 'ESXi',
    VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE => 'Ansible SSH',
];

const VIRTUSPHERE_ESXI_SCHEMES = ['http', 'https'];

const VIRTUSPHERE_CREDENTIAL_PORT_SSH = 22;
const VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTP = 80;
const VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTPS = 443;

function credential_type_label(string $type): string
{
    return VIRTUSPHERE_CREDENTIAL_LABELS[$type] ?? ucfirst($type);
}

function credential_normalize_port(mixed $port): ?int
{
    if ($port === null || $port === '') {
        return null;
    }

    $int = (int) $port;

    return ($int >= 1 && $int <= 65535) ? $int : null;
}

function credential_ssh_port(mixed $port): int
{
    return credential_normalize_port($port) ?? VIRTUSPHERE_CREDENTIAL_PORT_SSH;
}

function credential_esxi_default_port(string $scheme): int
{
    return strtolower($scheme) === 'http'
        ? VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTP
        : VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTPS;
}

/**
 * Normalize a stored ESXi host value (bare host/IP or full URL) plus an
 * optional port column into scheme/hostname/port parts. An explicit port in
 * the URL wins over the port column; otherwise the scheme default applies.
 * Returns null when the host is empty or cannot be parsed to a valid host.
 *
 * @return array{scheme: string, hostname: string, port: int}|null
 */
function credential_esxi_normalize(string $host, mixed $port = null): ?array
{
    $host = trim($host);
    if ($host === '') {
        return null;
    }

    $hasScheme = preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $host) === 1;
    $work = $hasScheme ? $host : 'https://' . $host;

    $parts = parse_url($work);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    if (!in_array($scheme, VIRTUSPHERE_ESXI_SCHEMES, true)) {
        return null;
    }
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
        return null;
    }
    if (isset($parts['path']) && !in_array((string) $parts['path'], ['', '/'], true)) {
        return null;
    }

    $resolvedPort = credential_normalize_port($parts['port'] ?? null)
        ?? credential_normalize_port($port)
        ?? credential_esxi_default_port($scheme);

    return [
        'scheme' => $scheme,
        'hostname' => (string) $parts['host'],
        'port' => $resolvedPort,
    ];
}
