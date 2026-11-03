<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_remote_execution.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_worker_lease.php';

final class RemoteInventoryConsumerTest extends TestCase
{
    private const CREDENTIAL_NAME = 'phpunit_remote_inventory_consumer';
    private const CORRELATION_ID = '8ro3fixture00000000000000000001';
    private ?mysqli $db = null;
    private int $credentialId = 0;
    /** @var array<string, mixed> */
    private array $leaseBefore = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->leaseBefore = repo_deploy_worker_lease_snapshot($this->db);
        $this->cleanup();
        $actor = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        if ($actor <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }
        $this->credentialId = repo_create_credential($this->db, [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'name' => self::CREDENTIAL_NAME,
            'host' => 'ansible.example.test',
            'port' => 22,
            'username' => 'svc-ansible',
        ], 'fixture-secret', $actor);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testDisabledActivationCannotPrepareAHandle(): void
    {
        $jobId = $this->fixtureJob();
        $fence = ['worker_id' => 'fixture-worker', 'lock_token' => str_repeat('a', 32), 'worker_epoch' => 1];
        try {
            repo_prepare_remote_inventory_execution($this->db, $jobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $fence);
        } catch (RuntimeException $exception) {
            self::assertSame('Remote inventory activation is not site-approved.', $exception->getMessage());
            self::assertSame('0', (string) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_executions WHERE job_id = ?', 'i', [$jobId]));
            return;
        }
        self::fail('disabled inventory activation unexpectedly prepared a remote handle');
    }

    public function testFixturePilotProvesReattachOffsetAndCleanupWithoutLeavingActivation(): void
    {
        $jobId = $this->fixtureJob();
        $fence = $this->fixtureFence($jobId);
        repo_execute($this->db, "UPDATE deploy_remote_mode_activations SET state = 'pilot_remote', contract_version = 'remote_v1' WHERE credential_ansible_id = ? AND mode = 'inventory'", 'i', [$this->credentialId]);
        $execution = repo_prepare_remote_inventory_execution($this->db, $jobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $fence);
        self::assertSame('prepared', $execution['controller_state']);
        self::assertSame('pending', $execution['cleanup_state']);
        try {
            repo_prepare_remote_inventory_execution($this->db, $jobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $fence);
            self::fail('duplicate prepare unexpectedly created a second handle');
        } catch (mysqli_sql_exception) {
            self::assertSame('1', (string) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_executions WHERE job_id = ?', 'i', [$jobId]));
        }
        try {
            repo_begin_remote_inventory_cleanup($this->db, (int) $execution['id'], $fence);
            self::fail('unsafe cleanup unexpectedly passed');
        } catch (RuntimeException) {
            self::assertSame('pending', repo_deploy_remote_execution($this->db, (int) $execution['id'])['cleanup_state']);
        }

        $launch = json_encode([
            'schema' => 'virtusphere.remote.launch/v1',
            'run_token' => $execution['run_token'],
            'unit_name' => $execution['unit_name'],
            'decision' => 'launched',
            'written_at' => '2026-08-20T12:00:00.000Z',
        ], JSON_THROW_ON_ERROR);
        $staleFence = $fence;
        $staleFence['worker_epoch']--;
        try {
            repo_observe_remote_inventory_execution($this->db, (int) $execution['id'], $launch, null, null, false, $staleFence);
            self::fail('stale epoch unexpectedly wrote a remote observation');
        } catch (RuntimeException) {
            self::assertSame('prepared', repo_deploy_remote_execution($this->db, (int) $execution['id'])['controller_state']);
        }
        $active = repo_observe_remote_inventory_execution($this->db, (int) $execution['id'], $launch, null, null, false, $fence);
        self::assertSame('active', $active['controller_state']);

        $sentinel = 'O3-secret-sentinel';
        $rawChunk = 'inventory ' . $sentinel . " line\n";
        $nextOffset = repo_import_remote_inventory_output($this->db, (int) $execution['id'], 0, $rawChunk, $fence, [$sentinel]);
        self::assertSame(strlen($rawChunk), $nextOffset);
        self::assertSame($nextOffset, repo_import_remote_inventory_output($this->db, (int) $execution['id'], 0, $rawChunk, $fence, [$sentinel]));
        self::assertSame('1', (string) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND stream = 'ansible'", 'i', [$jobId]));
        self::assertSame('0', (string) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line LIKE ?', 'is', [$jobId, '%' . $sentinel . '%']));

        $result = json_encode([
            'schema' => 'virtusphere.remote.result/v1',
            'run_token' => $execution['run_token'],
            'unit_name' => $execution['unit_name'],
            'outcome' => 'completed',
            'exit_code' => 0,
            'output_truncated' => false,
            'started_at' => '2026-08-20T12:00:00.000Z',
            'finished_at' => '2026-08-20T12:01:00.000Z',
        ], JSON_THROW_ON_ERROR);
        $finished = repo_observe_remote_inventory_execution($this->db, (int) $execution['id'], null, null, $result, false, $fence);
        self::assertSame('exited_0', $finished['controller_state']);
        self::assertSame('pending', $finished['reconciliation_state']);
        self::assertNotSame('goal_verified', $finished['effect_state']);
        self::assertSame(hash('sha256', $result), $finished['result_sha256']);

        repo_mark_remote_inventory_reconciled($this->db, (int) $execution['id'], true, $fence);
        repo_begin_remote_inventory_cleanup($this->db, (int) $execution['id'], $fence);
        repo_record_remote_inventory_cleanup($this->db, (int) $execution['id'], true, $fence);
        $cleaned = repo_deploy_remote_execution($this->db, (int) $execution['id']);
        self::assertSame('goal_verified', $cleaned['effect_state']);
        self::assertSame('resolved_success', $cleaned['reconciliation_state']);
        self::assertSame('cleaned', $cleaned['cleanup_state']);
    }

    public function testOffsetGapAndCorruptResultRequireManualReview(): void
    {
        $foreignJobId = $this->fixtureJob();
        $foreignFence = $this->fixtureFence($foreignJobId);
        repo_execute($this->db, "UPDATE deploy_remote_mode_activations SET state = 'pilot_remote', contract_version = 'remote_v1' WHERE credential_ansible_id = ? AND mode = 'inventory'", 'i', [$this->credentialId]);
        repo_execute($this->db, 'UPDATE deploy_jobs SET execution_generation_id = UNHEX(?) WHERE id = ?', 'si', [str_repeat('f', 32), $foreignJobId]);
        try {
            repo_prepare_remote_inventory_execution($this->db, $foreignJobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $foreignFence);
            self::fail('foreign generation unexpectedly prepared a handle');
        } catch (RuntimeException) {
            self::assertSame('0', (string) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_executions WHERE job_id = ?', 'i', [$foreignJobId]));
        }

        $jobId = $this->fixtureJob();
        $fence = $this->fixtureFence($jobId);
        repo_execute($this->db, "UPDATE deploy_remote_mode_activations SET state = 'pilot_remote', contract_version = 'remote_v1' WHERE credential_ansible_id = ? AND mode = 'inventory'", 'i', [$this->credentialId]);
        $execution = repo_prepare_remote_inventory_execution($this->db, $jobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $fence);
        try {
            repo_import_remote_inventory_output($this->db, (int) $execution['id'], 9, 'gap', $fence);
            self::fail('offset gap unexpectedly passed');
        } catch (RuntimeException) {
            $afterGap = repo_deploy_remote_execution($this->db, (int) $execution['id']);
            self::assertSame('protocol_error', $afterGap['controller_state']);
            self::assertSame('manual_required', $afterGap['reconciliation_state']);
        }

        $corruptJobId = $this->fixtureJob();
        $corruptFence = $this->fixtureFence($corruptJobId);
        $corrupt = repo_prepare_remote_inventory_execution($this->db, $corruptJobId, $this->credentialId, str_repeat('1', 32), '/home/ansible/.local/state/virtusphere', $corruptFence);
        try {
            repo_observe_remote_inventory_execution($this->db, (int) $corrupt['id'], null, null, '{"bad":true}', false, $corruptFence);
            self::fail('corrupt result unexpectedly passed');
        } catch (RuntimeException) {
            $afterCorrupt = repo_deploy_remote_execution($this->db, (int) $corrupt['id']);
            self::assertSame('protocol_error', $afterCorrupt['controller_state']);
            self::assertSame('manual_required', $afterCorrupt['reconciliation_state']);
            self::assertSame('protocol', $afterCorrupt['last_probe_category']);
        }
    }

    private function fixtureJob(): int
    {
        $generation = (string) repo_scalar($this->db, 'SELECT current_generation_id FROM deploy_runtime_identity WHERE id = 1');
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY], JSON_THROW_ON_ERROR);
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE;
        $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $correlationId = self::CORRELATION_ID;
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (status, attempts, payload_json, credential_ansible_id, execution_contract, execution_generation_id, correlation_id) VALUES (?, 1, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $status, $payload, $this->credentialId, $contract, $generation, $correlationId);
        $stmt->execute();
        return (int) $this->db->insert_id;
    }

    /** @return array{worker_id:string,lock_token:string,worker_epoch:int} */
    private function fixtureFence(int $jobId): array
    {
        $fence = [
            'worker_id' => 'fixture-worker',
            'lock_token' => str_repeat('a', 32),
            'worker_epoch' => (int) ($this->leaseBefore['epoch'] ?? 0) + 1,
        ];
        repo_execute($this->db, "UPDATE deploy_worker_leases SET epoch = ?, owner_token = ?, claims_paused = 0, pause_reason = NULL WHERE lease_name = 'deploy-worker'", 'is', [$fence['worker_epoch'], $fence['lock_token']]);
        repo_execute($this->db, 'UPDATE deploy_jobs SET locked_by = ?, lock_token = ?, worker_epoch = ? WHERE id = ?', 'ssii', [$fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId]);
        return $fence;
    }

    private function cleanup(): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE correlation_id = ?', 's', [self::CORRELATION_ID]);
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name = ?');
        $name = self::CREDENTIAL_NAME;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        if ($this->leaseBefore !== []) {
            $epoch = (int) $this->leaseBefore['epoch'];
            $owner = $this->leaseBefore['owner_token'];
            $paused = $this->leaseBefore['claims_paused'] ? 1 : 0;
            $reason = $this->leaseBefore['pause_reason'];
            repo_execute($this->db, "UPDATE deploy_worker_leases SET epoch = ?, owner_token = ?, claims_paused = ?, pause_reason = ? WHERE lease_name = 'deploy-worker'", 'isis', [$epoch, $owner, $paused, $reason]);
        }
    }
}
