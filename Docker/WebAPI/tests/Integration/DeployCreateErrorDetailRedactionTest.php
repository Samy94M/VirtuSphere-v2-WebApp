<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/**
 * FC2-08. A create unit's error_detail can carry the error text of a marker the
 * Ansible host wrote, up to 1024 characters. The job log redacts every line
 * against both credential secrets; the stored detail was only run through the
 * generic patterns, so a password echoed by a module survived next to a log
 * line that had already lost it.
 */
final class DeployCreateErrorDetailRedactionTest extends TestCase
{
    private const ESXI_SECRET = 'Esxi-Secret-4711';
    private const ANSIBLE_SECRET = 'Ansible-Secret-0815';

    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $vmId;
    /** @var list<int> */
    private array $credentialIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_fc208_' . bin2hex(random_bytes(4));
        $name = $this->prefix . '_mission';
        $stmt = $this->db->prepare(
            "INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) VALUES (?, 'active', 'DC1', 'DS1', 'WDS')"
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper($this->prefix . '_VM');
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ($this->credentialIds as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    public function testAMarkerErrorIsStoredWithoutEitherCredentialSecret(): void
    {
        [$jobId, $fence] = $this->createJob();
        $channel = new DeployWorkerDbChannel($this->db, fn (): mysqli => $this->db, $jobId, $fence['worker_id']);
        $channel->withSecrets([self::ESXI_SECRET, self::ANSIBLE_SECRET]);
        $unit = repo_deploy_create_results($this->db, $jobId)[0];

        deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            'Cannot login: ' . self::ESXI_SECRET . ' rejected (ssh ' . rawurlencode(self::ANSIBLE_SECRET) . ')'
        );

        $detail = (string) repo_deploy_create_results($this->db, $jobId)[0]['error_detail'];
        self::assertStringNotContainsString(self::ESXI_SECRET, $detail);
        self::assertStringNotContainsString(self::ANSIBLE_SECRET, $detail);
        self::assertStringContainsString('Cannot login: *** rejected', $detail, 'the rest of the finding stays readable');
    }

    /** @return array{0:int,1:array{worker_id:string,lock_token:string,worker_epoch:int}} */
    private function createJob(): array
    {
        $esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
        $userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $userId, $esxiId, $ansibleId, [
            'mode' => 'create',
            'vm_ids' => [$this->vmId],
        ]);
        $fence = ['worker_id' => 'phpunit-fc208', 'lock_token' => bin2hex(random_bytes(16)), 'worker_epoch' => 1];
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = ?, locked_at = UTC_TIMESTAMP() WHERE id = ?',
            'sssii',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId]
        );
        repo_deploy_create_mark_started($this->db, $jobId);

        return [$jobId, $fence];
    }

    private function insertCredential(string $type, int $port): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.example.invalid';
        $user = 'svc';
        $secret = 'ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();
        $this->credentialIds[] = (int) $this->db->insert_id;

        return (int) $this->db->insert_id;
    }
}
