<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';

/**
 * The per-VM create rows against a real MySQL server (Etappe 14B, Teiletappe C).
 *
 * What only a server can answer: that job and units commit together, that the
 * CHECK constraints refuse an impossible row even if a future writer forgets
 * the PHP guard, and that two workers racing for the same unit produce exactly
 * one winner. The state machine itself is proved without a database in
 * DeployCreateResultStateTest.
 */
final class DeployCreateResultsIntegrationTest extends TestCase
{
    private mysqli $db;
    private ?mysqli $second = null;
    private string $prefix;
    private int $missionId;
    private int $userId;
    private int $esxiId;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->second = new mysqli(
                envboot_required('DB_HOST'),
                envboot_required('DB_USER'),
                envboot_required('DB_PASS'),
                envboot_required('DB_NAME'),
                (int) envboot_optional('DB_PORT', '3306')
            );
            $this->second->set_charset('utf8mb4');
            $this->second->query("SET time_zone = '+00:00'");
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_create14b_' . bin2hex(random_bytes(4));
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
        if ($this->second !== null) {
            $this->second->close();
            $this->second = null;
        }
    }

    public function testQueueingACreateJobMaterializesOneOrderedUnitPerVm(): void
    {
        // Deliberately inserted out of order: position must follow vm_name, not
        // insertion order, or position 7 would mean a different VM on a retry.
        $this->insertVm('CHARLIE');
        $alpha = $this->insertVm('ALPHA');
        $this->insertVm('BRAVO');

        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $rows = repo_deploy_create_results($this->db, $jobId);

        self::assertCount(3, $rows);
        self::assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['position'], $rows));
        self::assertSame(
            [strtoupper($this->prefix) . '_ALPHA', strtoupper($this->prefix) . '_BRAVO', strtoupper($this->prefix) . '_CHARLIE'],
            array_map(static fn (array $r): string => (string) $r['vm_name'], $rows)
        );
        self::assertSame([3, 3, 3], array_map(static fn (array $r): int => (int) $r['total'], $rows));
        self::assertSame($alpha, (int) $rows[0]['vm_id']);
        foreach ($rows as $row) {
            self::assertSame(VIRTUSPHERE_CREATE_ACTION_CREATE, (string) $row['action']);
            self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, (string) $row['status']);
            self::assertNull($row['finished_at']);
        }

        // The empty selection was resolved at queue time, so a VM added now
        // cannot join a job nobody chose it for.
        $payload = json_decode((string) repo_scalar($this->db, 'SELECT payload_json FROM deploy_jobs WHERE id = ?', 'i', [$jobId]), true);
        self::assertSame(
            array_map(static fn (array $r): int => (int) $r['vm_id'], $rows),
            $payload['vm_ids'],
            'the payload carries the materialized selection, not "everything, decided later"'
        );
    }

    public function testAStaggeredCreateGroupMaterializesEverySlot(): void
    {
        // The staggered path inserts its job rows itself, so it is the one place
        // where a create job could end up without its unit. One slot is one VM.
        // `create` itself cannot be staggered (there is nothing to spread out),
        // but `full` can, and its sequence starts with the create playbook.
        self::assertContains(VIRTUSPHERE_DEPLOY_MODE_FULL, VIRTUSPHERE_DEPLOY_STAGGER_MODES);
        self::assertTrue(ansible_mode_creates_vms(VIRTUSPHERE_DEPLOY_MODE_FULL));

        $this->insertVm('ALPHA');
        $this->insertVm('BRAVO');
        $base = gmdate('Y-m-d H:i:s', time() + 3600);
        $group = repo_enqueue_deploy_group($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL], $base, 10);

        self::assertSame(2, $group['count']);
        $jobIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            repo_fetch_all($this->db->query('SELECT id FROM deploy_jobs WHERE mission_id = ' . $this->missionId . ' ORDER BY id')) // csp-allow: interpolated-sql
        );
        self::assertCount(2, $jobIds);
        foreach ($jobIds as $jobId) {
            $rows = repo_deploy_create_results($this->db, $jobId);
            self::assertCount(1, $rows, 'every staggered slot carries its unit');
            self::assertSame(1, (int) $rows[0]['position']);
            self::assertSame(1, (int) $rows[0]['total']);
            self::assertSame(VIRTUSPHERE_CREATE_ACTION_CREATE, (string) $rows[0]['action']);
        }
    }

    public function testAModeWithoutACreateStepMaterializesNothing(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'export']);

        self::assertSame([], repo_deploy_create_results($this->db, $jobId));
        // A job without rows is legacy-readable, not an empty create job.
        self::assertFalse(repo_deploy_job_is_create_tracked($this->db, $jobId));
    }

    public function testTheJobAndItsUnitsCommitTogether(): void
    {
        $this->insertVm('ALPHA');
        // A second active job for the same mission is refused after the job row
        // was already inserted in the transaction, so this exercises the
        // rollback of a half-initialized create job.
        $first = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        self::assertCount(1, repo_deploy_create_results($this->db, $first));

        try {
            repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
            self::fail('the one-active-job guard must refuse the second job');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('active deploy job', $exception->getMessage());
        }

        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]));
        self::assertSame(
            0,
            (int) repo_scalar(
                $this->db,
                'SELECT COUNT(*) FROM deploy_create_vm_results WHERE job_id <> ? AND vm_name LIKE ?',
                'is',
                [$first, strtoupper($this->prefix) . '%']
            ),
            'no orphan unit survived the rolled back job'
        );
    }

    public function testTheSchemaRefusesAnImpossibleRowEvenWithoutThePhpGuard(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $resultId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_create_vm_results WHERE job_id = ?', 'i', [$jobId]);

        // Success without the verified identity: the exact shape that would let
        // a name-based claim pass as a created VM.
        $this->assertWriteRefused(
            'UPDATE deploy_create_vm_results SET status = ?, outcome = ?, changed = 1, existed_before = 0, finished_at = UTC_TIMESTAMP() WHERE id = ?',
            'ssi',
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_OUTCOME_CREATED, $resultId]
        );

        // The fourth evidence combination: nothing existed and nothing changed.
        $this->assertWriteRefused(
            'UPDATE deploy_create_vm_results SET status = ?, outcome = ?, changed = 0, existed_before = 0, vm_moid = ?, vm_instance_uuid = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?',
            'ssssi',
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_OUTCOME_CREATED, 'vm-1', '5001-a', $resultId]
        );

        // A failure that names no cause.
        $this->assertWriteRefused(
            'UPDATE deploy_create_vm_results SET status = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?',
            'si',
            [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, $resultId]
        );

        // A running unit without the async job id nobody could find again.
        $this->assertWriteRefused(
            'UPDATE deploy_create_vm_results SET status = ? WHERE id = ?',
            'si',
            [VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, $resultId]
        );

        // A skip on a unit that was materialized as a real create.
        $this->assertWriteRefused(
            'UPDATE deploy_create_vm_results SET status = ?, outcome = ?, changed = 0, existed_before = 1, vm_moid = ?, vm_instance_uuid = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?',
            'ssssi',
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED, VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED, 'vm-1', '5001-a', $resultId]
        );

        // A duplicate position for the same job.
        $this->assertWriteRefused(
            'INSERT INTO deploy_create_vm_results (job_id, vm_name, position, total, action, status) VALUES (?, ?, 1, 1, ?, ?)',
            'isss',
            [$jobId, 'DUPLICATE', VIRTUSPHERE_CREATE_ACTION_CREATE, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING]
        );
    }

    public function testOnlyOneOfTwoRacingWorkersPerformsTheTransition(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $fence = $this->claimJob($jobId);

        $fields = ['existed_before' => 0];
        $first = repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, $fields, $fence);
        $second = repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, $fields, $fence);

        self::assertTrue($first);
        // The replayed transition is not an error and not a second write: a
        // duplicated answer arriving late must simply lose.
        self::assertFalse($second);

        $rows = repo_deploy_create_results($this->db, $jobId);
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, (string) $rows[0]['status']);
        self::assertNotNull($rows[0]['started_at'], 'leaving pending stamps the start time');
    }

    public function testAForeignWorkerCannotWriteTheUnit(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $fence = $this->claimJob($jobId);
        $foreign = ['worker_id' => 'someone-else', 'lock_token' => str_repeat('b', 32), 'worker_epoch' => (int) $fence['worker_epoch']];

        self::assertFalse(
            repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, ['existed_before' => 0], $foreign),
            'a worker that no longer owns the job writes nothing'
        );
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, (string) repo_deploy_create_results($this->db, $jobId)[0]['status']);
    }

    public function testAResumedUncertainUnitLosesItsFinishMarksInTheSameStatement(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $fence = $this->claimJob($jobId);
        $handleId = $this->insertRemoteHandle($jobId, 1);

        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, ['existed_before' => 0], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '747689456876.6394',
            'remote_execution_id' => $handleId,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST,
            'error_detail' => 'poll transport lost',
        ], $fence);

        $row = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, (string) $row['status']);
        self::assertNotNull($row['finished_at']);
        self::assertSame('747689456876.6394', (string) $row['async_jid'], 'the job id stays: it is how the unit is resumed');

        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '747689456876.6394',
            'remote_execution_id' => $handleId,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence));

        $resumed = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, (string) $resumed['status']);
        self::assertNull($resumed['finished_at']);
        self::assertNull($resumed['error_code'], 'the row is open again; the uncertainty stays in the log');
    }

    public function testARetryTakesOverProvenUnitsAsVerificationWork(): void
    {
        $this->insertVm('ALPHA');
        $this->insertVm('BRAVO');
        $sourceJob = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $fence = $this->claimJob($sourceJob);
        $handleId = $this->insertRemoteHandle($sourceJob, 1);

        $this->driveToSuccess($sourceJob, 1, $fence, $handleId);
        repo_deploy_create_transition($this->db, $sourceJob, 2, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            'error_detail' => 'module reported a failure',
        ], $fence);
        $this->db->query('UPDATE deploy_jobs SET status = ' . "'failed'" . ' WHERE id = ' . $sourceJob); // csp-allow: interpolated-sql

        $sourceRows = repo_deploy_create_results($this->db, $sourceJob);
        $retryJob = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $this->db->query('DELETE FROM deploy_create_vm_results WHERE job_id = ' . $retryJob); // csp-allow: interpolated-sql
        repo_deploy_create_materialize_retry($this->db, $retryJob, $sourceRows);

        $retryRows = repo_deploy_create_results($this->db, $retryJob);
        self::assertCount(2, $retryRows);
        self::assertSame(VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP, (string) $retryRows[0]['action']);
        self::assertSame((int) $sourceRows[0]['id'], (int) $retryRows[0]['resumed_from_result_id']);
        // The proven VM is queued as verification, NOT copied as a success:
        // "it existed an hour ago" is not evidence that it exists now.
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, (string) $retryRows[0]['status']);
        self::assertSame(VIRTUSPHERE_CREATE_ACTION_CREATE, (string) $retryRows[1]['action']);
        self::assertNull($retryRows[1]['resumed_from_result_id']);
    }

    public function testARetryIsRefusedWhileASourceUnitIsUnresolved(): void
    {
        $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create']);
        $fence = $this->claimJob($jobId);
        $handleId = $this->insertRemoteHandle($jobId, 1);

        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, ['existed_before' => 0], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '747689456876.6394',
            'remote_execution_id' => $handleId,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED,
            'error_detail' => 'no async state found',
        ], $fence);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unresolved create units');
        repo_deploy_create_materialize_retry($this->db, $jobId + 100000, repo_deploy_create_results($this->db, $jobId));
    }

    /** @param array<int, mixed> $params */
    private function assertWriteRefused(string $sql, string $types, array $params): void
    {
        try {
            repo_execute($this->db, $sql, $types, $params);
            self::fail('the database accepted a row the contract forbids: ' . $sql);
        } catch (mysqli_sql_exception $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    /** @return array{worker_id:string,lock_token:string,worker_epoch:int} */
    private function claimJob(int $jobId): array
    {
        $fence = ['worker_id' => 'phpunit-worker', 'lock_token' => str_repeat('a', 32), 'worker_epoch' => 1];
        $stmt = $this->db->prepare(
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = ?, locked_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt->bind_param('sssii', $running, $fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId);
        $stmt->execute();

        return $fence;
    }

    private function insertRemoteHandle(int $jobId, int $position): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_remote_executions (job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir,'
            . ' instance_id, generation_id, controller_state, effect_state, reconciliation_state, cleanup_state)'
            . ' VALUES (?, 1, ?, 1, ?, ?, ?, UNHEX(?), UNHEX(?), ?, ?, ?, ?)'
        );
        $stepKey = deploy_create_step_key($position);
        $token = bin2hex(random_bytes(16));
        $unit = 'vs-' . $token;
        $dir = '/tmp/vs-' . $token;
        $instance = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $controller = 'prepared';
        $effect = 'not_started';
        $reconciliation = 'not_required';
        $cleanup = 'pending';
        $stmt->bind_param(
            'issssssssss',
            $jobId,
            $stepKey,
            $token,
            $unit,
            $dir,
            $instance,
            $generation,
            $controller,
            $effect,
            $reconciliation,
            $cleanup
        );
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence */
    private function driveToSuccess(int $jobId, int $position, array $fence, int $handleId): void
    {
        repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, ['existed_before' => 0], $fence);
        repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '123456789012.' . $position,
            'remote_execution_id' => $handleId,
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence);
        repo_deploy_create_transition($this->db, $jobId, $position, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, [
            'outcome' => VIRTUSPHERE_CREATE_OUTCOME_CREATED,
            'changed' => 1,
            'existed_before' => 0,
            'vm_moid' => 'vm-' . $position,
            'vm_instance_uuid' => '5001-' . $position,
        ], $fence);
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

    private function insertVm(string $suffix): int
    {
        $name = strtoupper($this->prefix . '_' . $suffix);
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $name, $name);
        $stmt->execute();
        $vmId = (int) $this->db->insert_id;
        $empty = '';
        $vlan = 'WDS';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $empty);
        $stmt->execute();

        return $vmId;
    }
}
