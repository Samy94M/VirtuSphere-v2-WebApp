<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

final class VmProgressWatchTest extends TestCase
{
    private const PREFIX = 'phpunit_vmwatch_';

    private mysqli $db;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->cleanup();
        $this->missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . bin2hex(random_bytes(3))]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testPendingWatchRestartChangesOnlyItsClockAndWritesHistory(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL 1 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ' . $vmId);
        $before = $this->row($vmId);

        self::assertSame(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId));

        $after = $this->row($vmId);
        self::assertSame($before['lifecycle_state'], $after['lifecycle_state']);
        self::assertSame($before['mecm_sync_state'], $after['mecm_sync_state']);
        self::assertSame($before['updated_at'], $after['updated_at'], 'an observation clock is not a VM edit');
        self::assertNotSame($before['mecm_pending_since'], $after['mecm_pending_since']);
        self::assertNull($after['os_install_watch_started_at']);
        self::assertSame(1, $this->eventCount($vmId, 'MECM pending observation restarted'));
    }

    public function testInstallWatchIsExplicitAndRepeatableWithoutChangingLifecycle(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);

        self::assertSame(VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING, repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId));
        $first = $this->row($vmId);
        self::assertNotNull($first['os_install_watch_started_at']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $first['lifecycle_state']);

        $this->db->query('UPDATE deploy_vms SET os_install_watch_started_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $vmId);
        $beforeRestart = $this->row($vmId);
        repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId);
        self::assertNotSame($beforeRestart['os_install_watch_started_at'], $this->row($vmId)['os_install_watch_started_at']);
        self::assertSame(2, $this->eventCount($vmId, 'OS installation observation restarted'));
    }

    public function testUnrelatedStateCannotStartAWatch(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);

        $this->expectException(RuntimeException::class);
        repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId);
    }

    public function testAttentionCountUsesDedicatedClocksAndNeverUpdatedAt(): void
    {
        $oldPending = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $freshPending = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $unwatchedInstall = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $oldInstall = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $oldPending);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = NOW(), updated_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ' . $freshPending);
        $this->db->query('UPDATE deploy_vms SET os_install_watch_started_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $oldInstall);

        self::assertSame(2, repo_vm_progress_attention_count($this->db));
        self::assertSame(2, repo_vm_progress_attention_count($this->db, $this->missionId));
        self::assertNull($this->row($unwatchedInstall)['os_install_watch_started_at']);
    }

    private function insertVm(string $lifecycle, string $mecm): int
    {
        $name = 'WATCH' . bin2hex(random_bytes(4));
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $this->missionId, $name, $name, $lifecycle, $mecm);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @return array<string,mixed> */
    private function row(int $vmId): array
    {
        $result = $this->db->query('SELECT lifecycle_state, mecm_sync_state, mecm_pending_since, os_install_watch_started_at, updated_at FROM deploy_vms WHERE id = ' . $vmId);
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        self::assertIsArray($row);

        return $row;
    }

    private function eventCount(int $vmId, string $note): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_vm_status_events WHERE vm_id = ? AND note = ?');
        $stmt->bind_param('is', $vmId, $note);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
