<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_view.php';
require_once dirname(__DIR__, 2) . '/lib/mac_import_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_network.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms_mecm_reset.php';

/** K3: real worker transitions and HTTP callback, without SSH transport. */
final class RegisteredVmExportTest extends TestCase
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

    public static function preservedStates(): array
    {
        return [
            'registered export' => ['deployed', 'export', 0, null],
            'installed export' => ['os_installed', 'export', 0, '2026-01-02 03:04:05'],
            'registered full with queued pickup' => ['deployed', 'full', 1, '2026-01-02 03:04:05'],
            'installed powercycle' => ['os_installed', 'powercycle', 0, null],
        ];
    }

    #[DataProvider('preservedStates')]
    public function testBoundVmIsUnchangedAcrossWorkerCallbackAndConclusion(string $lifecycle, string $mode, int $pickup, ?string $pending): void
    {
        [$mission, $vm, $jobId, $claim] = $this->boundFixture($lifecycle, $mode, $pickup, $pending);
        $before = $this->snapshot($vm);
        $prior = deploy_worker_mark_vms_deploying($this->db, $claim, 'K3 test start', [$vm]);
        $afterMark = $this->snapshot($vm);
        $response = $this->sendCallback($mission, $jobId, $this->mac('old'));
        $afterCallback = $this->snapshot($vm);
        self::assertSame(mac_import_canonical_value($response), mac_import_canonical_value($this->sendCallback($mission, $jobId, $this->mac('old'))), 'identical active callback replay; JSON object key order is not semantic');
        self::assertSame($afterCallback, $this->snapshot($vm), 'replay must write nothing');
        deploy_worker_conclude_sequence($this->db, $claim, 'phpunit:k3', [$vm], $prior);
        self::assertSame('succeeded', repo_deploy_job($this->db, $jobId)['status']);
        self::assertSame($before, $afterMark, 'worker marking must preserve bound VMs');
        self::assertSame($before, $afterCallback, 'callback must preserve every runtime field');
        self::assertSame($before, $this->snapshot($vm), 'conclusion must not repaint a successful unchanged export');
        self::assertSame(0, $response['vm_results'][0]['updated_interfaces'], 'success with zero writes is shown as unchanged');
        self::assertSame([], $response['errors']);
        if ($pickup === 0) {
            self::assertSame(0, (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_vms WHERE id = ? AND (updated = 1 OR mecm_sync_state = 'pending')", 'i', [$vm]), 'Devices Sync must not pick this VM up again');
        }
        self::assertNotNull(mac_import_decode_result($this->rawJobResult($jobId)));
    }

    public static function changedCards(): array
    {
        return ['WDS changed' => [true], 'APP changed' => [false]];
    }

    #[DataProvider('changedCards')]
    public function testChangedMacFailsBoundVmAndPreservesBindingAndMac(bool $wdsChanged): void
    {
        [$mission, $vm, $jobId, $claim] = $this->boundFixture('os_installed', 'export', 0, null);
        $before = $this->snapshot($vm);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'K3 start', [$vm]);
        $response = $this->sendCallback($mission, $jobId, $this->mac($wdsChanged ? 'new' : 'old'), $this->mac($wdsChanged ? 'app' : 'app-new'));
        // A wholly failed MAC result follows the same worker failure path as
        // a sequence exception. FC2-E1 remains the existing convergence owner.
        try {
            deploy_worker_conclude_sequence($this->db, $claim, 'phpunit:k3', [$vm]);
        } catch (RuntimeException $exception) {
            deploy_worker_handle_failure($this->db, $claim, 'phpunit:k3', [$vm], $exception->getMessage());
        }
        self::assertSame('failed', repo_deploy_job($this->db, $jobId)['status']);
        self::assertSame('failed', $this->snapshot($vm)['lifecycle_state']);
        self::assertSame($before['mecm_id'], $this->snapshot($vm)['mecm_id']);
        self::assertSame($before['mac'], $this->snapshot($vm)['mac']);
        self::assertContains('bound_mac_changed', $response['vm_results'][0]['error_codes']);
        self::assertNotNull(mac_import_decode_result($this->rawJobResult($jobId)));
    }

    public function testMixedScopeKeepsUnchangedBindingAndImportsOnlyTheUnboundVm(): void
    {
        [$mission, $bound, $jobId] = $this->boundFixture('os_installed', 'export', 0, null);
        $changed = $this->insertVm($mission, 'CHANGED');
        $fresh = $this->insertVm($mission, 'NEW');
        $this->insertInterface($changed, 'WDS', virtusphere_normalize_mac($this->mac('changed-old')));
        $this->insertInterface($fresh, 'WDS');
        repo_execute($this->db, "UPDATE deploy_vms SET mecm_id = '4812', mecm_sync_state = 'registered', lifecycle_state = 'deployed' WHERE id = ?", 'i', [$changed]);
        $ids = [$bound, $changed, $fresh];
        $payload = json_encode(['mode' => 'export', 'vm_ids' => $ids], JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'UPDATE deploy_jobs SET payload_json = ? WHERE id = ?', 'si', [$payload, $jobId]);
        $claim = repo_deploy_job($this->db, $jobId);
        $before = $this->snapshot($bound);
        $prior = deploy_worker_mark_vms_deploying($this->db, $claim, 'K3 mixed start', $ids);
        $results = [];
        foreach (['BOUND' => 'old', 'CHANGED' => 'changed-new', 'NEW' => 'fresh'] as $name => $salt) {
            $instance = ['hw_name' => $this->vmName($name), 'hw_eth0' => ['summary' => 'WDS', 'macaddress' => $this->mac($salt)]];
            if ($name === 'BOUND') {
                $instance['hw_eth1'] = ['summary' => 'APP', 'macaddress' => $this->mac('app')];
            }
            $results[] = ['instance' => $instance];
        }
        [$status, $body] = $this->post(['mission_id' => $mission, 'job_id' => $jobId, 'results' => $results]);
        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $response['outcome']);
        self::assertSame(1, $response['updated_interfaces']);
        deploy_worker_conclude_sequence($this->db, $claim, 'phpunit:k3', $ids, $prior);
        self::assertSame('partial', repo_deploy_job($this->db, $jobId)['status']);
        self::assertSame($before, $this->snapshot($bound));
        self::assertSame('failed', $this->snapshot($changed)['lifecycle_state']);
        self::assertSame('4812', $this->snapshot($changed)['mecm_id']);
        self::assertSame(virtusphere_normalize_mac($this->mac('changed-old')), $this->interfaceMac($changed, 'WDS'));
        self::assertSame(['deployed', 'pending', 1], $this->vmState($fresh));
    }

    /**
     * K3 decision (b), 05.10.2026: an empty stored MAC on a bound VM means
     * "not known yet". IDR-P02 replacement forgets the MACs; the next export
     * must take them over without touching the VM, so the reset finds a MAC.
     */
    public function testReplacedBoundVmTakesFirstMacKeepsStateAndCanBeReset(): void
    {
        [$mission, $vm, $jobId, $claim] = $this->boundFixture('os_installed', 'export', 0, '2026-01-02 03:04:05');
        $hostname = 'K3R' . strtoupper(substr($this->prefix, -8));
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_hostname = ?, mecm_rollout_hostname = ?, mecm_rollout_revision = 1 WHERE id = ?', 'ssi', [$hostname, $hostname, $vm]);
        repo_vm_network_forget_observed_macs($this->db, $vm);
        $before = $this->snapshot($vm);
        self::assertSame(',', $before['mac'], 'fixture: replacement forgot both MACs');

        $prior = deploy_worker_mark_vms_deploying($this->db, $claim, 'K3 replacement start', [$vm]);
        $response = $this->sendCallback($mission, $jobId, $this->mac('new'), $this->mac('app-new'));
        deploy_worker_conclude_sequence($this->db, $claim, 'phpunit:k3', [$vm], $prior);

        self::assertSame('success', $response['outcome'], json_encode($response['errors'] ?? []));
        self::assertSame([], $response['vm_results'][0]['error_codes']);
        self::assertSame(2, $response['vm_results'][0]['updated_interfaces']);
        self::assertSame('succeeded', repo_deploy_job($this->db, $jobId)['status']);
        $after = $this->snapshot($vm);
        self::assertSame(virtusphere_normalize_mac($this->mac('new')), $this->interfaceMac($vm, 'WDS'));
        self::assertSame(virtusphere_normalize_mac($this->mac('app-new')), $this->interfaceMac($vm, 'APP'));
        unset($before['mac'], $after['mac']);
        self::assertSame($before, $after, 'lifecycle, MECM state, legacy status, pickup flag and pending times stay');

        self::assertSame([$vm], deploy_log_bound_first_mac_vm_ids($this->db, repo_deploy_job($this->db, $jobId)));
        $job = repo_deploy_job($this->db, $jobId);
        $job['mac_bound_first_vm_ids'] = deploy_log_bound_first_mac_vm_ids($this->db, $job);
        $rows = mac_import_present_vm_rows((array) mac_import_decode_result($this->rawJobResult($jobId)), $job);
        self::assertSame(__t('deploy.mac_notice_bound_first_mac'), $rows[0]['notice']['message'] ?? null);
        self::assertFalse($rows[0]['unchanged']);

        $reset = repo_reset_vm_mecm_id($this->db, $mission, $vm);
        self::assertTrue($reset['changed'], 'the reset guard no_mac must now find the imported MAC');
        self::assertSame(['deployed', 'pending', 1], $this->vmState($vm));

        $nextJob = $this->insertJob($mission, [$vm]);
        repo_execute($this->db, "UPDATE deploy_jobs SET locked_by = 'phpunit:k3', lock_token = REPEAT('a',32), worker_epoch = 1, attempts = 1 WHERE id = ?", 'i', [$nextJob]);
        $nextClaim = repo_deploy_job($this->db, $nextJob);
        $nextPrior = deploy_worker_mark_vms_deploying($this->db, $nextClaim, 'K3 unbound export', [$vm]);
        $next = $this->sendCallback($mission, $nextJob, $this->mac('new'), $this->mac('app-new'));
        deploy_worker_conclude_sequence($this->db, $nextClaim, 'phpunit:k3', [$vm], $nextPrior);
        self::assertSame('success', $next['outcome']);
        self::assertSame(2, $next['vm_results'][0]['updated_interfaces'], 'unbound export imports again');
        self::assertSame(['deployed', 'pending', 1], $this->vmState($vm));
        self::assertSame([], deploy_log_bound_first_mac_vm_ids($this->db, repo_deploy_job($this->db, $nextJob)));
    }

    public function testStoredMacInOtherFormatCountsAsUnchanged(): void
    {
        [$mission, $vm, $jobId, $claim] = $this->boundFixture('deployed', 'export', 0, null);
        $stored = strtolower(str_replace(':', '-', $this->mac('old')));
        repo_execute($this->db, "UPDATE deploy_interfaces SET mac = ? WHERE vm_id = ? AND vlan = 'WDS'", 'si', [$stored, $vm]);
        $before = $this->snapshot($vm);
        $prior = deploy_worker_mark_vms_deploying($this->db, $claim, 'K3 format start', [$vm]);
        $response = $this->sendCallback($mission, $jobId, $this->mac('old'));
        deploy_worker_conclude_sequence($this->db, $claim, 'phpunit:k3', [$vm], $prior);
        self::assertSame('success', $response['outcome']);
        self::assertSame(0, $response['vm_results'][0]['updated_interfaces']);
        self::assertSame($before, $this->snapshot($vm), 'format alone is no change and nothing is rewritten');
        self::assertSame([], deploy_log_bound_first_mac_vm_ids($this->db, repo_deploy_job($this->db, $jobId)));
    }

    private function boundFixture(string $lifecycle, string $mode, int $pickup, ?string $pending): array
    {
        $mission = $this->insertMission('bound');
        $vm = $this->insertVm($mission, 'BOUND');
        $this->insertInterface($vm, 'WDS', virtusphere_normalize_mac($this->mac('old')));
        $this->insertInterface($vm, 'APP', virtusphere_normalize_mac($this->mac('app')));
        repo_execute($this->db, "UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = 'registered', vm_status = ?, mecm_id = '4711', updated = ?, mecm_pending_since = ?, os_install_watch_started_at = '2026-01-01 01:02:03', updated_at = '2026-01-01 01:02:03' WHERE id = ?", 'ssisi', [$lifecycle, $lifecycle === 'os_installed' ? VIRTUSPHERE_STATUS_OS_INSTALLED : VIRTUSPHERE_STATUS_REGISTERED, $pickup, $pending, $vm]);
        $jobId = $this->insertJob($mission, [$vm]);
        $payload = json_encode(['mode' => $mode, 'vm_ids' => [$vm]], JSON_THROW_ON_ERROR);
        repo_execute($this->db, "UPDATE deploy_jobs SET payload_json = ?, locked_by = 'phpunit:k3', lock_token = REPEAT('a',32), worker_epoch = 1, attempts = 1 WHERE id = ?", 'si', [$payload, $jobId]);
        return [$mission, $vm, $jobId, repo_deploy_job($this->db, $jobId)];
    }

    private function sendCallback(int $mission, int $job, string $mac, ?string $appMac = null): array
    {
        [$status, $body] = $this->post(['mission_id' => $mission, 'job_id' => $job, 'results' => [['instance' => [
            'hw_name' => $this->vmName('BOUND'),
            'moid' => 'vm-999',
            'hw_eth0' => ['summary' => 'WDS', 'macaddress' => $mac],
            'hw_eth1' => ['summary' => 'APP', 'macaddress' => $appMac ?? $this->mac('app')],
        ]]]]);
        self::assertSame(200, $status, $body);
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshot(int $vm): array
    {
        return repo_fetch_one($this->db, 'SELECT v.lifecycle_state, v.mecm_sync_state, v.vm_status, v.mecm_id, v.updated, v.mecm_pending_since, v.os_install_watch_started_at, v.updated_at, v.vm_moid, v.vm_instance_uuid, (SELECT COUNT(*) FROM deploy_vm_status_events WHERE vm_id = v.id) AS events, (SELECT GROUP_CONCAT(mac ORDER BY id) FROM deploy_interfaces WHERE vm_id = v.id) AS mac FROM deploy_vms v WHERE v.id = ?', 'i', [$vm]);
    }
    private function insertMission(string $suffix, string $wds = 'WDS'): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status, wds_vlan) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $status, $wds);
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
        $contract = VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, payload_json, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1'
        );
        $stmt->bind_param('isss', $missionId, $status, $payload, $contract);
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
