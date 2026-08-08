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
        $this->expectException(ValidationException::class);
        $this->credential('esxi', 'http://esxi-' . bin2hex(random_bytes(4)) . '.invalid');
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
