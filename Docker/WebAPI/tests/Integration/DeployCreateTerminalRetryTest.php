<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_retry_confirmation.php';

/** SC-002: the actual worker terminal summary must survive the retry boundary. */
final class DeployCreateTerminalRetryTest extends TestCase
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
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'create-retry-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        $this->esxiId = $this->credential('esxi');
        $this->ansibleId = $this->credential('ansible');
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$this->prefix]);
        $this->missionId = (int) $this->db->insert_id;
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
    }

    public static function createModes(): array
    {
        return [['create'], ['full']];
    }

    #[DataProvider('createModes')]
    public function testRealWorkerPartialConclusionRetriesOriginalModeAndMaterializedScope(string $mode): void
    {
        [$jobId, $ids] = $this->partialCreate($mode);
        $source = repo_deploy_create_results($this->db, $jobId);
        $extra = $this->vm('new-after-source');
        // Input order is not identity: a historical payload with the same
        // explicit members in reverse order still describes these exact units.
        $payload = $this->payload($jobId);
        $payload['vm_ids'] = array_reverse($ids);
        $this->writePayload($jobId, $payload);

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertTrue($evaluation['allowed'], json_encode($evaluation['blocking_findings']));
        self::assertSame('create_units', $evaluation['plan']['scope']);
        self::assertSame($mode, $evaluation['effective_mode']);
        self::assertSame($ids, $evaluation['scope_vm_ids']);
        self::assertSame(__t('deploy.confirm_retry_create', ['name' => $this->prefix]), deploy_retry_confirmation($evaluation, $this->prefix));

        $retryId = repo_retry_deploy_job($this->db, $jobId, $this->userId);
        $retried = $this->payload($retryId);
        self::assertSame($mode, $retried['mode']);
        self::assertSame($evaluation['scope_vm_ids'], $retried['vm_ids']);
        self::assertNotContains($extra, $retried['vm_ids']);
        self::assertTrue($retried['verbose']);
        self::assertSame($payload['powercycle_wait'], $retried['powercycle_wait']);
        self::assertSame($payload['start_wait'], $retried['start_wait']);
        $rows = repo_deploy_create_results($this->db, $retryId);
        self::assertSame(['verify_skip', 'create', 'create'], array_column($rows, 'action'));
        self::assertSame(['pending', 'pending', 'pending'], array_column($rows, 'status'));
        self::assertSame(array_column($source, 'vm_name'), array_column($rows, 'vm_name'));
        self::assertSame((int) $source[0]['id'], (int) $rows[0]['resumed_from_result_id']);
        self::assertNull($rows[1]['resumed_from_result_id']);
        self::assertNull($rows[2]['resumed_from_result_id']);
        self::assertSame($source, repo_deploy_create_results($this->db, $jobId), 'Retry preserves the original evidence.');
        $log = (string) repo_scalar($this->db, "SELECT line FROM deploy_job_logs WHERE job_id = ? AND line LIKE 'Retry of deploy job %' ORDER BY id DESC LIMIT 1", 'i', [$retryId]);
        self::assertStringContainsString('1 confirmed VM(s) to verify, 2 to create', $log);
        self::assertStringNotContainsString('export-only', $log);
    }

    public static function inflightStates(): array
    {
        return array_map(static fn (string $state): array => [$state], VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES);
    }

    public function testMacPartialConclusionStillRetriesOnlyFailedVmIdsAsExport(): void
    {
        $ids = [$this->vm('MAC-A'), $this->vm('MAC-B'), $this->vm('MAC-C')];
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'export', 'vm_ids' => $ids]);
        $worker = $this->prefix . '-worker';
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($jobId, (int) $job['id']);
        $result = json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'partial',
            'successful_vm_ids' => [$ids[0], $ids[1]],
            'failed_vm_ids' => [$ids[2]],
            'errors' => [],
            'counts' => ['expected_vms' => 3, 'successful_vms' => 2, 'failed_vms' => 1, 'updated_interfaces' => 2],
            'retry' => ['mode' => 'export', 'vm_ids' => [$ids[2]]],
        ], JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'UPDATE deploy_jobs SET result_json = ? WHERE id = ?', 'si', [$result, $jobId]);
        deploy_worker_conclude_sequence($this->db, $job, $worker, $ids);
        self::assertSame('partial', repo_deploy_job($this->db, $jobId)['status']);
        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertTrue($evaluation['allowed']);
        self::assertSame('failed_vms', $evaluation['plan']['scope']);
        self::assertSame(__t('deploy.confirm_retry_partial_one', ['name' => $this->prefix]), deploy_retry_confirmation($evaluation, $this->prefix));
        $retryId = repo_retry_deploy_job($this->db, $jobId, $this->userId);
        self::assertSame('export', $this->payload($retryId)['mode']);
        self::assertSame([$ids[2]], $this->payload($retryId)['vm_ids']);
        self::assertSame([], repo_deploy_create_results($this->db, $retryId));
    }

    #[DataProvider('inflightStates')]
    public function testGenericPartialSummaryNeverReleasesHistoricalUncertainty(string $state): void
    {
        [$jobId] = $this->partialCreate('create');
        $finished = $state === 'uncertain' ? gmdate('Y-m-d H:i:s') : null;
        repo_execute($this->db, 'UPDATE deploy_create_vm_results SET status = ?, existed_before = 0, async_jid = ?, async_deadline_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), finished_at = ? WHERE job_id = ? AND position = 2', 'sssi', [$state, '123456789.2', $finished, $jobId]);
        $evaluation = $this->assertBlocked($jobId, 'retry_create_unresolved');
        self::assertNotContains('retry_result_protocol_error', array_column($evaluation['blocking_findings'], 'code'));
    }

    public static function invalidEvidence(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'missing result', 'unknown version', 'string version', 'unknown kind',
            'wrong outcome', 'extra result field', 'missing rows', 'incomplete rows',
            'deleted selected vm', 'empty scope', 'incomplete scope', 'other mode', 'other job status',
        ]);
    }

    #[DataProvider('invalidEvidence')]
    public function testTerminalSummaryCannotAuthorizeUnknownOrIncompleteCreateEvidence(string $case): void
    {
        [$jobId, $ids] = $this->partialCreate('full');
        $result = ['version' => 1, 'kind' => 'deploy_job', 'outcome' => 'partial'];
        switch ($case) {
            case 'unknown version': $result['version'] = 2; break;
            case 'string version': $result['version'] = '1'; break;
            case 'unknown kind': $result['kind'] = 'unknown'; break;
            case 'wrong outcome': $result['outcome'] = 'succeeded'; break;
            case 'extra result field': $result['vm_ids'] = $ids; break;
            case 'missing rows': repo_execute($this->db, 'DELETE FROM deploy_create_vm_results WHERE job_id = ?', 'i', [$jobId]); break;
            case 'incomplete rows': repo_execute($this->db, 'DELETE FROM deploy_create_vm_results WHERE job_id = ? AND position = 3', 'i', [$jobId]); break;
            case 'deleted selected vm': repo_execute($this->db, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$ids[2]]); break;
            case 'empty scope':
            case 'incomplete scope':
            case 'other mode':
                $payload = $this->payload($jobId);
                if ($case === 'other mode') {
                    $payload['mode'] = 'export';
                } else {
                    $payload['vm_ids'] = $case === 'empty scope' ? [] : [$ids[0]];
                }
                $this->writePayload($jobId, $payload);
                break;
            case 'other job status': repo_execute($this->db, "UPDATE deploy_jobs SET status = 'failed', terminal_reason_code = 'execution_failed' WHERE id = ?", 'i', [$jobId]); break;
        }
        $json = $case === 'missing result' ? null : json_encode($result, JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'UPDATE deploy_jobs SET result_json = ? WHERE id = ?', 'si', [$json, $jobId]);
        $this->assertBlocked($jobId, 'retry_result_protocol_error');
    }

    private function assertBlocked(int $jobId, string $code): array
    {
        $count = (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]);
        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertFalse($evaluation['allowed']);
        self::assertContains($code, array_column($evaluation['blocking_findings'], 'code'));
        try {
            repo_retry_deploy_job($this->db, $jobId, $this->userId);
            self::fail('A blocked result created a new retry.');
        } catch (DeployRetryBlockedException $exception) {
            self::assertContains($code, array_column($exception->evaluation['blocking_findings'], 'code'));
        }
        self::assertSame($count, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]));
        return $evaluation;
    }

    private function partialCreate(string $mode): array
    {
        $ids = [$this->vm('C'), $this->vm('B'), $this->vm('A')];
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => $mode, 'vm_ids' => $ids, 'verbose' => true, 'powercycle_wait' => 45, 'start_wait' => 30]);
        $worker = $this->prefix . '-worker';
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($jobId, (int) $job['id']);
        $fence = deploy_worker_job_fence($this->db, $jobId, $worker, $job);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', ['async_jid' => '123456789.1', 'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $fence));
        self::assertTrue(repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-' . $jobId, $this->prefix . '-uuid', $fence)['committed']);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 2, 'pending', 'failed', ['error_code' => 'module_failed', 'error_detail' => 'Synthetic confirmed failure before launch.'], $fence));
        $ids = deploy_create_retry_vm_ids(repo_deploy_create_results($this->db, $jobId));
        deploy_worker_conclude_create_section($this->db, $job, $worker, $ids, [], ['all_successful' => false, 'summary' => repo_deploy_create_summary($this->db, $jobId), 'stop_reason' => null]);
        $stored = repo_deploy_job($this->db, $jobId);
        self::assertSame('partial', $stored['status']);
        self::assertSame(['outcome' => 'partial'], deploy_job_decode_terminal_result($stored['result_json']));
        self::assertNull(mac_import_decode_result($stored['result_json']));
        return [$jobId, $ids];
    }

    private function payload(int $jobId): array
    {
        return json_decode((string) repo_scalar($this->db, 'SELECT payload_json FROM deploy_jobs WHERE id = ?', 'i', [$jobId]), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writePayload(int $jobId, array $payload): void
    {
        repo_execute($this->db, 'UPDATE deploy_jobs SET payload_json = ? WHERE id = ?', 'si', [json_encode($payload, JSON_THROW_ON_ERROR), $jobId]);
    }

    private function vm(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname) VALUES (?,?,?)', 'iss', [$this->missionId, $name, $name]);
        $id = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_interfaces (vm_id,ip,subnet,gateway,vlan,mac) VALUES (?,'','','','WDS','')", 'i', [$id]);
        return $id;
    }

    private function credential(string $type): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $this->prefix . '_' . $type, $this->prefix . '.' . $type . '.invalid', 'fixture', 'unused-ciphertext']);
        return (int) $this->db->insert_id;
    }
}
