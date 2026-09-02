<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_identity.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/**
 * The deterministic fifteen-VM fixture of Etappe 14B, Teiletappe E, against a
 * real MySQL server.
 *
 * This is the incident, reproduced as data: fifteen units, an outcome per unit,
 * and the question "which fourteen worked" answered from rows. The failures sit
 * at positions 1, 8 and 15 on purpose - the first unit, one in the middle and
 * the last one are the three places where an off-by-one in the ordering or the
 * summary would hide.
 *
 * What this test does NOT touch is the SSH transport: it drives the same
 * repository writes the worker performs, with the same fence, in the same
 * order. The command building and the marker reading are proved without a
 * server in CreateFlowWorkerContractTest and AnsibleCreateProtocolTest, and the
 * behaviour of a real ESXi host is a site acceptance, not a test.
 */
final class DeployCreateWorkerFlowTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $userId;
    private int $esxiId;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_create14be_' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId, 'the integration fixture needs the seeded user');
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);

        $name = $this->prefix . '_mission';
        $active = 'active';
        $dc = 'DC1';
        $ds = 'DS1';
        $wds = 'WDS';
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssss', $name, $active, $dc, $ds, $wds);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();
        foreach ([$this->esxiId, $this->ansibleId] as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
    }

    public function testFourteenConfirmedResultsSurviveAFailureAtEveryEdgeOfTheSelection(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();

        // Position 1: refused before any mutation, which is the identity guard.
        $this->driveToFailure($jobId, 1, $fence, VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT);
        // Position 8: the module itself failed, after an async job existed.
        $this->driveToModuleFailure($jobId, 8, $fence);
        for ($position = 2; $position <= 15; $position++) {
            if ($position === 8 || $position === 15) {
                continue;
            }
            $this->driveToSuccess($jobId, $position, $fence);
        }
        // Position 15: nobody established what happened. It is the one the plan
        // is named after, and it must not read as a failure.
        $this->driveToUncertain($jobId, 15, $fence);

        $summary = repo_deploy_create_summary($this->db, $jobId);
        self::assertSame(15, $summary['total']);
        self::assertSame(12, $summary['succeeded']);
        self::assertSame(12, $summary['created']);
        self::assertSame(2, $summary['failed']);
        self::assertSame(1, $summary['uncertain']);
        self::assertSame(0, $summary['not_started']);
        self::assertSame(15, $summary['processed']);
        // The unresolved unit is what the operator has to decide about, so it
        // is the one the progress card points at.
        self::assertSame(15, $summary['current']['position']);

        // Twelve real VMs changed on the host: `failed` would send an operator
        // to clean up work that is fine.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, deploy_worker_create_job_status($summary));
        self::assertFalse(deploy_create_all_successful(repo_deploy_create_results($this->db, $jobId)));

        // Every confirmed result is still exactly what it was, including the
        // twelfth one written after the failure at position 8.
        foreach (repo_deploy_create_results($this->db, $jobId) as $row) {
            $position = (int) $row['position'];
            if (in_array($position, [1, 8, 15], true)) {
                continue;
            }
            self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, (string) $row['status'], 'position ' . $position);
            self::assertSame('vm-' . $position, (string) $row['vm_moid']);
            self::assertNotNull($row['finished_at']);
        }
    }

    public function testAnUnresolvedUnitStopsTheJobWhileAConfirmedFailureDoesNot(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();

        $this->driveToModuleFailure($jobId, 1, $fence);
        $rows = repo_deploy_create_results($this->db, $jobId);
        // A confirmed per-VM failure continues (decision F5): the next unit is
        // the next pending position.
        self::assertSame(2, (int) deploy_worker_create_next_unit($rows)['position']);

        $this->driveToUncertain($jobId, 2, $fence);
        $rows = repo_deploy_create_results($this->db, $jobId);
        $next = deploy_worker_create_next_unit($rows);
        // An unresolved unit is returned as the current one, never skipped over.
        self::assertSame(2, (int) $next['position']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, (string) $next['status']);
        self::assertTrue(deploy_create_has_inflight($rows));
        self::assertNull(deploy_create_next_position($rows), 'no pending unit may start while one is unresolved');
    }

    public function testTheIdentityCommitBindsRefreshesAndRefusesInThatOrder(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();
        $rows = repo_deploy_create_results($this->db, $jobId);
        $vmId = (int) $rows[0]['vm_id'];

        // 1. The portal has no stored UUID: the live one is bound.
        $this->driveToRunning($jobId, 1, $fence, false);
        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-901', '5001-abcd', $fence);
        self::assertTrue($commit['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_CREATED, $commit['outcome']);
        self::assertSame('5001-abcd', (string) repo_scalar($this->db, 'SELECT vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
        self::assertSame('vm-901', (string) repo_scalar($this->db, 'SELECT vm_moid FROM deploy_vms WHERE id = ?', 'i', [$vmId]));

        // 2. A repeated terminal answer with the same evidence is a no-op that
        // reports the same outcome, which is what lets a poll be retried after
        // a database outage without a second DONE line.
        $replay = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-901', '5001-abcd', $fence);
        self::assertFalse($replay['committed']);
        self::assertTrue($replay['replayed']);
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_CREATED, $replay['outcome']);

        // 3. The stored UUID matches: only the MOID is refreshed, because a
        // MOID moves and the UUID does not.
        $this->driveToRunning($jobId, 2, $fence, true);
        $secondVmId = (int) $rows[1]['vm_id'];
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_instance_uuid = ?, vm_moid = ? WHERE id = ?', 'ssi', ['5002-ABCD', 'vm-old', $secondVmId]);
        $updated = repo_deploy_create_commit_success($this->db, $jobId, 2, true, true, 'vm-902', '5002-abcd', $fence);
        self::assertTrue($updated['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_UPDATED, $updated['outcome']);
        self::assertSame('vm-902', (string) repo_scalar($this->db, 'SELECT vm_moid FROM deploy_vms WHERE id = ?', 'i', [$secondVmId]));
        self::assertSame('5002-ABCD', (string) repo_scalar($this->db, 'SELECT vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [$secondVmId]), 'the stored identity is not rewritten by a case difference');

        // 4. The stored UUID contradicts the live one: nothing is written at
        // all, neither the VM nor the success.
        $this->driveToRunning($jobId, 3, $fence, true);
        $thirdVmId = (int) $rows[2]['vm_id'];
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_instance_uuid = ?, vm_moid = ? WHERE id = ?', 'ssi', ['5003-somebody-else', 'vm-foreign', $thirdVmId]);
        $refused = repo_deploy_create_commit_success($this->db, $jobId, 3, true, true, 'vm-903', '5003-ours', $fence);
        self::assertFalse($refused['committed']);
        self::assertFalse($refused['replayed']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID, $refused['error_code']);
        self::assertSame('vm-foreign', (string) repo_scalar($this->db, 'SELECT vm_moid FROM deploy_vms WHERE id = ?', 'i', [$thirdVmId]));
        self::assertSame(
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            (string) repo_scalar($this->db, 'SELECT status FROM deploy_create_vm_results WHERE job_id = ? AND position = 3', 'i', [$jobId])
        );

        // 5. "Nothing was there and nothing changed" is not a quiet success.
        $this->driveToRunning($jobId, 4, $fence, false);
        $illegal = repo_deploy_create_commit_success($this->db, $jobId, 4, false, false, 'vm-904', '5004-abcd', $fence);
        self::assertFalse($illegal['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID, $illegal['error_code']);
    }

    public function testAForeignWorkerCannotCommitAnIdentity(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();
        $this->driveToRunning($jobId, 1, $fence, false);

        $foreign = ['worker_id' => $fence['worker_id'], 'lock_token' => str_repeat('f', 32), 'worker_epoch' => (int) $fence['worker_epoch']];
        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-901', '5001-abcd', $foreign);

        // Same worker id, different claim. That is exactly the returning old
        // worker the lock token exists for.
        self::assertFalse($commit['committed']);
        self::assertFalse($commit['replayed']);
        self::assertNull($commit['error_code']);
        self::assertSame(
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            (string) repo_scalar($this->db, 'SELECT status FROM deploy_create_vm_results WHERE job_id = ? AND position = 1', 'i', [$jobId])
        );
    }

    public function testTheReaperConvergesOnlyTheUnfinishedUnits(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();
        $this->driveToSuccess($jobId, 1, $fence);
        $this->driveToFailure($jobId, 2, $fence, VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT);
        $this->driveToRunning($jobId, 3, $fence, false);

        $converged = repo_deploy_create_converge_reaped($this->db, $jobId, 'Job ' . $jobId . ': last heartbeat 900 s ago, limit 600 s.');
        self::assertSame(1, $converged);

        $rows = repo_deploy_create_results($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, (string) $rows[0]['status'], 'a confirmed success survives the reaper');
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, (string) $rows[1]['status']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, (string) $rows[2]['status']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, (string) $rows[2]['error_code']);
        // The stored job id stays: it is the evidence a reconciliation needs,
        // and clearing it would be the blindness this stage removes.
        self::assertSame('123456789012.3', (string) $rows[2]['async_jid']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, (string) $rows[3]['status'], 'a unit that never started is not converged');

        // Idempotent: a second pass finds nothing in flight any more.
        self::assertSame(0, repo_deploy_create_converge_reaped($this->db, $jobId, 'second pass'));
    }

    public function testASkipResolvesToTheRealSuccessRatherThanToASkipChain(): void
    {
        [$jobId, $fence] = $this->fifteenVmJob();
        $this->driveToSuccess($jobId, 1, $fence);
        $source = repo_deploy_create_results($this->db, $jobId)[0];
        // The source job has to be over before a retry can exist: a mission
        // carries at most one active deploy job, which is the guard that stops
        // two jobs from creating the same VM twice.
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = NULL, lock_token = NULL, worker_epoch = NULL WHERE id = ?',
            'si',
            [VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $jobId]
        );

        [$retryJobId, $retryFence] = $this->fifteenVmJob();
        repo_execute(
            $this->db,
            'UPDATE deploy_create_vm_results SET action = ?, resumed_from_result_id = ? WHERE job_id = ? AND position = 1',
            'sii',
            [VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP, (int) $source['id'], $retryJobId]
        );
        repo_deploy_create_transition($this->db, $retryJobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED, [
            'outcome' => VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED,
            'changed' => 0,
            'existed_before' => 1,
            'vm_moid' => 'vm-1',
            'vm_instance_uuid' => '5001-1',
            'resumed_from_result_id' => (int) $source['id'],
        ], $retryFence);
        $skip = repo_deploy_create_results($this->db, $retryJobId)[0];

        // A skip that points at a skip is not evidence. The walk ends at the
        // row that actually proved the VM, however many retries ago that was.
        $resolved = repo_deploy_create_skip_source($this->db, (int) $skip['id']);
        self::assertNotNull($resolved);
        self::assertSame((int) $source['id'], (int) $resolved['id']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, (string) $resolved['status']);

        // A chain that never reaches a success answers null, which the worker
        // turns into a refusal rather than into a create.
        repo_execute($this->db, 'UPDATE deploy_create_vm_results SET resumed_from_result_id = NULL WHERE id = ?', 'i', [(int) $skip['id']]);
        self::assertNull(repo_deploy_create_skip_source($this->db, (int) $skip['id']));
    }

    /** @return array{0:int,1:array{worker_id:string,lock_token:string,worker_epoch:int}} */
    private function fifteenVmJob(): array
    {
        static $missionCounter = 0;
        $missionCounter++;
        for ($index = 1; $index <= 15; $index++) {
            $this->insertVm(sprintf('VM%03d_%d', $index, $missionCounter));
        }
        $vmIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            repo_fetch_all(
                (static function (mysqli $db, int $missionId, int $counter): mysqli_result {
                    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ? AND vm_name LIKE ? ORDER BY vm_name, id');
                    $like = '%\\_' . $counter;
                    $stmt->bind_param('is', $missionId, $like);
                    $stmt->execute();

                    return $stmt->get_result();
                })($this->db, $this->missionId, $missionCounter)
            )
        );
        self::assertCount(15, $vmIds);

        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, [
            'mode' => 'create',
            'vm_ids' => $vmIds,
        ]);
        self::assertCount(15, repo_deploy_create_results($this->db, $jobId));

        return [$jobId, $this->claimJob($jobId)];
    }

    /** @return array{worker_id:string,lock_token:string,worker_epoch:int} */
    private function claimJob(int $jobId): array
    {
        $fence = ['worker_id' => 'phpunit-worker', 'lock_token' => bin2hex(random_bytes(16)), 'worker_epoch' => 1];
        $stmt = $this->db->prepare(
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = ?, locked_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt->bind_param('sssii', $running, $fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId);
        $stmt->execute();
        repo_deploy_create_mark_started($this->db, $jobId);

        return $fence;
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToRunning(int $jobId, int $position, array $fence, bool $existedBefore): void
    {
        $prepared = ['existed_before' => $existedBefore ? 1 : 0];
        if ($existedBefore) {
            $prepared['precheck_moid'] = 'vm-pre-' . $position;
            $prepared['precheck_instance_uuid'] = '500' . $position . '-pre';
        }
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, $prepared, $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            // No bound remote handle: the generic handle belongs to the remote
            // execution contract, which stays locked until its site acceptance.
            'async_jid' => '123456789012.' . $position,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence));
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToSuccess(int $jobId, int $position, array $fence): void
    {
        $this->driveToRunning($jobId, $position, $fence, false);
        $commit = repo_deploy_create_commit_success($this->db, $jobId, $position, false, true, 'vm-' . $position, '5001-' . $position, $fence);
        self::assertTrue($commit['committed'], 'position ' . $position . ' could not be committed');
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToFailure(int $jobId, int $position, array $fence, string $errorCode): void
    {
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, [
            'error_code' => $errorCode,
            'error_detail' => 'fixture: refused before any mutation',
        ], $fence));
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToModuleFailure(int $jobId, int $position, array $fence): void
    {
        $this->driveToRunning($jobId, $position, $fence, false);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            'error_detail' => 'fixture: the create module reported a failure',
        ], $fence));
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToUncertain(int $jobId, int $position, array $fence): void
    {
        $this->driveToRunning($jobId, $position, $fence, false);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
            'error_detail' => 'fixture: the budget was reached while this VM was still being created',
        ], $fence));
    }

    private function insertVm(string $suffix): int
    {
        $name = strtoupper($this->prefix . '_' . $suffix);
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $name, $name);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertCredential(string $type, int $port): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.example.invalid';
        $user = 'svc';
        $secret = 'ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }
}
