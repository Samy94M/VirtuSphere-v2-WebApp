<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';

/**
 * U-01 against a real database: a worker that lost its SSH connection to the
 * Ansible host in the middle of a step concludes `outcome_unknown` with reason
 * `ansible_connection_lost` (migration 0071), converges its VMs like the
 * failure path and the reaper, stores no failed step and writes a warning
 * outcome row. A cancel accepted before the loss still wins.
 */
final class DeployAnsibleConnectionLostFlowTest extends TestCase
{
    private const WORKER = 'phpunit:u01-worker';
    private const DETAIL = 'SSH connection to the Ansible host was lost while the remote command ran: Connection closed by server (failed step: export.yml)';

    private mysqli $db;
    private string $prefix;
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_u01_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
        }
    }

    public function testALostConnectionEndsUnknownAndConvergesLikeTheFailurePath(): void
    {
        $missionId = $this->insertMission('lost');
        $imported = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $unfinished = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertJob($missionId, [$imported, $unfinished], $this->resultJson([$imported], [$unfinished]));
        $this->markAndTouch($jobId, [$imported, $unfinished], [$unfinished]);

        deploy_worker_handle_failure(
            $this->db,
            $this->job($jobId),
            self::WORKER,
            [$imported, $unfinished],
            self::DETAIL,
            VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST,
            true,
            null,
            null,
            'export'
        );

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, (string) $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST, (string) $job['terminal_reason_code']);
        self::assertSame(self::DETAIL, (string) $job['terminal_reason_detail']);
        // Like the reaper: the diagnosis outlives the log retention.
        self::assertSame(self::DETAIL, (string) $job['last_error']);
        self::assertNull($job['failed_step'], 'no resume at a step nobody knows the end of');
        self::assertNull($job['locked_by']);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->vmState($imported), 'a committed import keeps its state');
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED], $this->vmState($unfinished), 'no MECM pickup without proof');

        $audit = repo_fetch_one(
            $this->db,
            'SELECT result, context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?',
            'sss',
            [VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME, 'deploy_job', (string) $jobId]
        );
        self::assertNotNull($audit);
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_WARNING, (string) $audit['result']);
        $context = json_decode((string) $audit['context_json'], true);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, $context['status'] ?? null);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST, $context['reason'] ?? null);
    }

    public function testACancelAcceptedBeforeTheLossStillWins(): void
    {
        $missionId = $this->insertMission('cancel');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->insertJob($missionId, [$vmId], null);
        $this->markAndTouch($jobId, [$vmId], [$vmId]);
        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ? WHERE id = ?', 'si', [VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, $jobId]);

        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$vmId], self::DETAIL, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST);

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertStringContainsString('connection to the Ansible host was lost', (string) $job['terminal_reason_detail'], 'the cancel says the step end is unknown');
    }

    public function testAWorkerMayNotClaimTheSupervisionsReason(): void
    {
        $missionId = $this->insertMission('reason');
        $jobId = $this->insertJob($missionId, [], null);
        foreach ([VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT, null] as $reason) {
            try {
                repo_finish_deploy_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, 'x', $reason);
                self::fail('A worker concluded outcome_unknown with reason ' . var_export($reason, true) . '.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, (string) $this->job($jobId)['status']);
    }

    public function testTheCheckAdmitsTheReasonOnlyWithTheUnknownOutcome(): void
    {
        $missionId = $this->insertMission('check');
        $jobId = $this->insertJob($missionId, [], null);
        try {
            repo_execute($this->db, 'UPDATE deploy_jobs SET status = ?, terminal_reason_code = ? WHERE id = ?', 'ssi', [VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST, $jobId]);
            self::fail('failed with ansible_connection_lost passed the CHECK.');
        } catch (mysqli_sql_exception $exception) {
            self::assertStringContainsString('deploy_jobs_terminal_reason_check', $exception->getMessage());
        }
        self::assertTrue(repo_execute($this->db, 'UPDATE deploy_jobs SET status = ?, terminal_reason_code = ? WHERE id = ?', 'ssi', [VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_ANSIBLE_CONNECTION_LOST, $jobId]));
    }

    public function testTheMigrationIsIdempotent(): void
    {
        $first = $this->runMigration();
        $second = $this->runMigration();

        self::assertStringContainsString('0071:', $first);
        self::assertStringContainsString('0071:', $second);
        $check = (string) repo_scalar(
            $this->db,
            "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'deploy_jobs_terminal_reason_check'"
        );
        self::assertStringContainsString('ansible_connection_lost', $check);
        self::assertStringContainsString('stale_heartbeat', $check);
    }

    private function runMigration(): string
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/migrate.php', true) . '; migrate_0071_deploy_ansible_connection_lost(db());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);

        return $stdout;
    }

    private function insertMission(string $suffix): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [$this->prefix . '_' . $suffix, 'active']);
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;

        return $id;
    }

    private function insertVm(int $missionId, string $lifecycleState, string $mecmSyncState): int
    {
        $name = strtoupper($this->prefix . '_VM' . bin2hex(random_bytes(2)));
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)',
            'issss',
            [$missionId, $name, $name, $lifecycleState, $mecmSyncState]
        );

        return (int) $this->db->insert_id;
    }

    /** @param list<int> $vmIds */
    private function insertJob(int $missionId, array $vmIds, ?string $resultJson): int
    {
        $payload = json_encode(['mode' => 'export', 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, result_json, locked_at, locked_by, lock_token, worker_epoch, heartbeat_at, attempts, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, ?, NOW(), ?, REPEAT(CHAR(97), 32), 1, NOW(), 1, ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1');
        $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $worker = self::WORKER;
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        $stmt->bind_param('isssss', $missionId, $status, $payload, $resultJson, $worker, $contract);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param list<int> $successful @param list<int> $failed */
    private function resultJson(array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'partial',
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'errors' => [],
            'counts' => [
                'expected_vms' => count($successful) + count($failed),
                'successful_vms' => count($successful),
                'failed_vms' => count($failed),
                'updated_interfaces' => count($successful),
            ],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function job(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return $job;
    }

    /** @param list<int> $vmIds @param list<int> $touchedVmIds */
    private function markAndTouch(int $jobId, array $vmIds, array $touchedVmIds): void
    {
        $job = $this->job($jobId);
        deploy_worker_mark_vms_deploying($this->db, $job, 'U-01 journal setup', $vmIds);
        foreach ($touchedVmIds as $vmId) {
            self::assertTrue(repo_deploy_job_vm_admit_touch($this->db, $jobId, $vmId, (int) $job['attempts'], (string) $job['execution_generation_id'], 'export'));
        }
    }

    /** @return array{0:string,1:string} */
    private function vmState(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertNotNull($row);

        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }
}
