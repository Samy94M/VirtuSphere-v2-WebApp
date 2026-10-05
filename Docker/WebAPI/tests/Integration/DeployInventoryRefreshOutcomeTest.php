<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_finish.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/** DF-L6: durable launch evidence, rather than success, triggers the refresh. */
final class DeployInventoryRefreshOutcomeTest extends TestCase
{
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
        $prefix = 'phpunit_refresh_' . bin2hex(random_bytes(4));
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
        repo_execute($this->db, "INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname,lifecycle_state,mecm_sync_state) VALUES (?,?,?,'deploying','not_ready')", 'iss', [$this->missionId, $prefix, $prefix]);
        $this->vmId = (int) $this->db->insert_id;
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

    public static function terminalPaths(): array
    {
        return [['create', 'failed'], ['full', 'failed'], ['create', 'cancelled'], ['full', 'cancelled'], ['create', 'uncertain'], ['full', 'uncertain']];
    }

    #[DataProvider('terminalPaths')]
    public function testEveryTerminalPathRefreshesAfterALaunch(string $mode, string $path): void
    {
        $job = $this->job($mode, $path === 'cancelled', true);
        if ($path === 'cancelled') {
            deploy_worker_handle_cancelled($this->db, $job, [$this->vmId]);
        } elseif ($path === 'uncertain') {
            deploy_worker_conclude_create_section($this->db, $job, 'phpunit:refresh', [$this->vmId], [], ['all_successful' => false, 'stop_reason' => null, 'summary' => repo_deploy_create_summary($this->db, (int) $job['id'])]);
        } else {
            deploy_worker_handle_failure($this->db, $job, 'phpunit:refresh', [$this->vmId], 'Synthetic failure after launch.');
        }
        self::assertSame(1, $this->refreshCount());
        // The canonical system-job writer deduplicates repeated scheduling.
        deploy_worker_refresh_inventory_after_deploy($this->db, $job);
        self::assertSame(1, $this->refreshCount());
    }

    public function testFailureBeforeLaunchDoesNotScheduleARefresh(): void
    {
        $job = $this->job('full', false, false);
        deploy_worker_handle_failure($this->db, $job, 'phpunit:refresh', [$this->vmId], 'Synthetic failure before launch.');
        self::assertSame(0, $this->refreshCount());
    }

    public function testAnOldWorkerCannotScheduleUnderAnotherClaim(): void
    {
        $job = $this->job('full', false, true);
        repo_execute($this->db, "UPDATE deploy_jobs SET locked_by = 'successor', worker_epoch = worker_epoch + 1 WHERE id = ?", 'i', [(int) $job['id']]);
        deploy_worker_handle_failure($this->db, $job, 'phpunit:refresh', [$this->vmId], 'Old worker stopped.');
        self::assertSame(0, $this->refreshCount());
    }

    private function job(string $mode, bool $cancelling, bool $launched): array
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => [$this->vmId]], JSON_THROW_ON_ERROR);
        repo_execute($this->db, "INSERT INTO deploy_jobs (mission_id,credential_esxi_id,credential_ansible_id,status,payload_json,locked_at,locked_by,lock_token,worker_epoch,heartbeat_at) VALUES (?,?,?,?,?,NOW(),'phpunit:refresh',REPEAT(CHAR(97),32),1,NOW())", 'iiiss', [$this->missionId, $this->esxiId, $this->ansibleId, $cancelling ? 'cancelling' : 'running', $payload]);
        $id = (int) $this->db->insert_id;
        repo_deploy_create_materialize($this->db, $id, [['id' => $this->vmId, 'vm_name' => 'Refresh VM']]);
        if ($launched) {
            repo_execute($this->db, "UPDATE deploy_create_vm_results SET status = 'uncertain', async_jid = '123456789.1', started_at = NOW(), error_code = 'transport_lost', error_detail = 'phpunit: launched, outcome unknown', finished_at = NOW() WHERE job_id = ?", 'i', [$id]);
        }
        $job = repo_deploy_job($this->db, $id);
        self::assertNotNull($job);
        return $job;
    }

    private function refreshCount(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ?', 'i', [$this->esxiId]);
    }
}
