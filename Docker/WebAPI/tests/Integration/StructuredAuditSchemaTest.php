<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * Migration 0044 against a real MySQL.
 *
 * The application cap and the database CHECK are two independent enforcers of
 * one number, which is the only arrangement that survives a future writer: the
 * app cannot be the only guard, because a direct write would bypass it, and the
 * database cannot be the only one, because a rejected INSERT reaches the
 * operator as a 500 rather than as a bounded diagnostic. So both are pinned, on
 * the same three values, and `check-bounds-sync.php` proves the literal in
 * struktur.sql equals the PHP constant.
 */
final class StructuredAuditSchemaTest extends TestCase
{
    private const IP = '203.0.113.201';

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

    public function testTheStructuredColumnsExistAndAreNullable(): void
    {
        $columns = [];
        $result = $this->db->query('SHOW COLUMNS FROM deploy_logs');
        while (is_array($row = $result->fetch_assoc())) {
            $columns[(string) $row['Field']] = $row;
        }

        foreach (['event_code', 'object_type', 'object_id', 'result', 'context_json'] as $column) {
            self::assertArrayHasKey($column, $columns, $column . ' is missing');
            self::assertSame('YES', $columns[$column]['Null'], $column . ' must stay nullable for historical rows');
        }
        self::assertSame('longtext', strtolower((string) $columns['context_json']['Type']));
    }

    /**
     * A historical row keeps every structured column NULL and stays readable.
     * Nothing guessed a code out of its prose, and the CHECK must permit that
     * shape or the migration would have been a data migration in disguise.
     */
    public function testAHistoricalRowWithNoStructuredColumnsIsStillWritableAndReadable(): void
    {
        $this->insertLegacyRow('legacy free-text audit line for phpunit');

        $row = $this->fetchLatest();
        self::assertSame('legacy free-text audit line for phpunit', (string) $row['log_message']);
        self::assertNull($row['event_code']);
        self::assertNull($row['object_type']);
        self::assertNull($row['object_id']);
        self::assertNull($row['result']);
        self::assertNull($row['context_json']);
    }

    /** A half-structured row is refused: the columns travel together or not at all. */
    public function testAPartiallyStructuredRowIsRefusedByTheCheckConstraint(): void
    {
        $this->expectException(mysqli_sql_exception::class);
        $this->db->query(
            "INSERT INTO deploy_logs (ip, category, log_message, event_code, object_type, result)
             VALUES ('" . self::IP . "', 'system', 'phpunit half structured', 'system.unhandled_error', NULL, 'failure')"
        );
    }

    public function testContextJsonMustBeAJsonObject(): void
    {
        foreach (['[1,2,3]', '"a string"', 'not json at all'] as $invalid) {
            $refused = false;
            try {
                $this->insertStructuredRow($invalid);
            } catch (mysqli_sql_exception) {
                $refused = true;
            }
            self::assertTrue($refused, 'the database accepted a non-object context: ' . $invalid);
        }

        $this->insertStructuredRow('{"error_class":"RuntimeException"}');
        self::assertSame('{"error_class":"RuntimeException"}', (string) $this->fetchLatest()['context_json']);
    }

    /**
     * The three values around the byte limit, against the real CHECK. The limit
     * is measured in BYTES on the stored JSON, which is what OCTET_LENGTH and
     * strlen() both answer; a character-based cap would let a German payload
     * through at nearly twice the size.
     */
    public function testTheDatabaseAcceptsExactlyUpToTheByteLimit(): void
    {
        $limit = VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES;
        $overhead = strlen('{"error_class":""}');

        $below = '{"error_class":"' . str_repeat('a', $limit - $overhead - 1) . '"}';
        self::assertSame($limit - 1, strlen($below));
        $this->insertStructuredRow($below);
        self::assertSame($limit - 1, strlen((string) $this->fetchLatest()['context_json']));

        $atLimit = '{"error_class":"' . str_repeat('a', $limit - $overhead) . '"}';
        self::assertSame($limit, strlen($atLimit));
        $this->insertStructuredRow($atLimit);
        self::assertSame($limit, strlen((string) $this->fetchLatest()['context_json']));

        $above = '{"error_class":"' . str_repeat('a', $limit - $overhead + 1) . '"}';
        self::assertSame($limit + 1, strlen($above));
        $this->expectException(mysqli_sql_exception::class);
        $this->insertStructuredRow($above);
    }

    /**
     * A multibyte payload is measured in bytes on both sides. The application
     * refuses it before the database sees it, so the two caps cannot disagree
     * about which unit they count in.
     */
    public function testTheApplicationRefusesTheSameSizeTheDatabaseWould(): void
    {
        $limit = VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES;
        // Each 'ü' is two bytes, so this is well under the limit in characters
        // and just over it in bytes.
        $payload = str_repeat('ü', (int) (($limit / 2) + 8));

        $this->expectException(LengthException::class);
        audit_context_json(['reason' => $payload]);
    }

    /**
     * The writer stores exactly what the registry produced, in the columns the
     * migration added, and derives the category from the event rather than
     * accepting one.
     */
    public function testTheWriterPersistsTheStructuredShape(): void
    {
        audit_event(
            $this->db,
            VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED,
            'user',
            424242,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ['role' => 'admin'],
            null,
            self::IP
        );

        $row = $this->fetchLatest();
        self::assertSame(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, (string) $row['event_code']);
        self::assertSame('user', (string) $row['object_type']);
        self::assertSame('424242', (string) $row['object_id']);
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, (string) $row['result']);
        self::assertSame(VIRTUSPHERE_LOG_CATEGORY_USERS, (string) $row['category']);
        self::assertSame('{"role":"admin"}', (string) $row['context_json']);
        // The compatibility description is rendered from the same data, so it
        // agrees with the columns instead of being a second, older opinion.
        self::assertStringContainsString('424242', (string) $row['log_message']);
        self::assertStringContainsString('admin', (string) $row['log_message']);
    }

    /** An unknown event never reaches the table. */
    public function testAnUnknownEventIsRefusedBeforeAnyWrite(): void
    {
        $before = $this->rowCount();
        try {
            audit_event($this->db, 'auth.not_an_event', 'user', 1, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [], null, self::IP);
            self::fail('an unknown event was accepted');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $this->rowCount(), 'a refused event must leave no row behind');
        }
    }

    private function insertLegacyRow(string $message): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
        );
        $ip = self::IP;
        $category = VIRTUSPHERE_LOG_CATEGORY_SYSTEM;
        $stmt->bind_param('sss', $ip, $category, $message);
        $stmt->execute();
    }

    private function insertStructuredRow(string $contextJson): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_logs (ip, category, log_message, event_code, object_type, object_id, result, context_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $ip = self::IP;
        $category = VIRTUSPHERE_LOG_CATEGORY_SYSTEM;
        $message = 'phpunit structured probe';
        $event = VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR;
        $objectType = 'error';
        $objectId = 'deadbeefdeadbeef';
        $result = VIRTUSPHERE_AUDIT_RESULT_FAILURE;
        $stmt->bind_param('ssssssss', $ip, $category, $message, $event, $objectType, $objectId, $result, $contextJson);
        $stmt->execute();
    }

    /** @return array<string, mixed> */
    private function fetchLatest(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM deploy_logs WHERE ip = ? ORDER BY id DESC LIMIT 1');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();

        return (array) $stmt->get_result()->fetch_assoc();
    }

    private function rowCount(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_logs WHERE ip = ?');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    private function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE ip = ?');
        $ip = self::IP;
        $stmt->bind_param('s', $ip);
        $stmt->execute();
    }
}
