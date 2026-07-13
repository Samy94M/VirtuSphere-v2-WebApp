<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * The one-active-job-per-mission guard under two OVERLAPPING enqueues.
 *
 * The guard used to be a plain COUNT. Under REPEATABLE READ a plain read serves
 * the transaction snapshot, and that snapshot is pinned by the enqueue's first
 * read, i.e. before the transaction starts waiting on the mission row lock. The
 * second of two overlapping enqueues therefore counted against a state in which
 * the first one's job did not exist yet, and both inserted: two active jobs on
 * one mission, which the single worker then runs back to back (the second
 * deploy re-running against the freshly created VMs). Proven live before the
 * fix; the guard is a locking read now.
 *
 * The interleaving is reproduced deterministically with two connections:
 * session A pins its snapshot (the way repo_deploy_assert_user_exists does),
 * session B enqueues and commits, then A enqueues inside its old snapshot.
 * No timing involved, so the test cannot flake.
 */
final class DeployEnqueueRaceTest extends TestCase
{
    private const PREFIX = 'phpunit_race_';

    private ?mysqli $db = null;
    private ?mysqli $second = null;
    private int $missionId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->second = new mysqli(
                envboot_required('DB_HOST'),
                envboot_required('DB_USER'),
                envboot_required('DB_PASS'),
                envboot_required('DB_NAME'),
                (int) envboot_optional('DB_PORT', '3306')
            );
            $this->second->set_charset('utf8mb4');
            $this->second->query("SET time_zone = '+00:00'");
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
        repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            ['vm_name' => 'PHPUNITRACE1', 'vm_hostname' => 'PHPUNITRACE1', 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '10.9.9.10', 'subnet' => '255.255.255.0', 'gateway' => '10.9.9.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => '', 'mac' => '']],
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
        if ($this->second !== null) {
            try {
                $this->second->close();
            } catch (Throwable) {
            }
        }
    }

    public function testOverlappingEnqueuesCannotBothPassTheActiveJobGuard(): void
    {
        $userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');

        [$jobB, $errorA] = repo_transaction($this->db, function () use ($userId): array {
            // Pin A's snapshot, exactly what the enqueue's first plain read does.
            repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users');

            // The "first" of the two overlapping requests enqueues and commits.
            $jobB = repo_create_deploy_job($this->second, $this->missionId, $userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);

            // The "second" request enqueues inside its pre-commit snapshot. Only
            // guard reads run before the guard throws, so catching here leaves
            // nothing half-written in A's transaction.
            $errorA = null;
            try {
                repo_create_deploy_job($this->db, $this->missionId, $userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);
            } catch (RuntimeException $exception) {
                $errorA = $exception->getMessage();
            }

            return [$jobB, $errorA];
        });

        self::assertGreaterThan(0, $jobB);
        self::assertNotNull($errorA, 'the second overlapping enqueue must be rejected, not silently queued');
        self::assertStringContainsString('active deploy job', $errorA);
        self::assertSame(1, $this->activeJobs(), 'exactly one active job may survive the overlap');
    }

    public function testOverlappingGroupEnqueueHitsTheSameGuard(): void
    {
        $userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');

        $errorA = repo_transaction($this->db, function () use ($userId): ?string {
            repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users');
            repo_create_deploy_job($this->second, $this->missionId, $userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);

            try {
                repo_enqueue_deploy_group($this->db, $this->missionId, $userId, $this->esxiId, $this->ansibleId, ['mode' => 'start'], null, 10);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return null;
        });

        self::assertNotNull($errorA, 'the staggered-group path shares the guard and must reject the overlap');
        self::assertStringContainsString('active deploy job', $errorA);
        self::assertSame(1, $this->activeJobs());
    }

    private function activeJobs(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM deploy_jobs WHERE mission_id = ? AND status IN ('queued', 'running') AND cancelled_at IS NULL");
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();

        return (int) $stmt->get_result()->fetch_assoc()['c'];
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
