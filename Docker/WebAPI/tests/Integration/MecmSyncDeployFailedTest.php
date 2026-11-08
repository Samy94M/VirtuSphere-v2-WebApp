<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * MX0 (migration 0070): the deploy failure mark on the MECM axis is
 * `deploy_failed`; `failed` is withdrawn.
 *
 *  - The migration renames every stored `failed` in the three places that hold
 *    the token (VM, status event, job prestate), keeps `updated_at`, ends with
 *    the final ENUM and renames nothing on a second run. The test widens the
 *    column first, because a database at 0069 is the only one that can still
 *    hold the old token.
 *  - The reset path leaves the new value exactly as it left `failed`.
 */
final class MecmSyncDeployFailedTest extends TestCase
{
    private const PREFIX = 'phpunit_mx0_';
    private const FINAL_TYPE = "enum('not_ready','pending','registered','deploy_failed')";
    private const UNION = "ENUM('not_ready','pending','registered','failed','deploy_failed') NOT NULL DEFAULT 'not_ready'";
    private const FINAL = "ENUM('not_ready','pending','registered','deploy_failed') NOT NULL DEFAULT 'not_ready'";

    private ?mysqli $db = null;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        // Whatever happened above, the shared column leaves this test in its final shape.
        if ($this->columnType() !== self::FINAL_TYPE) {
            $this->db->query("UPDATE deploy_vms SET mecm_sync_state = 'deploy_failed', updated_at = updated_at WHERE mecm_sync_state = 'failed'");
            $this->db->query('ALTER TABLE deploy_vms MODIFY mecm_sync_state ' . self::FINAL);
        }
        $this->cleanup();
    }

    public function testTheValueListHasTheDeployFailureMarkAndNoLongerFailed(): void
    {
        self::assertSame(self::FINAL_TYPE, $this->columnType());
        self::assertContains(VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, VIRTUSPHERE_MECM_SYNC_STATES);
        self::assertNotContains('failed', VIRTUSPHERE_MECM_SYNC_STATES);
    }

    public function testMigrationRenamesEveryStoredDeployFailureMarkAndIsIdempotent(): void
    {
        $this->db->query('ALTER TABLE deploy_vms MODIFY mecm_sync_state ' . self::UNION);
        $vmId = $this->makeVm('PHPUNITMX01');
        $stamp = '2026-01-02 03:04:05';
        repo_execute($this->db, "UPDATE deploy_vms SET lifecycle_state = 'failed', mecm_sync_state = 'failed', updated_at = ? WHERE id = ?", 'si', [$stamp, $vmId]);
        repo_execute($this->db, "INSERT INTO deploy_vm_status_events (vm_id, lifecycle_state, mecm_sync_state, legacy_status, note) VALUES (?, 'failed', 'failed', ?, 'phpunit mx0 legacy')", 'is', [$vmId, VIRTUSPHERE_STATUS_INITIALIZING]);
        $eventId = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, 'failed', '{}')", 'i', [$this->missionId]);
        $jobId = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, 'ready', 'failed')", 'ii', [$jobId, $vmId]);

        $first = $this->runMigration();

        self::assertStringContainsString('0070:', $first);
        self::assertSame(self::FINAL_TYPE, $this->columnType(), 'the migration must end with the final value list');
        $vm = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state, updated_at FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertSame(['failed', 'deploy_failed', $stamp], [$vm['lifecycle_state'], $vm['mecm_sync_state'], $vm['updated_at']], 'renamed without moving updated_at');
        self::assertSame('deploy_failed', repo_scalar($this->db, 'SELECT mecm_sync_state FROM deploy_vm_status_events WHERE id = ?', 'i', [$eventId]));
        self::assertSame('deploy_failed', repo_scalar($this->db, 'SELECT mecm_before FROM deploy_job_vm_states WHERE job_id = ? AND vm_id = ?', 'ii', [$jobId, $vmId]));
        self::assertSame('ready', repo_scalar($this->db, 'SELECT lifecycle_before FROM deploy_job_vm_states WHERE job_id = ? AND vm_id = ?', 'ii', [$jobId, $vmId]));

        $second = $this->runMigration();

        self::assertStringContainsString('renamed: 0 VM(s), 0 status event(s), 0 job prestate(s)', $second);
        self::assertSame(self::FINAL_TYPE, $this->columnType());
    }

    public function testMecmResetLeavesTheDeployFailureMark(): void
    {
        $vmId = $this->makeVm('PHPUNITMX02');
        repo_execute($this->db, "UPDATE deploy_interfaces SET mac = '02:AA:BB:CC:DD:70' WHERE vm_id = ?", 'i', [$vmId]);
        // Bound to a ResourceID: an unbound VM whose rollout name already matches
        // is the idempotent `already_pending` click, which resets nothing.
        repo_execute($this->db, "UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, mecm_id = '4870' WHERE id = ?", 'ssi', [
            VIRTUSPHERE_LIFECYCLE_FAILED,
            VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED,
            $vmId,
        ]);

        $result = repo_reset_vm_mecm_id($this->db, $this->missionId, $vmId);

        self::assertTrue($result['changed'], json_encode($result, JSON_THROW_ON_ERROR));
        $vm = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state, updated, mecm_id, mecm_previous_id FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, 1, null, '4870'],
            [$vm['lifecycle_state'], $vm['mecm_sync_state'], (int) $vm['updated'], $vm['mecm_id'], $vm['mecm_previous_id']]
        );
    }

    private function runMigration(): string
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/migrate.php', true) . '; migrate_0070_mecm_sync_deploy_failed(db());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);

        return $stdout;
    }

    private function columnType(): string
    {
        return (string) repo_scalar($this->db, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deploy_vms' AND COLUMN_NAME = 'mecm_sync_state'");
    }

    private function makeVm(string $name): int
    {
        return repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '192.0.2.7', 'subnet' => '255.255.255.0', 'gateway' => '192.0.2.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN10', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach (['DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
