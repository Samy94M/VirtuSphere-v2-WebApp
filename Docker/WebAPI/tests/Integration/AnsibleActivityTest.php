<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/ansible_activity.php';

/**
 * Operational Ansible evidence comes from deploy_jobs without mutating or
 * masquerading as the manual preflight state.
 */
final class AnsibleActivityTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    /** @var list<int> */
    private array $credentialIds = [];
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_ansible_activity_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        foreach ($this->credentialIds as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_ansible_id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
        foreach ($this->credentialIds as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
    }

    public function testLatestCompletedMissionJobIsSelectedPerCredential(): void
    {
        $credentialA = $this->insertCredential('a');
        $credentialB = $this->insertCredential('b');
        $credentialWithoutJobs = $this->insertCredential('none');
        $missionA = $this->insertMission('ALPHA');
        $missionB = $this->insertMission('BETA');

        $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00');
        $sameSecondOlderId = $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_FAILED, '2026-08-11 10:00:00');
        $sameSecondNewerId = $this->insertJob($credentialA, $missionB, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, '2026-08-11 10:00:00');

        // Neither an active mission job nor a newer mission-less system job may
        // hide the last outcome an operator can actually inspect as completed.
        $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, '2026-08-11 11:00:00');
        $this->insertJob($credentialA, null, VIRTUSPHERE_DEPLOY_STATUS_FAILED, '2026-08-11 12:00:00');

        $credentialBJob = $this->insertJob($credentialB, $missionA, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 08:00:00');

        $rows = repo_latest_completed_ansible_mission_jobs($this->db);

        self::assertArrayHasKey($credentialA, $rows);
        self::assertSame($sameSecondNewerId, (int) $rows[$credentialA]['id'], 'the higher id must break a one-second timestamp tie');
        self::assertNotSame($sameSecondOlderId, (int) $rows[$credentialA]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $rows[$credentialA]['status']);
        self::assertSame($missionB, (int) $rows[$credentialA]['mission_id']);
        self::assertSame($this->prefix . '_BETA', $rows[$credentialA]['mission_name']);

        self::assertSame($credentialBJob, (int) $rows[$credentialB]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $rows[$credentialB]['status']);
        self::assertArrayNotHasKey($credentialWithoutJobs, $rows);
    }

    private function insertCredential(string $suffix): int
    {
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
        $name = $this->prefix . '_' . $suffix;
        $host = $suffix . '.phpunit.invalid';
        $username = 'svc';
        $secret = 'phpunit-ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $type, $name, $host, $username, $secret);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->credentialIds[] = $id;

        return $id;
    }

    private function insertMission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;

        return $id;
    }

    private function insertJob(int $credentialId, ?int $missionId, string $status, string $updatedAt): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, credential_ansible_id, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isis', $missionId, $status, $credentialId, $updatedAt);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }
}
