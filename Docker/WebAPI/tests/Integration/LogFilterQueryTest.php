<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/log_filter.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * The filter struct against real rows.
 *
 * LogFilterTest proves what the struct SAYS; this proves what the database then
 * selects, which is a different claim and the one an operator relies on. Two
 * cases carry the weight:
 *
 *  - the day boundary. The upper bound is exclusive and sits at the start of
 *    the day after the one typed, so a row written at 23:59:59 local on the
 *    last day is inside and one written at 00:00:00 the next morning is not.
 *    An off-by-one here is invisible on any day but the boundary.
 *  - exact matching. The correlation id and the structured fields are
 *    identities, not prefixes: a row whose id merely STARTS with the search
 *    term must not come back, or a trace silently gathers foreign requests.
 */
final class LogFilterQueryTest extends TestCase
{
    private const IP = '203.0.113.91';
    private const TAB = VIRTUSPHERE_LOG_TAB_SECURITY;
    private const CATEGORY = VIRTUSPHERE_LOG_CATEGORY_AUTH;

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

    public function testTheUpperDayBoundIsExclusiveAndTheLastDayIsWholeInside(): void
    {
        $zone = new DateTimeZone(portal_timezone());
        $utc = new DateTimeZone('UTC');
        $atLocal = static fn (string $local): string =>
            (new DateTimeImmutable($local, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');

        $this->insert(['created_at' => $atLocal('2026-07-01 00:00:00'), 'log_message' => 'first second']);
        $this->insert(['created_at' => $atLocal('2026-07-02 23:59:59'), 'log_message' => 'last second']);
        $this->insert(['created_at' => $atLocal('2026-07-03 00:00:00'), 'log_message' => 'next morning']);
        $this->insert(['created_at' => $atLocal('2026-06-30 23:59:59'), 'log_message' => 'evening before']);

        $filter = $this->filter(['from' => '2026-07-01', 'to' => '2026-07-02']);
        $messages = $this->messages($filter);

        self::assertContains('first second', $messages);
        self::assertContains('last second', $messages);
        self::assertNotContains('next morning', $messages, 'the upper bound is exclusive');
        self::assertNotContains('evening before', $messages, 'the lower bound starts at local midnight');
        self::assertSame(2, repo_count_logs($this->db, $filter));
    }

    /**
     * The same two dates select different rows in two display timezones,
     * because a local day is a different interval in each. The filter converts
     * at the boundary rather than comparing local strings against UTC columns.
     */
    public function testTheRangeIsReadInTheConfiguredDisplayTimezone(): void
    {
        $utc = new DateTimeZone('UTC');
        // 22:30 UTC on 30 June is already 1 July in Europe/Berlin (+02:00).
        $this->insert([
            'created_at' => (new DateTimeImmutable('2026-06-30 22:30:00', $utc))->format('Y-m-d H:i:s'),
            'log_message' => 'late june in utc, early july locally',
        ]);

        $filter = $this->filter(['from' => '2026-07-01', 'to' => '2026-07-01']);

        self::assertSame(
            portal_timezone() === 'UTC' ? 0 : 1,
            repo_count_logs($this->db, $filter),
            'the day boundary follows the display timezone, not UTC'
        );
    }

    public function testCorrelationMatchesTheWholeIdAndNeverAPrefix(): void
    {
        $this->insert(['correlation_id' => 'a1b2c3d4', 'log_message' => 'the short one']);
        $this->insert(['correlation_id' => 'a1b2c3d4e5f60718', 'log_message' => 'the long one']);

        self::assertSame(['the short one'], $this->messages($this->filter(['correlation' => 'a1b2c3d4'])));
        self::assertSame(['the long one'], $this->messages($this->filter(['correlation' => 'a1b2c3d4e5f60718'])));
    }

    public function testStructuredFieldsSelectExactlyTheirValue(): void
    {
        $this->insertStructured(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '7', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, 'login ok');
        $this->insertStructured(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '77', VIRTUSPHERE_AUDIT_RESULT_DENIED, 'login denied');
        $this->insertStructured(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT, 'user', '7', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, 'logout');

        self::assertSame(
            ['login denied', 'login ok'],
            $this->sorted($this->messages($this->filter(['event' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN])))
        );
        self::assertSame(
            ['login ok', 'logout'],
            $this->sorted($this->messages($this->filter(['object_id' => '7']))),
            'an object id is an identity, not a prefix'
        );
        self::assertSame(['login denied'], $this->messages($this->filter(['result' => VIRTUSPHERE_AUDIT_RESULT_DENIED])));
        self::assertSame(
            ['login ok'],
            $this->messages($this->filter([
                'event' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN,
                'object_id' => '7',
                'result' => VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ])),
            'the conditions combine with AND'
        );
    }

    /**
     * Historical rows keep every structured column NULL. A structured filter
     * must simply not match them; it must not error and must not sweep them in.
     */
    public function testHistoricalRowsAreNeitherMatchedNorBroken(): void
    {
        $this->insert(['log_message' => 'a row from before the registry']);

        self::assertSame(1, repo_count_logs($this->db, $this->filter()));
        self::assertSame(0, repo_count_logs($this->db, $this->filter(['result' => VIRTUSPHERE_AUDIT_RESULT_SUCCESS])));
        self::assertSame(0, repo_count_logs($this->db, $this->filter(['object_type' => 'user'])));
    }

    /** @param array<string,string> $extra */
    private function filter(array $extra = []): array
    {
        return log_filter_from_query(['tab' => self::TAB, 'ip' => self::IP] + $extra);
    }

    /** @return list<string> */
    private function messages(array $filter): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['log_message'],
            repo_recent_logs($this->db, $filter, 100, 0)
        );
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    private function sorted(array $messages): array
    {
        sort($messages, SORT_STRING);

        return $messages;
    }

    /** @param array<string,string> $row */
    private function insert(array $row): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, correlation_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $ip = self::IP;
        $category = self::CATEGORY;
        $message = $row['log_message'];
        $correlation = $row['correlation_id'] ?? null;
        $createdAt = $row['created_at'] ?? gmdate('Y-m-d H:i:s');
        $stmt->bind_param('sssss', $ip, $category, $message, $correlation, $createdAt);
        $stmt->execute();
    }

    private function insertStructured(string $event, string $objectType, string $objectId, string $result, string $message): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, event_code, object_type, object_id, result, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $ip = self::IP;
        $category = self::CATEGORY;
        $stmt->bind_param('sssssss', $ip, $category, $message, $event, $objectType, $objectId, $result);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE ip = ?');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }
}
