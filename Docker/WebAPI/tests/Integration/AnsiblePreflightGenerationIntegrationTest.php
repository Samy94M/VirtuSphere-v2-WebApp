<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/ansible_preflight.php';

final class AnsiblePreflightGenerationIntegrationTest extends TestCase
{
    private const NAME = 'phpunit_ansible_preflight_generation';
    private ?mysqli $db = null;
    private int $credentialId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $actor = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        if ($actor <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }
        $this->credentialId = repo_create_credential($this->db, $this->payload('ansible-a.example.test'), 'secret-a', $actor);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testConfigurationChangeRejectsTheOlderResult(): void
    {
        $before = repo_credential($this->db, $this->credentialId);
        $revision = (int) $before['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);

        repo_update_credential($this->db, $this->credentialId, $this->payload('ansible-b.example.test'), 'secret-b');
        self::assertFalse(repo_ansible_preflight_record(
            $this->db,
            $this->credentialId,
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
            null,
            $revision,
            $generation
        ));
        self::assertNull(repo_ansible_preflight_state($this->db, $this->credentialId));
    }

    public function testNewerGenerationWinsWhenTestsFinishInReverseOrder(): void
    {
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $older = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
        $newer = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
        self::assertTrue(repo_ansible_preflight_record($this->db, $this->credentialId, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, null, $revision, $newer));
        self::assertFalse(repo_ansible_preflight_record($this->db, $this->credentialId, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED, 'ssh', $revision, $older));
        $state = repo_ansible_preflight_state($this->db, $this->credentialId);
        self::assertSame($newer, (int) $state['test_generation']);
        self::assertSame(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, $state['last_status']);
        self::assertTrue($state['evidence_current']);
    }

    public function testUpdateInvalidationCommitsBeforeNewRevisionEvidenceCanBeStored(): void
    {
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
        self::assertTrue(repo_ansible_preflight_record(
            $this->db,
            $this->credentialId,
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
            null,
            $revision,
            $generation
        ));

        $cleared = false;
        repo_update_credential(
            $this->db,
            $this->credentialId,
            $this->payload('ansible-c.example.test'),
            'secret-c',
            $cleared
        );
        self::assertTrue($cleared);
        self::assertNull(repo_ansible_preflight_state($this->db, $this->credentialId));

        $newRevision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $newGeneration = repo_ansible_preflight_begin($this->db, $this->credentialId, $newRevision);
        self::assertTrue(repo_ansible_preflight_record(
            $this->db,
            $this->credentialId,
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
            null,
            $newRevision,
            $newGeneration
        ));
        self::assertTrue(repo_ansible_preflight_state($this->db, $this->credentialId)['evidence_current']);
    }

    private function payload(string $host): array
    {
        return ['type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 'name' => self::NAME, 'host' => $host, 'port' => 22, 'username' => 'svc-ansible'];
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name = ?');
        $name = self::NAME;
        $stmt->bind_param('s', $name);
        $stmt->execute();
    }
}
