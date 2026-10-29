<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
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

    public function testTheCertificateChoiceHasThreeFormValuesAndNoneIsNeverAStoredKind(): void
    {
        self::assertSame('none', VIRTUSPHERE_ESXI_CERT_NONE);
        self::assertSame(VIRTUSPHERE_ESXI_CERT_NONE, VIRTUSPHERE_ESXI_CERT_DEFAULT_NEW, 'the create form preselects no verification');
        self::assertSame([
            VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            VIRTUSPHERE_ESXI_CERT_SERVER,
            VIRTUSPHERE_ESXI_CERT_NONE,
        ], VIRTUSPHERE_ESXI_CERT_CHOICES);
        // The DB ENUM keeps the two stored kinds; 'none' is stored as NULL.
        self::assertSame([VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, VIRTUSPHERE_ESXI_CERT_SERVER], VIRTUSPHERE_ESXI_CERT_KINDS);
        self::assertFalse(defined('VIRTUSPHERE_ESXI_TRUST_DEFAULT_NEW'), 'the new-credential default is the choice now, not a trust constant');
    }

    public function testAMissingLegacyColumnStaysInsecureAndAStrictRowStaysStrict(): void
    {
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, credential_esxi_trust_mode([]));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, credential_esxi_trust_mode([
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_STRICT,
        ]));
    }

    public function testStrictProbeIsExactlyALegacyRowWithAStoredCertificate(): void
    {
        $pem = $this->certificate();
        self::assertTrue(credential_esxi_strict_probe_ready([
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE,
            'esxi_certificate_pem' => $pem,
        ]));
        self::assertFalse(credential_esxi_strict_probe_ready([
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE,
            'esxi_certificate_pem' => "  \n",
        ]));
        self::assertFalse(credential_esxi_strict_probe_ready([
            'esxi_trust_mode' => VIRTUSPHERE_ESXI_TRUST_STRICT,
            'esxi_certificate_pem' => $pem,
        ]));
    }

    public function testSaveDerivesTheTrustModeFromTheChosenCertificateType(): void
    {
        $esxi = ['type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI];
        // A create (no row before) with a verifying choice starts strict; 'none' is legacy.
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, credential_esxi_trust_mode_after_save($esxi + ['esxi_cert_choice' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE], null));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, credential_esxi_trust_mode_after_save($esxi + ['esxi_cert_choice' => VIRTUSPHERE_ESXI_CERT_NONE], null));
        // An existing ESXi row keeps its mode for a verifying choice; 'none' always means legacy.
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, credential_esxi_trust_mode_after_save($esxi + ['esxi_cert_choice' => VIRTUSPHERE_ESXI_CERT_SERVER], VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, credential_esxi_trust_mode_after_save($esxi + ['esxi_cert_choice' => VIRTUSPHERE_ESXI_CERT_SERVER], VIRTUSPHERE_ESXI_TRUST_STRICT));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, credential_esxi_trust_mode_after_save($esxi + ['esxi_cert_choice' => VIRTUSPHERE_ESXI_CERT_NONE], VIRTUSPHERE_ESXI_TRUST_STRICT));
        // Ansible rows carry no ESXi trust mode of their own.
        self::assertNull(credential_esxi_trust_mode_after_save([
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'esxi_cert_choice' => null,
        ], null));
    }

    public function testNoneStillRefusesADifferentCertificateOfAnUpdatedRow(): void
    {
        // Only the row's own stored certificate is taken off with "none"; another one is refused
        // before the name lookup, so no database access happens here.
        $db = $this->createStub(mysqli::class);
        $stored = $this->certificate();
        try {
            credential_validate_payload($db, [
                'type' => 'esxi',
                'name' => 'esxi-choice',
                'host' => 'esxi.invalid',
                'username' => 'root',
                'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_NONE,
                'esxi_certificate_pem' => $stored . $stored,
            ], null, false, 1, $stored);
            self::fail('a different certificate was accepted under "none"');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('esxi_certificate_pem', $exception->errors());
        }
    }

    public function testCertificateChoiceIsCheckedBeforeAnythingIsStored(): void
    {
        // The validator throws before the name lookup, so no database access happens here.
        $db = $this->createStub(mysqli::class);
        $pem = $this->certificate();
        $base = ['type' => 'esxi', 'name' => 'esxi-choice', 'host' => 'esxi.invalid', 'username' => 'root'];
        $cases = [
            'empty choice' => [['esxi_cert_kind' => ''], 'esxi_cert_kind'],
            'unknown choice' => [['esxi_cert_kind' => 'pinned'], 'esxi_cert_kind'],
            'none with a certificate' => [['esxi_cert_kind' => 'none', 'esxi_certificate_pem' => $pem], 'esxi_certificate_pem'],
            'CA bundle without a certificate' => [['esxi_cert_kind' => 'ca_bundle', 'esxi_certificate_pem' => ''], 'esxi_certificate_pem'],
            'server certificate without a certificate' => [['esxi_cert_kind' => 'server_certificate'], 'esxi_certificate_pem'],
        ];
        foreach ($cases as $label => [$extra, $field]) {
            try {
                credential_validate_payload($db, $base + $extra, 'secret', true);
                self::fail($label . ' was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors(), $label);
            }
        }
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
        // Derived from the directory, not from a list: Etappe 14B and K15 each
        // moved VMware calls into task files, and every named list missed one
        // (powercycle_vm_tasks.yml was in none of them). requirements.yml only
        // pins the collection and runs nothing.
        $paths = glob(ansible_source_dir() . DIRECTORY_SEPARATOR . '*.yml');
        self::assertIsArray($paths);
        $sources = [];
        foreach ($paths as $path) {
            if (basename($path) !== 'requirements.yml') {
                $sources[basename($path)] = (string) file_get_contents($path);
            }
        }

        $vmwareFiles = 0;
        foreach ($sources as $file => $source) {
            if (!str_contains($source, 'community.vmware.')) {
                continue;
            }
            $vmwareFiles++;
            self::assertStringNotContainsString('validate_certs: false', $source, $file);
            self::assertStringContainsString('validate_certs: "{{ esxi_validate_certs | bool }}"', $source, $file);
            if (str_contains($file, '_playbook')) {
                self::assertStringContainsString('SSL_CERT_FILE', $source, $file);
                self::assertStringContainsString('REQUESTS_CA_BUNDLE', $source, $file);
                continue;
            }
            // A task file has no play of its own; it inherits the CA bundle
            // environment from every playbook that includes it.
            $includers = array_filter(
                $sources,
                static fn (string $candidate, string $name): bool => str_contains($name, '_playbook')
                    && preg_match('/include_tasks: \.\/' . preg_quote($file, '/') . '\b/', $candidate) === 1,
                ARRAY_FILTER_USE_BOTH
            );
            self::assertNotSame([], $includers, $file . ' issues VMware calls but no playbook includes it');
            foreach ($includers as $name => $includer) {
                self::assertStringContainsString('SSL_CERT_FILE', $includer, $name . ' includes ' . $file);
                self::assertStringContainsString('REQUESTS_CA_BUNDLE', $includer, $name . ' includes ' . $file);
            }
        }
        self::assertGreaterThan(0, $vmwareFiles, 'zero-match: no VMware access path found');
        foreach ([VIRTUSPHERE_POWERCYCLE_VM_INFO_TASKS, VIRTUSPHERE_EXPORT_VM_INFO_TASKS] as $registered) {
            self::assertStringContainsString('community.vmware.', $sources[$registered] ?? '', $registered . ' is no longer a VMware access path');
        }
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

            // The create path is a control call now, not a sequence step
            // (Etappe 14B-E), so it is checked where it actually runs; a mode
            // that still HAS a sequence step covers the other shape.
            require_once dirname(__DIR__, 2) . '/lib/ansible_command_create.php';
            foreach ([
                ansible_remote_steps('/tmp/deploy', ['mode' => 'export'])[0]['command'],
                ansible_create_control_command('/tmp/deploy', VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE, 1, 1, [
                    'vs_portal_vm_id' => 1,
                    'vs_result_file' => '/tmp/deploy/create.vm.1/prepare.json',
                ]),
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
