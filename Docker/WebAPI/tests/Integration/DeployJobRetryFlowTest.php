<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';

/**
 * The repo half of the retry matrix: repo_retry_deploy_job() must turn the
 * unit-tested deploy_job_retry_plan() verdict into a real queued job. Matrix 7
 * (a partial job re-queues export for exactly its failed VMs) and Matrix 16
 * (a failed job whose stored outcome says success repeats export for the
 * original selection, never the full deploy).
 */
final class DeployJobRetryFlowTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $userId;
    private int $esxiCredentialId;
    private int $ansibleCredentialId;
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_retry_' . bin2hex(random_bytes(4));
        $name = $this->prefix . '_user';
        $password = password_hash('irrelevant', PASSWORD_DEFAULT);
        $email = $this->prefix . '@example.invalid';
        $stmt = $this->db->prepare("INSERT INTO deploy_users (name, password, email, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param('sss', $name, $password, $email);
        $stmt->execute();
        $this->userId = (int) $this->db->insert_id;

        $this->esxiCredentialId = $this->insertCredential('esxi');
        $this->ansibleCredentialId = $this->insertCredential('ansible');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
        foreach ([$this->esxiCredentialId, $this->ansibleCredentialId] as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
    }

    /** Matrix 7: retry of a partial job queues export for exactly the failed VMs. */
    public function testPartialJobRetriesExportForOnlyTheFailedVms(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('partial', 3);
        [$okA, $okB, $failed] = $vmIds;
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, $this->resultJson('partial', [$okA, $okB], [$failed]));

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame('export', $payload['mode'], 'a partial retry must never repeat create/powercycle');
        self::assertSame([$failed], $payload['vm_ids']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, $this->jobStatus($newJobId));
        self::assertStringContainsString('export-only: 1 failed VMs', $this->jobLog($newJobId));
    }

    /** Matrix 16: divergence (job failed, stored outcome success) repeats export for the original selection. */
    public function testDivergentFailedJobRetriesExportForTheOriginalSelection(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('diverge', 2);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, $this->resultJson('success', $vmIds, []));

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame('export', $payload['mode'], 'a committed import must never be redeployed from scratch');
        self::assertSame($vmIds, $payload['vm_ids']);
        self::assertStringContainsString('export-only: original selection', $this->jobLog($newJobId));
    }

    /**
     * The pre-existing branch: a plain failed job without a result re-queues its
     * old payload. Since Etappe 14B-F it also carries its create rows, and the
     * retry turns the confirmed one into a verify_skip unit instead of creating
     * that VM a second time.
     */
    public function testPlainFailedJobRequeuesTheOriginalPayload(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('plain', 2);
        $jobId = $this->insertTerminalJob(
            $missionId,
            VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            $vmIds,
            null,
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED]
        );

        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);

        $payload = $this->jobPayload($newJobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, $payload['mode']);
        // The WHOLE original selection, not the failed half: the pipeline after
        // the create section has to run for every VM it was asked for.
        self::assertSame($vmIds, $payload['vm_ids']);
        self::assertStringNotContainsString('export-only', $this->jobLog($newJobId));
        self::assertStringContainsString('create: 1 confirmed VM(s) to verify, 1 to create', $this->jobLog($newJobId));

        $rows = repo_deploy_create_results($this->db, $newJobId);
        self::assertSame(
            [VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP, VIRTUSPHERE_CREATE_ACTION_CREATE],
            array_map(static fn (array $row): string => (string) $row['action'], $rows)
        );
        // The skip points at the row that actually proved the VM, so the worker
        // can compare the live UUID against real evidence rather than a claim.
        self::assertNotNull($rows[0]['resumed_from_result_id']);
        self::assertNull($rows[1]['resumed_from_result_id']);
    }

    /**
     * Plan 10.4: a create-capable job from before this contract has no per-VM
     * evidence at all, so its retry is refused rather than invented. The
     * operator checks ESXi, adopts what is really theirs, and queues a fresh
     * job through the form.
     */
    public function testALegacyCreateJobWithoutResultRowsCannotBeRetried(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('legacy', 2);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, null);

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertFalse($evaluation['allowed']);
        self::assertContains('retry_create_results_missing', array_column($evaluation['blocking_findings'], 'code'));

        $this->expectException(DeployRetryBlockedException::class);
        repo_retry_deploy_job($this->db, $jobId, $this->userId);
    }

    /**
     * An unresolved unit of the source job blocks the retry entirely, whatever
     * the rest of the job looks like: its async create may still be alive on
     * the Ansible host.
     */
    public function testAnUnresolvedCreateUnitBlocksTheRetry(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('unresolved', 2);
        $jobId = $this->insertTerminalJob(
            $missionId,
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            $vmIds,
            null,
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN]
        );
        repo_execute(
            $this->db,
            'UPDATE deploy_create_vm_results SET error_code = ?, error_detail = ?, finished_at = UTC_TIMESTAMP()'
            . ' WHERE job_id = ? AND position = 2',
            'ssi',
            [VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT, 'fixture', $jobId]
        );

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertFalse($evaluation['allowed']);
        self::assertContains('retry_create_unresolved', array_column($evaluation['blocking_findings'], 'code'));
    }

    public function testRepairedNetworkPreflightFailureIsRetryableWithoutMacProtocolError(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('preflight', 1);
        $result = vm_network_preflight_result_contract(
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            [['id' => $vmIds[0], 'vm_name' => 'blocked-at-worker']],
            [[
                'code' => VIRTUSPHERE_VM_NETWORK_AMBIGUOUS,
                'vm_id' => $vmIds[0],
                'vlan' => 'WDS',
                'interface_ids' => [11, 12],
            ]]
        );
        $jobId = $this->insertTerminalJob(
            $missionId,
            VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            VIRTUSPHERE_DEPLOY_MODE_FULL,
            $vmIds,
            json_encode($result, JSON_THROW_ON_ERROR),
            [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED]
        );

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertTrue($evaluation['allowed']);
        self::assertNotContains('retry_result_protocol_error', array_column($evaluation['findings'], 'code'));
        $newJobId = repo_retry_deploy_job($this->db, $jobId, $this->userId);
        self::assertSame(VIRTUSPHERE_DEPLOY_MODE_FULL, $this->jobPayload($newJobId)['mode']);
    }

    public function testRetryReportsNetworkFindingsEvenWhenRemoteRecoveryAlreadyBlocks(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('precedence', 1);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, null);
        repo_execute($this->db, 'UPDATE deploy_jobs SET recovery_requested_at = NOW() WHERE id = ?', 'i', [$jobId]);
        repo_execute($this->db, "UPDATE deploy_interfaces SET vlan = '' WHERE vm_id = ?", 'i', [$vmIds[0]]);

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        $kinds = array_column($evaluation['findings'], 'kind');
        self::assertContains('remote', $kinds);
        self::assertContains('network', $kinds);
        self::assertLessThan(array_search('network', $kinds, true), array_search('remote', $kinds, true));
    }

    public function testRetryFindingKindsKeepRemoteIdentityNetworkExternalPrecedence(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('all_precedence', 1);
        $vmId = $vmIds[0];
        $result = json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'failed',
            'successful_vm_ids' => [],
            'failed_vm_ids' => [$vmId],
            'errors' => [
                ['code' => 'future_protocol_break', 'vm_id' => $vmId],
                ['code' => VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND, 'vm_id' => $vmId],
            ],
            'counts' => ['expected_vms' => 1, 'successful_vms' => 0, 'failed_vms' => 1, 'updated_interfaces' => 0],
            'retry' => ['mode' => 'export', 'vm_ids' => [$vmId]],
        ], JSON_THROW_ON_ERROR);
        $jobId = $this->insertTerminalJob($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, $result);
        repo_execute($this->db, 'UPDATE deploy_jobs SET recovery_requested_at = NOW() WHERE id = ?', 'i', [$jobId]);

        $token = substr(hash('sha256', $this->prefix . ':' . $jobId), 0, 32);
        $unit = 'virtusphere-retry-' . $jobId . '.service';
        $remoteDir = '/tmp/virtusphere-retry-' . $jobId;
        $step = 'export';
        $controller = 'prepared';
        $effect = 'active_or_possible';
        $reconciliation = 'pending';
        $cleanup = 'pending';
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_remote_executions '
            . '(job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir, instance_id, generation_id, controller_state, effect_state, reconciliation_state, cleanup_state) '
            . 'VALUES (?, 1, ?, 1, ?, ?, ?, RANDOM_BYTES(16), RANDOM_BYTES(16), ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssssss', $jobId, $step, $token, $unit, $remoteDir, $controller, $effect, $reconciliation, $cleanup);
        $stmt->execute();

        $vmName = (string) repo_scalar($this->db, 'SELECT vm_name FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        $kind = VIRTUSPHERE_INVENTORY_KIND_VM;
        $meta = json_encode(['moid' => 'vm-foreign', 'instance_uuid' => 'foreign-instance'], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_esxi_inventory (credential_id, kind, name, meta_json) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $this->esxiCredentialId, $kind, $vmName, $meta);
        $stmt->execute();
        repo_execute($this->db, "UPDATE deploy_interfaces SET vlan = '' WHERE vm_id = ?", 'i', [$vmId]);

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        $kindTransitions = [];
        foreach (array_column($evaluation['findings'], 'kind') as $findingKind) {
            if ($kindTransitions === [] || end($kindTransitions) !== $findingKind) {
                $kindTransitions[] = $findingKind;
            }
        }
        // `create` sits between remote and identity since Etappe 14B-F, and the
        // position is the meaning: it answers whether work of the SOURCE job may
        // still be running, which has to be settled before anything about the
        // VM's identity or its network is worth deciding.
        self::assertSame(['remote', 'create', 'identity', 'network', 'external'], $kindTransitions);
        self::assertContains('retry_result_protocol_error', array_column($evaluation['findings'], 'code'));
    }

    public function testActiveAndSucceededJobsCannotBeRetried(): void
    {
        [$missionId, $vmIds] = $this->insertMissionWithVms('guard', 1);
        foreach ([VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED] as $status) {
            $jobId = $this->insertTerminalJob($missionId, $status, VIRTUSPHERE_DEPLOY_MODE_FULL, $vmIds, null);
            try {
                repo_retry_deploy_job($this->db, $jobId, $this->userId);
                self::fail($status . ' must not be retryable');
            } catch (DeployRetryBlockedException $exception) {
                self::assertSame('retry_job_not_terminal', $exception->evaluation['blocking_findings'][0]['code']);
            }
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
        }
    }

    private function insertCredential(string $type): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.phpunit.invalid';
        $user = 'svc';
        $secret = 'phpunit-ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $type, $name, $host, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @return array{0:int,1:list<int>} */
    private function insertMissionWithVms(string $suffix, int $vmCount): array
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $datacenter = 'DC1';
        $datastore = 'datastore1';
        $wds = 'WDS';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $name, $status, $datacenter, $datastore, $wds);
        $stmt->execute();
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;

        $vmIds = [];
        for ($i = 0; $i < $vmCount; $i++) {
            $vmName = strtoupper($name . '_VM' . $i);
            $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $missionId, $vmName, $vmName);
            $stmt->execute();
            $vmId = (int) $this->db->insert_id;
            $vmIds[] = $vmId;
            $vlan = 'WDS';
            $empty = '';
            $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $empty);
            $stmt->execute();
        }

        return [$missionId, $vmIds];
    }

    /** @param list<int> $vmIds */
    /**
     * A terminal job as the queue would have left it.
     *
     * `$createRows` decides which world the fixture is from. A create-capable
     * job of the CURRENT world carries one materialized result per VM; a job
     * without them is a legacy job queued before Etappe 14B, and its retry is
     * refused fail-closed (plan 10.4). Both cases are real, and the tests below
     * say which one they mean instead of getting one by accident.
     *
     * @param list<int> $vmIds
     * @param list<string> $createRows One create status per VM, or [] for a
     *        legacy job / a mode that creates nothing.
     */
    private function insertTerminalJob(int $missionId, string $status, string $mode, array $vmIds, ?string $resultJson, array $createRows = []): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, result_json, credential_esxi_id, credential_ansible_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisssii', $missionId, $this->userId, $status, $payload, $resultJson, $this->esxiCredentialId, $this->ansibleCredentialId);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;

        $position = 0;
        foreach ($createRows as $index => $createStatus) {
            $position++;
            $terminal = in_array($createStatus, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true);
            $stmt = $this->db->prepare(
                'INSERT INTO deploy_create_vm_results (job_id, vm_id, vm_name, position, total, action, status,'
                . ' outcome, changed, existed_before, vm_moid, vm_instance_uuid, error_code, error_detail, finished_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $vmId = $vmIds[$index];
            $vmName = (string) repo_scalar($this->db, 'SELECT vm_name FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
            $total = count($createRows);
            $action = VIRTUSPHERE_CREATE_ACTION_CREATE;
            $outcome = $terminal ? VIRTUSPHERE_CREATE_OUTCOME_CREATED : null;
            $changed = $terminal ? 1 : null;
            $existed = $terminal ? 0 : null;
            $moid = $terminal ? 'vm-' . $position : null;
            $uuid = $terminal ? '5001-' . $position : null;
            // An unresolved row needs the same three fields as a failed one: the
            // schema refuses a row that ends without naming its code, its
            // reason and when it stopped.
            $failed = in_array(
                $createStatus,
                [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN],
                true
            );
            $errorCode = $failed ? VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED : null;
            $errorDetail = $failed ? 'fixture' : null;
            $finishedAt = $terminal || $failed ? gmdate('Y-m-d H:i:s') : null;
            $stmt->bind_param(
                'iisiisssiisssss',
                $jobId,
                $vmId,
                $vmName,
                $position,
                $total,
                $action,
                $createStatus,
                $outcome,
                $changed,
                $existed,
                $moid,
                $uuid,
                $errorCode,
                $errorDetail,
                $finishedAt
            );
            $stmt->execute();
        }

        return $jobId;
    }

    /** @param list<int> $successful @param list<int> $failed */
    private function resultJson(string $outcome, array $successful, array $failed): string
    {
        return json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => $outcome,
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'errors' => [],
            'counts' => [
                'expected_vms' => count($successful) + count($failed),
                'successful_vms' => count($successful),
                'failed_vms' => count($failed),
                'updated_interfaces' => count($successful),
            ],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function jobPayload(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function jobStatus(int $jobId): string
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return (string) $job['status'];
    }

    private function jobLog(int $jobId): string
    {
        $stmt = $this->db->prepare('SELECT line FROM deploy_job_logs WHERE job_id = ? ORDER BY id');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return implode("\n", array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'line'));
    }
}
