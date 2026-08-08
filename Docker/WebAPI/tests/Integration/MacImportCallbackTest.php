<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';

final class MacImportCallbackTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private ?int $temporaryAccessId = null;
    /** @var list<int> */
    private array $missionIds = [];

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

        $this->prefix = 'phpunit_mac_' . bin2hex(random_bytes(4));
        [$status, $body] = $this->post([
            'mission_id' => 2147483647,
            'results' => [['failed' => true, 'item' => ['vm_name' => $this->prefix . '_probe']]],
        ]);
        if ($status === 403) {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (preg_match('/Ihre IP: (\S+)$/', (string) ($response['error'] ?? ''), $match) !== 1) {
                self::markTestSkipped('Could not discover the integration-test client IP.');
            }
            $description = $this->prefix . ' temporary callback access';
            $stmt = $this->db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description) VALUES (?, ?)');
            $stmt->bind_param('ss', $match[1], $description);
            $stmt->execute();
            $this->temporaryAccessId = (int) $this->db->insert_id;
        }
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
        if ($this->temporaryAccessId !== null) {
            $stmt = $this->db->prepare('DELETE FROM deploy_accessToWebAPI WHERE id = ?');
            $stmt->bind_param('i', $this->temporaryAccessId);
            $stmt->execute();
        }
    }

    public function testPartialCallbackWritesOnlyFullyValidVmsAndPersistsTheResult(): void
    {
        $missionId = $this->insertMission('partial');
        $vmA = $this->insertVm($missionId, 'A');
        $vmB = $this->insertVm($missionId, 'B');
        $vmC = $this->insertVm($missionId, 'C');
        $this->insertInterface($vmA, 'WDS');
        $this->insertInterface($vmA, 'APP');
        $this->insertInterface($vmB, 'WDS');
        $this->insertInterface($vmC, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmA, $vmB, $vmC]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [
                ['instance' => [
                    'hw_name' => $this->vmName('A'),
                    'hw_eth0' => ['macaddress' => $this->mac('a0'), 'summary' => 'WDS'],
                    'hw_eth1' => ['macaddress' => '', 'summary' => 'APP'],
                    'hw_eth2' => ['macaddress' => $this->mac('a2'), 'summary' => 'WDS'],
                ]],
                ['instance' => [
                    'hw_name' => $this->vmName('B'),
                    'hw_eth0' => ['macaddress' => $this->mac('b0'), 'summary' => 'wds'],
                ]],
                ['failed' => true, 'item' => ['vm_name' => $this->vmName('C')], 'msg' => 'must not be persisted'],
            ],
        ]);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($response['success']);
        self::assertSame('partial', $response['outcome']);
        self::assertSame(1, $response['updated_interfaces']);
        self::assertSame(1, $response['updated_vms']);
        self::assertSame(['expected_vms' => 3, 'successful_vms' => 1, 'failed_vms' => 2, 'updated_interfaces' => 1], $response['counts']);

        $vmResults = array_column($response['vm_results'], null, 'vm_name');
        self::assertContains('missing_nic_data', $vmResults[$this->vmName('A')]['error_codes']);
        self::assertSame('success', $vmResults[$this->vmName('B')]['outcome']);
        self::assertContains('esxi_query_failed', $vmResults[$this->vmName('C')]['error_codes']);

        self::assertSame('', $this->interfaceMac($vmA, 'WDS'), 'one bad NIC must discard every write for that VM');
        self::assertContains('ambiguous_vlan', $vmResults[$this->vmName('A')]['error_codes']);
        self::assertSame(virtusphere_normalize_mac($this->mac('b0')), $this->interfaceMac($vmB, 'WDS'));
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($vmA));
        self::assertSame(['deployed', 'pending', 1], $this->vmState($vmB));

        $result = $this->jobResult($jobId);
        self::assertSame(1, $result['version']);
        self::assertSame('mac_import', $result['kind']);
        self::assertSame('partial', $result['outcome']);
        self::assertSame([$vmB], $result['successful_vm_ids']);
        self::assertSame([$vmA, $vmC], $result['failed_vm_ids']);
        self::assertStringNotContainsString('must not be persisted', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * Matrix 3 + 5: when no VM succeeds the outcome is `failed`, and every
     * rejection class carries its fixed error code - no input row disappears
     * silently and no write leaks through.
     */
    public function testWhollyFailedImportReportsAFixedCodePerRejectionClass(): void
    {
        $missionId = $this->insertMission('codes');
        $noInterface = $this->insertVm($missionId, 'NOIFACE');
        $this->insertInterface($noInterface, 'APP');
        $badMac = $this->insertVm($missionId, 'BADMAC');
        $this->insertInterface($badMac, 'WDS');
        $outOfScope = $this->insertVm($missionId, 'OUTOFSCOPE');
        $this->insertInterface($outOfScope, 'WDS');
        $jobId = $this->insertJob($missionId, [$noInterface, $badMac]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [
                ['instance' => ['hw_name' => $this->vmName('NOIFACE'), 'hw_eth0' => ['macaddress' => $this->mac('n0'), 'summary' => 'WDS']]],
                ['instance' => ['hw_name' => $this->vmName('BADMAC'), 'hw_eth0' => ['macaddress' => 'ZZ:99:XX:00:11:22', 'summary' => 'WDS']]],
                ['instance' => ['hw_name' => $this->vmName('OUTOFSCOPE'), 'hw_eth0' => ['macaddress' => $this->mac('o0'), 'summary' => 'WDS']]],
                ['instance' => ['hw_name' => $this->vmName('GHOST'), 'hw_eth0' => ['macaddress' => $this->mac('g0'), 'summary' => 'WDS']]],
                ['instance' => ['hw_eth0' => ['macaddress' => $this->mac('x0'), 'summary' => 'WDS']]],
            ],
        ]);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($response['success']);
        self::assertSame('failed', $response['outcome']);
        self::assertSame(0, $response['updated_vms']);
        self::assertSame(0, $response['updated_interfaces']);
        self::assertSame([$this->vmName('GHOST')], $response['missing_vms']);

        $vmResults = array_column($response['vm_results'], null, 'vm_name');
        self::assertSame(['interface_not_found'], $vmResults[$this->vmName('NOIFACE')]['error_codes']);
        self::assertSame(['invalid_mac'], $vmResults[$this->vmName('BADMAC')]['error_codes']);
        self::assertSame(['vm_not_in_job_scope'], $vmResults[$this->vmName('OUTOFSCOPE')]['error_codes']);
        self::assertSame(['vm_not_in_mission'], $vmResults[$this->vmName('GHOST')]['error_codes']);
        self::assertSame(['missing_name'], $vmResults['']['error_codes'], 'a nameless row must not disappear');

        self::assertSame('', $this->interfaceMac($noInterface, 'APP'));
        self::assertSame('', $this->interfaceMac($badMac, 'WDS'));
        self::assertSame('', $this->interfaceMac($outOfScope, 'WDS'), 'an out-of-scope row must never be written');
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($noInterface));
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($badMac));
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($outOfScope));

        $result = $this->jobResult($jobId);
        self::assertSame('failed', $result['outcome']);
        self::assertSame([], $result['successful_vm_ids']);
        self::assertSame([$noInterface, $badMac], $result['failed_vm_ids'], 'the failed set is the job scope, not the input rows');
        self::assertSame([$noInterface, $badMac], $result['retry']['vm_ids']);
    }

    public function testRunningJobCallbackIsIdempotentIncludingStatusHistory(): void
    {
        $missionId = $this->insertMission('idempotent');
        $vmId = $this->insertVm($missionId, 'ONE');
        $this->insertInterface($vmId, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmId]);
        $payload = [
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('ONE'),
                'hw_eth0' => ['macaddress' => $this->mac('one'), 'summary' => 'WDS'],
            ]]],
        ];

        [$firstStatus, $firstBody] = $this->post($payload);
        [$secondStatus, $secondBody] = $this->post($payload);
        self::assertSame(200, $firstStatus, $firstBody);
        self::assertSame(200, $secondStatus, $secondBody);
        self::assertSame('success', json_decode($secondBody, true, 512, JSON_THROW_ON_ERROR)['outcome']);
        self::assertSame(1, $this->statusEventCount($vmId, 'ansible mac import'));
        self::assertSame('success', $this->jobResult($jobId)['outcome']);
    }

    public function testExistingMacOwnerWinsAConflictingResult(): void
    {
        $missionId = $this->insertMission('duplicate');
        $ownerVm = $this->insertVm($missionId, 'OWNER');
        $contenderVm = $this->insertVm($missionId, 'CONTENDER');
        $sharedMac = $this->mac('shared');
        $this->insertInterface($ownerVm, 'WDS', $sharedMac);
        $this->insertInterface($contenderVm, 'WDS');
        $jobId = $this->insertJob($missionId, [$ownerVm, $contenderVm]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [
                ['instance' => ['hw_name' => $this->vmName('OWNER'), 'hw_eth0' => ['macaddress' => $sharedMac, 'summary' => 'WDS']]],
                ['instance' => ['hw_name' => $this->vmName('CONTENDER'), 'hw_eth0' => ['macaddress' => $sharedMac, 'summary' => 'WDS']]],
            ],
        ]);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $result = $this->jobResult($jobId);
        self::assertSame('partial', $response['outcome']);
        self::assertSame(1, $response['updated_vms']);
        self::assertNotEmpty($response['duplicate_macs']);
        self::assertSame([$ownerVm], $result['successful_vm_ids']);
        self::assertSame([$contenderVm], $result['failed_vm_ids']);
        self::assertSame($sharedMac, $this->interfaceMac($ownerVm, 'WDS'));
        self::assertSame('', $this->interfaceMac($contenderVm, 'WDS'));
    }

    public function testForeignAndTerminalJobsReturn409WithoutWrites(): void
    {
        $missionId = $this->insertMission('target');
        $foreignMissionId = $this->insertMission('foreign');
        $vmId = $this->insertVm($missionId, 'TARGET');
        $this->insertInterface($vmId, 'WDS');
        $foreignJob = $this->insertJob($foreignMissionId, []);
        $terminalJob = $this->insertJob($missionId, [$vmId], VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        $result = [['instance' => [
            'hw_name' => $this->vmName('TARGET'),
            'hw_eth0' => ['macaddress' => $this->mac('target'), 'summary' => 'WDS'],
        ]]];

        [$foreignStatus] = $this->post(['mission_id' => $missionId, 'job_id' => $foreignJob, 'results' => $result]);
        [$terminalStatus] = $this->post(['mission_id' => $missionId, 'job_id' => $terminalJob, 'results' => $result]);
        self::assertSame(409, $foreignStatus);
        self::assertSame(409, $terminalStatus);
        self::assertSame('', $this->interfaceMac($vmId, 'WDS'));
        self::assertNull($this->rawJobResult($foreignJob));
        self::assertNull($this->rawJobResult($terminalJob));
        self::assertSame(0, $this->statusEventCount($vmId, 'ansible mac import'));
    }

    /**
     * ADR-0033: the callback window follows the machine, not the wish. A
     * cancel of a running job now parks it in `cancelling` while the playbook
     * finishes its current step - and that step's own MAC upload is exactly
     * the callback arriving here. Bouncing it with 409 threw away addresses a
     * sequence had really assigned (B4).
     */
    public function testCallbackIsAcceptedWhileTheJobIsCancelling(): void
    {
        $missionId = $this->insertMission('cancelling');
        $vmId = $this->insertVm($missionId, 'CANCELLING');
        $this->insertInterface($vmId, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmId], VIRTUSPHERE_DEPLOY_STATUS_CANCELLING);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('CANCELLING'),
                'hw_eth0' => ['macaddress' => $this->mac('cancelling'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('success', $response['outcome']);
        self::assertSame($this->mac('cancelling'), $this->interfaceMac($vmId, 'WDS'));
        self::assertNotNull($this->rawJobResult($jobId), 'the durable result belongs to the sequence that produced it');
        self::assertSame(['deployed', 'pending', 1], $this->vmState($vmId), 'an imported VM is really deployed, cancel or not');
    }

    /**
     * Only the CONFIRMED end state refuses, and the refusal is findable: the
     * old 409 left its trace in error_log only, so an operator staring at the
     * job log saw MACs vanish without a sentence anywhere in the portal.
     */
    public function testACancelledJobReturns409AndLeavesAJobLogTrace(): void
    {
        $missionId = $this->insertMission('cancelled');
        $vmId = $this->insertVm($missionId, 'CANCELLED');
        $this->insertInterface($vmId, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmId], VIRTUSPHERE_DEPLOY_STATUS_CANCELLED);

        [$status] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('CANCELLED'),
                'hw_eth0' => ['macaddress' => $this->mac('cancelled'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(409, $status);
        self::assertSame('', $this->interfaceMac($vmId, 'WDS'));
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_job_logs WHERE job_id = ? AND line LIKE ?');
        $needle = '%MAC callback%';
        $stmt->bind_param('is', $jobId, $needle);
        $stmt->execute();
        self::assertGreaterThan(0, (int) $stmt->get_result()->fetch_assoc()['c'], 'the rejection must be readable in the job log the operator actually opens');
    }

    public function testRequestWithoutJobIdIsRejectedWithoutAnyWrite(): void
    {
        // ADR-0035: the job_id-less callback fell with the desktop client. A
        // MAC import without a job scope answers 400 and must not touch a row,
        // because an unscoped write is exactly the surface the retirement
        // removed.
        $missionId = $this->insertMission('nojob');
        $submittedVm = $this->insertVm($missionId, 'SUBMITTED');
        $this->insertInterface($submittedVm, 'WDS');

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'results' => [['instance' => [
                'hw_name' => strtolower($this->vmName('SUBMITTED')),
                'hw_eth0' => ['macaddress' => $this->mac('nojob'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(400, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('job_id is required for MAC import payload', $response['error']);
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($submittedVm));
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
        $name = $this->vmName($suffix);
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $missionId, $name, $name);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertInterface(int $vmId, string $vlan, string $mac = ''): void
    {
        $empty = '';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $mac);
        $stmt->execute();
    }

    /** @param list<int> $vmIds */
    private function insertJob(int $missionId, array $vmIds, string $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING): int
    {
        // heartbeat_at = NOW(): a `running` job with a NULL heartbeat counts as
        // stale on sight, and the maintenance-worker CONTAINER of the dev stack
        // reaps it mid-test. The callback then answers 409 "job does not accept
        // this MAC import" for a reason that has nothing to do with the subject,
        // which is a flake and reads as a real regression. Same shape as
        // DeployWorkerOutcomeTest::insertJob.
        $payload = json_encode(['mode' => 'export', 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, heartbeat_at) VALUES (?, ?, ?, NOW())');
        $stmt->bind_param('iss', $missionId, $status, $payload);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function vmName(string $suffix): string
    {
        return strtoupper($this->prefix . '_' . $suffix);
    }

    private function mac(string $salt): string
    {
        $hex = '02' . strtoupper(substr(hash('sha256', $this->prefix . $salt), 0, 10));

        return implode(':', str_split($hex, 2));
    }

    private function interfaceMac(int $vmId, string $vlan): string
    {
        $stmt = $this->db->prepare('SELECT mac FROM deploy_interfaces WHERE vm_id = ? AND vlan = ? LIMIT 1');
        $stmt->bind_param('is', $vmId, $vlan);
        $stmt->execute();

        return (string) ($stmt->get_result()->fetch_assoc()['mac'] ?? '');
    }

    /** @return array{0:string,1:string,2:int} */
    private function vmState(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT lifecycle_state, mecm_sync_state, updated FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [(string) $row['lifecycle_state'], (string) $row['mecm_sync_state'], (int) $row['updated']];
    }

    private function rawJobResult(int $jobId): ?string
    {
        $stmt = $this->db->prepare('SELECT result_json FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $value = $stmt->get_result()->fetch_assoc()['result_json'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string,mixed> */
    private function jobResult(int $jobId): array
    {
        $result = $this->rawJobResult($jobId);
        self::assertNotNull($result);

        return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    }

    private function statusEventCount(int $vmId, string $note): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_vm_status_events WHERE vm_id = ? AND note = ?');
        $stmt->bind_param('is', $vmId, $note);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /**
     * ADR-0032, matrix point 7: a valid correlation id is echoed back, an
     * invalid one is ignored without changing the outcome, and the absent
     * field stays legacy-legal. The id must never be load-bearing.
     */
    public function testCorrelationIdIsEchoedWhenValidAndIgnoredWhenNot(): void
    {
        $missionId = $this->insertMission('corr');
        $vmA = $this->insertVm($missionId, 'A');
        $this->insertInterface($vmA, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmA]);
        $results = [
            ['instance' => [
                'hw_name' => $this->vmName('A'),
                'hw_eth0' => ['macaddress' => $this->mac('a0'), 'summary' => 'WDS'],
            ]],
        ];

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'correlation_id' => 'feedface00000020',
            'results' => $results,
        ]);
        self::assertSame(200, $status);
        $decoded = json_decode($body, true);
        self::assertSame('feedface00000020', $decoded['correlation_id'] ?? null, 'a valid id is echoed');
        self::assertSame('success', $decoded['outcome'] ?? null);

        // Invalid id: ignored, never a 4xx, outcome unchanged. The callback
        // does not terminate the job, so the idempotent repeat rides the same
        // still-running job scope.
        [$status2, $body2] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'correlation_id' => 'NOT-AN-ID',
            'results' => $results,
        ]);
        self::assertSame(200, $status2, 'a broken id must not be able to break an import');
        $decoded2 = json_decode($body2, true);
        self::assertNull($decoded2['correlation_id'] ?? null, 'an invalid id is not echoed');

        // Absent field: the contract stays optional-diagnostic.
        [$status3, $body3] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => $results,
        ]);
        self::assertSame(200, $status3);
        $decoded3 = json_decode($body3, true);
        self::assertArrayHasKey('correlation_id', $decoded3);
        self::assertNull($decoded3['correlation_id']);
    }

    /**
     * Etappe 9 (Entscheidung 6): the export result has always carried the
     * hypervisor identity (instance.moid, instance.instance_uuid); the callback
     * now persists it so every later mutation can prove it is talking about the
     * same VM and not a foreign one that merely shares the name.
     */
    public function testCallbackPersistsTheVmIdentityFromTheInstance(): void
    {
        $missionId = $this->insertMission('identity');
        $vmId = $this->insertVm($missionId, 'IDENT');
        $this->insertInterface($vmId, 'WDS');
        $jobId = $this->insertJob($missionId, [$vmId]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('IDENT'),
                'moid' => 'vm-4242',
                'instance_uuid' => $this->uuid('ident'),
                'hw_eth0' => ['macaddress' => $this->mac('id0'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame('success', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['outcome']);
        self::assertSame(['vm-4242', $this->uuid('ident')], $this->vmIdentity($vmId));
    }

    /**
     * A stored instance UUID is the VM's identity; a callback naming the same
     * VM name with a DIFFERENT instance UUID talks about a foreign VM. Nothing
     * of that row may be written - not the MACs, not the state, and least of
     * all the foreign identity itself.
     */
    public function testAnIdentityMismatchRejectsTheVmWithoutAnyWrite(): void
    {
        $missionId = $this->insertMission('mismatch');
        $vmId = $this->insertVm($missionId, 'FOREIGN');
        $this->insertInterface($vmId, 'WDS');
        $this->setVmIdentity($vmId, 'vm-1', $this->uuid('ours'));
        $jobId = $this->insertJob($missionId, [$vmId]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('FOREIGN'),
                'moid' => 'vm-9',
                'instance_uuid' => $this->uuid('theirs'),
                'hw_eth0' => ['macaddress' => $this->mac('fo0'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('failed', $response['outcome']);
        $vmResults = array_column($response['vm_results'], null, 'vm_name');
        self::assertSame(['identity_mismatch'], $vmResults[$this->vmName('FOREIGN')]['error_codes']);
        self::assertSame('', $this->interfaceMac($vmId, 'WDS'), 'a foreign VM result must not write a MAC');
        self::assertSame(['ready', 'not_ready', 0], $this->vmState($vmId));
        self::assertSame(['vm-1', $this->uuid('ours')], $this->vmIdentity($vmId), 'the stored identity must survive');
        self::assertSame([$vmId], $this->jobResult($jobId)['failed_vm_ids']);
    }

    /**
     * The instance UUID is the identity, the MOID is the host's current handle:
     * an unregister/re-register keeps the former and changes the latter, so a
     * matching UUID refreshes the MOID instead of arguing with it.
     */
    public function testAMatchingIdentityRefreshesTheMoid(): void
    {
        $missionId = $this->insertMission('refresh');
        $vmId = $this->insertVm($missionId, 'REFRESH');
        $this->insertInterface($vmId, 'WDS');
        $this->setVmIdentity($vmId, 'vm-old', $this->uuid('same'));
        $jobId = $this->insertJob($missionId, [$vmId]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [['instance' => [
                'hw_name' => $this->vmName('REFRESH'),
                'moid' => 'vm-new',
                'instance_uuid' => $this->uuid('same'),
                'hw_eth0' => ['macaddress' => $this->mac('rf0'), 'summary' => 'WDS'],
            ]]],
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame('success', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['outcome']);
        self::assertSame(['vm-new', $this->uuid('same')], $this->vmIdentity($vmId));
        self::assertSame(virtusphere_normalize_mac($this->mac('rf0')), $this->interfaceMac($vmId, 'WDS'));
    }

    /**
     * Identity fields are additive on the wire: a result without them (an older
     * playbook copy still deployed on an Ansible host) imports exactly as
     * before and neither erases a stored identity nor invents one.
     */
    public function testACallbackWithoutIdentityFieldsKeepsTheStoredIdentity(): void
    {
        $missionId = $this->insertMission('legacywire');
        $keeperVm = $this->insertVm($missionId, 'KEEPER');
        $blankVm = $this->insertVm($missionId, 'BLANK');
        $this->insertInterface($keeperVm, 'WDS');
        $this->insertInterface($blankVm, 'WDS');
        $this->setVmIdentity($keeperVm, 'vm-7', $this->uuid('keeper'));
        $jobId = $this->insertJob($missionId, [$keeperVm, $blankVm]);

        [$status, $body] = $this->post([
            'mission_id' => $missionId,
            'job_id' => $jobId,
            'results' => [
                ['instance' => [
                    'hw_name' => $this->vmName('KEEPER'),
                    'hw_eth0' => ['macaddress' => $this->mac('ke0'), 'summary' => 'WDS'],
                ]],
                ['instance' => [
                    'hw_name' => $this->vmName('BLANK'),
                    'hw_eth0' => ['macaddress' => $this->mac('bl0'), 'summary' => 'WDS'],
                ]],
            ],
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame('success', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['outcome']);
        self::assertSame(['vm-7', $this->uuid('keeper')], $this->vmIdentity($keeperVm));
        self::assertSame([null, null], $this->vmIdentity($blankVm));
        self::assertSame(virtusphere_normalize_mac($this->mac('ke0')), $this->interfaceMac($keeperVm, 'WDS'));
    }

    private function uuid(string $salt): string
    {
        $hex = hash('sha256', $this->prefix . '_uuid_' . $salt);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /** @return array{0:?string,1:?string} */
    private function vmIdentity(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT vm_moid, vm_instance_uuid FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);

        return [$row['vm_moid'] ?? null, $row['vm_instance_uuid'] ?? null];
    }

    private function setVmIdentity(int $vmId, string $moid, string $instanceUuid): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_vms SET vm_moid = ?, vm_instance_uuid = ? WHERE id = ?');
        $stmt->bind_param('ssi', $moid, $instanceUuid, $vmId);
        $stmt->execute();
    }

    /** @return array{0:int,1:string} */
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
        $body = @file_get_contents(virtusphere_test_base_url() . '/db_importMAC.php?action=updateInterface', false, $context);
        if ($body === false) {
            self::markTestSkipped('MAC callback endpoint is not reachable.');
        }

        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, $body];
    }
}
