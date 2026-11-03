<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';

/**
 * LG-06: the maintenance pass remembers a source's last known state only once the
 * transition row for it is stored. Before, the state moved first, so a failed insert
 * left one STDERR line and the next pass compared against the new state: the
 * transition was never logged. Now a failed row keeps the old state, so the next pass
 * sees the same transition again, and the pass counts as failed until it is recorded.
 */
final class MaintenanceTransitionAuditTest extends TestCase
{
    /** Not a registered integration source: the registry refuses its audit row, which is the failure under test. */
    private const UNRECORDED_SOURCE = 'phpunit-unrecorded-source';

    private ?mysqli $db = null;
    private int $startId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->startId = $this->maxTransitionId();
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND id > ?', 'si', [VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE, $this->startId]);
    }

    public function testAStoredTransitionIsRememberedAndRecordedOnce(): void
    {
        $source = VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER;
        $state = ['states' => [$source => 'ok']];

        maintenance_worker_record_transitions($this->db, $state, [['source' => $source, 'state' => 'danger']]);

        self::assertSame('danger', $state['states'][$source], 'a recorded transition becomes the new state');
        $rows = $this->transitionRows($source);
        self::assertCount(1, $rows, 'the transition is written exactly once');
        self::assertEqualsCanonicalizing(['old_state' => 'ok', 'new_state' => 'danger'], json_decode((string) $rows[0]['context_json'], true));
    }

    public function testAFailedTransitionRowKeepsTheOldStateAndFailsThePass(): void
    {
        $state = ['states' => [self::UNRECORDED_SOURCE => 'ok']];

        try {
            maintenance_worker_record_transitions($this->db, $state, [['source' => self::UNRECORDED_SOURCE, 'state' => 'danger']]);
            self::fail('a transition that is not stored must fail the pass, not pass silently');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(self::UNRECORDED_SOURCE, $exception->getMessage());
        }

        self::assertSame('ok', $state['states'][self::UNRECORDED_SOURCE], 'the old state stays, so the next pass compares against it again');
    }

    /** One source that cannot be recorded does not hold back the transitions of the others. */
    public function testAFailingRowDoesNotStopTheOthers(): void
    {
        $source = VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER;
        $state = ['states' => [self::UNRECORDED_SOURCE => 'ok', $source => 'ok']];

        try {
            maintenance_worker_record_transitions($this->db, $state, [
                ['source' => self::UNRECORDED_SOURCE, 'state' => 'danger'],
                ['source' => $source, 'state' => 'danger'],
            ]);
            self::fail('the failed row must still fail the pass');
        } catch (RuntimeException) {
            // expected: the verdict is a failure
        }

        self::assertSame('danger', $state['states'][$source]);
        self::assertSame('ok', $state['states'][self::UNRECORDED_SOURCE]);
        self::assertCount(1, $this->transitionRows($source));
    }

    /** No change and a benign wobble (ok <-> legacy) move the state and write no row. */
    public function testAnUnchangedOrBenignStateWritesNoRow(): void
    {
        $source = VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER;
        $state = ['states' => [$source => 'ok']];

        maintenance_worker_record_transitions($this->db, $state, [['source' => $source, 'state' => 'ok']]);
        $state['states'][$source] = 'legacy';
        maintenance_worker_record_transitions($this->db, $state, [['source' => $source, 'state' => 'ok']]);

        self::assertSame([], $this->transitionRows($source));
    }

    private function maxTransitionId(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COALESCE(MAX(id), 0) FROM deploy_logs WHERE event_code = ?', 's', [VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE]);
    }

    /** @return list<array{context_json: string}> */
    private function transitionRows(string $source): array
    {
        $event = VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE;
        $stmt = $this->db->prepare('SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_id = ? AND id > ? ORDER BY id');
        $stmt->bind_param('ssi', $event, $source, $this->startId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
