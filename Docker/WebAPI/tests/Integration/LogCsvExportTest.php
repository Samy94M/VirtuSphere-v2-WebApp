<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/lang.php';
// The export renders timestamps through the portal presenters. This file used
// to reach them only because some earlier test in the full suite had loaded
// layout.php first, so running it alone died on an undefined function - the
// same require-closure gap CliRequireClosureContractTest guards in lib/.
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/log_filter.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/logs_export.php';

/**
 * The audit-log CSV export against real rows.
 *
 * The cases are the boundary values from the Etappe 10C acceptance: 0, 1, one
 * below the cap, exactly the cap and one above it. The middle three are the
 * cheap ones; the two at the cap are the whole point, because "exactly ten
 * thousand" must NOT be reported as truncated (every matching row is in the
 * file) while ten thousand and one must be, both in the header and in the audit
 * row.
 *
 * Building 10 001 rows through the writer would take minutes, so the fixtures
 * are inserted directly as historical-shaped rows. That is legitimate here for
 * the same reason the retention tests do it: the subject is the READER, and a
 * row with every structured column NULL is exactly the case an installation
 * upgrading into 10C has most of.
 */
final class LogCsvExportTest extends TestCase
{
    private const IP = '203.0.113.77';
    private const CATEGORY = VIRTUSPHERE_LOG_CATEGORY_AUTH;
    private const TAB = VIRTUSPHERE_LOG_TAB_SECURITY;

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

    /** @return array<string, array{0:int,1:int,2:bool}> total => exported, truncated */
    public static function boundaryProvider(): array
    {
        $limit = VIRTUSPHERE_LOG_EXPORT_MAX_ROWS;

        return [
            'empty' => [0, 0, false],
            'single' => [1, 1, false],
            'one below the cap' => [$limit - 1, $limit - 1, false],
            'exactly the cap' => [$limit, $limit, false],
            'one above the cap' => [$limit + 1, $limit, true],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('boundaryProvider')]
    public function testTheExportStopsAtTheCapAndSaysSo(int $total, int $expectedRows, bool $expectedTruncated): void
    {
        $this->seed($total);
        $filter = $this->filter();
        $before = $this->auditRows();
        $export = $this->prepareExport($filter);
        $bounds = $export['bounds'];
        self::assertSame($total, $export['total'], 'the fixture and the count disagree');
        self::assertSame($expectedRows, $bounds['exported']);
        self::assertSame($expectedTruncated, $bounds['truncated']);
        self::assertCount($expectedRows, $export['rows'], 'the streamed row count must equal the announced one');
        self::assertSame($before + 1, $this->auditRows(), 'an export must write exactly one audit row');

        $context = json_decode((string) $this->latestAuditRow()['context_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($expectedRows, $context['rows_exported']);
        self::assertSame($total, $context['total_rows']);
        self::assertSame($expectedTruncated, $context['truncated']);
    }

    /**
     * The table and the export select the same rows in the same order. Not "a
     * similar set": the first page of the table has to be the first rows of the
     * file, or the download is not evidence about what the screen showed.
     */
    public function testTheTableAndTheExportAgreeOnRowsAndOrder(): void
    {
        $this->seed(120);
        $filter = $this->filter();

        $table = repo_recent_logs($this->db, $filter, 50, 0);
        $export = logs_export_rows($this->db, $filter, 120);

        self::assertCount(120, $export);
        foreach (array_values($table) as $index => $row) {
            self::assertSame((string) $row['id'], $export[$index][0], 'row ' . $index . ' differs between table and export');
        }

        // ... and the second page continues where the first left off, in the
        // same order the file has.
        $secondPage = repo_recent_logs($this->db, $filter, 50, 50);
        self::assertSame((string) $secondPage[0]['id'], $export[50][0]);
    }

    /** A narrower filter narrows both readers identically. */
    public function testAFilterNarrowsTheExportExactlyAsItNarrowsTheTable(): void
    {
        $this->seed(30);
        $this->seed(10, VIRTUSPHERE_LOG_CATEGORY_USERS, 'phpunit export users fixture');

        $all = log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP]);
        $narrowed = log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP, 'category' => self::CATEGORY]);

        self::assertSame(40, repo_count_logs($this->db, $all));
        self::assertSame(30, repo_count_logs($this->db, $narrowed));
        self::assertCount(30, logs_export_rows($this->db, $narrowed, 30));
    }

    /**
     * A category outside the active tab cannot widen the export. The filter is
     * the only place that decides, and it scopes the category to the tab.
     */
    public function testACategoryFromAnotherTabCannotWidenTheExport(): void
    {
        $this->seed(5);
        $this->seed(7, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'phpunit export deploy fixture');

        // `deploy` is not in the security tab, so it is dropped and the whole
        // tab is exported instead. The row from the deploy tab must not appear.
        $filter = log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP, 'category' => VIRTUSPHERE_LOG_CATEGORY_DEPLOY]);
        $rows = logs_export_rows($this->db, $filter, 100);

        self::assertCount(5, $rows);
    }

    /**
     * Exactly one audit row per export, carrying the counts and the fingerprint
     * and none of the filter's contents.
     */
    public function testTheExportWritesOneRedactedAuditRow(): void
    {
        $this->seed(3, self::CATEGORY, 'a-very-distinctive-search-term');
        $filter = log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP, 'q' => 'a-very-distinctive-search-term']);

        $before = $this->auditRows();
        $export = $this->prepareExport($filter);
        self::assertCount(3, $export['rows']);
        self::assertSame($before + 1, $this->auditRows(), 'an export must write exactly one audit row');

        $row = $this->latestAuditRow();
        $context = json_decode((string) $row['context_json'], true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(self::TAB, (string) $row['object_id']);
        self::assertSame(VIRTUSPHERE_LOG_CATEGORY_SYSTEM, (string) $row['category']);
        self::assertSame(3, $context['rows_exported']);
        self::assertSame(VIRTUSPHERE_LOG_EXPORT_MAX_ROWS, $context['limit']);
        self::assertFalse($context['truncated']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $context['filter_fingerprint']);

        // Neither the search term nor the IP filter appears anywhere in the row.
        $whole = implode(' ', array_map('strval', array_values($row)));
        self::assertStringNotContainsString('a-very-distinctive-search-term', $whole);
        self::assertStringNotContainsString('"ip"', (string) $row['context_json']);
    }

    /**
     * The Etappe-10C export semantics survive the Etappe-15 filter fields.
     *
     * A cap, a header and an audit row that hold for a category filter but not
     * for a date range would be a contract that quietly stops applying as soon
     * as somebody uses the new controls, which is when the file is most likely
     * to be evidence. So the combined filter is driven end to end: the file
     * carries exactly the matching rows, the correlation column is one of them,
     * and the single audit row still describes the export rather than its
     * contents.
     */
    public function testTheCapHeaderAndAuditSemanticsHoldForTheNewFilterFields(): void
    {
        $this->seedTraced(4, 'a1b2c3d4e5f60718');
        $this->seedTraced(2, 'ffffffffffffffff');

        $today = (new DateTimeImmutable('now', new DateTimeZone(portal_timezone())))->format('Y-m-d');
        $filter = log_filter_from_query([
            'tab' => self::TAB,
            'ip' => self::IP,
            'from' => $today,
            'to' => $today,
            'correlation' => 'a1b2c3d4e5f60718',
        ]);
        self::assertTrue(log_filter_is_usable($filter), 'the combined filter must be accepted');

        $before = $this->auditRows();
        $export = $this->prepareExport($filter);

        self::assertSame(4, $export['total'], 'the count follows every condition, not only the category');
        self::assertCount(4, $export['rows']);
        self::assertFalse($export['bounds']['truncated']);
        foreach ($export['rows'] as $row) {
            self::assertSame('a1b2c3d4e5f60718', $row[6], 'the correlation column carries the value');
        }

        self::assertSame($before + 1, $this->auditRows(), 'still exactly one audit row');
        $context = json_decode((string) $this->latestAuditRow()['context_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(4, $context['rows_exported']);
        self::assertSame(4, $context['total_rows']);
        self::assertFalse($context['truncated']);
        // The context describes the export, never the filter: the correlation
        // id is as much a search term as the free text is.
        self::assertStringNotContainsString('a1b2c3d4e5f60718', (string) $this->latestAuditRow()['context_json']);
    }

    /**
     * The same filter fingerprints identically across two exports, so an
     * operator can group them, and a different filter does not collide.
     */
    public function testTheFingerprintGroupsTheSameFilterAcrossExports(): void
    {
        $a = log_filter_from_query(['tab' => self::TAB, 'q' => 'bob']);
        $b = log_filter_from_query(['q' => 'bob', 'tab' => self::TAB]);
        $c = log_filter_from_query(['tab' => self::TAB, 'q' => 'alice']);

        self::assertSame(log_filter_fingerprint($a), log_filter_fingerprint($b));
        self::assertNotSame(log_filter_fingerprint($a), log_filter_fingerprint($c));
    }

    private function filter(): array
    {
        return log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP, 'category' => self::CATEGORY]);
    }

    /** Rows of one traced request, written now so a same-day range covers them. */
    private function seedTraced(int $count, string $correlationId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, correlation_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $ip = self::IP;
        $category = self::CATEGORY;
        $message = 'phpunit traced export fixture';
        $stmt->bind_param('ssss', $ip, $category, $message, $correlationId);
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute();
        }
    }

    private function seed(int $count, string $category = self::CATEGORY, string $message = 'phpunit export fixture'): void
    {
        if ($count === 0) {
            return;
        }
        // Batched multi-row insert: 10 001 single statements is minutes, this
        // is under a second, and the export is what is under test.
        $chunk = 500;
        for ($written = 0; $written < $count; $written += $chunk) {
            $rows = min($chunk, $count - $written);
            $values = implode(', ', array_fill(0, $rows, '(?, ?, ?, NOW(), NOW())'));
            $stmt = $this->db->prepare(
                'INSERT INTO deploy_logs (ip, category, log_message, created_at, updated_at) VALUES ' . $values
            );
            $params = [];
            for ($i = 0; $i < $rows; $i++) {
                $params[] = self::IP;
                $params[] = $category;
                $params[] = $message;
            }
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
        }
    }

    /**
     * @param array{tab:string,category:string,search:string,ip:string,categories:list<string>} $filter
     * @return array{rows:list<list<string>>,total:int,bounds:array{limit:int,exported:int,truncated:bool}}
     */
    private function prepareExport(array $filter): array
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = self::IP;
        try {
            return logs_export_prepare($this->db, $filter, null);
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    private function auditRows(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_logs WHERE event_code = ? AND ip = ?');
        $event = VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED;
        $ip = self::IP;
        $stmt->bind_param('ss', $event, $ip);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function latestAuditRow(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM deploy_logs WHERE event_code = ? AND ip = ? ORDER BY id DESC LIMIT 1'
        );
        $event = VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED;
        $ip = self::IP;
        $stmt->bind_param('ss', $event, $ip);
        $stmt->execute();

        return (array) $stmt->get_result()->fetch_assoc();
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE ip = ?');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }
}
