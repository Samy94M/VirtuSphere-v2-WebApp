<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/**
 * IDR-P02: Create on a VM whose bound instance was deleted on ESXi creates a
 * new VM in its place, and the success commit adopts it. The replacement is
 * allowed for exactly the stored UUID the preparation proved absent; anything
 * written to the binding since then keeps the refusal. Create deletes nothing.
 */
final class DeployCreateReplacementTest extends TestCase
{
    private const OLD_UUID = '52677625-4986-ed5b-89b5-ee9597f0f4cb';
    private const NEW_UUID = '52b3bff3-ed69-665f-faa3-fa8ea7a2b4bb';
    private const OLD_MAC = '00:50:56:aa:bb:cc';

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
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'create-replace-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        $this->esxiId = $this->credential('esxi');
        $this->ansibleId = $this->credential('ansible');
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$this->prefix]);
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->missionId)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ([$this->esxiId, $this->ansibleId] as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
    }

    public function testAProvenAbsentBindingIsReplacedByTheNewInstance(): void
    {
        $vmId = $this->boundVm();
        $before = $this->vmRow($vmId);
        [$jobId, $fence] = $this->runningUnit($vmId, self::OLD_UUID);

        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-2', self::NEW_UUID, $fence);

        self::assertTrue($commit['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_CREATED, $commit['outcome']);
        self::assertSame(self::OLD_UUID, $commit['replaced_instance_uuid'] ?? null);
        $after = $this->vmRow($vmId);
        self::assertSame(self::NEW_UUID, $after['vm_instance_uuid']);
        self::assertSame('vm-2', $after['vm_moid']);
        self::assertNotSame($before['generation'], $after['generation'], 'the report generation rotates with the instance');
        self::assertGreaterThan((int) $before['edit_version'], (int) $after['edit_version'], 'an open editor must not save the old MAC back');
        self::assertSame('', $this->mac($vmId), 'the MAC of the deleted VM is forgotten');
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, $unit['status']);
        self::assertSame(self::OLD_UUID, $unit['replaced_instance_uuid']);
        self::assertSame(self::NEW_UUID, $unit['vm_instance_uuid']);
    }

    public function testAVerifySkipOfADeletedSuccessCanCreateAndAdoptItsReplacement(): void
    {
        $vmId = $this->boundVm();
        [$jobId, $fence, $sourceId] = $this->verifySkipUnit($vmId);
        $before = $this->vmRow($vmId);

        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', [
            'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
            'existed_before' => 0,
            'replaced_instance_uuid' => self::OLD_UUID,
        ], $fence));
        $prepared = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_ACTION_CREATE, $prepared['action']);
        self::assertSame($sourceId, (int) $prepared['resumed_from_result_id']);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', [
            'async_jid' => '123456789.2',
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence));

        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-2', self::NEW_UUID, $fence);

        self::assertTrue($commit['committed']);
        self::assertSame(self::OLD_UUID, $commit['replaced_instance_uuid']);
        self::assertSame(self::NEW_UUID, $this->vmRow($vmId)['vm_instance_uuid']);
        self::assertNotSame($before['generation'], $this->vmRow($vmId)['generation']);
        self::assertSame('', $this->mac($vmId));
    }

    public function testAVerifySkipCannotCreateWhenTheBindingChangedAfterItsSourceSuccess(): void
    {
        $vmId = $this->boundVm();
        [$jobId, $fence] = $this->verifySkipUnit($vmId);
        repo_execute($this->db, "UPDATE deploy_vms SET vm_instance_uuid = 'adopted-uuid' WHERE id = ?", 'i', [$vmId]);

        self::assertFalse(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', [
            'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
            'existed_before' => 0,
            'replaced_instance_uuid' => self::OLD_UUID,
        ], $fence));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP, $unit['action']);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, $unit['status']);
        self::assertSame(self::OLD_MAC, $this->mac($vmId));
    }

    public function testABindingChangedSinceThePreparationIsNotReplaced(): void
    {
        $vmId = $this->boundVm();
        [$jobId, $fence] = $this->runningUnit($vmId, self::OLD_UUID);
        // An adoption between preparation and commit moved the binding.
        repo_execute($this->db, "UPDATE deploy_vms SET vm_instance_uuid = 'adopted-uuid' WHERE id = ?", 'i', [$vmId]);

        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-2', self::NEW_UUID, $fence);

        self::assertFalse($commit['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID, $commit['error_code']);
        self::assertSame('adopted-uuid', $this->vmRow($vmId)['vm_instance_uuid']);
        self::assertSame(self::OLD_MAC, $this->mac($vmId));
    }

    public function testWithoutProofADifferentUuidIsStillRefused(): void
    {
        $vmId = $this->boundVm();
        [$jobId, $fence] = $this->runningUnit($vmId, null);

        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-2', self::NEW_UUID, $fence);

        self::assertFalse($commit['committed']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID, $commit['error_code']);
        self::assertSame(self::OLD_UUID, $this->vmRow($vmId)['vm_instance_uuid']);
        self::assertSame(self::OLD_MAC, $this->mac($vmId));
    }

    public function testTheSchemaRefusesAReplacementBesideAnExistingVm(): void
    {
        $vmId = $this->boundVm();
        [$jobId] = $this->runningUnit($vmId, self::OLD_UUID);

        $this->expectException(mysqli_sql_exception::class);
        repo_execute($this->db, 'UPDATE deploy_create_vm_results SET existed_before = 1 WHERE job_id = ?', 'i', [$jobId]);
    }

    /** @return array{0:int,1:array{worker_id:string,lock_token:string,worker_epoch:int}} */
    private function runningUnit(int $vmId, ?string $provenAbsent): array
    {
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create', 'vm_ids' => [$vmId], 'verbose' => false, 'powercycle_wait' => 5, 'start_wait' => 30]);
        $worker = $this->prefix . '-worker';
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($jobId, (int) $job['id']);
        $fence = deploy_worker_job_fence($this->db, $jobId, $worker, $job);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0, 'replaced_instance_uuid' => $provenAbsent], $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', ['async_jid' => '123456789.1', 'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $fence));

        return [$jobId, $fence];
    }

    /** @return array{0:int,1:array{worker_id:string,lock_token:string,worker_epoch:int},2:int} */
    private function verifySkipUnit(int $vmId): array
    {
        [$sourceJobId, $sourceFence] = $this->runningUnit($vmId, null);
        $commit = repo_deploy_create_commit_success($this->db, $sourceJobId, 1, true, false, 'vm-1', self::OLD_UUID, $sourceFence);
        self::assertTrue($commit['committed']);
        $sourceId = (int) repo_deploy_create_results($this->db, $sourceJobId)[0]['id'];
        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ?, locked_by = NULL, lock_token = NULL, worker_epoch = NULL WHERE id = ?',
            'si', [VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $sourceJobId]);

        $retryJobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId,
            ['mode' => 'create', 'vm_ids' => [$vmId], 'verbose' => false, 'powercycle_wait' => 5, 'start_wait' => 30]);
        $worker = $this->prefix . '-retry-worker';
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($retryJobId, (int) $job['id']);
        $fence = deploy_worker_job_fence($this->db, $retryJobId, $worker, $job);
        repo_execute($this->db, 'UPDATE deploy_create_vm_results SET action = ?, resumed_from_result_id = ? WHERE job_id = ? AND position = 1',
            'sii', [VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP, $sourceId, $retryJobId]);

        return [$retryJobId, $fence, $sourceId];
    }

    private function boundVm(): int
    {
        $name = $this->prefix . '_vm';
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname,vm_moid,vm_instance_uuid) VALUES (?,?,?,?,?)', 'issss', [$this->missionId, $name, $name, 'vm-1', self::OLD_UUID]);
        $id = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_interfaces (vm_id,ip,subnet,gateway,vlan,mac) VALUES (?,'','','','WDS',?)", 'is', [$id, self::OLD_MAC]);

        return $id;
    }

    /** @return array{vm_instance_uuid:?string,vm_moid:?string,generation:string,edit_version:int|string} */
    private function vmRow(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT vm_instance_uuid, vm_moid, HEX(package_report_generation) AS generation, edit_version FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertNotNull($row);

        return $row;
    }

    private function mac(int $vmId): string
    {
        return (string) repo_scalar($this->db, 'SELECT mac FROM deploy_interfaces WHERE vm_id = ? LIMIT 1', 'i', [$vmId]);
    }

    private function credential(string $type): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $this->prefix . '_' . $type, $this->prefix . '.' . $type . '.invalid', 'fixture', 'unused-ciphertext']);

        return (int) $this->db->insert_id;
    }
}
