<?php

declare(strict_types=1);

/**
 * MECM TCP reachability probe SSoT.
 *
 * The stored host deliberately remains backwards compatible: an empty value
 * means automatic discovery from the most recent device-sync heartbeat; every
 * non-empty value is a manual target. No machine endpoint depends on this file.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/settings.php';

function mecm_probe_mode(string $storedHost): string
{
    return trim($storedHost) === ''
        ? VIRTUSPHERE_PROBE_MODE_AUTO
        : VIRTUSPHERE_PROBE_MODE_MANUAL;
}

function mecm_probe_normalize_host(string $host): string
{
    $host = trim($host);
    if (strlen($host) >= 2 && $host[0] === '[' && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }

    return $host;
}

function mecm_probe_host_is_valid(string $host): bool
{
    $host = mecm_probe_normalize_host($host);
    if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    if (str_ends_with($host, '.')) {
        $host = substr($host, 0, -1);
    }
    if ($host === '') {
        return false;
    }
    foreach (explode('.', $host) as $label) {
        if ($label === '' || strlen($label) > 63
            || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1
        ) {
            return false;
        }
    }

    return true;
}

function mecm_probe_port_is_valid(string $port): bool
{
    if ($port === '' || preg_match('/^[0-9]+$/', $port) !== 1) {
        return false;
    }
    $value = (int) $port;

    return $value >= 1 && $value <= 65535;
}

function mecm_probe_tcp_uri(string $host, int $port): string
{
    $host = mecm_probe_normalize_host($host);
    $socketHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ? '[' . $host . ']'
        : $host;

    return 'tcp://' . $socketHost . ':' . $port;
}

/**
 * @return array{mode:string,configured_host:string,host:?string,port:int,source_ip:?string,source_seen_at:?string}
 */
function mecm_probe_target(mysqli $db): array
{
    $configuredHost = mecm_probe_normalize_host(
        repo_setting_value($db, VIRTUSPHERE_SETTING_MECM_PROBE_HOST, '')
    );
    $mode = mecm_probe_mode($configuredHost);
    $sourceIp = null;
    $sourceSeenAt = null;
    $host = $configuredHost !== '' ? $configuredHost : null;

    if ($mode === VIRTUSPHERE_PROBE_MODE_AUTO) {
        $row = repo_fetch_one(
            $db,
            'SELECT last_ip, last_seen_at FROM deploy_integration_heartbeats WHERE source = ? LIMIT 1',
            's',
            [VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC]
        );
        $candidate = mecm_probe_normalize_host((string) ($row['last_ip'] ?? ''));
        if ($candidate !== '' && mecm_probe_host_is_valid($candidate)) {
            $host = $candidate;
            $sourceIp = $candidate;
            $sourceSeenAt = isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null;
        }
    }

    $storedPort = trim(repo_setting_value(
        $db,
        VIRTUSPHERE_SETTING_MECM_PROBE_PORT,
        (string) VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT
    ));
    $port = mecm_probe_port_is_valid($storedPort)
        ? (int) $storedPort
        : VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT;

    return [
        'mode' => $mode,
        'configured_host' => $configuredHost,
        'host' => $host,
        'port' => $port,
        'source_ip' => $sourceIp,
        'source_seen_at' => $sourceSeenAt,
    ];
}

function mecm_probe_error_category(int $errno, string $detail): string
{
    $haystack = strtolower($detail . ' ' . $errno);
    if (preg_match('/name or service|nodename nor servname|temporary failure in name|no such host|getaddrinfo|php_network_getaddresses|11001/', $haystack) === 1) {
        return VIRTUSPHERE_PROBE_ERROR_DNS;
    }
    if (preg_match('/timed out|timeout|operation now in progress|10060| 110\b/', $haystack) === 1) {
        return VIRTUSPHERE_PROBE_ERROR_TIMEOUT;
    }
    if (preg_match('/refused|10061| 111\b/', $haystack) === 1) {
        return VIRTUSPHERE_PROBE_ERROR_REFUSED;
    }
    if (preg_match('/network is unreachable|no route to host|host is unreachable|10051|10065| 101\b| 113\b/', $haystack) === 1) {
        return VIRTUSPHERE_PROBE_ERROR_NETWORK;
    }

    return VIRTUSPHERE_PROBE_ERROR_UNKNOWN;
}

function mecm_probe_redact_detail(string $detail, int $errno): string
{
    $detail = preg_replace('/[\x00-\x1f\x7f]+/', ' ', trim($detail)) ?? '';
    if ($detail === '') {
        $detail = 'socket error ' . $errno;
    }

    return mb_substr($detail, 0, VIRTUSPHERE_MECM_PROBE_DETAIL_TEXT_MAX);
}

/**
 * @return array{ok:bool,error_category:?string,detail:string}
 */
function mecm_probe_tcp_check(string $host, int $port, int $timeoutSeconds): array
{
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(mecm_probe_tcp_uri($host, $port), $errno, $errstr, $timeoutSeconds);
    if ($socket !== false) {
        fclose($socket);

        return ['ok' => true, 'error_category' => null, 'detail' => ''];
    }

    return [
        'ok' => false,
        'error_category' => mecm_probe_error_category($errno, $errstr),
        'detail' => mecm_probe_redact_detail($errstr, $errno),
    ];
}

/**
 * @param array<string,mixed> $context
 */
function mecm_probe_encode_detail(array $context): string
{
    $context['detail'] = mb_substr(
        (string) ($context['detail'] ?? ''),
        0,
        VIRTUSPHERE_MECM_PROBE_DETAIL_TEXT_MAX
    );
    $context['version'] = VIRTUSPHERE_MECM_PROBE_DETAIL_VERSION;

    return json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @return array{version:int,legacy:bool,target:?string,port:?int,status:string,error_category:?string,detail:string,mode:?string}
 */
function mecm_probe_decode_detail(?string $stored): array
{
    $stored = trim((string) $stored);
    if ($stored !== '') {
        try {
            $decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && (int) ($decoded['version'] ?? 0) === VIRTUSPHERE_MECM_PROBE_DETAIL_VERSION) {
                $category = (string) ($decoded['error_category'] ?? '');

                return [
                    'version' => VIRTUSPHERE_MECM_PROBE_DETAIL_VERSION,
                    'legacy' => false,
                    'target' => isset($decoded['target']) ? (string) $decoded['target'] : null,
                    'port' => isset($decoded['port']) ? (int) $decoded['port'] : null,
                    'status' => (string) ($decoded['status'] ?? 'unknown'),
                    'error_category' => in_array($category, VIRTUSPHERE_PROBE_ERROR_CATEGORIES, true) ? $category : null,
                    'detail' => (string) ($decoded['detail'] ?? ''),
                    'mode' => isset($decoded['mode']) ? (string) $decoded['mode'] : null,
                ];
            }
        } catch (JsonException) {
            // Pre-version rows are intentionally still readable below.
        }
    }

    return [
        'version' => 0,
        'legacy' => true,
        'target' => null,
        'port' => null,
        'status' => $stored === '' ? 'unknown' : 'legacy',
        'error_category' => null,
        'detail' => $stored,
        'mode' => null,
    ];
}

/**
 * Runs and persists one probe. A missing automatic target is a neutral waiting
 * state and does not fabricate a failed heartbeat.
 *
 * @return array{status:string,mode:string,target:?string,port:int,error_category:?string,detail:string,source_seen_at:?string}
 */
function mecm_probe_run(mysqli $db): array
{
    $target = mecm_probe_target($db);
    $host = $target['host'];
    if ($host === null) {
        return [
            'status' => 'waiting',
            'mode' => $target['mode'],
            'target' => null,
            'port' => $target['port'],
            'error_category' => null,
            'detail' => '',
            'source_seen_at' => $target['source_seen_at'],
        ];
    }

    $check = mecm_probe_tcp_check($host, $target['port'], VIRTUSPHERE_MECM_PROBE_TIMEOUT_SECONDS);
    $status = $check['ok'] ? 'ok' : 'fail';
    $context = [
        'target' => $host,
        'port' => $target['port'],
        'status' => $status,
        'error_category' => $check['error_category'],
        'detail' => $check['detail'],
        'mode' => $target['mode'],
    ];
    $encoded = mecm_probe_encode_detail($context);
    if ($check['ok']) {
        repo_touch_integration_heartbeat(
            $db,
            VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE,
            '',
            VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS,
            $encoded
        );
    } else {
        repo_mark_integration_failure(
            $db,
            VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE,
            $encoded,
            VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS
        );
    }

    return [
        'status' => $status,
        'mode' => $target['mode'],
        'target' => $host,
        'port' => $target['port'],
        'error_category' => $check['error_category'],
        'detail' => $check['detail'],
        'source_seen_at' => $target['source_seen_at'],
    ];
}
