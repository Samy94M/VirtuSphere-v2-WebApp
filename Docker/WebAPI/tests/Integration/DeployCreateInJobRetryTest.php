<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/**
 * The one writer that re-opens a create unit inside its job (prod job 1527),
 * against a real MySQL server: its CAS conditions, the evidence it keeps, the
 * launch intent it clears, the CHECK that keeps attempt and evidence together,
 * and the K15 convergence that must keep treating the VM as touched.
 */
final class DeployCreateInJobRetryTest extends TestCase
{
    private const WORKER = 'phpunit:create-retry';

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
        $this->prefix = 'phpunit_cretry_' . bin2hex(random_bytes(4));
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

    public function testAModuleFailureIsReopenedWithItsEvidenceAndAClearedIntent(): void
    {
        [$claim, $vms] = $this->createJob(2);
        $this->moduleFailure($claim, 1, $vms[0]);
        $this->failBeforeLaunch($claim, 2, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST);
        self::assertSame(1, (int) $this->journal($claim)[$vms[0]]['create_launch_intent']);

        $unit = deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1);

        self::assertNotNull($unit);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, (string) $unit['status']);
        self::assertSame(2, (int) $unit['attempt']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED, (string) $unit['first_error_code']);
        self::assertSame('503 Service Unavailable', (string) $unit['first_error_detail']);
        self::assertSame('1234567890.1', (string) $unit['first_async_jid']);
        foreach (['error_code', 'error_detail', 'async_jid', 'async_deadline_at', 'finished_at', 'existed_before'] as $column) {
            self::assertNull($unit[$column], $column . ' belongs to the next attempt');
        }
        self::assertNotNull($unit['started_at'], 'the unit keeps the start of its first attempt');
        self::assertSame(0, (int) $this->journal($claim)[$vms[0]]['create_launch_intent']);
        // The cleared intent is what lets the second attempt launch at all;
        // with it still set the launch would only search for the old job.
        self::assertTrue(deploy_worker_vm_create_launch_intent($this->db, $claim, $vms[0], true));
        // The unit at position 2 was not a candidate and stays as it was.
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST, (string) repo_deploy_create_results($this->db, (int) $claim['id'])[1]['error_code']);
    }

    public function testTheFourthAttemptIsNeverReopenedAndTheFirstEvidenceStays(): void
    {
        [$claim, $vms] = $this->createJob(1);
        $this->moduleFailure($claim, 1, $vms[0]);
        for ($attempt = 2; $attempt <= VIRTUSPHERE_CREATE_MAX_ATTEMPTS; $attempt++) {
            $unit = deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1);
            self::assertNotNull($unit, 'attempt ' . $attempt . ' is re-opened');
            self::assertSame($attempt, (int) $unit['attempt']);
            // The first attempt's evidence, never the attempt just before.
            self::assertSame('1234567890.1', (string) $unit['first_async_jid']);
            self::assertSame('503 Service Unavailable', (string) $unit['first_error_detail']);
            $this->moduleFailure($claim, 1, $vms[0], '1234567890.' . (10 + $attempt), 'later failure ' . $attempt);
        }

        self::assertNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1));
        $row = repo_deploy_create_results($this->db, (int) $claim['id'])[0];
        self::assertSame(VIRTUSPHERE_CREATE_MAX_ATTEMPTS, (int) $row['attempt']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, (string) $row['status']);
        self::assertSame('later failure ' . VIRTUSPHERE_CREATE_MAX_ATTEMPTS, (string) $row['error_detail']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED, (string) $row['first_error_code']);
    }

    public function testNothingIsReopenedWhileAUnitIsInFlightOrForAnotherCode(): void
    {
        [$claim, $vms] = $this->createJob(2);
        $this->moduleFailure($claim, 1, $vms[0]);
        $this->toRunning($claim, 2, '1234567890.2');
        self::assertNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1), 'a running unit holds the pass back');

        [$claim] = $this->createJob(1);
        $this->failBeforeLaunch($claim, 1, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST);
        self::assertNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1), 'only module_failed is re-opened');
    }

    public function testAForeignOrCancellingClaimCannotReopen(): void
    {
        [$claim, $vms] = $this->createJob(1);
        $this->moduleFailure($claim, 1, $vms[0]);
        $foreign = $this->fence($claim);
        $foreign['lock_token'] = bin2hex(random_bytes(16));
        self::assertNull(deploy_worker_create_requeue_unit($this->db, $claim, $foreign, 1));

        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ? WHERE id = ?', 'si', [VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, (int) $claim['id']]);
        self::assertNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1), 'a cancelling job starts no further VM');
        self::assertSame(1, (int) repo_deploy_create_results($this->db, (int) $claim['id'])[0]['attempt']);
        self::assertSame(1, (int) $this->journal($claim)[$vms[0]]['create_launch_intent'], 'a refused re-open keeps the intent');
    }

    public function testTheSchemaKeepsAttemptAndEvidenceTogether(): void
    {
        [$claim] = $this->createJob(1);
        $this->failBeforeLaunch($claim, 1, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST);
        $jobId = (int) $claim['id'];

        foreach ([
            'UPDATE deploy_create_vm_results SET attempt = 2 WHERE job_id = ?',
            "UPDATE deploy_create_vm_results SET first_error_code = 'module_failed', first_error_detail = 'x', first_async_jid = '1.2' WHERE job_id = ?",
            "UPDATE deploy_create_vm_results SET attempt = 2, first_error_code = 'module_failed', first_error_detail = 'x' WHERE job_id = ?",
        ] as $sql) {
            try {
                repo_execute($this->db, $sql, 'i', [$jobId]);
                self::fail('the CHECK must refuse: ' . $sql);
            } catch (mysqli_sql_exception $exception) {
                self::assertStringContainsString('deploy_create_result_attempt_check', $exception->getMessage());
            }
        }
    }

    /**
     * Without the retry, a unit that failed before its launch restores the
     * VM's bracket. With it, the first attempt's async job reached ESXi, and a
     * second attempt whose read-only Prepare then failed must not paint over
     * that: the VM is failed, as it would have been after attempt one.
     */
    public function testAFailedSecondAttemptKeepsTheVmTouched(): void
    {
        [$claim, $vms] = $this->createJob(1);
        $this->moduleFailure($claim, 1, $vms[0]);
        self::assertNotNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1));
        $this->failBeforeLaunch($claim, 1, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST);

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, $vms, 'create section did not complete');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->state($vms[0]));

        [$plain, $plainVms] = $this->createJob(1);
        $this->failBeforeLaunch($plain, 1, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST);
        deploy_worker_handle_failure($this->db, $plain, self::WORKER, $plainVms, 'create section did not complete');
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($plainVms[0]), 'the countercase without a first launch restores');
    }

    /**
     * A worker lost between Prepare and Launch of the second attempt: the row
     * is prepared, its intent was cleared by the re-open and not yet set
     * again, so the reaper may call it failed, and the evidence of the first
     * attempt stays on the row.
     */
    public function testTheReaperConvergesAPreparedSecondAttemptWithoutLosingTheFirst(): void
    {
        [$claim, $vms] = $this->createJob(1);
        $this->moduleFailure($claim, 1, $vms[0]);
        self::assertNotNull(deploy_worker_create_requeue_unit($this->db, $claim, $this->fence($claim), 1));
        self::assertTrue(repo_deploy_create_transition($this->db, (int) $claim['id'], 1, 'pending', 'prepared', ['existed_before' => 0], $this->fence($claim)));

        self::assertSame(0, repo_deploy_create_converge_reaped($this->db, (int) $claim['id'], 'fixture: heartbeat silent'));
        $row = repo_deploy_create_results($this->db, (int) $claim['id'])[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, (string) $row['status']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, (string) $row['error_code']);
        self::assertSame('1234567890.1', (string) $row['first_async_jid']);
    }

    /** @return array{0:array<string,mixed>,1:list<int>} */
    private function createJob(int $vmCount): array
    {
        $name = $this->prefix . '_' . count($this->missionIds);
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [$name, 'active']);
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;
        $vms = [];
        $selection = [];
        for ($index = 1; $index <= $vmCount; $index++) {
            $vmName = strtoupper($name . '_VM' . $index);
            repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)',
                'issss', [$missionId, $vmName, $vmName, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY]);
            $vms[] = (int) $this->db->insert_id;
            $selection[] = ['id' => $vms[$index - 1], 'vm_name' => $vmName];
        }
        $payload = json_encode(['mode' => 'create', 'vm_ids' => $vms], JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, lock_token, worker_epoch, attempts, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, NOW(), ?, ?, 1, 1, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1',
            'isssss', [$missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $payload, self::WORKER, bin2hex(random_bytes(16)), VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY]);
        $jobId = (int) $this->db->insert_id;
        $claim = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($claim);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'create retry fixture', $vms);
        repo_deploy_create_materialize($this->db, $jobId, $selection);

        return [$claim, $vms];
    }

    /** @param array<string,mixed> $claim */
    private function toRunning(array $claim, int $position, string $jid): void
    {
        $jobId = (int) $claim['id'];
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, 'pending', 'prepared', ['existed_before' => 0], $this->fence($claim)));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, 'prepared', 'running', [
            'async_jid' => $jid,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $this->fence($claim)));
    }

    /** @param array<string,mixed> $claim */
    private function moduleFailure(array $claim, int $position, int $vmId, ?string $jid = null, string $detail = '503 Service Unavailable'): void
    {
        self::assertNotNull(deploy_worker_vm_create_launch_intent($this->db, $claim, $vmId, true));
        $this->toRunning($claim, $position, $jid ?? '1234567890.' . $position);
        self::assertTrue(repo_deploy_create_transition($this->db, (int) $claim['id'], $position, 'running', 'failed', [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            'error_detail' => $detail,
        ], $this->fence($claim)));
    }

    /** @param array<string,mixed> $claim */
    private function failBeforeLaunch(array $claim, int $position, string $code): void
    {
        self::assertTrue(repo_deploy_create_transition($this->db, (int) $claim['id'], $position, 'pending', 'failed', [
            'error_code' => $code,
            'error_detail' => 'fixture: the read-only preparation could not be completed',
        ], $this->fence($claim)));
    }

    /**
     * @param array<string,mixed> $claim
     * @return array<int,array<string,mixed>>
     */
    private function journal(array $claim): array
    {
        return repo_deploy_job_vm_states($this->db, (int) $claim['id']);
    }

    /** @return array{0:string,1:string} */
    private function state(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]);

        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{worker_id:string,lock_token:string,worker_epoch:int}
     */
    private function fence(array $claim): array
    {
        return ['worker_id' => (string) $claim['locked_by'], 'lock_token' => (string) $claim['lock_token'], 'worker_epoch' => (int) $claim['worker_epoch']];
    }
}
