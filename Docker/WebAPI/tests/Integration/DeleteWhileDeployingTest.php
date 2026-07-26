<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * Nothing an operator can click may delete the state a running deploy depends
 * on. Two paths could, and both looked like ordinary row actions:
 *
 *  - deleting a MISSION cascaded its deploy jobs, their logs and its VM rows
 *    away while a worker was mid-playbook,
 *  - deleting a SINGLE VM had no guard at all, although the bulk delete on the
 *    same page has had one from the start.
 *
 * repo_delete_credential() answered this situation from the beginning, and it
 * answers it by refusing. These two do the same now: refuse, never cancel
 * somebody else's job as a side effect.
 *
 * The counter-direction matters as much: a FINISHED job must not block the
 * delete forever, or a mission with an old failed job could never be removed.
 */
final class DeleteWhileDeployingTest extends TestCase
{
    private const PREFIX = 'phpunit_delguard_';

    private ?mysqli $db = null;
    private int $missionId = 0;
    private int $vmId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();

        $this->missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->vmId = repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            ['vm_name' => 'PHPUNITDEL1', 'vm_hostname' => 'PHPUNITDEL1', 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '', 'subnet' => '', 'gateway' => '', 'mode' => 'dhcp', 'type' => 'vmxnet3', 'vlan' => 'VLAN10', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testDeletingAMissionIsRefusedWhileAJobIsQueued(): void
    {
        $jobId = $this->queueJob();

        try {
            deleteMission($this->missionId, $this->db);
            self::fail('deleting a mission with an active job must be refused');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('active deploy job', $exception->getMessage());
        }

        self::assertTrue($this->missionExists(), 'the mission must survive the refused delete');
        self::assertNotNull(repo_deploy_job($this->db, $jobId), 'the job must not be cascaded away');
        self::assertSame(1, $this->vmRows(), 'the VM rows the deploy works on must survive');
    }

    public function testDeletingAMissionIsRefusedWhileAJobIsRunning(): void
    {
        $jobId = $this->queueJob();
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING);

        $this->expectExceptionMessageMatches('/active deploy job/');
        deleteMission($this->missionId, $this->db);
    }

    public function testDeletingAMissionStillWorksOnceTheJobIsFinished(): void
    {
        $jobId = $this->queueJob();
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_FAILED);

        self::assertTrue(deleteMission($this->missionId, $this->db));
        self::assertFalse($this->missionExists(), 'a finished job must not block the delete forever');
    }

    public function testDeletingASingleVmIsRefusedWhileAJobIsRunning(): void
    {
        $jobId = $this->queueJob();
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING);

        try {
            repo_delete_vm_by_id($this->db, $this->missionId, $this->vmId);
            self::fail('the single-VM delete must be refused while a job is running');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('active deploy job', $exception->getMessage());
        }

        self::assertSame(1, $this->vmRows());
    }

    public function testTheSingleVmDeleteAndTheBulkDeleteAgreeOnTheSameState(): void
    {
        $jobId = $this->queueJob();
        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING);

        // The bulk path reports the refusal per VM instead of throwing, because
        // it has other VMs to account for; both leave the row in place, which is
        // the agreement that matters.
        $bulk = repo_bulk_delete_vms($this->db, $this->missionId, [$this->vmId]);
        self::assertSame(0, $bulk['deleted']);
        self::assertSame([['vm_name' => 'PHPUNITDEL1', 'reason' => 'active_job']], $bulk['skipped']);
        self::assertSame(1, $this->vmRows());

        $this->setJobStatus($jobId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        self::assertTrue(repo_delete_vm_by_id($this->db, $this->missionId, $this->vmId));
        self::assertSame(0, $this->vmRows());
    }

    private function queueJob(): int
    {
        $userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');

        return repo_create_deploy_job($this->db, $this->missionId, $userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);
    }

    private function setJobStatus(int $jobId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $jobId);
        $stmt->execute();
    }

    private function missionExists(): bool
    {
        return repo_fetch_one($this->db, 'SELECT id FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]) !== null;
    }

    private function vmRows(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms WHERE mission_id = ?', 'i', [$this->missionId]);
    }

    private function makeCredential(string $type, int $port): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $name = self::PREFIX . $type;
        $host = 'host.example.com';
        $user = 'svc';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach ([
            'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
            'DELETE FROM deploy_credentials WHERE name LIKE ?',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
