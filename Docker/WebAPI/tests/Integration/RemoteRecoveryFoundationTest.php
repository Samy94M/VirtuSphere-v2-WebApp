<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_remote_recovery.php';

final class RemoteRecoveryFoundationTest extends TestCase
{
    private const CORRELATION_ID = '8ro4fixture00000000000000000001';
    private ?mysqli $db = null;

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
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testLegacyRecoveryStaysActiveUnlocksAndIsIdempotent(): void
    {
        $jobId = $this->fixtureJob(VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY);
        repo_request_remote_recovery($this->db, $jobId, null, VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN);
        repo_request_remote_recovery($this->db, $jobId, null, VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN);
        $job = repo_fetch_one($this->db, 'SELECT status, locked_by, lock_token, worker_epoch, recovery_count, recovery_reason, recovery_requested_at FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $job['status']);
        self::assertNull($job['locked_by']);
        self::assertNull($job['lock_token']);
        self::assertNull($job['worker_epoch']);
        self::assertSame(1, (int) $job['recovery_count']);
        self::assertSame(VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN, $job['recovery_reason']);
        self::assertNotNull($job['recovery_requested_at']);
    }

    public function testRemoteRecoveryKeepsJobActiveAndMovesHandleOnlyToPending(): void
    {
        $jobId = $this->fixtureJob(VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE);
        $generation = (string) repo_scalar($this->db, 'SELECT current_generation_id FROM deploy_runtime_identity WHERE id = 1');
        $token = str_repeat('b', 32);
        $unit = 'virtusphere-j' . $jobId . '-a1-inventory-' . substr($token, 0, 12) . '.service';
        $dir = '/home/ansible/.local/state/virtusphere/' . str_repeat('1', 32) . '/' . bin2hex($generation) . '/jobs/' . $jobId . '/1/inventory/' . $token;
        $instance = hex2bin(str_repeat('1', 32));
        $controller = 'active';
        $effect = 'active_or_possible';
        $reconciliation = 'not_required';
        $cleanup = 'pending';
        $step = 'inventory';
        $protocol = 1;
        $stmt = $this->db->prepare('INSERT INTO deploy_remote_executions (job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir, instance_id, generation_id, controller_state, effect_state, reconciliation_state, cleanup_state) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isisssssssss', $jobId, $step, $protocol, $token, $unit, $dir, $instance, $generation, $controller, $effect, $reconciliation, $cleanup);
        $stmt->execute();
        $executionId = (int) $this->db->insert_id;

        repo_request_remote_recovery($this->db, $jobId, $executionId, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
        $job = repo_fetch_one($this->db, 'SELECT status, recovery_reason FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
        $execution = repo_deploy_remote_execution($this->db, $executionId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $job['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION, $job['recovery_reason']);
        self::assertSame('active', $execution['controller_state']);
        self::assertSame('pending', $execution['reconciliation_state']);
        self::assertSame('pending', $execution['cleanup_state']);
    }

    private function fixtureJob(string $contract): int
    {
        $generation = (string) repo_scalar($this->db, 'SELECT current_generation_id FROM deploy_runtime_identity WHERE id = 1');
        $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $correlation = self::CORRELATION_ID;
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (status, locked_at, locked_by, lock_token, worker_epoch, heartbeat_at, attempts, payload_json, execution_contract, execution_generation_id, correlation_id) VALUES (?, NOW(), ?, ?, 1, NOW(), 1, JSON_OBJECT(\'mode\', \'inventory\'), ?, ?, ?)');
        $worker = 'fixture-worker';
        $token = str_repeat('a', 32);
        $stmt->bind_param('ssssss', $status, $worker, $token, $contract, $generation, $correlation);
        $stmt->execute();
        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE correlation_id = ?', 's', [self::CORRELATION_ID]);
    }
}
