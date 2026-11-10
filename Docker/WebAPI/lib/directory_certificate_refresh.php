<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/directory_result.php';
require_once __DIR__ . '/directory_tls.php';

/**
 * N1a F7 (user decision 2026-11-09, "Ja, automatisch"): after a successful
 * directory contact the portal reads the controller certificate again, at most
 * once per VIRTUSPHERE_DIRECTORY_CERTIFICATE_REFRESH_SECONDS per controller and
 * configuration revision. One extra TLS handshake (about 50 ms) in the request
 * that wins the slot; every other request skips it.
 *
 * The slot is an exclusively created stamp file in the private CA directory of
 * this container (directory_ca_file() owns its mode checks). A restart may
 * cost one extra read; two requests can never both win the same slot.
 *
 * The read never changes the answer of the request: any failure (deadline,
 * handshake, untrusted or expired certificate) returns [] and the stored
 * values stay as they are. A real certificate fault surfaces on the next LDAP
 * handshake and in the manual test, not here.
 *
 * @param array<string,mixed> $controller
 * @return array{fingerprint?:string,not_after?:string}
 */
function directory_certificate_refresh_if_due(array $controller, int $revision, string $caPem, float $deadline, ?int $now = null): array
{
    try {
        $caFile = directory_ca_file($caPem);
        if (!directory_certificate_refresh_claim_slot(dirname($caFile), (int) $controller['id'], $revision, $now ?? time())) {
            return [];
        }
        $remaining = $deadline - directory_deadline_now();
        if ($remaining < 1.0) {
            return [];
        }
        $probeDeadline = directory_deadline_now() + min($remaining, (float) VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS);

        return directory_tls_probe((string) $controller['host'], (int) $controller['port'], $caFile, $probeDeadline);
    } catch (Throwable) {
        return [];
    }
}

/** True for exactly one caller per controller, revision and refresh slot. */
function directory_certificate_refresh_claim_slot(string $directory, int $controllerId, int $revision, int $now): bool
{
    if ($controllerId <= 0 || !is_dir($directory) || is_link($directory)) {
        return false;
    }
    $slot = intdiv($now, VIRTUSPHERE_DIRECTORY_CERTIFICATE_REFRESH_SECONDS);
    $prefix = $directory . DIRECTORY_SEPARATOR . 'certificate-read-' . $controllerId . '-' . $revision . '-';
    $handle = @fopen($prefix . $slot . '.stamp', 'x');
    if ($handle === false) {
        return false;
    }
    fclose($handle);
    @chmod($prefix . $slot . '.stamp', 0600);
    // Stale stamps go by the slot in their name, the same clock the claim
    // used, never by file time: a stamp of the current slot must survive.
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'certificate-read-*.stamp') ?: [] as $stamp) {
        if (preg_match('/-([0-9]+)\.stamp$/', $stamp, $match) === 1 && (int) $match[1] < $slot - 1) {
            @unlink($stamp);
        }
    }

    return true;
}
