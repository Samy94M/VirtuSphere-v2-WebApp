<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
$vmJournalRepo = dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';
if (is_file($vmJournalRepo)) {
    require_once $vmJournalRepo;
}
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';

/** K15 behavioral matrix for lifecycle/MECM convergence after worker loss or error. */
final class K15DeployVmJournalBehaviorTest extends TestCase
{
    private const WORKER = 'phpunit:k15-journal';

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
        if (!function_exists('repo_deploy_job_vm_admit_touch')
            || !function_exists('deploy_worker_vm_create_launch_intent')) {
            self::markTestSkipped('K15 journal and touch APIs are not installed on this baseline.');
        }
        $this->prefix = 'phpunit_k15_' . bin2hex(random_bytes(4));
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

    public static function lifecycleModes(): array
    {
        return [
            'create' => ['create'],
            'full' => [VIRTUSPHERE_DEPLOY_MODE_FULL],
            'powercycle' => ['powercycle'],
            'export' => ['export'],
            'start' => ['start'],
            'autostart' => [VIRTUSPHERE_DEPLOY_MODE_AUTOSTART],
        ];
    }

    public static function lifecycleOwningModes(): array
    {
        return [
            'create' => ['create'],
            'full' => [VIRTUSPHERE_DEPLOY_MODE_FULL],
            'powercycle' => ['powercycle'],
            'export' => ['export'],
        ];
    }

    public static function statePreservingModes(): array
    {
        return [
            'start' => ['start'],
            'autostart' => [VIRTUSPHERE_DEPLOY_MODE_AUTOSTART],
        ];
    }

    #[DataProvider('lifecycleModes')]
    public function testFailureBeforeAnyMarkDoesNotChangeLifecycleOrMecm(string $mode): void
    {
        $mission = $this->mission('pre-mark-' . $mode);
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, in_array($mode, ['powercycle', 'export', 'start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART], true));
        $jobId = $this->job($mission, $mode, [$vm]);
        $claim = $this->jobRow($jobId);

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'pre-mark error', vmsMarked: false);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vm));
        self::assertSame([], $this->journal($jobId), 'preflight failure has no VM prestate journal');
    }

    #[DataProvider('statePreservingModes')]
    public function testStartAndAutostartNeverWriteVmStateEvenWhenClaimedAndFailed(string $mode): void
    {
        $mission = $this->mission('preserve-' . $mode);
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, true);
        $jobId = $this->job($mission, $mode, [$vm]);
        $claim = $this->jobRow($jobId);

        deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 claim mark', [$vm]);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->state($vm));
        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'worker failed after claim');

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->state($vm));
        $journal = $this->journal($jobId);
        self::assertSame(0, (int) $journal[$vm]['marked_deploying']);
        self::assertSame(0, (int) $journal[$vm]['esxi_touched']);
    }

    #[DataProvider('lifecycleOwningModes')]
    public function testMarkedButUntouchedVmRestoresBothCapturedStates(string $mode): void
    {
        $mission = $this->mission('restore-' . $mode);
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, in_array($mode, ['powercycle', 'export'], true));
        $jobId = $this->job($mission, $mode, [$vm]);
        $claim = $this->jobRow($jobId);

        deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 claim mark', [$vm]);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vm));
        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'failure before playbook');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($vm));
        self::assertSame(0, (int) $this->journal($jobId)[$vm]['esxi_touched']);
    }

    public function testFailureFailsOnlyTouchedVmRestoresNeighborAndKeepsImportedSuccess(): void
    {
        $mission = $this->mission('per-vm');
        $imported = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, true);
        $touched = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, true);
        $untouched = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, true);
        $jobId = $this->job($mission, 'export', [$imported, $touched, $untouched], $this->resultJson([$imported], [$touched]));
        $claim = $this->jobRow($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 per-VM mark', [$imported, $touched, $untouched]);
        self::assertTrue($this->touch($claim, $touched, 'export'));

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$imported, $touched, $untouched], 'post-callback failure');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING], $this->state($imported));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->state($touched));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY], $this->state($untouched));
        self::assertSame([$imported], json_decode((string) $this->jobRow($jobId)['result_json'], true)['successful_vm_ids']);
    }

    public function testRegisteredVmTouchedWithoutDeployingMarkStillFails(): void
    {
        $mission = $this->mission('registered-touch');
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered', true);
        repo_execute($this->db, "UPDATE deploy_vms SET mecm_id = '4812' WHERE id = ?", 'i', [$vm]);
        $jobId = $this->job($mission, 'export', [$vm]);
        $claim = $this->jobRow($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 registered capture', [$vm]);

        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'registered'], $this->state($vm));
        self::assertTrue($this->touch($claim, $vm, 'export'));
        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'export failed after access attempt');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->state($vm));
    }

    public function testCreatePreparedWithoutLaunchIntentRestoresButWithIntentRemainsUnknown(): void
    {
        foreach ([false, true] as $launchIntent) {
            $mission = $this->mission('create-intent-' . (int) $launchIntent);
            $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
            $jobId = $this->job($mission, 'create', [$vm]);
            $claim = $this->jobRow($jobId);
            deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 create mark', [$vm]);
            $this->materializeCreateUnit($jobId, $vm);
            self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $this->fence($claim)));
            if ($launchIntent) {
                self::assertSame(true, deploy_worker_vm_create_launch_intent($this->db, $claim, $vm, true));
            }

            deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'create worker lost after prepared');

            self::assertSame(
                $launchIntent
                    ? [VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_NOT_READY]
                    : [VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY],
                $this->state($vm),
                'prepared alone proves no launch; a durable intent leaves a crash gap unresolved'
            );
        }
    }

    public function testCreateLaunchIntentIsIdempotentAndAnOldClaimCannotClearIt(): void
    {
        $mission = $this->mission('create-intent-fence');
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->job($mission, 'create', [$vm]);
        $oldClaim = $this->jobRow($jobId);
        deploy_worker_mark_vms_deploying($this->db, $oldClaim, 'K15 intent fence', [$vm]);
        $this->materializeCreateUnit($jobId, $vm);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $this->fence($oldClaim)));

        self::assertSame(true, deploy_worker_vm_create_launch_intent($this->db, $oldClaim, $vm, true));
        self::assertSame(false, deploy_worker_vm_create_launch_intent($this->db, $oldClaim, $vm, true), 'a resumed owner discovers the existing intent instead of authorizing another launch');
        repo_execute($this->db, 'UPDATE deploy_jobs SET locked_by = ?, lock_token = ?, worker_epoch = worker_epoch + 1 WHERE id = ?',
            'ssi', ['phpunit:k15-successor', bin2hex(random_bytes(16)), $jobId]);

        self::assertNull(deploy_worker_vm_create_launch_intent($this->db, $oldClaim, $vm, false), 'an expired claim cannot clear launch evidence');
        $newClaim = $this->jobRow($jobId);
        self::assertSame(false, deploy_worker_vm_create_launch_intent($this->db, $newClaim, $vm, true));
        self::assertSame(1, (int) $this->journal($jobId)[$vm]['create_launch_intent']);
        self::assertSame(true, deploy_worker_vm_create_launch_intent($this->db, $newClaim, $vm, false), 'the current owner may clear intent only after a proven pre-launch refusal');
        self::assertSame(0, (int) $this->journal($jobId)[$vm]['create_launch_intent']);
    }

    public function testRunningCreateWithAsyncJobIdIsKnownTouchedAndFails(): void
    {
        $mission = $this->mission('create-running');
        $vm = $this->vm($mission, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $jobId = $this->job($mission, 'create', [$vm]);
        $claim = $this->jobRow($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'K15 create mark', [$vm]);
        $this->materializeCreateUnit($jobId, $vm);
        $deadline = gmdate('Y-m-d H:i:s', time() + 3600);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $this->fence($claim)));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', [
            'async_jid' => '1234567890.1',
            'async_deadline_at' => $deadline,
        ], $this->fence($claim)));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'running', 'failed', [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            'error_detail' => 'confirmed module failure',
        ], $this->fence($claim)));

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'create sequence failed');

        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED], $this->state($vm));
    }

    private function mission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [$name, 'active']);
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;
        return $id;
    }

    private function vm(int $missionId, string $lifecycle, string $mecm, bool $bound = false): int
    {
        $name = strtoupper($this->prefix . '_VM' . bin2hex(random_bytes(2)));
        $uuid = $bound ? 'uuid-' . bin2hex(random_bytes(8)) : null;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid) VALUES (?, ?, ?, ?, ?, ?)',
            'isssss', [$missionId, $name, $name, $lifecycle, $mecm, $uuid]);
        return (int) $this->db->insert_id;
    }

    /** @param list<int> $vmIds */
    private function job(int $missionId, string $mode, array $vmIds, ?string $resultJson = null): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        repo_execute($this->db, 'INSERT INTO deploy_jobs (mission_id, status, payload_json, result_json, locked_at, locked_by, lock_token, worker_epoch, attempts, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, ?, NOW(), ?, ?, 1, 1, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1',
            'issssss', [$missionId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $payload, $resultJson, self::WORKER, bin2hex(random_bytes(16)), $contract]);
        return (int) $this->db->insert_id;
    }

    private function resultJson(array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => $failed === [] ? 'success' : ($successful === [] ? 'failed' : 'partial'),
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'errors' => [],
            'counts' => ['expected_vms' => count($successful) + count($failed), 'successful_vms' => count($successful), 'failed_vms' => count($failed), 'updated_interfaces' => count($successful)],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function jobRow(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        return $job;
    }

    private function jobStatus(int $jobId): string
    {
        return (string) $this->jobRow($jobId)['status'];
    }

    /** @return array{0:string,1:string} */
    private function state(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }

    /** @return array<int,array<string,mixed>> */
    private function journal(int $jobId): array
    {
        return repo_deploy_job_vm_states($this->db, $jobId);
    }

    private function touch(array $job, int $vmId, string $phase): bool
    {
        return repo_deploy_job_vm_admit_touch($this->db, (int) $job['id'], $vmId,
            (int) $job['attempts'], (string) $job['execution_generation_id'], $phase);
    }

    /** @return array{worker_id:string,lock_token:string,worker_epoch:int} */
    private function fence(array $job): array
    {
        return ['worker_id' => (string) $job['locked_by'], 'lock_token' => (string) $job['lock_token'], 'worker_epoch' => (int) $job['worker_epoch']];
    }

    private function materializeCreateUnit(int $jobId, int $vmId): void
    {
        $vm = repo_fetch_one($this->db, 'SELECT id, vm_name FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        repo_deploy_create_materialize($this->db, $jobId, [['id' => $vmId, 'vm_name' => (string) $vm['vm_name']]]);
    }
}
