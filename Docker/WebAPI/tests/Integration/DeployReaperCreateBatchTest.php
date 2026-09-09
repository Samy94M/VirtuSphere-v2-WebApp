<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/repo/deploy_jobs.php';
require_once __DIR__ . '/../../lib/repo/deploy_create_identity.php';

/** Full reaper transactions, including the terminal log guard (SC-001). */
final class DeployReaperCreateBatchTest extends TestCase
{
    private mysqli $db;
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        if ((int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_jobs WHERE status IN ('running','cancelling')") > 0) {
            self::markTestSkipped('Foreign active executions exist; global reaper must not mutate them.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->missionIds as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$id]);
        }
    }

    public function testMixedBatchCommitsOpenUnitsAndPreservesEveryDecidedUnitAndCancelLog(): void
    {
        $plain = $this->job('start');
        $running = $this->job('create', ['succeeded', 'failed', 'skipped', 'pending', 'prepared', 'running', 'uncertain']);
        $cancelling = $this->job('create', ['prepared', 'running']);
        $fresh = $this->job('create', ['running'], false);
        $actor = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $actor);
        repo_cancel_deploy_job($this->db, $cancelling, $actor);
        $cancelLogs = $this->logs($cancelling);
        self::assertCount(1, $cancelLogs, 'The accepted cancel request owns its one SYSTEM line.');
        $before = repo_deploy_create_results($this->db, $running);
        $freshBefore = repo_deploy_create_results($this->db, $fresh);

        $reaped = repo_reap_stale_deploy_jobs($this->db, 600);

        self::assertSame([$plain, $running, $cancelling], array_map('intval', array_column($reaped, 'id')));
        self::assertSame(['failed', 'failed', 'cancelled'], array_column($reaped, 'reaped_to'));
        $after = repo_deploy_create_results($this->db, $running);
        self::assertSame(['succeeded', 'failed', 'skipped', 'pending', 'uncertain', 'uncertain', 'uncertain'], array_column($after, 'status'));
        foreach ([0, 1, 2, 3, 6] as $index) {
            self::assertSame($before[$index], $after[$index], 'A decided or unstarted unit must remain byte-for-byte unchanged.');
        }
        foreach ([4, 5] as $index) {
            self::assertSame($before[$index]['async_jid'], $after[$index]['async_jid']);
            self::assertSame(VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, $after[$index]['error_code']);
            self::assertNotNull($after[$index]['finished_at']);
        }
        self::assertSame(['uncertain', 'uncertain'], array_column(repo_deploy_create_results($this->db, $cancelling), 'status'));
        self::assertSame($cancelLogs, $this->logs($cancelling), 'Reaping never appends after an accepted cancellation.');
        foreach ([$plain, $running, $cancelling] as $jobId) {
            $job = repo_deploy_job($this->db, $jobId);
            self::assertSame($jobId === $cancelling ? 'cancelled' : 'failed', $job['status']);
            foreach (['locked_by', 'lock_token', 'worker_epoch', 'heartbeat_at'] as $field) {
                self::assertNull($job[$field]);
            }
            if ($jobId !== $plain) {
                self::assertStringContainsString('2 create unit(s)', (string) $job['terminal_reason_detail']);
            }
        }
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CANCEL_CONVERGED, repo_deploy_job($this->db, $cancelling)['terminal_reason_code']);
        self::assertNull(repo_deploy_job($this->db, $cancelling)['last_error']);
        self::assertCount(1, $this->logs($plain));
        self::assertCount(1, $this->logs($running));
        self::assertStringContainsString('2 create unit(s)', $this->logs($running)[0]['line']);
        self::assertSame('running', repo_deploy_job($this->db, $fresh)['status']);
        self::assertSame($freshBefore, repo_deploy_create_results($this->db, $fresh));
        self::assertSame([], repo_reap_stale_deploy_jobs($this->db, 600));
        self::assertSame($after, repo_deploy_create_results($this->db, $running));
        self::assertSame($cancelLogs, $this->logs($cancelling));

        $logs = $this->logs($running);
        try {
            repo_append_deploy_job_log($this->db, $running, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'forbidden late line');
            self::fail('Terminal log immutability must remain enforced.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot append to a terminal deploy job.', $exception->getMessage());
        }
        self::assertSame($logs, $this->logs($running));
    }

    public function testLockedMissionIsSkippedAndLaterReapedWithItsUnits(): void
    {
        $jobId = $this->job('create', ['running']);
        $missionId = (int) repo_deploy_job($this->db, $jobId)['mission_id'];
        $other = new mysqli(envboot_required('DB_HOST'), envboot_required('DB_USER'), envboot_required('DB_PASS'), envboot_required('DB_NAME'), (int) envboot_optional('DB_PORT', '3306'));
        try {
            repo_transaction($other, function () use ($other, $jobId, $missionId): void {
                repo_fetch_one($other, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$missionId]);
                self::assertSame([], repo_reap_stale_deploy_jobs($this->db, 600));
                self::assertSame('running', repo_deploy_job($this->db, $jobId)['status']);
                self::assertSame('running', repo_deploy_create_results($this->db, $jobId)[0]['status']);
            });
        } finally {
            $other->close();
        }
        self::assertCount(1, repo_reap_stale_deploy_jobs($this->db, 600));
        self::assertSame('uncertain', repo_deploy_create_results($this->db, $jobId)[0]['status']);
    }

    public function testReversedHeartbeatBatchLocksAllCreateRowsBeforeAnyVmConvergence(): void
    {
        $first = $this->job('create', ['running']);
        $second = $this->job('create', ['running']);
        repo_execute($this->db, 'UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?', 'i', [$second]);
        // A real second mysqli connection observes the VM-convergence boundary;
        // only prepare is instrumented, every statement still executes on MySQL.
        $reaper = new class(envboot_required('DB_HOST'), envboot_required('DB_USER'), envboot_required('DB_PASS'), envboot_required('DB_NAME'), (int) envboot_optional('DB_PORT', '3306')) extends mysqli {
            public ?Closure $beforePrepare = null;

            public function prepare(string $query): mysqli_stmt|false
            {
                if ($this->beforePrepare !== null) {
                    ($this->beforePrepare)($query);
                }
                return parent::prepare($query);
            }
        };
        $observations = (object) ['count' => 0];
        $reaper->beforePrepare = function (string $sql) use ($first, $second, $observations): void {
            if (!str_contains($sql, 'SELECT id, lifecycle_state, mecm_sync_state, vm_status FROM deploy_vms')) {
                return;
            }
            $observations->count++;
            foreach ([$first, $second] as $jobId) {
                try {
                    repo_fetch_one($this->db, 'SELECT id FROM deploy_create_vm_results WHERE job_id = ? FOR UPDATE NOWAIT', 'i', [$jobId]);
                    self::fail('The complete Create batch must be locked before the first VM convergence.');
                } catch (mysqli_sql_exception $exception) {
                    self::assertSame(3572, $exception->getCode(), 'NOWAIT must observe the other connection holding this row.');
                }
            }
        };
        try {
            $reaped = repo_reap_stale_deploy_jobs($reaper, 600);
            self::assertSame([$second, $first], array_map('intval', array_column($reaped, 'id')), 'Observation order still follows heartbeat age.');
            self::assertSame(2, $observations->count, 'Both real VM-convergence boundaries must be observed.');
            foreach ([$first, $second] as $jobId) {
                self::assertSame('failed', repo_deploy_job($this->db, $jobId)['status']);
                self::assertSame('uncertain', repo_deploy_create_results($this->db, $jobId)[0]['status']);
            }
        } finally {
            $reaper->close();
        }
    }

    /** Synthetic pre-existing worker state; the actual full reaper is the action under test. */
    private function job(string $mode, array $statuses = [], bool $stale = true): int
    {
        $name = 'phpunit_reap_batch_' . bin2hex(random_bytes(5));
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [$name]);
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;
        $selection = [];
        foreach ($statuses as $index => $status) {
            $vmName = $name . '_' . $index;
            repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)', 'iss', [$missionId, $vmName, $vmName]);
            $selection[] = ['id' => (int) $this->db->insert_id, 'vm_name' => $vmName];
        }
        $payload = json_encode(['mode' => $mode, 'vm_ids' => array_column($selection, 'id')], JSON_THROW_ON_ERROR);
        repo_execute($this->db, "INSERT INTO deploy_jobs (mission_id, status, locked_by, locked_at, heartbeat_at, payload_json) VALUES (?, 'running', 'phpunit-reaper', NOW(), NOW(), ?)", 'is', [$missionId, $payload]);
        $jobId = (int) $this->db->insert_id;
        if ($selection !== []) {
            repo_deploy_create_materialize($this->db, $jobId, $selection);
            foreach ($statuses as $index => $status) {
                $success = in_array($status, ['succeeded', 'skipped'], true);
                $terminal = in_array($status, ['succeeded', 'skipped', 'failed', 'uncertain'], true);
                $values = [
                    'status' => $status,
                    'action' => $status === 'skipped' ? 'verify_skip' : 'create',
                    'async_jid' => '123456789.' . ($index + 1),
                    'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                    'finished_at' => $terminal ? gmdate('Y-m-d H:i:s') : null,
                    'outcome' => $success ? 'unchanged' : null,
                    'changed' => $success ? 0 : null,
                    'existed_before' => $success ? 1 : 0,
                    'vm_moid' => $success ? 'vm-fixture-' . $index : null,
                    'vm_instance_uuid' => $success ? 'uuid-fixture-' . $index : null,
                    'error_code' => $terminal && !$success ? VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT : null,
                    'error_detail' => $terminal && !$success ? 'fixture evidence before reap' : null,
                ];
                $row = repo_deploy_create_results($this->db, $jobId)[$index];
                repo_update_from_values($this->db, 'deploy_create_vm_results', $values, 'id = ?', 'i', [(int) $row['id']]);
            }
        }
        if ($stale) {
            repo_execute($this->db, 'UPDATE deploy_jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 900 SECOND) WHERE id = ?', 'i', [$jobId]);
        }

        return $jobId;
    }

    private function logs(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT seq, stream, line FROM deploy_job_logs WHERE job_id = ? ORDER BY seq');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }
}
