<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/vm_save_service.php';

/**
 * MECM plan decision 32: a domain write and its audit row commit or roll back
 * together. Before, the write committed first and a failed audit insert left a
 * change nobody could attribute.
 */
final class AuditRequiredTransactionTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $userId;
    /** @var list<int> */
    private array $vmIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'audit-tx-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$this->prefix]);
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (isset($this->missionId)) {
            foreach ($this->vmIds as $vmId) {
                repo_execute($this->db, 'DELETE FROM deploy_logs WHERE object_type = ? AND object_id = ?', 'ss', ['vm', (string) $vmId]);
            }
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        }
    }

    public function testTheRequiredAuditRefusesToRunOutsideATransaction(): void
    {
        $this->expectException(LogicException::class);
        audit_event_required($this->db, VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED, 'mission', $this->missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated'], $this->userId);
    }

    public function testAFailedAuditRollsTheWriteBack(): void
    {
        try {
            repo_transaction($this->db, function (): void {
                repo_execute($this->db, 'UPDATE deploy_missions SET domain = ? WHERE id = ?', 'si', ['rolled.back', $this->missionId]);
                // An unregistered context key is refused by the audit registry,
                // which is the realistic way an audit write fails.
                audit_event_required($this->db, VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED, 'mission', $this->missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['not_a_registered_key' => 'x'], $this->userId);
            });
            self::fail('The refused audit did not surface.');
        } catch (Throwable) {
            // expected
        }

        self::assertNotSame('rolled.back', (string) repo_scalar($this->db, 'SELECT domain FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]));
        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE object_type = ? AND object_id = ? AND event_code = ?', 'sss', ['mission', (string) $this->missionId, VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED]));
    }

    public function testAVmSaveWritesTheVmAndItsAuditRowTogether(): void
    {
        $name = $this->vmName('at');
        $saved = vm_save_with_audit(
            $this->db,
            $this->missionId,
            0,
            [],
            false,
            ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'phpunit-os'],
            [],
            [],
            [],
            '',
            $this->userId
        );

        self::assertGreaterThan(0, $saved['vm_id']);
        $this->vmIds[] = $saved['vm_id'];
        self::assertSame('', $saved['frozen_snapshot']);
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE object_type = ? AND object_id = ? AND event_code = ?', 'sss', ['vm', (string) $saved['vm_id'], VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED]));
    }

    public function testARejectedVmSaveAuditRollsBackTheSavedRow(): void
    {
        $vmId = $this->createVm();
        $before = repo_get_vm_bundle($this->db, $vmId);
        self::assertNotNull($before);
        $newName = $this->vmName('ar');

        $this->expectException(mysqli_sql_exception::class);
        try {
            vm_save_with_audit(
                $this->db,
                $this->missionId,
                $vmId,
                $before,
                false,
                ['vm_name' => $newName, 'vm_hostname' => (string) $before['vm_hostname'], 'vm_os' => (string) $before['vm_os']],
                [],
                [],
                [],
                (string) $before['edit_version'],
                2147483647 // Unknown user: deploy_logs.user_id rejects the audit insert.
            );
        } finally {
            $after = repo_get_vm_bundle($this->db, $vmId);
            self::assertNotNull($after);
            self::assertSame($before['vm_name'], $after['vm_name']);
            self::assertSame($before['edit_version'], $after['edit_version']);
            self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE object_type = ? AND object_id = ? AND event_code = ?', 'sss', ['vm', (string) $vmId, VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED]));
        }
    }

    public function testMecmTransferChecksRevisionAndAuditsTheQueue(): void
    {
        $vmId = $this->createVm();
        $registered = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        repo_execute($this->db, 'UPDATE deploy_vms SET mecm_sync_state = ?, updated = 0 WHERE id = ?', 'si', [$registered, $vmId]);
        $revision = mecm_transfer_state($this->db, $this->missionId, $vmId)['revision'];

        repo_execute($this->db, 'UPDATE deploy_vms SET vm_os = ? WHERE id = ?', 'si', ['Changed OS', $vmId]);
        try {
            vm_queue_mecm_transfer_with_audit($this->db, $this->missionId, $vmId, $revision, $this->userId);
            self::fail('A stale assignment preview must not queue the VM.');
        } catch (RuntimeException $exception) {
            self::assertSame(__t('portal.vm_mecm_transfer_stale'), $exception->getMessage());
        }
        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT updated FROM deploy_vms WHERE id = ?', 'i', [$vmId]));

        $revision = mecm_transfer_state($this->db, $this->missionId, $vmId)['revision'];
        vm_queue_mecm_transfer_with_audit($this->db, $this->missionId, $vmId, $revision, $this->userId);
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT updated FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE object_type = ? AND object_id = ? AND event_code = ? AND JSON_UNQUOTE(JSON_EXTRACT(context_json, ?)) = ?', 'sssss', ['vm', (string) $vmId, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, '$.action', 'queued_mecm_transfer']));
    }

    private function createVm(): int
    {
        $name = $this->vmName('at');
        $saved = vm_save_with_audit($this->db, $this->missionId, 0, [], false, ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'phpunit-os'], [], [], [], '', $this->userId);
        $this->vmIds[] = $saved['vm_id'];

        return $saved['vm_id'];
    }

    private function vmName(string $prefix): string
    {
        return $prefix . '-' . substr(hash('sha256', $this->prefix), 0, 10);
    }
}
