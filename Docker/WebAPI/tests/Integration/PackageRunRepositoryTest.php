<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/package_run_report.php';
require_once dirname(__DIR__, 2) . '/lib/package_report_restore_converge.php';
require_once dirname(__DIR__, 2) . '/lib/repo/package_runs.php';

final class PackageRunRepositoryTest extends TestCase
{
    private const PROJECT = 'PHPUNIT-PACKAGE-REPORT';
    private const MAC = '02:00:00:00:56:01';
    private const RUNS = [
        '018f2f49-5e41-4d55-8f05-8f55a5335001',
        '018f2f49-5e41-4d55-8f05-8f55a5335002',
        '018f2f49-5e41-4d55-8f05-8f55a5335003',
        '018f2f49-5e41-4d55-8f05-8f55a5335004',
        '018f2f49-5e41-4d55-8f05-8f55a5335005',
        '018f2f49-5e41-4d55-8f05-8f55a5335006',
    ];

    private mysqli $db;
    private int $missionId;
    private int $vmId;
    private string $deviceGeneration;
    private string $acceptanceGeneration;
    private string $acceptanceRotatedAt;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->acceptanceGeneration = (string) repo_scalar(
            $this->db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1'
        );
        $this->acceptanceRotatedAt = (string) repo_scalar(
            $this->db,
            'SELECT rotated_at FROM deploy_package_report_state WHERE id = 1'
        );
        $name = self::PROJECT . '-MISSION';
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [$name, 'active']);
        $this->missionId = (int) $this->db->insert_id;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_rollout_revision) VALUES (?, ?, ?, 3)',
            'iss', [$this->missionId, self::PROJECT . '-VM', self::PROJECT . '-VM']);
        $this->vmId = (int) $this->db->insert_id;
        $this->deviceGeneration = (string) repo_scalar($this->db,
            'SELECT LOWER(BIN_TO_UUID(package_report_generation)) FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);
        repo_execute($this->db,
            'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss', [$this->vmId, '10.0.0.10', '255.255.255.0', '10.0.0.1', self::MAC, 'dhcp', 'vmxnet3']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'UPDATE deploy_package_report_state SET acceptance_generation = UUID_TO_BIN(?), rotated_at = ? WHERE id = 1',
            'ss', [$this->acceptanceGeneration, $this->acceptanceRotatedAt]);
        $this->cleanup();
    }

    public function testIdenticalReplayIsDeduplicatedWithoutRefreshingEvidenceOrExpiry(): void
    {
        $report = $this->validated($this->base(self::RUNS[0]));
        self::assertSame(['status' => 200, 'accepted' => true, 'deduplicated' => false],
            repo_package_report_record($this->db, $this->vmId, $report));
        $before = $this->runSnapshot(self::RUNS[0]);

        self::assertSame(['status' => 200, 'accepted' => false, 'deduplicated' => true],
            repo_package_report_record($this->db, $this->vmId, $report));
        self::assertSame($before, $this->runSnapshot(self::RUNS[0]));
        self::assertSame(1, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_run_events e JOIN deploy_package_runs r ON r.id = e.package_run_id WHERE r.run_id = UUID_TO_BIN(?)',
            's', [self::RUNS[0]]));
    }

    public function testConflictingReplayRollsBackAProvisionalTotalChange(): void
    {
        $first = $this->base(self::RUNS[1]);
        $first['total'] = null;
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($first))['status']);

        $conflict = $first;
        $conflict['event_seq'] = 2;
        $conflict['event_at'] = '2026-09-21T12:00:01Z';
        $conflict['total'] = 5;
        self::assertSame(['status' => 409, 'error' => 'event_conflict'],
            repo_package_report_record($this->db, $this->vmId, $this->validated($conflict)));
        self::assertNull(repo_scalar($this->db, 'SELECT total FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)',
            's', [self::RUNS[1]]));
    }

    public function testNormalCapKeepsTheLateFirstFailureAndCompletionReserve(): void
    {
        $base = $this->base(self::RUNS[2]);
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($base))['status']);
        for ($index = 1; $index <= VIRTUSPHERE_PACKAGE_REPORT_NORMAL_DETAIL_LIMIT; $index++) {
            $step = $this->step($base, $index, 'ok', false);
            self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($step))['status']);
        }
        $excess = $this->step($base, 257, 'ok', false);
        self::assertSame(['status' => 422, 'error' => 'detail_limit'],
            repo_package_report_record($this->db, $this->vmId, $this->validated($excess)));

        $failure = $this->step($base, 300, 'fail', true);
        $failure['error_category'] = 'child_exit';
        $failure['child_exit_code'] = 1;
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($failure))['status']);
        $completed = $this->completed($base, $failure);
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($completed))['status']);

        $runId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[2]]);
        self::assertSame(256, (int) repo_scalar($this->db,
            "SELECT COUNT(*) FROM deploy_package_step_results WHERE package_run_id = ? AND storage_class = 'normal'", 'i', [$runId]));
        self::assertSame(1, (int) repo_scalar($this->db,
            "SELECT COUNT(*) FROM deploy_package_step_results WHERE package_run_id = ? AND storage_class = 'first_failure'", 'i', [$runId]));
        self::assertSame(259, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_run_events WHERE package_run_id = ?', 'i', [$runId]));
    }

    public function testExpiredMarkerCannotRecreateItsDeletedDiagnosticRun(): void
    {
        $report = $this->validated($this->base(self::RUNS[3]));
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $report)['status']);
        repo_execute($this->db, 'DELETE FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[3]]);
        repo_execute($this->db, 'UPDATE deploy_package_run_markers SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MICROSECOND) WHERE run_id = UUID_TO_BIN(?)',
            's', [self::RUNS[3]]);

        self::assertSame(['status' => 410, 'error' => 'report_expired'],
            repo_package_report_record($this->db, $this->vmId, $report));
        self::assertSame(0, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[3]]));
    }

    public function testRoutineRetentionPurgesOnlyExpiredDiagnosticsAndKeepsReplayMarkers(): void
    {
        $expired = $this->validated($this->base(self::RUNS[0]));
        $live = $this->validated($this->base(self::RUNS[5]));
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $expired)['status']);
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $live)['status']);
        repo_execute($this->db,
            'UPDATE deploy_package_run_markers SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MICROSECOND) WHERE run_id = UUID_TO_BIN(?)',
            's', [self::RUNS[0]]);

        self::assertSame(1, repo_purge_expired_package_runs($this->db));
        self::assertSame(0, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[0]]));
        self::assertSame(1, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[5]]));
        self::assertSame(2, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_run_markers WHERE run_id IN (UUID_TO_BIN(?), UUID_TO_BIN(?))',
            'ss', [self::RUNS[0], self::RUNS[5]]));
        self::assertSame(['status' => 410, 'error' => 'report_expired'],
            repo_package_report_record($this->db, $this->vmId, $expired));
        self::assertSame(['status' => 200, 'accepted' => false, 'deduplicated' => true],
            repo_package_report_record($this->db, $this->vmId, $live));
    }

    public function testCompletionCannotContradictAlreadyStoredDetailCounts(): void
    {
        $base = $this->base(self::RUNS[3]);
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($base))['status']);
        $step = $this->step($base, 1, 'ok', false);
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($step))['status']);

        $completed = $this->completed($base, $this->step($base, 300, 'fail', true));
        $completed['ok_count'] = 0;
        $completed['fail_count'] = 1;
        $completed['processed_count'] = 1;
        $completed['last_processed_index'] = 300;

        self::assertSame(['status' => 409, 'error' => 'completion_conflict'],
            repo_package_report_record($this->db, $this->vmId, $this->validated($completed)));
        self::assertNull(repo_scalar($this->db,
            'SELECT completed_received_at FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)',
            's', [self::RUNS[3]]));
    }

    public function testRestoreGenerationRejectsOldEvidenceAndAcceptsANewRunWithTheCurrentGeneration(): void
    {
        $rotation = package_report_restore_converge($this->db);
        self::assertSame($this->acceptanceGeneration, $rotation['previous']);
        self::assertNotSame($rotation['previous'], $rotation['current']);
        $old = $this->validated($this->base(self::RUNS[4]));
        self::assertSame(['status' => 409, 'error' => 'acceptance_generation_mismatch'],
            repo_package_report_record($this->db, $this->vmId, $old));
        self::assertSame(0, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[4]]));

        $current = $this->base(self::RUNS[4]);
        $current['acceptance_generation'] = (string) repo_scalar($this->db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1');
        self::assertSame(200, repo_package_report_record($this->db, $this->vmId, $this->validated($current))['status']);
    }

    public function testMacSetResolvesOneVmAndRejectsCrossVmAmbiguity(): void
    {
        self::assertSame(['status' => 200, 'vm_id' => $this->vmId],
            repo_package_report_resolve_vm($this->db, [self::MAC, '02:00:00:00:FF:FF']));
        $other = self::PROJECT . '-OTHER';
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)',
            'iss', [$this->missionId, $other, $other]);
        $otherId = (int) $this->db->insert_id;
        repo_execute($this->db,
            'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss', [$otherId, '10.0.0.11', '255.255.255.0', '10.0.0.1', '02:00:00:00:56:02', 'dhcp', 'vmxnet3']);
        self::assertSame(['status' => 409, 'error' => 'ambiguous_device'],
            repo_package_report_resolve_vm($this->db, [self::MAC, '02:00:00:00:56:02']));
    }

    private function base(string $runId): array
    {
        return [
            'schema_version' => 1, 'run_id' => $runId, 'event' => 'started', 'event_seq' => 1,
            'mac_candidates' => [self::MAC], 'rollout_revision' => 3,
            'device_generation' => $this->deviceGeneration, 'acceptance_generation' => $this->acceptanceGeneration,
            'project_name' => self::PROJECT, 'package_version' => '1.0',
            'client_started_at' => '2026-09-21T12:00:00Z', 'event_at' => '2026-09-21T12:00:00Z',
            'context' => 'system', 'total' => 300,
        ];
    }

    private function step(array $base, int $index, string $result, bool $first): array
    {
        return array_replace($base, [
            'event' => 'step_result', 'event_seq' => $index + 1, 'event_at' => '2026-09-21T12:01:00Z',
            'step_index' => $index, 'script_name' => sprintf('%03d.ps1', $index), 'result' => $result,
            'is_first_failure' => $first, 'error_category' => null, 'child_exit_code' => null,
            'duration_ms' => 1, 'detail_path' => null,
        ]);
    }

    private function completed(array $base, array $failure): array
    {
        return array_replace($base, [
            'event' => 'completed', 'event_seq' => 302, 'event_at' => '2026-09-21T12:05:00Z',
            'wrapper_result' => 'failed', 'wrapper_exit_code' => 1, 'detection_result' => 'not_attempted',
            'processed_count' => 300, 'ok_count' => 299, 'skip_count' => 0, 'fail_count' => 1,
            'last_processed_index' => 300,
            'first_failure' => [
                'step_index' => 300, 'script_name' => $failure['script_name'],
                'error_category' => 'child_exit', 'child_exit_code' => 1, 'detail_path' => null,
            ],
            'payload_omitted_count' => 43, 'wrapper_log_path' => null, 'reporting_log_path' => null,
        ]);
    }

    private function validated(array $payload): array
    {
        $validated = package_run_report_validate($payload);
        self::assertArrayHasKey('report', $validated, json_encode($validated));

        return $validated['report'];
    }

    private function runSnapshot(string $runId): array
    {
        return repo_fetch_one($this->db,
            'SELECT r.first_received_at, r.last_evidence_at, m.expires_at FROM deploy_package_runs r JOIN deploy_package_run_markers m ON m.run_id = r.run_id WHERE r.run_id = UUID_TO_BIN(?)',
            's', [$runId]) ?? [];
    }

    private function cleanup(): void
    {
        foreach (self::RUNS as $runId) {
            repo_execute($this->db, 'DELETE FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?)', 's', [$runId]);
            repo_execute($this->db, 'DELETE FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [$runId]);
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name = ?', 's', [self::PROJECT . '-MISSION']);
    }
}
