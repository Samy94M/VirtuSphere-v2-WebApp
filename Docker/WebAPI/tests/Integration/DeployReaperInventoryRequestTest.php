<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_finish.php';

/**
 * TS1-K4 (G1-4): a create/full job the stale-job cleanup concludes owes its
 * credential the same inventory pull the live worker would have queued, or its
 * unresolved Create units can never be released, and with the interval at 0 no
 * pull ever comes. The full reaper runs here (deploy_worker_reap_stale_jobs()),
 * so the transaction boundary is real: the pull is queued after the commit.
 */
final class DeployReaperInventoryRequestTest extends TestCase
{
    private const REQUEST_LINE = 'Inventory pull requested after the stale-job cleanup. It is enqueued once this cleanup commits; whether it was queued is recorded as deploy.inventory_requested in the audit log, a failure in the container log.';

    private mysqli $db;
    private int $missionId;
    private int $vmId;
    private int $esxiId;
    private int $ansibleId;
    private ?array $oldAnsibleSetting;
    private ?array $oldIntervalSetting;
    private ?int $observerBefore;
    /** @var list<int> */
    private array $extraCredentialIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        if ((int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_jobs WHERE status IN ('running','cancelling')") > 0) {
            self::markTestSkipped('Foreign active executions exist; the global reaper must not mutate them.');
        }
        $prefix = 'phpunit_reap_inv_' . bin2hex(random_bytes(4));
        foreach (['esxi', 'ansible'] as $type) {
            repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $prefix . $type, $prefix . '.invalid', 'fixture', 'unused']);
            if ($type === 'esxi') {
                $this->esxiId = (int) $this->db->insert_id;
            } else {
                $this->ansibleId = (int) $this->db->insert_id;
            }
        }
        $this->oldAnsibleSetting = repo_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $this->ansibleId);
        // Interval 0: the automatic pull is off, so only the cleanup can queue one.
        $this->oldIntervalSetting = repo_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS);
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, '0');
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status) VALUES (?,'active')", 's', [$prefix]);
        $this->missionId = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname,lifecycle_state,mecm_sync_state) VALUES (?,?,?,'deploying','not_ready')", 'iss', [$this->missionId, $prefix, $prefix]);
        $this->vmId = (int) $this->db->insert_id;
        // The reaper refuses to judge silence it was not awake to observe.
        $this->observerBefore = deploy_reap_observer_since();
        deploy_reap_observer_since(time() - VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS - 60);
    }

    protected function tearDown(): void
    {
        if (!isset($this->missionId)) {
            return;
        }
        deploy_reap_observer_since($this->observerBefore ?? time());
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ?', 'i', [$this->esxiId]);
        repo_execute($this->db, 'DELETE FROM deploy_esxi_inventory_state WHERE credential_id = ?', 'i', [$this->esxiId]);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?', 'sss', [VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED, 'credential', (string) $this->esxiId]);
        foreach ([$this->esxiId, $this->ansibleId, ...$this->extraCredentialIds] as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
        $this->restoreSetting(VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, $this->oldAnsibleSetting);
        $this->restoreSetting(VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS, $this->oldIntervalSetting);
    }

    public function testAnUnknownOutcomeQueuesThePullAfterTheCommitAndSaysSo(): void
    {
        $jobId = $this->staleJob('create', false, true);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, repo_deploy_job($this->db, $jobId)['status']);
        $pulls = $this->pulls();
        self::assertCount(1, $pulls, 'interval 0 does not stop a pull the cleanup owes');
        $lines = array_column($this->logs($jobId), 'line');
        self::assertContains(self::REQUEST_LINE, $lines);
        self::assertStringStartsWith('Reaped stale deploy job.', (string) end($lines), 'the reaper keeps the final account of the job');
        $audits = $this->audits();
        self::assertCount(1, $audits);
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $audits[0]['result']);
        $context = json_decode((string) $audits[0]['context_json'], true);
        self::assertSame(VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_QUEUED, $context['reason'] ?? null);
        self::assertSame((int) $pulls[0]['id'], (int) ($context['job_id'] ?? 0));
    }

    public function testAWaitingPullIsNotDuplicated(): void
    {
        $waiting = esxi_inventory_enqueue_for_credential($this->db, $this->esxiId);
        self::assertTrue($waiting['enqueued']);
        $this->staleJob('full', false, true);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertCount(1, $this->pulls());
        $context = json_decode((string) ($this->audits()[0]['context_json'] ?? ''), true);
        self::assertSame(VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ALREADY_PENDING, $context['reason'] ?? null);
    }

    public function testARequestThatCannotBeQueuedIsAuditedAsAWarning(): void
    {
        // No Ansible host chosen for the pull: the request line stands (it
        // promises a request, not a queued job) and the audit row says why
        // nothing was queued.
        // A second Ansible credential and no selection make the choice ambiguous
        // whatever else the database holds.
        repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', ['ansible', 'phpunit_reap_inv_second_' . bin2hex(random_bytes(4)), 'second.invalid', 'fixture', 'unused']);
        $this->extraCredentialIds[] = (int) $this->db->insert_id;
        repo_delete_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
        $jobId = $this->staleJob('create', false, true);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertContains(self::REQUEST_LINE, array_column($this->logs($jobId), 'line'));
        self::assertCount(0, $this->pulls());
        $audits = $this->audits();
        self::assertCount(1, $audits);
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_WARNING, $audits[0]['result']);
        $reason = json_decode((string) $audits[0]['context_json'], true)['reason'] ?? null;
        self::assertContains($reason, [VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_NO_ANSIBLE, VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_INVALID_ANSIBLE, VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_AMBIGUOUS_ANSIBLE]);
    }

    public function testAPausedCredentialIsNotPulled(): void
    {
        repo_execute($this->db, "INSERT INTO deploy_esxi_inventory_state (credential_id, last_attempt_at, last_status, last_error_category, failure_streak, paused_until_credential_change) VALUES (?, NOW(), 'failed', ?, 1, 1)", 'is', [$this->esxiId, VIRTUSPHERE_INVENTORY_ERROR_AUTH]);
        $jobId = $this->staleJob('create', false, true);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertCount(0, $this->pulls(), 'a pull with a known-bad password could lock the ESXi account');
        self::assertCount(0, $this->audits());
        $lines = implode("\n", array_column($this->logs($jobId), 'line'));
        self::assertStringContainsString('No inventory pull after the stale-job cleanup: the ESXi credential is paused', $lines);
    }

    public function testACancelConvergedJobIsPulledWithoutALateLogLine(): void
    {
        $jobId = $this->staleJob('create', true, true);
        $before = $this->logs($jobId);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, repo_deploy_job($this->db, $jobId)['status']);
        self::assertCount(1, $this->pulls(), 'the remote outcome is as unknown as after a silent running job');
        self::assertSame($before, $this->logs($jobId), 'the accepted cancellation keeps its one log record');
        self::assertCount(1, $this->audits());
    }

    public function testAJobThatNeverLaunchedACreateOwesNothing(): void
    {
        $jobId = $this->staleJob('create', false, false);

        self::assertSame(1, deploy_worker_reap_stale_jobs($this->db));

        self::assertCount(0, $this->pulls());
        self::assertCount(0, $this->audits());
        self::assertNotContains(self::REQUEST_LINE, array_column($this->logs($jobId), 'line'));
    }

    private function staleJob(string $mode, bool $cancelling, bool $launched): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => [$this->vmId]], JSON_THROW_ON_ERROR);
        $stale = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS + 120;
        repo_execute(
            $this->db,
            "INSERT INTO deploy_jobs (mission_id,credential_esxi_id,credential_ansible_id,status,payload_json,locked_at,locked_by,lock_token,worker_epoch,heartbeat_at) VALUES (?,?,?,?,?,NOW(),'phpunit:reap-inventory',REPEAT(CHAR(97),32),1,DATE_SUB(NOW(), INTERVAL ? SECOND))",
            'iiissi',
            [$this->missionId, $this->esxiId, $this->ansibleId, $cancelling ? VIRTUSPHERE_DEPLOY_STATUS_CANCELLING : VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $payload, $stale]
        );
        $id = (int) $this->db->insert_id;
        repo_deploy_create_materialize($this->db, $id, [['id' => $this->vmId, 'vm_name' => 'Reap inventory VM']]);
        if ($launched) {
            repo_execute($this->db, "UPDATE deploy_create_vm_results SET status = 'running', async_jid = '123456789.1', started_at = NOW(), async_deadline_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE job_id = ?", 'i', [$id]);
        }

        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function pulls(): array
    {
        return $this->rows('SELECT id, status FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ? ORDER BY id', 'i', [$this->esxiId]);
    }

    /** @return list<array<string,mixed>> */
    private function audits(): array
    {
        return $this->rows('SELECT result, context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id', 'sss', [VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED, 'credential', (string) $this->esxiId]);
    }

    /** @return list<array<string,mixed>> */
    private function logs(int $jobId): array
    {
        return $this->rows('SELECT seq, stream, line FROM deploy_job_logs WHERE job_id = ? ORDER BY seq', 'i', [$jobId]);
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return array_values(repo_fetch_all($stmt->get_result()));
    }

    private function restoreSetting(string $key, ?array $old): void
    {
        if ($old === null) {
            repo_delete_setting($this->db, $key);
        } else {
            repo_set_setting($this->db, $key, (string) $old['setting_value']);
        }
    }
}
