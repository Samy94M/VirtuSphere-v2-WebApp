<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';

final class EsxiTrustModeTest extends TestCase
{
    private function certificate(): string
    {
        $pem = file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        self::assertIsString($pem);

        return $pem;
    }

    public function testNewDefaultIsStrictWhileAMissingLegacyColumnStaysInsecure(): void
    {
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, VIRTUSPHERE_ESXI_TRUST_DEFAULT_NEW);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, credential_esxi_trust_mode([]));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, credential_esxi_trust_mode([
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_STRICT,
        ]));
    }

    public function testCaBundleAcceptsSeveralCertificatesAndServerPinExactlyOne(): void
    {
        $certificate = $this->certificate();
        $bundle = credential_esxi_certificate_normalize(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $certificate . "\n" . $certificate);
        self::assertSame(2, substr_count($bundle, '-----BEGIN CERTIFICATE-----'));

        $server = credential_esxi_certificate_normalize(VIRTUSPHERE_ESXI_CERT_SERVER, $certificate);
        self::assertSame(1, substr_count($server, '-----BEGIN CERTIFICATE-----'));

        $this->expectException(InvalidArgumentException::class);
        credential_esxi_certificate_normalize(VIRTUSPHERE_ESXI_CERT_SERVER, $certificate . "\n" . $certificate);
    }

    public function testInvalidOrEmptyStrictCertificateIsRejected(): void
    {
        foreach (['', 'not a certificate'] as $value) {
            try {
                credential_esxi_certificate_normalize(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $value);
                self::fail('invalid certificate was accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testAccountsYamlDerivesValidationAndCaPathFromTrustMode(): void
    {
        $ansible = ['username' => 'ansible'];
        $legacy = ansible_accounts_yml([
            'host' => 'esxi01.lan',
            'port' => 443,
            'username' => 'root',
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE,
        ], 'secret', $ansible, 'http://portal.lan');
        self::assertStringContainsString("esxi_validate_certs: false\n", $legacy);
        self::assertStringContainsString("esxi_ca_bundle_path: \"\"\n", $legacy);

        $strict = ansible_accounts_yml([
            'host' => 'esxi01.lan',
            'port' => 443,
            'username' => 'root',
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_STRICT,
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ], 'secret', $ansible, 'http://portal.lan');
        self::assertStringContainsString("esxi_validate_certs: true\n", $strict);
        self::assertStringContainsString('esxi_ca_bundle_path: "./esxi-trust.pem"', $strict);
    }

    public function testEveryVmwarePlaybookUsesTheGeneratedTrustVariablesWithoutHardFalse(): void
    {
        $paths = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*_playbook.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'zero-match: no playbook found');

        $vmwarePlaybooks = 0;
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (!str_contains($source, 'community.vmware.')) {
                continue;
            }
            $vmwarePlaybooks++;
            self::assertStringNotContainsString('validate_certs: false', $source, basename($path));
            self::assertStringContainsString('validate_certs: "{{ esxi_validate_certs | bool }}"', $source, basename($path));
            self::assertStringContainsString('SSL_CERT_FILE', $source, basename($path));
            self::assertStringContainsString('REQUESTS_CA_BUNDLE', $source, basename($path));
        }
        self::assertSame(6, $vmwarePlaybooks, 'zero-match/count drift: every VMware playbook must share the trust contract');
    }

    public function testCertificateFailuresNeverFallThroughToParse(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE,
            ansible_categorize_inventory_error('certificate verify failed: unable to get local issuer certificate', 2)
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_CONFIG, 'ESXi certificate is required.')
        );
    }

    public function testMigrationKeepsExistingEsxiRowsLegacyWhileFreshSchemaDefaultsStrict(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/lib/migrate.php');
        self::assertIsString($migration);
        self::assertStringContainsString("esxi_trust_mode ENUM('legacy_insecure','strict') NOT NULL DEFAULT 'strict'", $migration);
        self::assertStringContainsString('$hadTrustMode = migrator_column_exists', $migration);
        self::assertStringContainsString("SET esxi_trust_mode = 'legacy_insecure' WHERE type = 'esxi'", $migration);
        self::assertStringContainsString('if (!$hadTrustMode)', $migration);
    }

    public function testTrustArtifactIsPrivateWhenPresentAndOptionalWhenLegacy(): void
    {
        $dir = sys_get_temp_dir() . '/virtusphere-trust-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($dir, 0700));
        try {
            self::assertNull(ansible_write_esxi_trust_artifact($dir, [
                'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE,
            ]));
            self::assertFileDoesNotExist($dir . '/' . VIRTUSPHERE_ESXI_TRUST_FILE);

            self::assertSame(VIRTUSPHERE_ESXI_TRUST_FILE, ansible_write_esxi_trust_artifact($dir, [
                'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_STRICT,
                'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_SERVER,
                'esxi_certificate_pem' => $this->certificate(),
            ]));
            self::assertFileExists($dir . '/' . VIRTUSPHERE_ESXI_TRUST_FILE);
            self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string) file_get_contents($dir . '/' . VIRTUSPHERE_ESXI_TRUST_FILE));

            foreach ([
                ansible_remote_command('/tmp/deploy', ['mode' => 'create']),
                ansible_inventory_remote_command('/tmp/inventory'),
            ] as $command) {
                self::assertStringContainsString('if [ -f esxi-trust.pem ]; then chmod 600 esxi-trust.pem; fi', $command);
            }
        } finally {
            $path = $dir . '/' . VIRTUSPHERE_ESXI_TRUST_FILE;
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($dir);
        }
    }
}
