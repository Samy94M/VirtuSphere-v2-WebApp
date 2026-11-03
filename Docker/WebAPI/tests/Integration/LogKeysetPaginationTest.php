<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/log_filter.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/** Stable cursor navigation over real audit rows and the measured index. */
final class LogKeysetPaginationTest extends TestCase
{
    private const IP = '203.0.113.107';
    private mysqli $db;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testOlderAndNewerWindowsStayStableAcrossConcurrentInsert(): void
    {
        $ids = $this->seed(125);
        $filter = $this->filter();

        $newest = repo_log_page($this->db, $filter, 50);
        self::assertSame(array_slice(array_reverse($ids), 0, 50), array_column($newest['rows'], 'id'));
        self::assertFalse($newest['has_newer']);
        self::assertTrue($newest['has_older']);

        $older = repo_log_page(
            $this->db,
            $filter,
            50,
            (int) $newest['rows'][array_key_last($newest['rows'])]['id']
        );
        self::assertSame(array_slice(array_reverse($ids), 50, 50), array_column($older['rows'], 'id'));

        $this->seed(3);
        $back = repo_log_page($this->db, $filter, 50, null, (int) $older['rows'][0]['id']);
        self::assertSame(array_column($newest['rows'], 'id'), array_column($back['rows'], 'id'));
        self::assertTrue($back['has_newer'], 'the concurrent inserts form a newer window, not part of the old one');
        self::assertTrue($back['has_older']);
    }

    public function testExhaustedCursorIsReportedAsStale(): void
    {
        $ids = $this->seed(2);
        $page = repo_log_page($this->db, $this->filter(), 50, min($ids));

        self::assertSame([], $page['rows']);
        self::assertTrue($page['stale']);
        self::assertFalse($page['has_older']);
        self::assertFalse($page['has_newer']);
    }

    public function testCategoryIndexHasCursorColumnAndNoCompetingLegacyShape(): void
    {
        $result = $this->db->query("SHOW INDEX FROM deploy_logs WHERE Key_name = 'deploy_logs_category_lookup'");
        $columns = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $columns[(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }
        ksort($columns);

        self::assertSame(['category', 'id'], array_values($columns));
    }

    /** @return list<int> */
    private function seed(int $count): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ids = [];
        $ip = self::IP;
        $category = VIRTUSPHERE_LOG_CATEGORY_AUTH;
        $createdAt = '2026-09-12 12:00:00';
        for ($i = 0; $i < $count; ++$i) {
            $message = 'phpunit keyset ' . $i;
            $stmt->bind_param('sssss', $ip, $category, $message, $createdAt, $createdAt);
            $stmt->execute();
            $ids[] = (int) $this->db->insert_id;
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    private function filter(): array
    {
        return log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'category' => VIRTUSPHERE_LOG_CATEGORY_AUTH,
            'ip' => self::IP,
        ]);
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE ip = ?');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }
}
