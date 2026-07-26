<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/machine_api.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * The audit throttle behind every machine-API warning, one test per defect it
 * used to have. All five were blocking, because the campaign puts new channels
 * (the 403 trace) on this helper, and a broken throttle turns a security signal
 * into a silence.
 *
 *  1. the key was the tag alone, so ONE noisy client suppressed that tag for
 *     every other client for an hour,
 *  2. the category was hardcoded to `mecm`, so the new `machine_api` category
 *     could not have used the helper at all,
 *  3. the lookup was a LIKE on the TEXT message column of deploy_logs, i.e. a
 *     full table scan for a tag that had never been written - on a path served
 *     every ten seconds,
 *  4. suppressed events left no counter, so a burst and a single
 *     misconfiguration read identically,
 *  5. two concurrent requests both passed the check and both wrote.
 *
 * Defect 3 is the one a test cannot observe as behaviour; it is pinned as the
 * absence of the LIKE and the presence of the keyed store.
 */
final class MachineApiAuditThrottleTest extends TestCase
{
    private const TAG = 'phpunit_throttle_tag';
    private const IP_A = '203.0.113.41';
    private const IP_B = '203.0.113.42';

    private mysqli $db;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $_SESSION = [];
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testTheFirstOccurrencePassesAndTheSecondIsSuppressed(): void
    {
        $first = machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);
        $second = machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);

        self::assertTrue($first['allowed']);
        self::assertSame(0, $first['suppressed']);
        self::assertFalse($second['allowed'], 'a repeat inside the window must not reach the audit log');
    }

    /** Defect 1: the loudest client used to silence everybody else. */
    public function testANoisyClientDoesNotSuppressAnotherClientsFirstOccurrence(): void
    {
        machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);
        machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);

        $other = machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_B);

        self::assertTrue($other['allowed'], 'a second client must be heard even while the first one is throttled');
    }

    /** Defect 2: one category could use the helper, the rest could not. */
    public function testTheSameTagIsThrottledPerCategory(): void
    {
        machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);

        $otherCategory = machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MECM, self::TAG, self::IP_A);

        self::assertTrue($otherCategory['allowed'], 'the category is part of the key, not a hardcoded constant');
    }

    /** Defect 4: the count of what was swallowed is the information content. */
    public function testTheSuppressedCountIsCarriedToTheNextLineThatGetsThrough(): void
    {
        machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);
        for ($i = 0; $i < 4; $i++) {
            machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);
        }

        // Age the row past the window instead of sleeping an hour.
        $this->ageThrottleRow(VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS + 60);
        $next = machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);

        self::assertTrue($next['allowed']);
        self::assertSame(4, $next['suppressed'], 'the four swallowed occurrences must be reported, not lost');

        // And the counter restarts, or the next window would inherit the number.
        $this->ageThrottleRow(VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS + 60);
        self::assertSame(0, machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A)['suppressed']);
    }

    /** The window is what reopens the channel, nothing else. */
    public function testTheChannelReopensAfterTheWindow(): void
    {
        machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A);
        self::assertFalse(machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A)['allowed']);

        $this->ageThrottleRow(VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS + 1);

        self::assertTrue(machine_api_throttle_allows($this->db, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, self::TAG, self::IP_A)['allowed']);
    }

    /**
     * The whole point: a warning reaches the portal log with its suppressed
     * count, and a repeat inside the window does not add a second row.
     */
    public function testTheAuditWriterProducesOneRowPerWindowAndNamesTheSuppressed(): void
    {
        machine_api_audit_warning($this->db, self::TAG, 'first occurrence', self::IP_A, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API);
        machine_api_audit_warning($this->db, self::TAG, 'second occurrence', self::IP_A, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API);

        self::assertSame(1, $this->logRows(), 'the second occurrence must not produce a second row');

        $this->ageThrottleRow(VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS + 60);
        machine_api_audit_warning($this->db, self::TAG, 'third occurrence', self::IP_A, VIRTUSPHERE_LOG_CATEGORY_MACHINE_API);

        self::assertSame(2, $this->logRows());
        $latest = (string) repo_scalar(
            $this->db,
            'SELECT log_message FROM deploy_logs WHERE category = ? AND log_message LIKE ? ORDER BY id DESC LIMIT 1',
            'ss',
            [VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, '%' . self::TAG . '%']
        );
        self::assertStringContainsString('third occurrence', $latest);
        self::assertStringContainsString('suppressed', $latest, 'the line that breaks the silence must say what it stood for');
    }

    /**
     * Defect 3 has no observable behaviour, only a cost. Pin its absence: the
     * decision must not read deploy_logs at all, because the message column is
     * TEXT and cannot carry an index.
     */
    public function testTheDecisionDoesNotScanTheLogTable(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/machine_api.php');

        self::assertStringNotContainsString('log_message LIKE', $source, 'the throttle must not ask the log table');
        self::assertStringContainsString('deploy_audit_throttle', $source);
        self::assertStringContainsString('FOR UPDATE', $source, 'defect 5: the decision has to be a locking read');
    }

    private function ageThrottleRow(int $seconds): void
    {
        repo_execute(
            $this->db,
            'UPDATE deploy_audit_throttle SET last_written_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE tag = ?',
            'is',
            [$seconds, self::TAG]
        );
    }

    private function logRows(): int
    {
        return (int) repo_scalar(
            $this->db,
            'SELECT COUNT(*) FROM deploy_logs WHERE category = ? AND log_message LIKE ?',
            'ss',
            [VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, '%' . self::TAG . '%']
        );
    }

    private function cleanup(): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_audit_throttle WHERE tag = ?', 's', [self::TAG]);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE log_message LIKE ?', 's', ['%' . self::TAG . '%']);
    }
}
