<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

final class EsxiTrustModeIntegrationTest extends TestCase
{
    private mysqli $db;
    private int $userId;
    /** @var list<int> */
    private array $credentialIds = [];

    protected function setUp(): void
    {
        $this->db = db();
        $row = $this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc();
        self::assertIsArray($row);
        $this->userId = (int) $row['id'];
    }

    protected function tearDown(): void
    {
        // db() throws when the database is unreachable, and PHPUnit runs
        // tearDown after a failed setUp: without this guard the real cause is
        // buried under an uninitialised-property error.
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->credentialIds as $id) {
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_esxi_id = ? OR credential_ansible_id = ?');
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
        }
        foreach (array_reverse($this->credentialIds) as $id) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
        }
    }

    public function testFreshEsxiCredentialIsStrictAndLegacyActivationNeedsAProvenTest(): void
    {
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, $row['esxi_trust_mode']);
        self::assertSame(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $row['esxi_cert_kind']);

        repo_activate_esxi_legacy_trust($this->db, $id);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, repo_credential($this->db, $id)['esxi_trust_mode']);

        try {
            repo_activate_esxi_strict_trust($this->db, $id);
            self::fail('Strict mode activated without a successful strict probe.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('connection test', $exception->getMessage());
        }

        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));
        $tested = repo_credential($this->db, $id);
        self::assertNotEmpty($tested['esxi_strict_tested_at']);
        repo_update_credential($this->db, $id, [
            'type' => 'esxi',
            'name' => (string) $tested['name'] . '-renamed',
            'host' => (string) $tested['host'],
            'port' => $tested['port'],
            'username' => (string) $tested['username'],
            'esxi_cert_kind' => (string) $tested['esxi_cert_kind'],
            'esxi_certificate_pem' => (string) $tested['esxi_certificate_pem'],
        ]);
        self::assertNotEmpty(repo_credential($this->db, $id)['esxi_strict_tested_at'], 'A display-name-only edit keeps the strict proof.');

        $tested = repo_credential($this->db, $id);
        repo_update_credential($this->db, $id, [
            'type' => 'esxi',
            'name' => (string) $tested['name'],
            'host' => 'https://changed-' . bin2hex(random_bytes(3)) . '.invalid',
            'port' => $tested['port'],
            'username' => (string) $tested['username'],
            'esxi_cert_kind' => (string) $tested['esxi_cert_kind'],
            'esxi_certificate_pem' => (string) $tested['esxi_certificate_pem'],
        ]);
        self::assertEmpty(repo_credential($this->db, $id)['esxi_strict_tested_at'], 'An endpoint edit invalidates the strict proof.');
        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));
        self::assertTrue(repo_activate_esxi_strict_trust($this->db, $id));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, repo_credential($this->db, $id)['esxi_trust_mode']);
    }

    public function testSystemJobCarriesStrictProbeWithoutChangingStoredLegacyMode(): void
    {
        $esxi = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_SERVER,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        repo_activate_esxi_legacy_trust($this->db, $esxi);
        $ansible = $this->credential('ansible', 'ansible-' . bin2hex(random_bytes(4)) . '.invalid');

        $jobId = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxi, $ansible, null, true);
        self::assertNotNull($jobId);
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        self::assertTrue(deploy_job_payload(json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR))['strict_trust_probe']);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, repo_credential($this->db, $esxi)['esxi_trust_mode']);
    }

    public function testFreshStrictCredentialRejectsAnExplicitHttpEndpoint(): void
    {
        // A certificate type makes the credential strict, so the https rule is what refuses it.
        $this->expectException(ValidationException::class);
        $this->credential('esxi', 'http://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
    }

    public function testNoneCreatesALegacyCredentialWithoutAnyStoredCertificate(): void
    {
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_NONE,
        ]);
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $row['esxi_trust_mode']);
        self::assertNull($row['esxi_cert_kind'], "'none' is a form choice and is never stored");
        self::assertNull($row['esxi_certificate_pem']);
        self::assertNull($row['esxi_strict_tested_at']);
    }

    public function testCertificateTypeNeedsItsPemAndNoneRefusesOne(): void
    {
        $host = 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid';
        $this->assertRefusedOn('esxi_certificate_pem', fn (): int => $this->credential('esxi', $host, [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
        ]));
        $this->assertRefusedOn('esxi_certificate_pem', fn (): int => $this->credential('esxi', $host, [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_NONE,
            'esxi_certificate_pem' => $this->certificate(),
        ]));
        $this->assertRefusedOn('esxi_cert_kind', fn (): int => $this->credential('esxi', $host));
    }

    public function testDowngradeFromStrictClearsTheCertificateAndTheStrictProof(): void
    {
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_SERVER,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));
        $cleared = false;
        $trust = ['before' => null, 'after' => null];

        repo_update_credential($this->db, $id, $this->esxiPayload(repo_credential($this->db, $id) ?? [], VIRTUSPHERE_ESXI_CERT_NONE, ''), null, $cleared, $trust);

        self::assertSame(['before' => VIRTUSPHERE_ESXI_TRUST_STRICT, 'after' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE], $trust);
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $row['esxi_trust_mode']);
        self::assertNull($row['esxi_cert_kind']);
        self::assertNull($row['esxi_certificate_pem']);
        self::assertNull($row['esxi_strict_tested_at']);
    }

    public function testNoneWithTheStoredCertificatePostedBackRemovesIt(): void
    {
        // The editor's textarea is prefilled with the stored PEM; a browser submits its line breaks as CRLF.
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);
        $posted = str_replace("\n", "\r\n", (string) $row['esxi_certificate_pem']);
        $cleared = false;
        $trust = ['before' => null, 'after' => null];

        repo_update_credential($this->db, $id, $this->esxiPayload($row, VIRTUSPHERE_ESXI_CERT_NONE, $posted), null, $cleared, $trust);

        self::assertSame(['before' => VIRTUSPHERE_ESXI_TRUST_STRICT, 'after' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE], $trust);
        $after = repo_credential($this->db, $id);
        self::assertNotNull($after);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $after['esxi_trust_mode']);
        self::assertNull($after['esxi_cert_kind']);
        self::assertNull($after['esxi_certificate_pem']);
        self::assertNull($after['esxi_strict_tested_at']);
    }

    public function testNoneWithADifferentCertificateIsRefusedAndChangesNothing(): void
    {
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);

        $this->assertRefusedOn('esxi_certificate_pem', fn (): bool => repo_update_credential(
            $this->db,
            $id,
            $this->esxiPayload($row, VIRTUSPHERE_ESXI_CERT_NONE, $this->certificate() . $this->certificate())
        ));
        $after = repo_credential($this->db, $id);
        self::assertNotNull($after);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, $after['esxi_trust_mode']);
        self::assertNotEmpty($after['esxi_certificate_pem']);
    }

    public function testVerifyingSaveOfALegacyRowStaysLegacyAndStoresTheCertificate(): void
    {
        $id = $this->credential('esxi', 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid', [
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_NONE,
        ]);
        $cleared = false;
        $trust = ['before' => null, 'after' => null];

        repo_update_credential($this->db, $id, $this->esxiPayload(repo_credential($this->db, $id) ?? [], VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $this->certificate()), null, $cleared, $trust);

        self::assertSame(['before' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, 'after' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE], $trust);
        $row = repo_credential($this->db, $id);
        self::assertNotNull($row);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $row['esxi_trust_mode'], 'the staged certificate waits for the strict probe');
        self::assertSame(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $row['esxi_cert_kind']);
        self::assertNotEmpty($row['esxi_certificate_pem']);
        self::assertNull($row['esxi_strict_tested_at']);
    }

    public function testAnsibleRowBecomingEsxiIsStrictWithACertificateAndLegacyWithout(): void
    {
        $withCertificate = $this->credential('ansible', 'ansible-' . bin2hex(random_bytes(4)) . '.invalid');
        $cleared = false;
        $trust = ['before' => null, 'after' => null];
        repo_update_credential($this->db, $withCertificate, $this->esxiPayload(repo_credential($this->db, $withCertificate) ?? [], VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $this->certificate(), [
            'host' => 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid',
            'port' => 443,
        ]), null, $cleared, $trust);
        self::assertSame(['before' => null, 'after' => VIRTUSPHERE_ESXI_TRUST_STRICT], $trust, 'a row that was not ESXi starts strict like a create');
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, repo_credential($this->db, $withCertificate)['esxi_trust_mode']);

        $withoutVerification = $this->credential('ansible', 'ansible-' . bin2hex(random_bytes(4)) . '.invalid');
        $trust = ['before' => null, 'after' => null];
        repo_update_credential($this->db, $withoutVerification, $this->esxiPayload(repo_credential($this->db, $withoutVerification) ?? [], VIRTUSPHERE_ESXI_CERT_NONE, '', [
            'host' => 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid',
            'port' => 443,
        ]), null, $cleared, $trust);
        self::assertSame(['before' => null, 'after' => VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE], $trust);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, repo_credential($this->db, $withoutVerification)['esxi_trust_mode']);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $overrides @return array<string,mixed> */
    private function esxiPayload(array $row, string $choice, string $pem, array $overrides = []): array
    {
        return array_replace([
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI,
            'name' => (string) ($row['name'] ?? ''),
            'host' => (string) ($row['host'] ?? ''),
            'port' => $row['port'] ?? 443,
            'username' => (string) ($row['username'] ?? ''),
            'esxi_cert_kind' => $choice,
            'esxi_certificate_pem' => $pem,
        ], $overrides);
    }

    /** @param callable(): mixed $attempt */
    private function assertRefusedOn(string $field, callable $attempt): void
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());

            return;
        }
        self::fail('The save was accepted, but ' . $field . ' must refuse it.');
    }

    /** @param array<string,string> $extra */
    private function credential(string $type, string $host, array $extra = []): int
    {
        $name = 'phpunit-trust-' . $type . '-' . bin2hex(random_bytes(5));
        $id = repo_create_credential($this->db, $extra + [
            'type' => $type,
            'name' => $name,
            'host' => $host,
            'port' => $type === 'esxi' ? 443 : 22,
            'username' => 'test-user',
        ], 'test-secret', $this->userId);
        $this->credentialIds[] = $id;

        return $id;
    }

    private function certificate(): string
    {
        $pem = file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        self::assertIsString($pem);

        return $pem;
    }
}
