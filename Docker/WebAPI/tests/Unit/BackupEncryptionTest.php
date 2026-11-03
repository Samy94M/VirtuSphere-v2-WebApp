<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/backup_encryption.php';

/**
 * K19 (VT-05, VT-E2): the portal accepts exactly one RSA certificate of at
 * least the minimum size and never a private key, and one derivation decides
 * whether backups are encrypted to it. Key pairs are generated per test run.
 */
final class BackupEncryptionTest extends TestCase
{
    /** @var array<int,array{cert:string,key:string}> */
    private static array $pairs = [];

    public function testAnRsaCertificateAtTheMinimumIsAcceptedWithItsFingerprint(): void
    {
        $pair = self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS);

        $parsed = backup_encryption_parse_certificate("\r\n" . $pair['cert'] . "\r\n");

        self::assertSame(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS, $parsed['bits']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parsed['fingerprint']);
        self::assertSame(openssl_x509_fingerprint($pair['cert'], 'sha256'), $parsed['fingerprint']);
        self::assertStringContainsString('CN=k19-unit', $parsed['subject']);
        self::assertStringNotContainsString("\r", $parsed['pem']);
    }

    /** @return iterable<string,array{0:callable():string,1:string}> */
    public static function refusals(): iterable
    {
        yield 'empty' => [static fn (): string => '  ', 'settings.backup_cert_required'];
        yield 'private key alone' => [static fn (): string => self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['key'], 'settings.backup_cert_private_key'];
        yield 'certificate with its key' => [static fn (): string => self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['cert'] . self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['key'], 'settings.backup_cert_private_key'];
        yield 'two certificates' => [static fn (): string => self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['cert'] . self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['cert'], 'settings.backup_cert_single'];
        yield 'garbage in a certificate block' => [static fn (): string => "-----BEGIN CERTIFICATE-----\nbm90IGEgY2VydA==\n-----END CERTIFICATE-----\n", 'settings.backup_cert_invalid'];
        yield 'too small a key' => [static fn (): string => self::pair(2048)['cert'], 'settings.backup_cert_weak'];
    }

    #[DataProvider('refusals')]
    public function testRefusalsNameTheirReasonAndNeverReturnTheInput(callable $input, string $messageKey): void
    {
        try {
            backup_encryption_parse_certificate($input());
            self::fail('expected a refusal');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['backup_certificate'] ?? '';
            self::assertSame(__t($messageKey, ['bits' => VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS]), $message);
            self::assertStringNotContainsString('PRIVATE KEY', $message);
        }
    }

    public function testWithoutACertificateBackupsAreReportedUnencrypted(): void
    {
        self::assertSame(VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED, backup_encryption_finding(null, ['last' => null]));
        self::assertSame(VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED, backup_encryption_finding(null, ['last' => ['status' => 'ok', 'encrypted' => true]]));
    }

    public function testTheLastSuccessfulRunMustBeEncryptedToExactlyTheStoredCertificate(): void
    {
        $certificate = backup_encryption_parse_certificate(self::pair(VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS)['cert']);
        $run = static fn (array $fields): array => ['last' => array_merge(['ts' => 1, 'status' => 'ok'], $fields)];

        self::assertNull(backup_encryption_finding($certificate, $run(['encrypted' => true, 'recipient_sha256' => strtoupper($certificate['fingerprint'])])));
        self::assertSame(VIRTUSPHERE_BACKUP_FINDING_LAST_UNENCRYPTED, backup_encryption_finding($certificate, $run(['encrypted' => false, 'recipient_sha256' => ''])));
        self::assertSame(VIRTUSPHERE_BACKUP_FINDING_LAST_UNENCRYPTED, backup_encryption_finding($certificate, $run([])), 'a line from before K19 is unencrypted');
        self::assertSame(VIRTUSPHERE_BACKUP_FINDING_LAST_UNENCRYPTED, backup_encryption_finding($certificate, $run(['encrypted' => true, 'recipient_sha256' => str_repeat('0', 64)])), 'another key does not count');
        self::assertNull(backup_encryption_finding($certificate, $run(['status' => 'failed'])), 'a failed run is the backup state, not this finding');
        self::assertNull(backup_encryption_finding($certificate, ['last' => null]), 'no run yet, nothing to say');
    }

    /** @return array{cert:string,key:string} */
    private static function pair(int $bits): array
    {
        if (!isset(self::$pairs[$bits])) {
            $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            $csr = openssl_csr_new(['commonName' => 'k19-unit'], $key, ['digest_alg' => 'sha256']);
            self::assertNotFalse($csr);
            $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
            self::assertNotFalse($cert);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem);
            self::$pairs[$bits] = ['cert' => (string) $certPem, 'key' => (string) $keyPem];
        }

        return self::$pairs[$bits];
    }
}
