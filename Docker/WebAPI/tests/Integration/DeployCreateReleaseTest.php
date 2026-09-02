<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_identity.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_create_release.php';

/**
 * The fail-closed operator release of an unresolved create unit (Etappe 14B,
 * Teiletappe F; plan section 10.5) against a real MySQL server.
 *
 * Every case here is a way the release must NOT happen. The action's failure
 * mode is a second VM on a production host, so the interesting assertions are
 * the refusals: a stale inventory pull, a pull whose VM query did not answer, a
 * namesake in that pull, the stored UUID in that pull, and a unit that stopped
 * being unresolved while the operator was looking at the page.
 */
final class DeployCreateReleaseTest extends TestCase
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
        $this->prefix = 'phpunit_release14bf_' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
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
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ([$this->esxiId, $this->ansibleId] as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_esxi_inventory WHERE credential_id = ?', 'i', [$credentialId]);
            repo_execute($this->db, 'DELETE FROM deploy_esxi_inventory_state WHERE credential_id = ?', 'i', [$credentialId]);
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    public function testAReleaseNeedsAFreshPullThatAnsweredAndFoundNothing(): void
    {
        [$jobId, $unit] = $this->unresolvedUnit();

        // Nothing pulled yet: nobody has looked at the host since the unit was
        // left open, so there is no evidence to release on.
        $decision = deploy_create_release_blockers($this->db, $jobId, 1);
        self::assertFalse($decision['eligible']);
        self::assertContains('release_inventory_never_succeeded', $decision['blockers']);
        self::assertContains('release_inventory_vm_kind_unanswered', $decision['blockers']);

        // A pull that ran BEFORE the unit was left open says nothing about it.
        $this->writeInventoryState('-1 hour', true);
        self::assertContains('release_inventory_stale', deploy_create_release_blockers($this->db, $jobId, 1)['blockers']);

        // Fresh, but its VM query did not answer: the VM list of that pull is
        // not evidence, which is a different thing from "found no VMs".
        $this->writeInventoryState('+2 minutes', false);
        self::assertContains('release_inventory_vm_kind_unanswered', deploy_create_release_blockers($this->db, $jobId, 1)['blockers']);

        // Fresh and answered, and the host has nothing of that name.
        $this->writeInventoryState('+2 minutes', true);
        self::assertTrue(deploy_create_release_blockers($this->db, $jobId, 1)['eligible']);

        // A namesake in exactly that pull means something IS there.
        $this->writeInventoryRow((string) $unit['vm_name'], '5001-live');
        self::assertContains('release_inventory_name_present', deploy_create_release_blockers($this->db, $jobId, 1)['blockers']);
    }

    public function testTheStoredInstanceUuidInTheFreshPullAlsoBlocks(): void
    {
        [$jobId, $unit] = $this->unresolvedUnit();
        $this->writeInventoryState('+2 minutes', true);
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_instance_uuid = ? WHERE id = ?', 'si', ['5002-OURS', (int) $unit['vm_id']]);
        // Another name, the same identity. A rename on the host must not make
        // the VM look absent: the UUID is who it is.
        $this->writeInventoryRow($this->prefix . '_RENAMED', '5002-ours');

        $decision = deploy_create_release_blockers($this->db, $jobId, 1);
        self::assertFalse($decision['eligible']);
        self::assertContains('release_inventory_uuid_present', $decision['blockers']);
    }

    public function testAReleaseWritesTheResolutionTheTransitionAndTheJobLine(): void
    {
        [$jobId, $unit] = $this->unresolvedUnit();
        $this->writeInventoryState('+2 minutes', true);

        $outcome = repo_deploy_create_release_unit($this->db, $jobId, 1, $this->userId, 'Checked the host; no such VM and no task.', 'TICKET-7');
        self::assertTrue($outcome['released']);
        self::assertGreaterThan(0, (int) $outcome['resolution_id']);

        $row = repo_deploy_create_results($this->db, $jobId)[0];
        self::assertSame(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, (string) $row['status']);
        self::assertSame(VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED, (string) $row['error_code']);
        self::assertNotNull($row['finished_at']);
        // Nothing about the VM was written: the release adopts nothing.
        self::assertNull(repo_scalar($this->db, 'SELECT vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [(int) $unit['vm_id']]));

        $resolution = repo_fetch_one(
            $this->db,
            'SELECT resolution_scope, resolution_code, create_result_id, remote_execution_id, reason, reference, actor_id'
            . ' FROM deploy_recovery_resolutions WHERE id = ? LIMIT 1',
            'i',
            [(int) $outcome['resolution_id']]
        );
        self::assertSame(VIRTUSPHERE_RECOVERY_RESOLUTION_SCOPE_CREATE_UNIT, (string) $resolution['resolution_scope']);
        self::assertSame(VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_NOT_APPLIED, (string) $resolution['resolution_code']);
        self::assertSame((int) $row['id'], (int) $resolution['create_result_id']);
        self::assertNull($resolution['remote_execution_id']);
        // The operator's own words live here, behind system.config, and not in
        // the audit row every users.manage holder reads.
        self::assertSame('Checked the host; no such VM and no task.', (string) $resolution['reason']);
        self::assertSame('TICKET-7', (string) $resolution['reference']);

        // No line in the source job's log, and that is the contract rather than
        // an omission: the log is the account of what the JOB did, it is
        // terminal evidence, and the append path refuses it. The release is
        // recorded in the create row, the resolution and the audit trail.
        self::assertSame(
            0,
            (int) repo_scalar(
                $this->db,
                "SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line LIKE '%operator_released%'",
                'i',
                [$jobId]
            )
        );

        // Second attempt on the same unit: it is no longer unresolved, so the
        // release refuses instead of writing a second resolution.
        $again = repo_deploy_create_release_unit($this->db, $jobId, 1, $this->userId, 'again', null);
        self::assertFalse($again['released']);
        self::assertSame(['release_unit_not_uncertain'], $again['blockers']);
    }

    public function testAReasonIsMandatory(): void
    {
        [$jobId] = $this->unresolvedUnit();
        $this->writeInventoryState('+2 minutes', true);

        $this->expectException(ValidationException::class);
        repo_deploy_create_release_unit($this->db, $jobId, 1, $this->userId, '   ', null);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unresolvedUnit(): array
    {
        $vmId = $this->insertVm('ALPHA');
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, [
            'mode' => 'create',
            'vm_ids' => [$vmId],
        ]);
        $fence = ['worker_id' => 'phpunit-worker', 'lock_token' => bin2hex(random_bytes(16)), 'worker_epoch' => 1];
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = ? WHERE id = ?',
            'sssii',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $fence['worker_id'], $fence['lock_token'], $fence['worker_epoch'], $jobId]
        );
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, ['existed_before' => 0], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => '123456789012.1',
            'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ], $fence);
        repo_deploy_create_transition($this->db, $jobId, 1, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
            'error_detail' => 'fixture: the budget was reached while this VM was still being created',
        ], $fence);
        // Terminal and unlocked, exactly as a reaped or timed-out job is left.
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = NULL, lock_token = NULL, worker_epoch = NULL WHERE id = ?',
            'si',
            [VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $jobId]
        );

        return [$jobId, repo_deploy_create_results($this->db, $jobId)[0]];
    }

    private function writeInventoryState(string $offset, bool $vmKindAnswered): void
    {
        $at = gmdate('Y-m-d H:i:s', strtotime($offset) ?: time());
        $freshness = json_encode($vmKindAnswered ? [VIRTUSPHERE_INVENTORY_KIND_VM => $at] : ['datastore' => $at], JSON_THROW_ON_ERROR);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, kind_freshness_json) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), kind_freshness_json = VALUES(kind_freshness_json)',
            'iss',
            [$this->esxiId, $at, $freshness]
        );
    }

    private function writeInventoryRow(string $name, string $instanceUuid): void
    {
        $kind = VIRTUSPHERE_INVENTORY_KIND_VM;
        $meta = json_encode(['moid' => 'vm-9', 'instance_uuid' => $instanceUuid], JSON_THROW_ON_ERROR);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_esxi_inventory (credential_id, kind, name, meta_json) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE meta_json = VALUES(meta_json)',
            'isss',
            [$this->esxiId, $kind, $name, $meta]
        );
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
