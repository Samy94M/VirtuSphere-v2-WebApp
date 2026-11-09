<?php
// Gate 0B: read-only TLS probe for one domain controller, run inside the
// production php container (same PHP/OpenSSL build as the portal).
// It opens one TLS connection to port 636, validates chain and name against
// the given CA file and prints certificate facts. No LDAP bind, no
// credentials, no writes. Not product code; step T2 of the Gate 0B protocol
// docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md uses it.
//
// Usage (script on stdin, arguments after --):
//   docker compose exec -T php php -- dc1.example.test 636 /tmp/gate0b-ca.pem < gate0b-tls-probe.php
//
// Exit code 0 = chain, name, Server Authentication EKU and validity are OK.

declare(strict_types=1);

$host = (string) ($argv[1] ?? '');
$port = (int) ($argv[2] ?? 636);
$caFile = (string) ($argv[3] ?? '');

if ($host === '' || $caFile === '' || !is_file($caFile)) {
    fwrite(STDERR, "ERGEBNIS: aufruf-falsch (Host, Port und vorhandene CA-Datei angeben)\n");
    exit(2);
}

echo 'PHP ' . PHP_VERSION . ', ' . OPENSSL_VERSION_TEXT . "\n";

$context = stream_context_create(['ssl' => [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'peer_name' => $host,
    'SNI_enabled' => true,
    'cafile' => $caFile,
    'capture_peer_cert' => true,
    'capture_peer_cert_chain' => true,
    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
]]);

$socket = @stream_socket_client('tls://' . $host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
if (!is_resource($socket)) {
    // Only a typed result, no raw OpenSSL text (it may name internal hosts).
    echo "ERGEBNIS: tls-fehlgeschlagen (Kette, Name, Erreichbarkeit oder TLS-Version)\n";
    exit(1);
}

$meta = stream_get_meta_data($socket);
$crypto = $meta['crypto'] ?? [];
echo 'TLS-Version: ' . ($crypto['protocol'] ?? '?') . "\n";
echo 'Cipher: ' . ($crypto['cipher_name'] ?? '?') . "\n";

$params = stream_context_get_params($socket);
fclose($socket);
$cert = $params['options']['ssl']['peer_certificate'] ?? null;
if ($cert === null) {
    echo "ERGEBNIS: kein-zertifikat\n";
    exit(1);
}

$parsed = openssl_x509_parse($cert);
$sha256 = strtoupper((string) openssl_x509_fingerprint($cert, 'sha256'));
// SHA-1 equals the Windows certificate "Thumbprint" shown on the DC.
$sha1 = strtoupper((string) openssl_x509_fingerprint($cert, 'sha1'));
$notAfter = (int) ($parsed['validTo_time_t'] ?? 0);
$days = intdiv($notAfter - time(), 86400);
$eku = (string) ($parsed['extensions']['extendedKeyUsage'] ?? '');
$san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
$purposeOk = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, [$caFile]) === true;

echo 'SHA-256 (wie im Portal): ' . implode(':', str_split($sha256, 2)) . "\n";
echo 'SHA-1 (Thumbprint am DC): ' . $sha1 . "\n";
echo 'Gueltig bis (UTC): ' . gmdate('Y-m-d H:i', $notAfter) . " (noch {$days} Tage)\n";
echo 'SAN: ' . $san . "\n";
echo 'EKU: ' . $eku . "\n";
echo 'Kette in der Antwort: ' . count($params['options']['ssl']['peer_certificate_chain'] ?? []) . " Zertifikat(e)\n";

if (!$purposeOk) {
    echo "ERGEBNIS: eku-fehlt (kein Server Authentication)\n";
    exit(1);
}
if ($days < 30) {
    echo "ERGEBNIS: ok-aber-laeuft-bald-ab (unter 30 Tagen)\n";
    exit(0);
}
echo "ERGEBNIS: ok\n";
exit(0);
