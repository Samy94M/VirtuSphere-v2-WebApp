<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

/**
 * Drives the worker's job-outcome state machine (lib/deploy_worker_outcome.php)
 * against a real database: what deploy_worker.php runs after a playbook
 * sequence ends, minus the SSH transport. Together with MacImportCallbackTest
 * (the HTTP half that writes result_json) this proves the deploy matrix
 * scenarios 1, 2, 3, 11, 14 and 17 end to end.
 */
final class DeployWorkerOutcomeTest extends TestCase
{
    private const WORKER = 'phpunit:outcome-worker';

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

        $this->prefix = 'phpunit_outcome_' . bin2hex(random_bytes(4));
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

    /** Matrix 1: every VM imported, job finishes `succeeded`, states stay deployed/pending. */
    public function testSuccessfulExportSequenceFinishesSucceededAndTouchesNoVm(): void
    {
        $missionId = $this->insertMission('m1');
        $vmA = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $vmB = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $jobId = $this->insertJob($missionId, 'export', [$vmA, $vmB], $this->resultJson('success', [$vmA, $vmB], []));

        deploy_worker_conclude_sequence($this->db, $this->job($jobId), self::WORKER, [$vmA, $vmB]);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($vmA));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($vmB));
    }

    /** Matrix 2/2b: a partial import finishes the job `partial` and converges only the failed VMs. */
    public function testPartialImportFinishesPartialAndConvergesOnlyFailedVms(): void
    {
        $missionId = $this->insertMission('m2');
        $ok = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $bad = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, 'export', [$ok, $bad], $this->resultJson('partial', [$ok], [$bad]));

        deploy_worker_conclude_sequence($this->db, $this->job($jobId), self::WORKER, [$ok, $bad]);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $this->jobStatus($jobId));
        self::assertStringContainsString('MAC import partial: 1 of 2', (string) $this->job($jobId)['last_error']);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($ok));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($bad));
    }

    /** Matrix 3: a wholly failed import throws, and the failure path converges job and VMs. */
    public function testWhollyFailedImportFailsJobAndAllVms(): void
    {
        $missionId = $this->insertMission('m3');
        $vmA = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $vmB = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, 'export', [$vmA, $vmB], $this->resultJson('failed', [], [$vmA, $vmB]));
        $job = $this->job($jobId);

        $message = '';
        try {
            deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$vmA, $vmB]);
            self::fail('a wholly failed import must throw');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        self::assertStringContainsString('MAC import failed for every VM', $message);

        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$vmA, $vmB], $message);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($vmA));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($vmB));
    }

    /** L3: an export sequence without any recorded result fails job and VMs, never leaves `deploying`. */
    public function testMissingResultForExportSequenceFailsJobAndVms(): void
    {
        $missionId = $this->insertMission('m17a');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, 'export', [$vmId], null);
        $job = $this->job($jobId);

        $message = '';
        try {
            deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$vmId]);
            self::fail('a missing result must throw for an export sequence');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        self::assertStringContainsString('no usable MAC import result', $message);

        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$vmId], $message);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($vmId));
    }

    /**
     * Matrix 17: modes without an export step demand no result and succeed.
     * Their claim-time `deploying` is restored to the prior lifecycle, so the
     * convergence sweep never "converges" the VMs of a green job.
     */
    public function testCreateSequenceSucceedsWithoutResultAndRestoresPriorLifecycles(): void
    {
        $missionId = $this->insertMission('m17b');
        $fresh = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_NOT_READY);
        $deployed = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $jobId = $this->insertJob($missionId, 'create', [$fresh, $deployed], null);

        $prior = deploy_worker_mark_vms_deploying($this->db, $missionId, 'deploy job ' . $jobId . ' started', [$fresh, $deployed]);
        self::assertSame([$fresh => VIRTUSPHERE_LIFECYCLE_READY, $deployed => VIRTUSPHERE_LIFECYCLE_DEPLOYED], $prior);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY], $this->vmState($fresh));

        deploy_worker_conclude_sequence($this->db, $this->job($jobId), self::WORKER, [$fresh, $deployed], $prior);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_NOT_READY], $this->vmState($fresh));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($deployed));

        // The sweep must find nothing left to converge for this mission.
        $swept = repo_sweep_orphaned_deploying_vms($this->db);
        self::assertNotContains($fresh, array_column($swept, 'vm_id'));
        self::assertNotContains($deployed, array_column($swept, 'vm_id'));
    }

    /** A VM without a known prior state is never guessed at; the sweep stays the authority. */
    public function testRestoreNeverGuessesAnUnknownPriorState(): void
    {
        $missionId = $this->insertMission('m17c');
        $vmId = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);

        $restored = deploy_worker_restore_deploying_vms($this->db, $missionId, 'restore probe', [$vmId], []);

        self::assertSame(0, $restored);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY], $this->vmState($vmId));
    }

    /**
     * Matrix 14 (E1): a follow-up failure after a committed import fails the
     * JOB, but VMs from successful_vm_ids keep deployed/pending; only the
     * rest converges to failed/failed.
     */
    public function testLateFollowUpFailureKeepsSuccessfullyImportedVms(): void
    {
        $missionId = $this->insertMission('m14');
        $imported = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $unfinished = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, VIRTUSPHERE_DEPLOY_MODE_FULL, [$imported, $unfinished], $this->resultJson('partial', [$imported], [$unfinished]));

        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$imported, $unfinished], 'Ansible command failed with exit code 2.');

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame('Ansible command failed with exit code 2.', (string) $this->job($jobId)['last_error']);
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($imported), 'a committed import outlives the failing job (E1)');
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($unfinished));
    }

    /**
     * Matrix 11 (worker half): the cancel handler converges only VMs still in
     * `deploying`; imported VMs and their stored MACs survive, the job status
     * stays `cancelled`.
     */
    public function testCancelHandlerConvergesOnlyStillDeployingVmsAndKeepsMacs(): void
    {
        $missionId = $this->insertMission('m11');
        $imported = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $mac = '02:AB:CD:EF:00:11';
        $this->insertInterface($imported, 'WDS', $mac);
        $stuck = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, 'export', [$imported, $stuck], null, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED);

        deploy_worker_handle_cancelled($this->db, $this->job($jobId), [$imported, $stuck]);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $this->jobStatus($jobId), 'a cancel must never be repainted');
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($imported));
        self::assertSame($mac, $this->interfaceMac($imported), 'stored MACs survive the cancel convergence');
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($stuck));
    }

    /**
     * L4 companion: reaping a crashed job keeps the VMs whose import already
     * committed and converges only the rest.
     */
    public function testReapingAStaleJobKeepsCommittedImportsAndConvergesTheRest(): void
    {
        $missionId = $this->insertMission('reap');
        $imported = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING);
        $stuck = $this->insertVm($missionId, VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_NOT_READY);
        $jobId = $this->insertJob($missionId, 'export', [$imported, $stuck], $this->resultJson('partial', [$imported], [$stuck]), VIRTUSPHERE_DEPLOY_STATUS_RUNNING, true);

        deploy_worker_reap_stale_jobs($this->db);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus($jobId));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_PENDING], $this->vmState($imported));
        self::assertSame([VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_FAILED], $this->vmState($stuck));
    }

    private function insertMission(string $suffix): int
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

    private function insertVm(int $missionId, string $lifecycleState, string $mecmSyncState): int
    {
        $name = strtoupper($this->prefix . '_VM' . bin2hex(random_bytes(2)));
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $missionId, $name, $name, $lifecycleState, $mecmSyncState);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertInterface(int $vmId, string $vlan, string $mac): void
    {
        $empty = '';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $mac);
        $stmt->execute();
    }

    /** @param list<int> $vmIds */
    private function insertJob(int $missionId, string $mode, array $vmIds, ?string $resultJson, string $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING, bool $staleHeartbeat = false): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $worker = self::WORKER;
        $heartbeat = $staleHeartbeat ? 'DATE_SUB(NOW(), INTERVAL 1 DAY)' : 'NOW()';
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, result_json, locked_at, locked_by, heartbeat_at) VALUES (?, ?, ?, ?, NOW(), ?, ' . $heartbeat . ')');
        $stmt->bind_param('issss', $missionId, $status, $payload, $resultJson, $worker);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param list<int> $successful @param list<int> $failed */
    private function resultJson(string $outcome, array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => $outcome,
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

    private function jobStatus(int $jobId): string
    {
        return (string) $this->job($jobId)['status'];
    }

    /** @return array{0:string,1:string} */
    private function vmState(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state']];
    }

    private function interfaceMac(int $vmId): string
    {
        $stmt = $this->db->prepare('SELECT mac FROM deploy_interfaces WHERE vm_id = ? LIMIT 1');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();

        return (string) ($stmt->get_result()->fetch_assoc()['mac'] ?? '');
    }
}
