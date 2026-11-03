<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';

/**
 * LG-18 / LG-19: the retention pass leaves one `system` audit row per run with what it
 * deleted, so the purge of the 365-day security window is on the record and not only
 * on STDOUT. A run that deleted nothing writes none.
 */
final class MaintenanceRetentionAuditTest extends TestCase
{
    private const MARKER = 'phpunit retention audit fixture MaintenanceRetentionAuditTest';

    private ?mysqli $db = null;
    private int $startId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->startId = $this->maxAuditId();
        // Past the 90-day general window: the general purge must delete it.
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, user_id, created_at, updated_at)'
            . ' VALUES (?, ?, ?, NULL, DATE_SUB(NOW(), INTERVAL 100 DAY), NOW())'
        );
        $ip = 'cli';
        $category = VIRTUSPHERE_LOG_CATEGORY_VMS;
        $message = self::MARKER;
        $stmt->bind_param('sss', $ip, $category, $message);
        $stmt->execute();
        // Past the 365-day security window: the security purge must delete it and
        // count it apart from the general window.
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, user_id, created_at, updated_at)'
            . ' VALUES (?, ?, ?, NULL, DATE_SUB(NOW(), INTERVAL 400 DAY), NOW())'
        );
        $category = VIRTUSPHERE_LOG_CATEGORY_AUTH;
        $stmt->bind_param('sss', $ip, $category, $message);
        $stmt->execute();
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE log_message = ?', 's', [self::MARKER]);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND id > ?', 'si', [VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED, $this->startId]);
    }

    public function testThePurgeIsRecordedWithItsCountsInOneSystemRow(): void
    {
        $failures = [];

        maintenance_worker_retention($this->db, $failures);

        self::assertSame([], $failures, 'no purge may fail on a healthy database');
        $rows = $this->retentionRows();
        self::assertCount(1, $rows, 'one audit row per run that deleted something');
        self::assertSame('retention', $rows[0]['object_id']);
        $context = json_decode((string) $rows[0]['context_json'], true);
        self::assertIsArray($context);
        self::assertGreaterThanOrEqual(1, (int) $context['item_count']);
        $items = implode("\n", (array) $context['items']);
        self::assertMatchesRegularExpression('/portal_logs_general: [1-9][0-9]*/', $items, 'the general window purged the fixture and says so');
        self::assertMatchesRegularExpression('/portal_logs_security: [1-9][0-9]*/', $items, 'the security window purged its fixture and is counted apart');
    }

    /** The counts go with the row: a second run with nothing left to delete adds no row. */
    public function testARunThatDeletesNothingWritesNoRow(): void
    {
        $failures = [];
        maintenance_worker_retention($this->db, $failures);
        $afterFirst = $this->maxAuditId();
        self::assertCount(1, $this->retentionRows());

        maintenance_worker_retention($this->db, $failures);

        self::assertSame([], $failures);
        self::assertSame($afterFirst, $this->maxAuditId(), 'nothing left to purge, so nothing is written');
    }

    private function maxAuditId(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COALESCE(MAX(id), 0) FROM deploy_logs WHERE event_code = ?', 's', [VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED]);
    }

    /** @return list<array{object_id: string, context_json: string}> */
    private function retentionRows(): array
    {
        $event = VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED;
        $stmt = $this->db->prepare('SELECT object_id, context_json FROM deploy_logs WHERE event_code = ? AND id > ? ORDER BY id');
        $stmt->bind_param('si', $event, $this->startId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
