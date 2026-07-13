<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * B3/B4 scheduling against the live DB: staggered group enqueue (per-VM jobs,
 * shared group_id, ascending scheduled_at), the worker claim query skipping a
 * future-scheduled job, and group cancel stopping only queued slots.
 */
final class DeployScheduleEnqueueTest extends TestCase
{
    private const PREFIX = 'phpunit_sched_';

    private ?mysqli $db = null;
    private int $missionId = 0;
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
        foreach (['PHPUNITSCH1', 'PHPUNITSCH2', 'PHPUNITSCH3'] as $ip => $name) {
            $this->makeVm($name, '10.0.0.' . (10 + $ip));
        }
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testStaggeredGroupCreatesOneJobPerVm(): void
    {
        $result = repo_enqueue_deploy_group($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'start'], null, 10);
        self::assertSame(3, $result['count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $result['group_id']);

        $rows = $this->jobRows();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame($result['group_id'], (string) $row['group_id']);
            self::assertNotNull($row['scheduled_at']);
        }
        // scheduled_at strictly ascending by 10 minutes.
        $times = array_map(static fn (array $r): int => strtotime((string) $r['scheduled_at'] . ' UTC'), $rows);
        self::assertSame($times[0] + 600, $times[1]);
        self::assertSame($times[1] + 600, $times[2]);
    }

    public function testFutureScheduledJobIsNotClaimed(): void
    {
        // The production claim is global; skip rather than mutate unrelated jobs.
        $eligible = (int) $this->db->query("SELECT COUNT(*) AS c FROM deploy_jobs WHERE status = 'queued' AND cancelled_at IS NULL AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())")->fetch_assoc()['c'];
        if ($eligible > 0) {
            self::markTestSkipped('Other eligible queued jobs present; claim is global.');
        }

        $futureUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        repo_create_deploy_job($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'full'], $futureUtc);

        $claimed = repo_claim_next_deploy_job($this->db, 'phpunit-worker');
        self::assertNull($claimed, 'A job scheduled for the future must not be claimed yet.');
    }

    public function testGroupCancelStopsQueuedSlots(): void
    {
        $result = repo_enqueue_deploy_group($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'start'], gmdate('Y-m-d H:i:s', time() + 3600), 10);
        $cancelled = repo_cancel_deploy_group($this->db, $result['group_id'], 1);
        self::assertSame(3, $cancelled);

        foreach ($this->jobRows() as $row) {
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $row['status']);
        }
    }

    public function testEnqueueRefusesASelectionThatIsEntirelyOrphaned(): void
    {
        // A selection whose VMs were all deleted since the form was rendered must
        // not silently widen to the whole mission. An id that does not exist at
        // all stands in for the deleted rows.
        $ghostId = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1000 AS n FROM deploy_vms')->fetch_assoc()['n'];

        try {
            repo_create_deploy_job($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'start', 'vm_ids' => [$ghostId]], null);
            self::fail('An all-orphaned selection must throw, not enqueue a whole-mission job.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('None of the selected VMs', $exception->getMessage());
        }

        self::assertCount(0, $this->jobRows(), 'The rolled-back transaction must leave no job.');
    }

    public function testEnqueueKeepsAPartiallyValidSelection(): void
    {
        $ownedId = (int) $this->db->query('SELECT id FROM deploy_vms WHERE mission_id = ' . $this->missionId . ' ORDER BY id ASC LIMIT 1')->fetch_assoc()['id'];
        $ghostId = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1000 AS n FROM deploy_vms')->fetch_assoc()['n'];

        // One live VM, one deleted: the job runs for the survivor, not the mission.
        $jobId = repo_create_deploy_job($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'start', 'vm_ids' => [$ownedId, $ghostId]], null);
        self::assertGreaterThan(0, $jobId);

        $stmt = $this->db->prepare('SELECT payload_json FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $payload = json_decode((string) $stmt->get_result()->fetch_assoc()['payload_json'], true);
        self::assertSame([$ownedId], $payload['vm_ids']);
    }

    /** @return array<int, array<string, mixed>> */
    private function jobRows(): array
    {
        $stmt = $this->db->prepare('SELECT id, status, scheduled_at, group_id FROM deploy_jobs WHERE mission_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function makeVm(string $name, string $ip): void
    {
        repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => $ip, 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => '', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
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
        foreach (['DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
                  'DELETE FROM deploy_credentials WHERE name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
