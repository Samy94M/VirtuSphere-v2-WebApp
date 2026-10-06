<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_identity.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_db_recovery.php';

/**
 * K11 (DF-E1, FC2-02). A new create or full job that meets an existing own VM
 * which is not powered off only verifies it: the unit ends `succeeded` with
 * outcome `unchanged`, nothing is launched, the binding is kept and the job
 * log names the VM whose hardware was not aligned. And a create success whose
 * identity commit is refused keeps the live MOID and instance UUID, in the
 * stored detail and in its own log line, so the VM on the host stays findable.
 */
final class DeployCreateRunningVmTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $vmId;
    private int $esxiId;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_k11_' . bin2hex(random_bytes(4));
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
        $name = $this->prefix . '_mission';
        $stmt = $this->db->prepare(
            "INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) VALUES (?, 'active', 'DC1', 'DS1', 'WDS')"
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper($this->prefix . '_VM');
        $stmt = $this->db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_moid, vm_instance_uuid) VALUES (?, ?, ?, 'vm-own', '5001-own')");
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ([$this->esxiId, $this->ansibleId] as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    /** DF-E1: only a VM that is provably off is launched; a new VM always is. */
    public function testOnlyAProvablyPoweredOffOwnVmIsLaunched(): void
    {
        $existing = ['event' => VIRTUSPHERE_CREATE_EVENT_PREPARED, 'existed_before' => true];
        self::assertTrue(deploy_worker_create_prepared_needs_launch(['existed_before' => false, 'precheck_power_state' => null] + $existing));
        self::assertTrue(deploy_worker_create_prepared_needs_launch(['precheck_power_state' => 'poweredOff'] + $existing));
        foreach (['poweredOn', 'suspended', null, 'somethingElse'] as $state) {
            self::assertFalse(
                deploy_worker_create_prepared_needs_launch(['precheck_power_state' => $state] + $existing),
                'an own VM in state ' . var_export($state, true) . ' must only be verified'
            );
        }
    }

    public function testARunningOwnVmIsVerifiedUnchangedWithoutLaunchAndKeepsItsBinding(): void
    {
        [$jobId, $fence] = $this->createJob();
        $unit = $this->prepare($jobId, $fence, 'vm-own', '5001-own');

        $verdict = deploy_worker_create_conclude_unchanged($this->channel($jobId, $fence), $fence, $unit, 'poweredOn');

        self::assertFalse($verdict['stop']);
        $row = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, (string) $row['status']);
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED, (string) $row['outcome']);
        self::assertSame(0, (int) $row['changed']);
        self::assertSame(1, (int) $row['existed_before']);
        self::assertSame('vm-own', (string) $row['vm_moid']);
        self::assertSame('5001-own', (string) $row['vm_instance_uuid']);
        self::assertNull($row['async_jid'], 'nothing was launched');
        self::assertSame('5001-own', (string) repo_scalar($this->db, 'SELECT vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]));
        self::assertSame(1, $this->logCount($jobId, '%not aligned%poweredOn%'));
    }

    /** The launch-less edge carries exactly one answer: an existing VM, unchanged. */
    public function testASuccessWithoutLaunchCanOnlyBeUnchanged(): void
    {
        [$jobId, $fence] = $this->createJob();
        $this->prepare($jobId, $fence, 'vm-own', '5001-own');

        foreach ([[false, true], [true, true]] as [$existedBefore, $changed]) {
            try {
                repo_deploy_create_commit_success($this->db, $jobId, 1, $existedBefore, $changed, 'vm-own', '5001-own', $fence, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED);
                self::fail('a created or updated outcome without a launch must be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('unchanged', $exception->getMessage());
            }
        }
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, (string) repo_deploy_create_results($this->db, $jobId)[0]['status']);
    }

    /** FC2-02: a refused success commit keeps the live identity findable. */
    public function testARefusedSuccessCommitKeepsTheLiveIdentity(): void
    {
        [$jobId, $fence] = $this->createJob();
        $unit = $this->prepare($jobId, $fence, 'vm-own', '5001-own');
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '123456789012.1',
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence));
        $unit['status'] = VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING;

        $verdict = deploy_worker_create_record_refused_success(
            $this->channel($jobId, $fence),
            $fence,
            $unit,
            ['moid' => 'vm-99', 'instance_uuid' => '5099-foreign'],
            VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID
        );

        self::assertFalse($verdict['stop'], 'a refused binding is a per-VM failure, not a global stop');
        $row = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, (string) $row['status']);
        self::assertStringContainsString('vm-99', (string) $row['error_detail']);
        self::assertStringContainsString('5099-foreign', (string) $row['error_detail']);
        self::assertSame(1, $this->logCount($jobId, '%vm-99%5099-foreign%'));
    }

    /** @return array{0:int,1:array{worker_id:string,lock_token:string,worker_epoch:int}} */
    private function createJob(): array
    {
        $userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $userId, $this->esxiId, $this->ansibleId, [
            'mode' => 'create',
            'vm_ids' => [$this->vmId],
        ]);
        $fence = ['worker_id' => 'phpunit-k11', 'lock_token' => bin2hex(random_bytes(16)), 'worker_epoch' => 1];
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = ?, locked_at = UTC_TIMESTAMP() WHERE id = ?',
            'sssii',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId]
        );
        repo_deploy_create_mark_started($this->db, $jobId);

        return [$jobId, $fence];
    }

    /**
     * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
     * @return array<string,mixed>
     */
    private function prepare(int $jobId, array $fence, string $moid, string $uuid): array
    {
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, [
            'existed_before' => 1,
            'precheck_moid' => $moid,
            'precheck_instance_uuid' => $uuid,
        ], $fence));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, (string) $unit['status']);

        return $unit;
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function channel(int $jobId, array $fence): DeployWorkerDbChannel
    {
        return new DeployWorkerDbChannel($this->db, fn (): mysqli => $this->db, $jobId, $fence['worker_id']);
    }

    private function logCount(int $jobId, string $pattern): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line LIKE ?', 'is', [$jobId, $pattern]);
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
