<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * Claim priority (ADR-0023 amendment): mission deploys claim before
 * mission-less system jobs (inventory pulls), so an interval burst of pulls
 * cannot delay an operator's deploy. Within each class the order stays FIFO.
 */
final class DeployClaimPriorityTest extends TestCase
{
    private const PREFIX = 'phpunit_prio_';

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
        $this->makeVm('PHPUNITPRIO1', '10.0.0.40');
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testMissionJobClaimsBeforeOlderSystemJob(): void
    {
        // The production claim is global; skip rather than mutate unrelated jobs.
        $eligible = (int) $this->db->query("SELECT COUNT(*) AS c FROM deploy_jobs WHERE status = 'queued' AND cancelled_at IS NULL AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())")->fetch_assoc()['c'];
        if ($eligible > 0) {
            self::markTestSkipped('Other eligible queued jobs present; claim is global.');
        }

        // The system job is enqueued FIRST (lower id), the mission job second.
        $systemJobId = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $this->esxiId, $this->ansibleId, null);
        self::assertNotNull($systemJobId);
        $missionJobId = repo_create_deploy_job($this->db, $this->missionId, 1, $this->esxiId, $this->ansibleId, ['mode' => 'full'], null);

        $first = repo_claim_next_deploy_job($this->db, 'phpunit-prio');
        $second = repo_claim_next_deploy_job($this->db, 'phpunit-prio');
        $claimedIds = array_map(static fn (array $j): int => (int) $j['id'], array_filter([$first, $second]));
        // A live deploy worker polls the same global queue; if it snatched one
        // of our jobs between enqueue and claim, the order proves nothing.
        if (!in_array($missionJobId, $claimedIds, true) || !in_array((int) $systemJobId, $claimedIds, true)) {
            self::markTestSkipped('A running deploy worker claimed a test job first.');
        }

        self::assertSame($missionJobId, (int) $first['id'], 'a mission deploy must claim before an older system job');
        self::assertSame((int) $systemJobId, (int) $second['id']);
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
        // Mission-less system jobs do not cascade from the credential (SET NULL
        // would keep orphans); delete them explicitly first.
        $this->db->query("DELETE FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '" . $this->db->real_escape_string($like) . "')");
        foreach (['DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
                  'DELETE FROM deploy_credentials WHERE name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
