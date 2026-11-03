<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * removeLog() prunes deploy_logs on two windows (ADR-0026): security categories
 * keep the long window, everything else the general one, and a category outside
 * today's taxonomy decays on the general window (the NOT IN branch) instead of
 * surviving forever. These are the invariants an operator relies on when they go
 * looking for a sign-in from three months ago.
 */
final class LogRetentionPurgeTest extends TestCase
{
    private const MARKER = 'phpunit retention fixture LogRetentionPurgeTest';

    private ?mysqli $db = null;
    /** @var int[] */
    private array $logIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        // Catch survivors and anything a failed assertion left behind.
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE log_message = ?');
        $marker = self::MARKER;
        $stmt->bind_param('s', $marker);
        $stmt->execute();
        $this->logIds = [];
    }

    public function testSecurityRowsOutliveGeneralRowsAndUnknownCategoriesDecay(): void
    {
        // Security window: the year-old auth row goes, the quarter-old one stays.
        $oldAuth = $this->makeLog(VIRTUSPHERE_LOG_CATEGORY_AUTH, 400);
        $recentAuth = $this->makeLog(VIRTUSPHERE_LOG_CATEGORY_AUTH, 100);
        // General window: the quarter-old vms row goes, the fresh one stays.
        $oldVms = $this->makeLog(VIRTUSPHERE_LOG_CATEGORY_VMS, 100);
        $recentVms = $this->makeLog(VIRTUSPHERE_LOG_CATEGORY_VMS, 10);
        // Unknown category on the NOT IN branch: decays on the general window.
        $oldUnknown = $this->makeLog('phpunit_unknown_category', 100);

        $purged = removeLog($this->db);

        self::assertGreaterThanOrEqual(3, $purged);
        self::assertFalse($this->exists($oldAuth), 'auth row past the security window survived');
        self::assertTrue($this->exists($recentAuth), 'auth row inside the security window was purged');
        self::assertFalse($this->exists($oldVms), 'vms row past the general window survived');
        self::assertTrue($this->exists($recentVms), 'fresh vms row was purged');
        self::assertFalse($this->exists($oldUnknown), 'unknown category did not decay on the general window');
    }

    private function makeLog(string $category, int $ageDays): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, user_id, created_at, updated_at)'
            . ' VALUES (?, ?, ?, NULL, DATE_SUB(NOW(), INTERVAL ? DAY), NOW())'
        );
        $ip = 'cli';
        $message = self::MARKER;
        $stmt->bind_param('sssi', $ip, $category, $message, $ageDays);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->logIds[] = $id;

        return $id;
    }

    private function exists(int $id): bool
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE id = ?', 'i', [$id]) === 1;
    }
}
