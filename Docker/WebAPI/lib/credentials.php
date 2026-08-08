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

// ESXi transport trust (Etappe 9b). A newly created credential must verify the
// peer; a row without the column can only be an upgraded legacy row and keeps
// the historical behaviour until an operator explicitly activates strict mode.
const VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE = 'legacy_insecure';
const VIRTUSPHERE_ESXI_TRUST_STRICT = 'strict';
const VIRTUSPHERE_ESXI_TRUST_MODES = [
    VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE,
    VIRTUSPHERE_ESXI_TRUST_STRICT,
];
const VIRTUSPHERE_ESXI_TRUST_DEFAULT_NEW = VIRTUSPHERE_ESXI_TRUST_STRICT;

const VIRTUSPHERE_ESXI_CERT_CA_BUNDLE = 'ca_bundle';
const VIRTUSPHERE_ESXI_CERT_SERVER = 'server_certificate';
const VIRTUSPHERE_ESXI_CERT_KINDS = [
    VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
    VIRTUSPHERE_ESXI_CERT_SERVER,
];
const VIRTUSPHERE_ESXI_CERT_MAX_BYTES = 262144;
const VIRTUSPHERE_ESXI_TRUST_FILE = 'esxi-trust.pem';

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

function credential_esxi_trust_mode(array $credential): string
{
    $mode = trim((string) ($credential['esxi_trust_mode'] ?? ''));

    return in_array($mode, VIRTUSPHERE_ESXI_TRUST_MODES, true)
        ? $mode
        : VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE;
}

/**
 * Validates and canonicalizes a CA bundle or one pinned server certificate.
 * Only PEM certificate blocks plus whitespace are accepted: private keys,
 * prose accidentally pasted from a browser and trailing garbage never reach
 * the Ansible runner. A server pin is deliberately exactly one certificate;
 * a CA bundle may contain a chain.
 */
function credential_esxi_certificate_normalize(string $kind, string $pem): string
{
    if (!in_array($kind, VIRTUSPHERE_ESXI_CERT_KINDS, true)) {
        throw new InvalidArgumentException('Unknown ESXi certificate kind.');
    }

    $pem = trim($pem);
    if ($pem === '') {
        throw new InvalidArgumentException('ESXi certificate is required.');
    }
    if (strlen($pem) > VIRTUSPHERE_ESXI_CERT_MAX_BYTES) {
        throw new InvalidArgumentException('ESXi certificate bundle is too large.');
    }

    $pattern = '/-----BEGIN CERTIFICATE-----\s+.+?\s+-----END CERTIFICATE-----/s';
    $matched = preg_match_all($pattern, $pem, $matches);
    if ($matched === false || $matched === 0) {
        throw new InvalidArgumentException('ESXi certificate must be PEM encoded.');
    }

    $outside = preg_replace($pattern, '', $pem);
    if ($outside === null || trim($outside) !== '') {
        throw new InvalidArgumentException('ESXi certificate input contains non-certificate data.');
    }
    if ($kind === VIRTUSPHERE_ESXI_CERT_SERVER && $matched !== 1) {
        throw new InvalidArgumentException('A pinned ESXi server certificate must contain exactly one certificate.');
    }

    $normalized = [];
    foreach ($matches[0] as $block) {
        $certificate = openssl_x509_read($block);
        if ($certificate === false || !openssl_x509_export($certificate, $exported, true)) {
            throw new InvalidArgumentException('ESXi certificate is invalid.');
        }
        $normalized[] = trim($exported);
    }

    return implode("\n", $normalized) . "\n";
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
