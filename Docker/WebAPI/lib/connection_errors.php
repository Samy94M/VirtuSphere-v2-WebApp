<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';

/**
 * Turns raw transport failures into the shared VIRTUSPHERE_INVENTORY_ERROR_*
 * categories, into an operator detail that is safe to show and to log, and into
 * the localized sentence the portal renders.
 *
 * The portal never renders the raw text as its own message: it maps the
 * category to a sentence and keeps the detail behind a details element
 * (portal.md, webapi.md). No database access, and __t() degrades to the bare
 * key when the portal bootstrap has not run, so the CLI worker can require this
 * as safely as a portal page (webapi.md).
 */

/** Longest operator detail we keep. Longer text is truncated, never wrapped. */
const VIRTUSPHERE_CONNECTION_DETAIL_MAX = 300;

/** A secret shorter than this is not redacted: it would shred the message. */
const VIRTUSPHERE_CONNECTION_REDACT_MIN = 4;

/**
 * Localized sentence per connection failure category. The credential test and
 * the inventory state row both classify into VIRTUSPHERE_INVENTORY_ERROR_*, so
 * both read their user-facing text from here (SSoT for the wording).
 */
const VIRTUSPHERE_CONNECTION_MESSAGE_KEYS = [
    VIRTUSPHERE_INVENTORY_ERROR_DNS => 'common.conn_dns',
    VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE => 'common.conn_unreachable',
    VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE => 'common.conn_certificate',
    VIRTUSPHERE_INVENTORY_ERROR_TLS => 'common.conn_tls',
    VIRTUSPHERE_INVENTORY_ERROR_AUTH => 'common.conn_auth',
    VIRTUSPHERE_INVENTORY_ERROR_AUTHZ => 'common.conn_authz',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS => 'common.conn_ansible_dns',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE => 'common.conn_ansible_unreachable',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH => 'common.conn_ansible_auth',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTHZ => 'common.conn_ansible_authz',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_PREFLIGHT => 'common.conn_ansible_preflight',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_CONFIG => 'common.conn_ansible_config',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP => 'common.conn_ansible_sftp',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT => 'common.conn_ansible_timeout',
    VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT => 'common.conn_ansible_transport',
    VIRTUSPHERE_INVENTORY_ERROR_HTTP => 'common.conn_http',
    VIRTUSPHERE_INVENTORY_ERROR_SSH => 'common.conn_ssh',
    VIRTUSPHERE_INVENTORY_ERROR_WORKER => 'common.conn_worker',
    VIRTUSPHERE_INVENTORY_ERROR_PARSE => 'common.conn_parse',
    VIRTUSPHERE_INVENTORY_ERROR_CONFIG => 'common.conn_config',
];

/**
 * Localized sentence for a connection failure category. Unknown or legacy
 * category values (the state row is a VARCHAR, not an ENUM) fall back to a
 * generic sentence instead of leaking the raw category name. Placeholders the
 * caller cannot fill render as "?" rather than as a bare ":host".
 *
 * @param array<string, string|int> $context Placeholders :host, :port, :status
 */
function connection_error_message(string $category, array $context = []): string
{
    $key = VIRTUSPHERE_CONNECTION_MESSAGE_KEYS[$category] ?? 'common.conn_unknown';
    $known = array_filter($context, static fn (mixed $value): bool => $value !== '' && $value !== null);

    return __t($key, $known + ['host' => '?', 'port' => '?', 'status' => '?']);
}

/**
 * Classifies PHP, OpenSSL or phpseclib error text into a connection category.
 *
 * The keyword sets are deliberately separate from
 * ansible_categorize_inventory_error(): that one reads Ansible task output,
 * this one reads exception and socket wording. Both return names from
 * VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES.
 *
 * Order matters. A DNS failure also says "failed", and a refused TLS port also
 * says "connection", so the most specific evidence is tested first.
 */
function connection_error_category(string $text): string
{
    $lower = strtolower($text);

    $needles = [
        VIRTUSPHERE_INVENTORY_ERROR_DNS => [
            'getaddrinfo',
            'name or service not known',
            'no address associated',
            'could not resolve',
            'name resolution',
            'nodename nor servname',
        ],
        VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE => [
            'certificate verify failed',
            'unable to get local issuer',
            'self-signed certificate',
            'self signed certificate',
        ],
        VIRTUSPHERE_INVENTORY_ERROR_TLS => [
            'ssl operation failed',
            'ssl routines',
            'ssl handshake',
            'tls handshake',
            'wrong version number',
        ],
        VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE => [
            'connection refused',
            'timed out',
            'timeout',
            'no route to host',
            'network is unreachable',
            'host is unreachable',
            'unable to connect',
            'connection reset',
        ],
        VIRTUSPHERE_INVENTORY_ERROR_AUTHZ => [
            'not authorized',
            'insufficient privilege',
            'access is denied',
        ],
        VIRTUSPHERE_INVENTORY_ERROR_AUTH => [
            'authentication',
            'permission denied',
            'incorrect user name or password',
            'invalid credentials',
            'login failed',
            'unauthorized',
        ],
    ];

    foreach ($needles as $category => $words) {
        foreach ($words as $word) {
            if (str_contains($lower, $word)) {
                return $category;
            }
        }
    }

    return VIRTUSPHERE_INVENTORY_ERROR_PARSE;
}

/**
 * Prepares raw error text for the portal and the error log: strips the secret
 * and any basic-auth header, collapses whitespace, truncates.
 */
function connection_error_detail(string $raw, string $secret = ''): string
{
    $raw = (string) preg_replace('/(Authorization:\s*Basic\s+)\S+/i', '$1***', $raw);
    if (strlen($secret) >= VIRTUSPHERE_CONNECTION_REDACT_MIN) {
        $raw = str_replace([$secret, rawurlencode($secret)], '***', $raw);
    }

    return connection_error_excerpt($raw);
}

/** Single-line excerpt of arbitrary output, for flashes, logs and audit rows. */
function connection_error_excerpt(string $text, int $max = VIRTUSPHERE_CONNECTION_DETAIL_MAX): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
}
