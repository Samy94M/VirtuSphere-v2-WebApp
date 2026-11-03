<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/status_events.php';

/**
 * B11 rest: deploy_vm_status_events had nine writers, no reader and no
 * retention. Every state transition of every VM was recorded for an operator
 * who could never see it, and the table grew without bound (only a VM delete
 * ever removed rows, via CASCADE). The VM editor now reads the history and
 * the maintenance retention prunes it.
 */
final class StatusEventHistoryTest extends TestCase
{
    private const PREFIX = 'phpunit_hist_';

    private mysqli $db;
    private int $missionId = 0;
    private int $vmId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();

        $name = self::PREFIX . 'mission';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper(self::PREFIX . 'VM');
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testTheReaderReturnsNewestFirstWithTheActorName(): void
    {
        $userId = $this->insertUser();
        repo_record_vm_status_event($this->db, $this->vmId, 'ready', 'not_ready', '2/5 Registered', 'first note', null);
        repo_record_vm_status_event($this->db, $this->vmId, 'deploying', 'not_ready', '2/5 Registered', 'second note', $userId);

        $events = repo_vm_status_events($this->db, $this->vmId, 10);

        self::assertCount(2, $events);
        self::assertSame('deploying', (string) $events[0]['lifecycle_state'], 'newest first: the last transition answers first');
        self::assertSame('second note', (string) $events[0]['note']);
        self::assertSame(self::PREFIX . 'user', (string) $events[0]['actor_name'], 'the actor is a name, not a numeric id');
        self::assertNull($events[1]['actor_name'], 'a system-written event has no actor');

        // The limit is a real limit, newest kept.
        self::assertCount(1, repo_vm_status_events($this->db, $this->vmId, 1));
    }

    public function testRetentionPrunesOnlyRowsPastTheWindow(): void
    {
        repo_record_vm_status_event($this->db, $this->vmId, 'ready', 'not_ready', '2/5 Registered', 'fresh');
        repo_record_vm_status_event($this->db, $this->vmId, 'deploying', 'not_ready', '2/5 Registered', 'old');
        $stmt = $this->db->prepare('UPDATE deploy_vm_status_events SET created_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE vm_id = ? AND note = ?');
        $days = VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS + 5;
        $old = 'old';
        $stmt->bind_param('iis', $days, $this->vmId, $old);
        $stmt->execute();

        $purged = repo_purge_vm_status_events($this->db);

        self::assertGreaterThanOrEqual(1, $purged);
        $notes = array_map(static fn (array $e): string => (string) $e['note'], repo_vm_status_events($this->db, $this->vmId, 10));
        self::assertContains('fresh', $notes, 'rows inside the window survive');
        self::assertNotContains('old', $notes, 'rows past the window are gone');
    }

    private function insertUser(): int
    {
        $name = self::PREFIX . 'user';
        $password = 'x';
        $email = $name . '@example.invalid';
        $stmt = $this->db->prepare('INSERT INTO deploy_users (name, password, email) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $password, $email);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
