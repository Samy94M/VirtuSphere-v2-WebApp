<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/status_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * A state write must never move a VM backwards, and the MECM assignment transfer
 * must be an explicit act.
 *
 * mecm_updateid.php wrote `os_installing` unconditionally. The device-sync
 * re-reports a ResourceID whenever a VM re-enters its queue, so a VM that had
 * already reported `os_installed` fell visibly back to 4/5 - the operator watched
 * a finished machine start installing again. Storing the ResourceID is still
 * right there (it is the same device), so the two halves of that write are
 * separated rather than the whole call dropped.
 */
final class VmStateMonotonicityTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_mono_' . bin2hex(random_bytes(4));
        $name = $this->prefix . '_m';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->missionId === 0) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();
    }

    public function testAnInstalledVmIsNotWalkedBackToInstalling(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLED);

        // Exactly what mecm_updateid.php does on a renewed registration.
        self::assertTrue(repo_set_vm_state_forward(
            $this->db,
            $vmId,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            VIRTUSPHERE_MECM_SYNC_REGISTERED,
            VIRTUSPHERE_STATUS_OS_INSTALLING,
            0,
            'mecm update id',
            'RES-4711'
        ));

        [$lifecycle, $sync, $legacy, $mecmId] = $this->vmState($vmId);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $lifecycle, 'a finished VM must not start installing again');
        self::assertSame(VIRTUSPHERE_STATUS_OS_INSTALLED, $legacy, 'the legacy status is held back with the lifecycle, or the two disagree');
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $sync);
        self::assertSame('RES-4711', $mecmId, 'the ResourceID still has to be stored: it is the same device');
    }

    public function testAForwardStepStillGoesThrough(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_DEPLOYED);

        repo_set_vm_state_forward($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', 'RES-1');

        [$lifecycle, $sync] = $this->vmState($vmId);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $lifecycle);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $sync);
    }

    /**
     * A failed VM registering again IS moving forward. Ranking `failed` above
     * anything would strand it in the failed state for good.
     */
    public function testAFailedVmCanBeRegisteredAgain(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED, VIRTUSPHERE_STATUS_DEPLOYED);

        repo_set_vm_state_forward($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', 'RES-2');

        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $this->vmState($vmId)[0]);
    }

    /** An unknown id is a failure, not a success. The endpoint answered 200 for it. */
    public function testAnUnknownVmIdIsReportedAsAFailure(): void
    {
        self::assertFalse(repo_set_vm_state_forward(
            $this->db,
            999000111,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            VIRTUSPHERE_MECM_SYNC_REGISTERED,
            VIRTUSPHERE_STATUS_OS_INSTALLING,
            0,
            'mecm update id',
            'RES-3'
        ));
    }

    /** Every lifecycle state has to be ranked, or it compares as "furthest along". */
    public function testEveryLifecycleStateIsRanked(): void
    {
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $state) {
            self::assertGreaterThanOrEqual(0, virtusphere_lifecycle_rank($state), $state . ' has no rank');
        }
        self::assertGreaterThan(
            virtusphere_lifecycle_rank(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING),
            virtusphere_lifecycle_rank(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED),
            'installed has to outrank installing, or the whole guard inverts'
        );
    }

    public function testTheTransferActionQueuesARegisteredVmWithoutTouchingItsLifecycle(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLED);

        repo_mark_vm_for_mecm_resync($this->db, $this->missionId, $vmId, null);

        [$lifecycle, $sync] = $this->vmState($vmId);
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT updated FROM deploy_vms WHERE id = ?', 'i', [$vmId]), 'the VM must be back in the device-sync queue');
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $lifecycle, 'transferring assignments must not undo the installation');
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $sync);
    }

    /**
     * Before the registration the portal selection travels with the next sync on
     * its own, so the action would promise work it does not do.
     */
    public function testTheTransferActionIsRefusedForAVmMecmDoesNotKnowYet(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_DEPLOYED);

        $this->expectExceptionMessageMatches('/not registered/');
        repo_mark_vm_for_mecm_resync($this->db, $this->missionId, $vmId, null);
    }

    private function insertVm(string $lifecycle, string $sync, string $legacyStatus): int
    {
        $name = strtoupper(substr($this->prefix, 0, 12)) . 'VM';
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $this->missionId, $name, $name, $lifecycle, $sync, $legacyStatus);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function vmState(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state, vm_status, mecm_id FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertIsArray($row);

        return [
            (string) $row['lifecycle_state'],
            (string) $row['mecm_sync_state'],
            (string) $row['vm_status'],
            (string) $row['mecm_id'],
        ];
    }
}
