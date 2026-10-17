<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';

/**
 * MA-11: the duplicate check, the daily cap and the insert of a client event are
 * one locked transaction. Concurrency is not testable here; the sequential path
 * through the same transaction is, and it pins the answers the endpoint maps to
 * its unchanged 200, 200-with-deduplicated and 429 bodies.
 */
final class ClientEventRecordOnceTest extends TestCase
{
    private const PREFIX = 'phpunit_client_once_';

    private mysqli $db;
    private int $missionId = 0;
    private int $vmId = 0;
    private string $mac = '02:00:5e:10:20:30';

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();

        $name = self::PREFIX . bin2hex(random_bytes(4));
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper($name);
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

    public function testAnIdenticalSecondReportIsRefusedAsDuplicateAndWritesNoRow(): void
    {
        $first = repo_client_event_record_once($this->db, $this->vmId, $this->mac, 'getinfo', 'started', 'once', '127.0.0.1');
        $second = repo_client_event_record_once($this->db, $this->vmId, $this->mac, 'getinfo', 'started', 'once', '127.0.0.1');

        self::assertSame('recorded', $first);
        self::assertSame('duplicate', $second, 'the second identical report inside the dedupe window is a duplicate');
        self::assertSame(1, $this->rowCount(), 'the duplicate wrote no second row');
    }

    public function testTheDailyCapRefusesTheNextEventAndWritesNothing(): void
    {
        self::assertSame('recorded', repo_client_event_record_once($this->db, $this->vmId, $this->mac, 'getinfo', 'started', null, '127.0.0.1', 1));

        // A different event is not a duplicate, so only the cap can refuse it.
        self::assertSame('capped', repo_client_event_record_once($this->db, $this->vmId, $this->mac, 'getinfo', 'finished', null, '127.0.0.1', 1));
        self::assertSame(1, $this->rowCount(), 'a capped event is not stored');
    }

    private function rowCount(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_client_events WHERE vm_id = ?');
        $stmt->bind_param('i', $this->vmId);
        $stmt->execute();

        return (int) $stmt->get_result()->fetch_assoc()['c'];
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
