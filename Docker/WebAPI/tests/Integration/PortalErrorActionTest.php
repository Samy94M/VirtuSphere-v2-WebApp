<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/NetworkMacFixtures.php';

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_blockers.php';

/**
 * R11 / FC2-06 / K7: a refusal that names the job standing in the way carries
 * the link to that job's log, where it can be watched or cancelled. Before K7
 * the translation path portal_error_message() returned a sentence only, so
 * "cancel it first in the deploy list" left the operator to find the job.
 */
final class PortalErrorActionTest extends TestCase
{
    private const PREFIX = 'phpunit_r11_';

    private ?mysqli $db = null;
    private int $missionId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        Lang::load('de');
        $this->cleanup();

        $this->missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'mission',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'example.test',
        ], true);
        repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            [
                'vm_name' => 'R11-VM-01',
                'vm_hostname' => 'R11-VM-01',
                'vm_os' => 'Windows Server 2022',
                'vm_domain' => 'example.test',
                'vm_guest_id' => 'windows2022srvNext_64Guest',
                'vm_cpu' => 2,
                'vm_ram' => 4096,
            ],
            [['ip' => '10.91.0.10', 'subnet' => '255.255.255.0', 'gateway' => '10.91.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN91', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 80, 'disk_type' => 'thin']],
            [],
            '',
            1
        );
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId, 'integration fixture requires one portal user');
        test_prepare_network_mac_fixture($this->db, $this->missionId, $this->esxiId, 'VLAN91');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testDeletingAMissionWithAnActiveJobLinksThatJobsLog(): void
    {
        $jobId = $this->queueJob();

        $exception = $this->refusal(fn () => deleteMission($this->missionId, $this->db));

        self::assertSame($this->jobLogAction($jobId), portal_error_action($exception, ['role' => VIRTUSPHERE_ROLE_USER]));
        self::assertStringNotContainsString('Bereitstellungsliste', portal_error_message($exception), 'the link carries the pointer now');
    }

    public function testQueueingASecondJobLinksTheActiveOne(): void
    {
        $jobId = $this->queueJob();

        $exception = $this->refusal(fn () => $this->queueJob());

        self::assertSame($this->jobLogAction($jobId), portal_error_action($exception, ['role' => VIRTUSPHERE_ROLE_USER]));
    }

    public function testThePortalPreWriteRecheckKeepsTheActiveJobAction(): void
    {
        $jobId = $this->queueJob();
        $exception = $this->refusal(fn () => deploy_assert_queue_unblocked($this->db, [
            'mission_id' => $this->missionId,
            'credential_esxi_id' => $this->esxiId,
            'credential_ansible_id' => $this->ansibleId,
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
        ]));
        self::assertSame($this->jobLogAction($jobId), portal_error_action($exception, ['role' => VIRTUSPHERE_ROLE_USER]));
    }

    /** R11-04: the credential fence names the job that holds the target. */
    public function testChangingTheTargetOfACredentialAnActiveJobUsesLinksThatJob(): void
    {
        $jobId = $this->queueJob();

        $exception = $this->refusal(fn () => repo_delete_credential($this->db, $this->esxiId));

        self::assertInstanceOf(ValidationException::class, $exception);
        self::assertSame($this->jobLogAction($jobId), portal_error_action($exception, ['role' => VIRTUSPHERE_ROLE_USER]));
    }

    /** FC2-06: the deploy page names the job, its mode and its start, and links its log. */
    public function testTheActiveJobBlockerNamesTheJobAndLinksItsLog(): void
    {
        $jobId = $this->queueJob();

        $blockers = deploy_queue_blockers($this->db, [
            'mission_id' => $this->missionId,
            'credential_esxi_id' => $this->esxiId,
            'credential_ansible_id' => $this->ansibleId,
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
        ]);
        $active = array_values(array_filter($blockers, static fn (array $blocker): bool => $blocker['code'] === 'active_job'));

        self::assertCount(1, $active);
        self::assertSame(__t('deploy.err_active_job', [
            'id' => $jobId,
            'mode' => deploy_mode_label(VIRTUSPHERE_DEPLOY_MODE_FULL),
        ]), $active[0]['message']);
        self::assertSame([
            'type' => 'link',
            'url' => deploy_job_log_url($jobId),
            'label' => __t('deploy.flash_open_job_log'),
            'permission' => 'deploy.run',
        ], $active[0]['action']);
    }

    public function testTheActiveJobBlockerIncludesTheScheduledStart(): void
    {
        $jobId = $this->queueJob();
        $scheduledAt = '2030-01-02 12:30:00';
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET scheduled_at = ? WHERE id = ?');
        $stmt->bind_param('si', $scheduledAt, $jobId);
        $stmt->execute();
        $blockers = deploy_queue_blockers($this->db, [
            'mission_id' => $this->missionId,
            'credential_esxi_id' => $this->esxiId,
            'credential_ansible_id' => $this->ansibleId,
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
        ]);
        $active = array_values(array_filter($blockers, static fn (array $blocker): bool => $blocker['code'] === 'active_job'));
        self::assertCount(1, $active);
        self::assertSame(__t('deploy.err_active_job_scheduled', [
            'id' => $jobId,
            'mode' => deploy_mode_label(VIRTUSPHERE_DEPLOY_MODE_FULL),
            'start' => portal_format_timestamp($scheduledAt),
        ]), $active[0]['message']);
    }

    private function queueJob(): int
    {
        return repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL]);
    }

    private function refusal(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }
        self::fail('the write was accepted although a job of this mission is active');
    }

    /** @return array{url:string,label:string} */
    private function jobLogAction(int $jobId): array
    {
        return ['url' => deploy_job_log_url($jobId), 'label' => __t('deploy.flash_open_job_log')];
    }

    private function makeCredential(string $type, int $port): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $name = self::PREFIX . $type;
        $host = $type === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI ? 'esxi-r11.example.test' : 'ansible-r11.example.test';
        $username = 'svc';
        $secret = 'x';
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach ([
            'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
            'DELETE FROM deploy_credentials WHERE name LIKE ?',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
