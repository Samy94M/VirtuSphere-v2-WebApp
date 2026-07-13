<?php

declare(strict_types=1);

require_once __DIR__ . '/envboot.php';

/**
 * Whether the request really arrived over TLS.
 *
 * Three security decisions hang off this: the Secure flag on the session cookie,
 * whether HSTS is sent, and whether the portal redirects to HTTPS. So it may only
 * believe evidence the client cannot fabricate.
 *
 * The only such evidence in this stack is `fastcgi_param HTTPS on`, set by the
 * generated TLS server block (lib/https_config.php) and by nothing else. The
 * X-Forwarded-Proto header used to be trusted here as well, which was a defect:
 * there is no reverse proxy in front of nginx in this deployment, so nobody
 * legitimate ever sets that header, while anyone may send it. A client (or a MITM
 * on the LAN) that added `X-Forwarded-Proto: https` was never redirected to HTTPS
 * and kept a session cookie without the Secure flag, on a connection that was
 * plain HTTP the whole time. Should a proxy ever be put in front of this stack,
 * the header must be re-introduced here *and* stripped/overwritten in nginx, so
 * that only the proxy can set it - never one without the other.
 *
 * SERVER_PORT 443 stays: nginx, not the client, decides which listener answered.
 */
function virtusphere_is_request_secure(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';

    return ($https !== '' && strtolower((string) $https) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function virtusphere_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

function virtusphere_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    $nonce = virtusphere_csp_nonce();
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'nonce-{$nonce}'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        // 'none', not 'self': the portal renders no <base> element anywhere, so
        // there is nothing to allow. An injected <base href> would otherwise
        // repoint every relative URL on the page (including form targets) at an
        // attacker's host, and 'self' still permits the tag.
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (virtusphere_is_request_secure() && virtusphere_hsts_enabled()) {
        header('Strict-Transport-Security: max-age=' . VIRTUSPHERE_HTTPS_HSTS_MAX_AGE_SECONDS);
    }
}

/**
 * HSTS is an admin toggle in deploy_settings (ADR-0027), not an env var. Any
 * failure means "no header": HSTS absence is always safe, and this runs on
 * every request including ones where the DB is restarting. The defined()
 * guard covers callers that load headers.php without the portal bootstrap.
 */
function virtusphere_hsts_enabled(): bool
{
    if (!defined('VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED') || !function_exists('db')) {
        return false;
    }

    require_once __DIR__ . '/repo/settings.php';
    try {
        return repo_setting_value(db(), VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED, '0') === '1';
    } catch (Throwable) {
        return false;
    }
}