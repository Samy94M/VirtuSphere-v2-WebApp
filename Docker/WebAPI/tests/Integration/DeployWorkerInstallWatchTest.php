<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_finish.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_install_watch.php';

/**
 * The worker's two triggers of the automatic observation start (ADR-0038
 * amendment 2), through the terminal paths that call them: the end of a job
 * with a start step, and an applied inventory whose `vm` kind answered.
 *
 * A job with a start step also asks for an inventory pull, because only the
 * pull can say which VMs are on after a start that failed half way, and it
 * confirms a hand-started VM. `start` still writes no lifecycle or MECM state.
 */
final class DeployWorkerInstallWatchTest extends TestCase
{
    private const WORKER = 'phpunit:watch';
    private const UUID = 'phpunit-watch-uuid';

    private mysqli $db;
    private int $missionId;
    private int $vmId;
    private int $esxiId;
    private int $ansibleId;
    private ?array $oldSetting;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $prefix = 'phpunit_watch_' . bin2hex(random_bytes(4));
        foreach (['esxi', 'ansible'] as $type) {
            repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $prefix . $type, $prefix . '.invalid', 'fixture', 'unused']);
            if ($type === 'esxi') {
                $this->esxiId = (int) $this->db->insert_id;
            } else {
                $this->ansibleId = (int) $this->db->insert_id;
            }
        }
        $this->oldSetting = repo_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $this->ansibleId);
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status) VALUES (?,'active')", 's', [$prefix]);
        $this->missionId = (int) $this->db->insert_id;
        // Registered and installing, bound to the access, clock still empty: the VM the triggers exist for.
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname,lifecycle_state,mecm_sync_state,vm_status,vm_instance_uuid,vm_bound_credential_id,vm_bound_esxi_host) VALUES (?,?,?,?,?,?,?,?,?)',
            'issssssis',
            [$this->missionId, $prefix, $prefix, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, self::UUID, $this->esxiId, $prefix . '.invalid']
        );
        $this->vmId = (int) $this->db->insert_id;
        // An old edit time, so "updated_at stays" below is a statement and not a coincidence of one second.
        repo_execute($this->db, 'UPDATE deploy_vms SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?', 'i', [$this->vmId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->missionId)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ?', 'i', [$this->esxiId]);
        foreach ([$this->esxiId, $this->ansibleId] as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
        if ($this->oldSetting === null) {
            repo_delete_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
        } else {
            repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $this->oldSetting['setting_value']);
        }
    }

    public function testASucceededStartJobStartsTheClockAsksForAnInventoryAndKeepsTheVmState(): void
    {
        $job = $this->runningJob(['mode' => 'start']);
        $before = $this->vm();

        deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$this->vmId]);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $this->jobStatus((int) $job['id']));
        $after = $this->vm();
        self::assertNotNull($after['os_install_watch_started_at']);
        self::assertSame(1, $this->noteCount(VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START));
        self::assertSame(
            [$before['lifecycle_state'], $before['mecm_sync_state'], $before['vm_status'], $before['updated_at']],
            [$after['lifecycle_state'], $after['mecm_sync_state'], $after['vm_status'], $after['updated_at']],
            'start writes no lifecycle or MECM state, and the clock is not a VM edit'
        );
        self::assertSame(1, $this->inventoryJobCount(), 'a job with a start step asks for an inventory pull');
    }

    public function testAResumedStartThatFailedOnlyInAutostartStillProvesTheStart(): void
    {
        $job = $this->runningJob(['mode' => 'start', 'then_autostart' => true]);

        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'Synthetic autostart failure.', failedStep: 'autostart');

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus((int) $job['id']));
        $vm = $this->vm();
        self::assertNotNull($vm['os_install_watch_started_at']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $vm['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $vm['mecm_sync_state']);
        self::assertSame(1, $this->inventoryJobCount());
    }

    /** A failed start step proves nothing for any VM; the inventory has to say which ones are on. */
    public function testAFailedStartStepAsksForAnInventoryButStartsNoClock(): void
    {
        $job = $this->runningJob(['mode' => 'start']);

        deploy_worker_handle_failure($this->db, $job, self::WORKER, [$this->vmId], 'Synthetic start failure.', failedStep: 'start');

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->jobStatus((int) $job['id']));
        self::assertNull($this->vm()['os_install_watch_started_at']);
        self::assertSame(0, $this->noteCount(VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START));
        self::assertSame(1, $this->inventoryJobCount());
    }

    public function testAJobWithoutAStartStepDoesNeither(): void
    {
        $job = $this->runningJob(['mode' => 'autostart']);

        deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$this->vmId]);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $this->jobStatus((int) $job['id']));
        self::assertNull($this->vm()['os_install_watch_started_at']);
        self::assertSame(0, $this->inventoryJobCount());
    }

    /** The decision is read back from the stored job, so a worker that lost its claim triggers nothing. */
    public function testAJobThatIsNotTerminalTriggersNothing(): void
    {
        $job = $this->runningJob(['mode' => 'start']);

        deploy_worker_after_start_step($this->db, $job);

        self::assertSame(['completed' => false, 'ran' => false], deploy_worker_job_start_step($this->db, (int) $job['id']));
        self::assertNull($this->vm()['os_install_watch_started_at']);
        self::assertSame(0, $this->inventoryJobCount());
    }

    /** ADR-0023: an access paused after an authentication failure is not polled; the clock does not depend on it. */
    public function testAPausedAccessIsNotAskedForAnInventory(): void
    {
        repo_execute($this->db, 'INSERT INTO deploy_esxi_inventory_state (credential_id, paused_until_credential_change) VALUES (?, 1)', 'i', [$this->esxiId]);
        $job = $this->runningJob(['mode' => 'start']);

        deploy_worker_conclude_sequence($this->db, $job, self::WORKER, [$this->vmId]);

        self::assertNotNull($this->vm()['os_install_watch_started_at']);
        self::assertSame(0, $this->inventoryJobCount());
    }

    public function testAnAppliedInventoryWhoseVmKindAnsweredStartsTheClock(): void
    {
        $this->cacheInventory(VIRTUSPHERE_VM_INVENTORY_POWERED_ON);
        $before = $this->vm();

        // The other kinds answered, the vm query did not: no power state was read.
        deploy_worker_after_vm_inventory($this->db, $this->esxiId, ['queries' => ['datastores' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED], 'vms' => ['state' => 'failed']]]);
        $unanswered = $this->vm();
        self::assertNull($unanswered['os_install_watch_started_at']);
        // A pull without the per-query report (old playbook) answers nothing.
        deploy_worker_after_vm_inventory($this->db, $this->esxiId, []);
        $withoutReport = $this->vm();
        self::assertNull($withoutReport['os_install_watch_started_at']);

        deploy_worker_after_vm_inventory($this->db, $this->esxiId, ['queries' => ['vms' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED]]]);

        $after = $this->vm();
        self::assertNotNull($after['os_install_watch_started_at']);
        self::assertSame(1, $this->noteCount(VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY));
        self::assertSame($before['updated_at'], $after['updated_at']);
        self::assertSame($before['lifecycle_state'], $after['lifecycle_state']);
    }

    public function testAnAppliedInventoryThatShowsTheVmPoweredOffStartsNoClock(): void
    {
        $this->cacheInventory(VIRTUSPHERE_VM_INVENTORY_POWERED_OFF);

        deploy_worker_after_vm_inventory($this->db, $this->esxiId, ['queries' => ['vms' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED]]]);

        self::assertNull($this->vm()['os_install_watch_started_at']);
        self::assertSame(0, $this->noteCount(VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY));
    }

    /**
     * A claimed job the way the worker holds it, with the journal row its claim wrote.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runningJob(array $payload): array
    {
        repo_execute(
            $this->db,
            "INSERT INTO deploy_jobs (mission_id,credential_esxi_id,credential_ansible_id,status,payload_json,locked_at,locked_by,lock_token,worker_epoch,heartbeat_at) VALUES (?,?,?,'running',?,NOW(),?,REPEAT(CHAR(97),32),1,NOW())",
            'iiiss',
            [$this->missionId, $this->esxiId, $this->ansibleId, json_encode($payload + ['vm_ids' => [$this->vmId]], JSON_THROW_ON_ERROR), self::WORKER]
        );
        $id = (int) $this->db->insert_id;
        repo_execute(
            $this->db,
            'INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, ?, ?)',
            'iiss',
            [$id, $this->vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED]
        );
        $job = repo_deploy_job($this->db, $id);
        self::assertNotNull($job);

        return $job;
    }

    private function cacheInventory(string $powerState): void
    {
        $freshAt = (string) repo_scalar($this->db, 'SELECT NOW()');
        repo_execute(
            $this->db,
            'INSERT INTO deploy_esxi_inventory_state (credential_id, kind_freshness_json, kind_observation_json) VALUES (?, ?, ?)',
            'iss',
            [$this->esxiId, json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => $freshAt]), json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => ['outcome' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED]])]
        );
        repo_execute(
            $this->db,
            "INSERT INTO deploy_esxi_inventory (credential_id, kind, name, meta_json) VALUES (?, 'vm', ?, ?)",
            'iss',
            [$this->esxiId, 'phpunit-watch-vm', json_encode(['instance_uuid' => self::UUID, 'power_state' => $powerState])]
        );
    }

    /** @return array<string,mixed> the row as it is now */
    private function vm(): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state, vm_status, os_install_watch_started_at, updated_at FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);
        self::assertIsArray($row);

        return $row;
    }

    private function jobStatus(int $jobId): string
    {
        return (string) repo_scalar($this->db, 'SELECT status FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
    }

    private function noteCount(string $note): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vm_status_events WHERE vm_id = ? AND note = ?', 'is', [$this->vmId, $note]);
    }

    private function inventoryJobCount(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ?', 'i', [$this->esxiId]);
    }
}
