<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * Baseline-compatible K15 behavior probes. They intentionally use only APIs
 * and columns that existed before the VM journal, so the pre-fix red run tests
 * product behavior instead of failing on missing K15 schema or helpers.
 */
final class K15LegacyVmConvergenceBehaviorTest extends TestCase
{
    private const WORKER = 'phpunit:k15-legacy';

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
        $this->prefix = 'phpunit_k15_legacy_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
    }

    public static function lifecyclePreservingModes(): array
    {
        return [
            'start' => ['start'],
            'autostart' => [VIRTUSPHERE_DEPLOY_MODE_AUTOSTART],
        ];
    }

    #[DataProvider('lifecyclePreservingModes')]
    public function testStartAndAutostartFailureDoNotChangeVmLifecycleOrMecm(string $mode): void
    {
        $missionId = $this->mission('preserve-' . $mode);
        $vmId = $this->vm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, true);
        $jobId = $this->job($missionId, $mode, [$vmId]);
        $job = $this->jobRow($jobId);

        deploy_worker_mark_vms_deploying($this->db, $job, 'pre-playbook marker', [$vmId]);
        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$vmId], 'playbook command failed');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vmId));
    }

    public function testFailureBeforeVmMarkKeepsLifecycleAndMecmUnchanged(): void
    {
        $missionId = $this->mission('pre-mark');
        $vmId = $this->vm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, true);
        $jobId = $this->job($missionId, 'powercycle', [$vmId]);

        deploy_worker_handle_failure($this->db, $this->jobRow($jobId), self::WORKER, [$vmId], 'preflight rejected', vmsMarked: false);

        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vmId));
    }

    public function testRegisteredVmRemainsUntouchedBeforeItsPerVmEsxiAttempt(): void
    {
        $missionId = $this->mission('registered');
        $vmId = $this->vm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered', true);
        $stmt = $this->db->prepare("UPDATE deploy_vms SET mecm_id = '4812' WHERE id = ?");
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $jobId = $this->job($missionId, 'export', [$vmId]);

        deploy_worker_mark_vms_deploying($this->db, $this->jobRow($jobId), 'pre-query marker', [$vmId]);

        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered'], $this->state($vmId));
    }

    public function testPreflightCancellationLeavesUnmarkedRegisteredVmAlone(): void
    {
        $missionId = $this->mission('cancel-registered');
        $vmId = $this->vm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered', true);
        $stmt = $this->db->prepare("UPDATE deploy_vms SET mecm_id = '4813' WHERE id = ?");
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $jobId = $this->job($missionId, 'export', [$vmId]);
        deploy_worker_mark_vms_deploying($this->db, $this->jobRow($jobId), 'pre-query marker', [$vmId]);
        $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $cancelling, $jobId);
        $stmt->execute();

        deploy_worker_handle_cancelled($this->db, $this->jobRow($jobId), [$vmId]);

        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered'], $this->state($vmId));
    }

    public function testHistoricalDeployingVmWithoutJournalIsNotGuessedBySweep(): void
    {
        $missionId = $this->mission('historical-sweep');
        $vmId = $this->vm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY);

        $swept = repo_sweep_orphaned_deploying_vms($this->db);

        self::assertNotContains($vmId, array_column($swept, 'vm_id'));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vmId));
    }

    private function mission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;
        return $id;
    }

    private function vm(int $missionId, string $lifecycle, string $mecm, bool $bound = false): int
    {
        $name = strtoupper($this->prefix . '_VM' . bin2hex(random_bytes(2)));
        $uuid = $bound ? 'uuid-' . bin2hex(random_bytes(8)) : null;
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $missionId, $name, $name, $lifecycle, $mecm, $uuid);
        $stmt->execute();
        return (int) $this->db->insert_id;
    }

    /** @param list<int> $vmIds */
    private function job(int $missionId, string $mode, array $vmIds, string $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        $worker = self::WORKER;
        if ($status === VIRTUSPHERE_DEPLOY_STATUS_RUNNING) {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, lock_token, worker_epoch, heartbeat_at, attempts, execution_contract, execution_generation_id) '
                . 'SELECT ?, ?, ?, NOW(), ?, REPEAT(CHAR(99), 32), 1, NOW(), 1, ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1');
            $stmt->bind_param('issss', $missionId, $status, $payload, $worker, $contract);
        } else {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, heartbeat_at, execution_contract, execution_generation_id) '
                . 'SELECT ?, ?, ?, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1');
            $stmt->bind_param('isss', $missionId, $status, $payload, $contract);
        }
        $stmt->execute();
        return (int) $this->db->insert_id;
    }

    /** @return array<string,mixed> */
    private function jobRow(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        return $job;
    }

    /** @return array{0:string,1:string} */
    private function state(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }
}
