<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';

final class RemoteActivationMaterializationTest extends TestCase
{
    private const NAME = 'phpunit_remote_activation';
    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testNewAnsibleCredentialGetsEveryModeDisabledAtomically(): void
    {
        $actor = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        if ($actor <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }
        $id = repo_create_credential($this->db, [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'name' => self::NAME,
            'host' => 'ansible.example.test',
            'port' => 22,
            'username' => 'svc-ansible',
        ], 'fixture-secret', $actor);

        $rows = repo_deploy_remote_mode_activations($this->db, $id);
        self::assertCount(count(virtusphere_deploy_modes()), $rows);
        self::assertSame(virtusphere_deploy_modes(), array_values(array_intersect(virtusphere_deploy_modes(), array_column($rows, 'mode'))));
        foreach ($rows as $row) {
            self::assertSame(VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED, $row['state']);
            self::assertNull($row['contract_version']);
            self::assertNull(remote_activation_contract($row));
        }

        repo_update_credential($this->db, $id, [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI,
            'name' => self::NAME,
            'host' => 'https://esxi.example.test',
            'port' => 443,
            'username' => 'svc-esxi',
        ]);
        self::assertSame([], repo_deploy_remote_mode_activations($this->db, $id));

        repo_update_credential($this->db, $id, [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'name' => self::NAME,
            'host' => 'ansible.example.test',
            'port' => 22,
            'username' => 'svc-ansible',
        ]);
        self::assertCount(count(virtusphere_deploy_modes()), repo_deploy_remote_mode_activations($this->db, $id));
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name = ?');
        $name = self::NAME;
        $stmt->bind_param('s', $name);
        $stmt->execute();
    }
}
