<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';

/**
 * MA-08 on the wire: a refused VM touch answers the unchanged 409 with the closed
 * `cause` added, and leaves one throttled audit row with the same cause. Only the
 * causes the existing fixtures reach are exercised here; the rest are pinned by
 * DeployVmTouchCauseContractTest and the boolean wrapper stays under the K15 tests.
 */
final class DeployVmTouchRefusalTest extends TestCase
{
    use ClientIpAllowlist;

    private const EVENT = 'machine_api.touch_refused';

    private mysqli $db;
    private string $prefix;
    /** @var list<int> */
    private array $missionIds = [];
    /** @var list<int> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        if (@file_get_contents(virtusphere_test_base_url() . '/portal/health.php') === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_touch_' . bin2hex(random_bytes(4));
        $this->ensureClientIpAllowlisted($this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->jobIds as $jobId) {
            $object = (string) $jobId;
            $type = 'deploy_job';
            $event = self::EVENT;
            $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?');
            $stmt->bind_param('sss', $event, $type, $object);
            $stmt->execute();
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
        $this->restoreClientIpAllowlistIfTouched();
    }

    /** @return iterable<string, array{0: string, 1: int, 2: string, 3: string}> phase, attempt, job kind, cause */
    public static function refusals(): iterable
    {
        // job kind: running | terminal | unknown. The attempt 99 never matches a fixture job.
        yield 'no such job' => ['export', 1, 'unknown', 'job_unknown'];
        yield 'terminal job' => ['export', 1, 'terminal', 'job_not_active'];
        yield 'stale attempt' => ['export', 99, 'running', 'attempt_stale'];
        yield 'phase outside the playbook sequence' => ['create', 1, 'running', 'phase_mismatch'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalAnswersItsCauseAndWritesOneAuditRow(string $phase, int $attempt, string $kind, string $cause): void
    {
        $missionId = $this->insertMission('case-' . $cause);
        $vmId = $this->insertVm($missionId, $cause);
        if ($kind === 'unknown') {
            $jobId = random_int(1900000000, 2100000000);
        } else {
            $jobId = $this->insertJob($missionId, [$vmId], $kind === 'terminal' ? VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED : VIRTUSPHERE_DEPLOY_STATUS_RUNNING);
        }
        $this->jobIds[] = $jobId;

        [$status, $body] = $this->post([
            'job_id' => $jobId,
            'vm_id' => $vmId,
            'attempt' => $attempt,
            'generation' => bin2hex(random_bytes(16)),
            'phase' => $phase,
        ]);

        self::assertSame(409, $status, $body);
        self::assertSame(
            ['error' => 'Deploy job does not admit this VM step', 'reason_code' => 'touch_refused', 'cause' => $cause],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            'the closed cause is additive; the rest of the 409 body is unchanged'
        );
        self::assertSame([$cause], $this->auditReasons($jobId), 'one refusal, one audit row with the same cause');
    }

    /** @param array<string, mixed> $payload @return array{0:int,1:string} */
    private function post(array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/deploy_vm_touch.php', false, $context);
        if ($body === false) {
            self::markTestSkipped('VM touch endpoint is not reachable.');
        }

        $status = 0;
        foreach (http_get_last_response_headers() ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, (string) $body];
    }

    /** @return list<string> the reason_code of every rejection row for this job, newest first */
    private function auditReasons(int $jobId): array
    {
        $event = self::EVENT;
        $type = 'deploy_job';
        $object = (string) $jobId;
        $stmt = $this->db->prepare('SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id DESC');
        $stmt->bind_param('sss', $event, $type, $object);
        $stmt->execute();
        $reasons = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $context = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
            $reasons[] = (string) ($context['reason_code'] ?? '');
        }

        return $reasons;
    }

    private function insertMission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;

        return $id;
    }

    private function insertVm(int $missionId, string $suffix): int
    {
        $name = strtoupper($this->prefix . '_' . $suffix);
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $missionId, $name, $name);
        $stmt->execute();
        // Every refusal in the provider is decided before the journal row of this VM
        // is read, so the VM needs no journal state to be refused with its cause.
        return (int) $this->db->insert_id;
    }

    /** @param list<int> $vmIds */
    private function insertJob(int $missionId, array $vmIds, string $status): int
    {
        // Same shape as MacImportCallbackTest::insertJob: a fresh heartbeat keeps the
        // maintenance worker from reaping the fixture mid-test.
        $payload = json_encode(['mode' => 'export', 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, payload_json, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1'
        );
        $stmt->bind_param('isss', $missionId, $status, $payload, $contract);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }
}
