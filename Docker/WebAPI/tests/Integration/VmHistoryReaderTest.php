<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/repo/status_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_history.php';
require_once dirname(__DIR__, 2) . '/lib/package_run_report.php';
require_once dirname(__DIR__, 2) . '/lib/repo/package_runs.php';

/**
 * The SQL side of the VM chronicle (SP3, F03 with F4): every stream applies the
 * keyset and the high-water mark of the pure owner, the change audit reaches the
 * bulk rows through the index of migration 0069, a refused source is never read,
 * and a failing source leaves the others readable.
 */
final class VmHistoryReaderTest extends TestCase
{
    private const PREFIX = 'phpunit_vmhist_';
    private const T = '2026-10-30 10:00:00';
    private const PACKAGE_RUN = '018f2f49-5e41-4d55-8f05-8f55a5336e01';

    private mysqli $db;
    private int $missionId = 0;
    private int $vm = 0;
    private int $other = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [self::PREFIX . 'mission', 'active']);
        $this->missionId = (int) $this->db->insert_id;
        $this->vm = $this->insertVm('A');
        $this->other = $this->insertVm('B');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testFortyFiveEntriesWithOneTimestampPageWithoutLossOrDuplicate(): void
    {
        $expected = $this->seedFortyFive();
        $sources = VIRTUSPHERE_VM_HISTORY_SOURCES;

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = repo_vm_history_page($this->db, $this->vm, $sources, null, null, $cursor, 10);
            self::assertSame([], $page['failed']);
            foreach ($page['entries'] as $entry) {
                $seen[] = $entry['kind'] . '#' . $entry['id'];
            }
            if ($pages === 0) {
                // Arrives after the chronicle was opened, in the same second.
                repo_execute($this->db, 'INSERT INTO deploy_vm_status_events (vm_id, lifecycle_state, mecm_sync_state, legacy_status, note, created_at) VALUES (?, ?, ?, ?, ?, ?)', 'isssss', [$this->vm, 'ready', 'not_ready', '2/5 Registered', 'late', self::T]);
            }
            $cursor = $page['next'] === null ? null : virtusphere_vm_history_cursor_decode($page['next']);
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertSame(5, $pages);
        self::assertCount(45, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no entry twice across page boundaries');
        self::assertEqualsCanonicalizing($expected, $seen, 'exactly the snapshot: the late row and the other VM never appear');
    }

    public function testTheAuditSourceIsNeverReadWithoutItsPermission(): void
    {
        $this->seedFortyFive();
        $sources = virtusphere_vm_history_filter([], false, 'UTC')['sources'];
        $streams = repo_vm_history_streams();
        $streams[VIRTUSPHERE_VM_HISTORY_AUDIT] = static function (): array {
            throw new LogicException('the audit stream must not be queried without users.manage');
        };

        $page = repo_vm_history_page($this->db, $this->vm, $sources, null, null, null, 100, $streams);

        self::assertSame([], $page['failed']);
        self::assertCount(30, $page['entries'], 'the 15 audit entries are neither read nor counted');
        self::assertNotContains(VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT, array_column($page['entries'], 'source'));
        self::assertNull(repo_vm_history_marks($this->db, $this->vm, $sources)[VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT], 'no high-water mark of the log either');
    }

    public function testAFailingSourceIsNamedAndTheOthersStayReadable(): void
    {
        $this->seedFortyFive();
        $streams = repo_vm_history_streams();
        $streams[VIRTUSPHERE_VM_TIMELINE_CLIENT] = static function (): array {
            throw new RuntimeException('synthetic client read failure');
        };

        $page = repo_vm_history_page($this->db, $this->vm, VIRTUSPHERE_VM_HISTORY_SOURCES, null, null, null, 100, $streams);

        self::assertSame([VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT], $page['failed']);
        self::assertCount(35, $page['entries']);
    }

    public function testTheDayRangeBoundsEveryStream(): void
    {
        $this->seedFortyFive();
        repo_record_vm_status_event($this->db, $this->vm, 'ready', 'not_ready', '2/5 Registered', 'outside');

        $inside = repo_vm_history_page($this->db, $this->vm, VIRTUSPHERE_VM_HISTORY_SOURCES, '2026-10-30 00:00:00.000000', '2026-10-31 00:00:00.000000', null, 100);
        $before = repo_vm_history_page($this->db, $this->vm, VIRTUSPHERE_VM_HISTORY_SOURCES, null, '2026-10-30 10:00:00.000000', null, 100);

        self::assertCount(45, $inside['entries']);
        self::assertSame([], $before['entries'], 'the upper bound is exclusive');
    }

    public function testTheWriterIndexesEveryContextThatNamesVms(): void
    {
        audit_event($this->db, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE, 'mission', $this->missionId, VIRTUSPHERE_AUDIT_RESULT_WARNING, [
            'vm_count' => 2, 'vm_ids' => [$this->vm, $this->other, $this->vm], 'reason' => VIRTUSPHERE_AUDIT_CONVERGENCE_REASON_STUCK,
        ], null, 'cli');
        $logId = $this->lastLogId();
        audit_event($this->db, VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, 'vm', $this->vm, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated', 'mission_id' => $this->missionId]);

        self::assertSame([[$this->vm], [$this->other]], $this->refs($logId), 'one reference per named VM, duplicates folded');
        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_log_vm_refs WHERE log_id = ?', 'i', [$this->lastLogId()]), 'a row without vm_ids has no reference');

        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE id = ?', 'i', [$logId]);
        self::assertSame([], $this->refs($logId), 'the log retention takes the references with the row');
    }

    public function testTheBackfillIndexesStoredRowsOnceAndIsRepeatable(): void
    {
        repo_execute(
            $this->db,
            "INSERT INTO deploy_logs (ip, category, log_message, event_code, object_type, object_id, result, context_json) VALUES ('cli', ?, 'legacy bulk', ?, 'mission', ?, 'success', ?)",
            'ssss',
            [VIRTUSPHERE_LOG_CATEGORY_VMS, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, (string) $this->missionId, json_encode(['action' => 'bulk_deleted', 'affected_count' => 2, 'vm_ids' => [$this->vm, $this->other]])]
        );
        $logId = (int) $this->db->insert_id;
        self::assertSame([], $this->refs($logId), 'a row written before 0069 has no reference');

        $this->runMigration();
        $this->runMigration();

        self::assertSame([[$this->vm], [$this->other]], $this->refs($logId));
    }

    /** @return list<string> the expected kind#id of the 45 entries of $this->vm */
    private function seedFortyFive(): array
    {
        $expected = [];
        for ($i = 0; $i < 10; $i++) {
            repo_record_vm_status_event($this->db, $this->vm, 'ready', 'not_ready', '2/5 Registered', 'seed');
            $expected[] = VIRTUSPHERE_VM_TIMELINE_STATUS . '#' . $this->db->insert_id;
            repo_execute($this->db, 'INSERT INTO deploy_client_events (vm_id, mac, phase, event, client_ip, created_at) VALUES (?, ?, ?, ?, ?, ?)', 'isssss', [$this->vm, '02:00:00:00:F3:01', 'os', 'started', '192.0.2.1', self::T]);
            $expected[] = VIRTUSPHERE_VM_TIMELINE_CLIENT . '#' . $this->db->insert_id;
        }
        repo_execute($this->db, 'UPDATE deploy_vm_status_events SET created_at = ? WHERE vm_id = ?', 'si', [self::T, $this->vm]);
        for ($i = 0; $i < 4; $i++) {
            repo_execute($this->db, 'INSERT INTO deploy_jobs (mission_id, status, payload_json, started_at) VALUES (?, ?, ?, ?)', 'isss', [$this->missionId, 'succeeded', json_encode(['mode' => 'export']), self::T]);
            $job = (int) $this->db->insert_id;
            repo_execute($this->db, 'UPDATE deploy_jobs SET updated_at = ? WHERE id = ?', 'si', [self::T, $job]);
            repo_execute($this->db, 'INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, ?, ?)', 'iiss', [$job, $this->vm, 'ready', 'not_ready']);
            $expected[] = VIRTUSPHERE_VM_TIMELINE_JOB_STARTED . '#' . $job;
            $expected[] = VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED . '#' . $job;
        }
        $run = $this->packageRun();
        $expected[] = VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED . '#' . $run;
        $expected[] = VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED . '#' . $run;
        $logIds = [];
        for ($i = 0; $i < 8; $i++) {
            audit_event($this->db, VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, 'vm', $this->vm, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated', 'mission_id' => $this->missionId]);
            $logIds[] = $this->lastLogId();
        }
        for ($i = 0; $i < 7; $i++) {
            audit_event($this->db, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, 'mission', $this->missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'bulk_reset_mecm_id', 'affected_count' => 2, 'vm_ids' => [$this->vm, $this->other]], null, 'cli');
            $logIds[] = $this->lastLogId();
        }
        // Noise that must never reach $this->vm: the other VM's own rows.
        repo_record_vm_status_event($this->db, $this->other, 'ready', 'not_ready', '2/5 Registered', 'other');
        audit_event($this->db, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, 'mission', $this->missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'bulk_deleted', 'affected_count' => 1, 'vm_ids' => [$this->other]], null, 'cli');
        $logIds[] = $this->lastLogId();
        foreach ($logIds as $index => $logId) {
            repo_execute($this->db, 'UPDATE deploy_logs SET created_at = ? WHERE id = ?', 'si', [self::T, $logId]);
            if ($index < 15) {
                $expected[] = VIRTUSPHERE_VM_HISTORY_AUDIT . '#' . $logId;
            }
        }

        return $expected;
    }

    private function packageRun(): int
    {
        // The report resolves its VM by MAC and checks the rollout revision, as for a real client.
        repo_execute($this->db, 'UPDATE deploy_vms SET mecm_rollout_revision = 1 WHERE id = ?', 'i', [$this->vm]);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss',
            [$this->vm, '192.0.2.31', '255.255.255.0', '192.0.2.1', '02:00:00:00:F3:02', 'dhcp', 'vmxnet3']
        );
        $device = (string) repo_scalar($this->db, 'SELECT LOWER(BIN_TO_UUID(package_report_generation)) FROM deploy_vms WHERE id = ?', 'i', [$this->vm]);
        $acceptance = (string) repo_scalar($this->db, 'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1');
        $base = [
            'schema_version' => 1, 'run_id' => self::PACKAGE_RUN, 'event' => 'started', 'event_seq' => 1,
            'mac_candidates' => ['02:00:00:00:F3:02'], 'rollout_revision' => 1,
            'device_generation' => $device, 'acceptance_generation' => $acceptance,
            'project_name' => 'PHPUNIT-VMHIST', 'package_version' => '1.0',
            'client_started_at' => '2026-10-30T10:00:00Z', 'event_at' => '2026-10-30T10:00:00Z',
            'context' => 'system', 'total' => 1,
        ];
        $completed = array_replace($base, [
            'event' => 'completed', 'event_seq' => 2, 'wrapper_result' => 'ok', 'wrapper_exit_code' => 0, 'detection_result' => 'not_attempted',
            'processed_count' => 1, 'ok_count' => 1, 'skip_count' => 0, 'fail_count' => 0, 'last_processed_index' => 1,
            'payload_omitted_count' => 0, 'wrapper_log_path' => null, 'reporting_log_path' => null,
        ]);
        foreach ([$base, $completed] as $report) {
            $validated = package_run_report_validate($report);
            self::assertArrayHasKey('report', $validated, json_encode($validated));
            self::assertSame(200, repo_package_report_record($this->db, $this->vm, $validated['report'])['status']);
        }
        repo_execute($this->db, 'UPDATE deploy_package_runs SET first_received_at = ?, completed_received_at = ? WHERE run_id = UUID_TO_BIN(?)', 'sss', [self::T, self::T, self::PACKAGE_RUN]);

        return (int) repo_scalar($this->db, 'SELECT id FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::PACKAGE_RUN]);
    }

    /** @return list<list<int>> */
    private function refs(int $logId): array
    {
        $rows = $this->rows('SELECT vm_id FROM deploy_log_vm_refs WHERE log_id = ? ORDER BY vm_id', 'i', [$logId]);

        return array_map(static fn (array $row): array => [(int) $row['vm_id']], $rows);
    }

    private function lastLogId(): int
    {
        return (int) repo_scalar($this->db, 'SELECT MAX(id) FROM deploy_logs');
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    private function runMigration(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/migrate.php', true) . '; migrate_0069_audit_vm_refs(db());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
    }

    private function insertVm(string $label): int
    {
        $name = strtoupper(self::PREFIX . $label);
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)', 'iss', [$this->missionId, $name, $name]);

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $vmIds = array_map(static fn (array $row): int => (int) $row['id'], $this->rows('SELECT v.id FROM deploy_vms v JOIN deploy_missions m ON m.id = v.mission_id WHERE m.mission_name LIKE ?', 's', [self::PREFIX . '%']));
        foreach ($vmIds as $vmId) {
            repo_execute($this->db, "DELETE l FROM deploy_logs l JOIN deploy_log_vm_refs x ON x.log_id = l.id WHERE x.vm_id = ?", 'i', [$vmId]);
            repo_execute($this->db, "DELETE FROM deploy_logs WHERE object_type = 'vm' AND object_id = ?", 's', [(string) $vmId]);
        }
        repo_execute($this->db, "DELETE FROM deploy_logs WHERE log_message = 'legacy bulk'");
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name LIKE ?', 's', [self::PREFIX . '%']);
        repo_execute($this->db, 'DELETE FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [self::PACKAGE_RUN]);
    }
}
