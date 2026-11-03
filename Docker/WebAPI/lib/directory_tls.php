<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_result.php';

function directory_deadline_now(): float
{
    return hrtime(true) / 1_000_000_000;
}

function directory_deadline_remaining_seconds(?float $deadline, int $maximum): int
{
    if ($deadline === null) {
        return $maximum;
    }
    $remaining = (int) ceil($deadline - directory_deadline_now());
    if ($remaining <= 0) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT, true);
    }

    return min($maximum, $remaining);
}

/** Returns a normalized certificate bundle, or throws on unsafe/invalid input. */
function directory_normalize_ca_bundle(string $pem): string
{
    $pem = trim(str_replace("\r\n", "\n", $pem));
    if ($pem === '' || strlen($pem) > VIRTUSPHERE_DIRECTORY_CA_MAX_BYTES) {
        throw new InvalidArgumentException('invalid_ca_bundle');
    }
    if (str_contains($pem, 'PRIVATE KEY')) {
        throw new InvalidArgumentException('private_key_not_allowed');
    }
    preg_match_all('/-----BEGIN CERTIFICATE-----\s+.*?-----END CERTIFICATE-----/s', $pem, $matches);
    $blocks = $matches[0];
    if ($blocks === []) {
        throw new InvalidArgumentException('invalid_ca_bundle');
    }
    $remainder = preg_replace('/-----BEGIN CERTIFICATE-----\s+.*?-----END CERTIFICATE-----/s', '', $pem);
    if (!is_string($remainder) || trim($remainder) !== '') {
        throw new InvalidArgumentException('invalid_ca_bundle');
    }
    $normalized = [];
    foreach ($blocks as $block) {
        if (@openssl_x509_read($block) === false) {
            throw new InvalidArgumentException('invalid_ca_bundle');
        }
        $normalized[] = trim($block) . "\n";
    }

    return implode('', $normalized);
}

/** Materializes the DB-owned CA bundle in the PHP tmpfs under a content hash. */
function directory_ca_file(string $pem): string
{
    $bundle = directory_normalize_ca_bundle($pem);
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'virtusphere-directory';
    if (is_link($dir)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    $dirStat = @lstat($dir);
    $ownerMismatch = function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid();
    if (!is_array($dirStat)
        || (((int) $dirStat['mode']) & 0170000) !== 0040000
        || (((int) $dirStat['mode']) & 0777) !== 0700
        || $ownerMismatch
    ) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    $path = $dir . DIRECTORY_SEPARATOR . 'ca-' . hash('sha256', $bundle) . '.pem';
    if (file_exists($path) || is_link($path)) {
        $existing = directory_ca_regular_file_contents($path);
        if (!is_string($existing) || !hash_equals($bundle, $existing)) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
        }

        directory_ca_cleanup($dir, $path);
        return $path;
    }
    $temporary = tempnam($dir, 'ca-');
    if ($temporary === false) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    try {
        if (file_put_contents($temporary, $bundle, LOCK_EX) === false || !chmod($temporary, 0600)) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
        }
        if (!@rename($temporary, $path) && !is_file($path)) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
        }
        $stored = directory_ca_regular_file_contents($path);
        if (!is_string($stored) || !hash_equals($bundle, $stored)) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }

    directory_ca_cleanup($dir, $path);

    return $path;
}

function directory_ca_regular_file_contents(string $path): string|false
{
    $stat = @lstat($path);
    if (!is_array($stat)
        || (((int) $stat['mode']) & 0170000) !== 0100000
        || (((int) $stat['mode']) & 0777) !== 0600
        || (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid())
    ) {
        return false;
    }

    return @file_get_contents($path);
}

function directory_ca_cleanup(string $dir, string $currentPath): void
{
    $files = glob($dir . DIRECTORY_SEPARATOR . 'ca-*.pem') ?: [];
    usort($files, static fn (string $left, string $right): int => (@filemtime($right) ?: 0) <=> (@filemtime($left) ?: 0));
    foreach (array_slice($files, 8) as $path) {
        if ($path !== $currentPath && (@filemtime($path) ?: time()) < time() - 3600 && directory_ca_regular_file_contents($path) !== false) {
            @unlink($path);
        }
    }
}

/** @return array{fingerprint:string,not_after:string} */
function directory_tls_probe(string $host, int $port, string $caFile, ?float $deadline = null): array
{
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
        'cafile' => $caFile,
        'capture_peer_cert' => true,
        'crypto_method' => $cryptoMethod,
        'disable_compression' => true,
    ]]);
    $socket = @stream_socket_client(
        'tls://' . $host . ':' . $port,
        $errorNumber,
        $errorText,
        directory_deadline_remaining_seconds($deadline, VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS),
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!is_resource($socket)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    stream_set_timeout($socket, directory_deadline_remaining_seconds($deadline, VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS));
    $params = stream_context_get_params($socket);
    fclose($socket);
    $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
    if ($certificate === null || @openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_SERVER, [$caFile]) !== true) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }
    $parsed = @openssl_x509_parse($certificate);
    $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
    if (!is_array($parsed) || !is_string($fingerprint) || $fingerprint === '') {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
    }
    $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);
    if ($notAfter <= time()) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, true);
    }

    return [
        'fingerprint' => implode(':', str_split(strtoupper($fingerprint), 2)),
        'not_after' => gmdate('Y-m-d H:i:s', $notAfter),
    ];
}
