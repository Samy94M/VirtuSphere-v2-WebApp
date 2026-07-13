<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';

/**
 * repo_transaction() re-entrancy against the live server, not a grep: nested
 * calls must commit exactly once (a second BEGIN would make MySQL silently
 * commit the outer transaction, publishing the half-done state the wrapper
 * exists to prevent), an inner failure must roll back the outer work too, and
 * the depth tracker must recover after an exception.
 *
 * Visibility is asserted through a SECOND connection: what that connection can
 * read is by definition what has been committed.
 */
final class RepoTransactionReentrancyTest extends TestCase
{
    private const TABLE = 'phpunit_txn_probe';

    private ?mysqli $db = null;
    private ?mysqli $observer = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->observer = new mysqli(
                envboot_required('DB_HOST'),
                envboot_required('DB_USER'),
                envboot_required('DB_PASS'),
                envboot_required('DB_NAME'),
                (int) envboot_optional('DB_PORT', '3306')
            );
            $this->observer->set_charset('utf8mb4');
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        // DDL commits implicitly in MySQL, so it must happen outside the
        // transactions under test.
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT AUTO_INCREMENT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        $this->db->query('DELETE FROM ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
        if ($this->observer !== null) {
            try {
                $this->observer->close();
            } catch (Throwable) {
            }
        }
    }

    private function insert(string $marker): void
    {
        $stmt = $this->db->prepare('INSERT INTO ' . self::TABLE . ' (marker) VALUES (?)');
        $stmt->bind_param('s', $marker);
        $stmt->execute();
    }

    /** What a different session can see, i.e. what is actually committed. */
    private function committedRows(): int
    {
        // The observer runs in autocommit, so every statement is its own
        // transaction with a fresh snapshot; it cannot serve a stale view while
        // judging someone else's commit.
        $result = $this->observer->query('SELECT COUNT(*) AS c FROM ' . self::TABLE);

        return (int) $result->fetch_assoc()['c'];
    }

    public function testNestedCallsCommitExactlyOnceAtTheOutermostLevel(): void
    {
        repo_transaction($this->db, function (): void {
            $this->insert('outer');

            repo_transaction($this->db, function (): void {
                $this->insert('inner');
            });

            // The inner call returned, but nothing may be committed yet: a second
            // BEGIN would have auto-committed the outer transaction right here.
            self::assertSame(0, $this->committedRows(), 'the inner return must not publish anything');
        });

        self::assertSame(2, $this->committedRows(), 'the outermost return commits both writes at once');
    }

    public function testInnerFailureRollsBackTheOuterWorkToo(): void
    {
        $caught = null;
        try {
            repo_transaction($this->db, function (): void {
                $this->insert('outer');
                repo_transaction($this->db, function (): void {
                    $this->insert('inner');
                    throw new RuntimeException('inner failure');
                });
            });
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        self::assertSame('inner failure', $caught, 'the inner exception must propagate');

        self::assertSame(0, $this->committedRows(), 'no partial state survives the inner failure');
    }

    public function testDepthRecoversAfterAFailedTransaction(): void
    {
        try {
            repo_transaction($this->db, function (): void {
                $this->insert('doomed');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        // If the depth tracker leaked, this call would believe it is nested,
        // skip its own BEGIN/COMMIT and run in autocommit or not at all.
        repo_transaction($this->db, function (): void {
            $this->insert('after');
        });

        self::assertSame(1, $this->committedRows(), 'a fresh transaction after a rollback works normally');
    }
}
