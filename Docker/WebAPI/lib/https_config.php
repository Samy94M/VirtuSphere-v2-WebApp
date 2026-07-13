<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * HTTPS admin flow (WP7, ADR-0012/ADR-0027). PHP validates and writes cert,
 * key and the generated nginx server block to the shared volumes; the watcher
 * in the nginx container tests and reloads. Nothing here ever calls docker or
 * nginx. All user-facing messages are ValidationException field errors; the
 * page maps everything else through portal_error_message().
 */

/**
 * Parses an uploaded certificate as either PKCS#12 (PFX, the Windows CA export
 * with optional password and chain) or a PEM pair (cert file + key file).
 * Validates that the key matches the certificate and that the leaf is not
 * expired. Returns cert/chain/key as PEM strings; the key never touches disk
 * here.
 *
 * @return array{cert_pem:string, chain_pem:string, key_pem:string}
 */
function https_parse_upload(string $rawCert, string $rawKey, string $password): array
{
    $certPem = '';
    $chainPem = '';
    $keyPem = '';

    if (str_contains($rawCert, '-----BEGIN')) {
        // PEM path: the key comes as its own file.
        if (trim($rawKey) === '') {
            throw new ValidationException(['key_file' => __t('settings.https_err_key_required')]);
        }
        $cert = @openssl_x509_read($rawCert);
        if ($cert === false) {
            throw new ValidationException(['cert_file' => __t('settings.https_err_parse')]);
        }
        $key = @openssl_pkey_get_private($rawKey, $password !== '' ? $password : null);
        if ($key === false) {
            throw new ValidationException(['key_file' => __t('settings.https_err_key_parse')]);
        }
        openssl_x509_export($cert, $certPem);
        // Unencrypted on purpose: nginx reads the key at reload without a
        // passphrase prompt; the 0600 file mode is the protection.
        openssl_pkey_export($key, $keyPem);
        // A PEM cert file may carry intermediates after the leaf: keep them.
        $blocks = https_split_pem_certificates($rawCert);
        $chainPem = implode('', array_slice($blocks, 1));
    } else {
        // PKCS#12 path. openssl_pkcs12_read wants the raw DER bytes.
        $bag = [];
        if (!@openssl_pkcs12_read($rawCert, $bag, $password)) {
            // Wrong password and not-a-PFX are indistinguishable here; the
            // message names both causes.
            throw new ValidationException(['pfx_password' => __t('settings.https_err_pfx')]);
        }
        $certPem = (string) ($bag['cert'] ?? '');
        $keyPem = (string) ($bag['pkey'] ?? '');
        foreach ((array) ($bag['extracerts'] ?? []) as $extra) {
            $chainPem .= (string) $extra;
        }
        if ($certPem === '' || $keyPem === '') {
            throw new ValidationException(['cert_file' => __t('settings.https_err_pfx_incomplete')]);
        }
    }

    if (!openssl_x509_check_private_key($certPem, $keyPem)) {
        throw new ValidationException(['key_file' => __t('settings.https_err_key_mismatch')]);
    }

    $meta = https_cert_metadata($certPem);
    if ($meta['valid_to'] !== 0 && $meta['valid_to'] < time()) {
        throw new ValidationException(['cert_file' => __t('settings.https_err_expired')]);
    }

    return ['cert_pem' => $certPem, 'chain_pem' => $chainPem, 'key_pem' => $keyPem];
}

/**
 * @return array<int, string> Every -----BEGIN CERTIFICATE----- block in order.
 */
function https_split_pem_certificates(string $pem): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s', $pem, $matches);

    return $matches[0];
}

/**
 * Display metadata for the settings card. Never returns key material.
 *
 * @return array{subject:string, sans:string, issuer:string, valid_from:int, valid_to:int, fingerprint:string, days_remaining:int}
 */
function https_cert_metadata(string $certPem): array
{
    $parsed = @openssl_x509_parse($certPem);
    if (!is_array($parsed)) {
        throw new ValidationException(['cert_file' => __t('settings.https_err_parse')]);
    }

    $subject = (string) ($parsed['subject']['CN'] ?? ($parsed['name'] ?? ''));
    $issuer = trim(((string) ($parsed['issuer']['CN'] ?? '')) !== ''
        ? (string) $parsed['issuer']['CN']
        : implode('/', array_map('strval', (array) ($parsed['issuer'] ?? []))));
    $sans = trim((string) ($parsed['extensions']['subjectAltName'] ?? ''));
    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
    $fingerprint = (string) (@openssl_x509_fingerprint($certPem, 'sha256') ?: '');
    // Uppercase pairs separated by colons, the format CA tooling shows.
    $fingerprint = implode(':', str_split(strtoupper($fingerprint), 2));

    return [
        'subject' => $subject,
        'sans' => $sans,
        'issuer' => $issuer,
        'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
        'valid_to' => $validTo,
        'fingerprint' => $fingerprint,
        'days_remaining' => $validTo !== 0 ? (int) floor(($validTo - time()) / 86400) : 0,
    ];
}

function https_material_present(string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): bool
{
    return is_file($sslDir . '/server.crt') && is_file($sslDir . '/server.key');
}

/**
 * Metadata of the currently installed certificate, or null when none exists
 * or it is unreadable (a half-written state must not take the page down).
 */
function https_installed_metadata(string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): ?array
{
    if (!https_material_present($sslDir)) {
        return null;
    }
    try {
        return https_cert_metadata((string) file_get_contents($sslDir . '/server.crt'));
    } catch (Throwable) {
        return null;
    }
}

/**
 * Atomically writes server.crt (leaf + chain) and server.key. The key gets
 * 0600 BEFORE the rename, so no reader ever sees it world-readable; per-file
 * rename keeps the watcher race harmless (it fingerprints again 5s later).
 */
function https_write_material(string $certPem, string $chainPem, string $keyPem, string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): void
{
    if (!is_dir($sslDir) || !is_writable($sslDir)) {
        throw new RuntimeException('HTTPS material directory is not writable: ' . $sslDir);
    }

    $keyTmp = $sslDir . '/server.key.tmp';
    if (file_put_contents($keyTmp, $keyPem) === false) {
        throw new RuntimeException('Failed to write the private key.');
    }
    @chmod($keyTmp, 0600);
    if (!rename($keyTmp, $sslDir . '/server.key')) {
        @unlink($keyTmp);
        throw new RuntimeException('Failed to install the private key.');
    }

    $crtTmp = $sslDir . '/server.crt.tmp';
    if (file_put_contents($crtTmp, rtrim($certPem) . "\n" . ltrim($chainPem)) === false) {
        throw new RuntimeException('Failed to write the certificate.');
    }
    @chmod($crtTmp, 0644);
    if (!rename($crtTmp, $sslDir . '/server.crt')) {
        @unlink($crtTmp);
        throw new RuntimeException('Failed to install the certificate.');
    }
}

/**
 * The generated HTTPS server block. Mirrors the HTTP block baked into
 * Docker/nginx/default.conf (root, deny rules, fastcgi) - keep the two in
 * sync when either changes. `fastcgi_param HTTPS on` is load-bearing: without
 * it PHP treats the TLS request as insecure (Secure cookies, HSTS, the
 * redirect check all read virtusphere_is_request_secure()).
 */
function https_render_nginx_conf(): string
{
    return <<<'CONF'
# Generated by VirtuSphere (portal settings, HTTPS tab). Do not edit by hand:
# every save overwrites this file. Remove it (or disable HTTPS in the portal)
# to stop serving TLS; the HTTP server block lives in the image and stays up
# either way.
server {
    listen 8443 ssl;
    http2 on;
    server_name _;

    ssl_certificate /etc/nginx/ssl/server.crt;
    ssl_certificate_key /etc/nginx/ssl/server.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:VirtuSphereSSL:10m;
    ssl_session_timeout 1h;

    root /var/www/html;
    index index.php index.html;
    charset utf-8;

    access_log /var/log/nginx/access.log virtusphere;
    error_log /var/log/nginx/error.log error;
    sendfile off;
    client_max_body_size 100m;

    # Same fallback headers as the HTTP block; the maps live in default.conf,
    # which nginx loads first. Only fills in what PHP did not already send, so a
    # PHP response keeps its single nonce-carrying CSP.
    add_header Content-Security-Policy $virtusphere_csp always;
    add_header X-Content-Type-Options $virtusphere_nosniff always;
    add_header Referrer-Policy $virtusphere_referrer always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

    # Keep in sync with Docker/nginx/default.conf (the HTTP block).
    location ~ ^/(lib|vendor|var|logs|tests)/ { deny all; }
    location ~* /(composer\.(json|lock)|phpstan.*\.(neon|dist)|.*\.lock)$ { deny all; }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_intercept_errors off;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
    }

    location ~ /\.ht {
        deny all;
    }
}
CONF;
}

/**
 * Reconciles the generated conf with the https_enabled setting: enabled means
 * the conf exists (written atomically, AFTER the material, so the watcher
 * never validates a block pointing at missing files), disabled means it is
 * gone. Certificate material always survives a disable.
 */
function https_apply_state(mysqli $db, string $confDir = VIRTUSPHERE_HTTPS_CONF_DIR, string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): void
{
    $target = $confDir . '/virtusphere-https.conf';
    $enabled = repo_setting_value($db, VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0') === '1';

    if (!$enabled) {
        if (is_file($target)) {
            unlink($target);
        }
        return;
    }

    if (!https_material_present($sslDir)) {
        throw new RuntimeException('HTTPS is enabled but no certificate material is installed.');
    }
    $tmp = $target . '.tmp';
    if (file_put_contents($tmp, https_render_nginx_conf()) === false || !rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to write the generated nginx config.');
    }
}

/**
 * Is nginx actually serving HTTPS right now? The setting alone does not say so.
 * init.sh quarantines a generated config that nginx rejects by renaming it to
 * `*.bad`, and it does that on boot, when nobody is watching. The file's presence
 * is the only evidence PHP has that a listener exists at all.
 */
function https_listener_live(string $confDir = VIRTUSPHERE_HTTPS_CONF_DIR, string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): bool
{
    return is_file($confDir . '/virtusphere-https.conf') && https_material_present($sslDir);
}

/**
 * Portal-only HTTP->HTTPS redirect (called from lib/bootstrap.php). The
 * machine API and health.php never load the bootstrap, so the ADR-0012
 * exemption holds by construction. 301 keeps GET/HEAD cacheable; anything
 * else gets the method-preserving 308. Any failure means "no redirect": a
 * broken settings read must never take the portal down.
 *
 * The same reasoning is why the redirect also requires a live listener. The boot
 * quarantine keeps *nginx* alive, but the redirect lives here, driven by a
 * setting that knows nothing about the quarantine: it would keep sending the
 * operator to a port nothing listens on, and HTTP, the documented way back, is
 * the very thing doing the sending. That is a locked-out portal in exactly the
 * situation the quarantine exists for.
 */
function https_redirect_if_required(mysqli $db, string $confDir = VIRTUSPHERE_HTTPS_CONF_DIR, string $sslDir = VIRTUSPHERE_HTTPS_SSL_DIR): void
{
    if (PHP_SAPI === 'cli' || virtusphere_is_request_secure()) {
        return;
    }

    try {
        if (repo_setting_value($db, VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0') !== '1') {
            return;
        }
    } catch (Throwable) {
        return;
    }

    if (!https_listener_live($confDir, $sslDir)) {
        return;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return;
    }
    // Strip an explicit :port; IPv6 literals keep their brackets.
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $port = envboot_optional('WEB_HTTPS_PORT', '8443');
    $portSuffix = $port === '443' ? '' : ':' . $port;
    $target = 'https://' . $host . $portSuffix . (string) ($_SERVER['REQUEST_URI'] ?? '/');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    http_response_code(in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
    header('Location: ' . $target);
    exit;
}
