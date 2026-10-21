<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/package_run_report.php';
require_once dirname(__DIR__, 2) . '/lib/repo/package_runs.php';

/**
 * The read side of the phase evidence (lib/repo/vm_evidence.php): the newest
 * journal row per VM, the uncertain Create units, the latest client event per
 * phase, the cached ESXi inventory with its freshness, and the newest package
 * run. Every source has its own writer; the test seeds rows the way those
 * writers do and checks what the reader hands to the pure rules.
 */
final class VmEvidenceReaderTest extends TestCase
{
    private const PREFIX = 'phpunit_evid_';
    private const CLIENT_MAC = '02:00:00:00:E1:01';
    private const PACKAGE_MAC = '02:00:00:00:E1:02';
    private const PACKAGE_PROJECT = 'PHPUNIT-EVID-PACKAGE';
    private const PACKAGE_RUN = '018f2f49-5e41-4d55-8f05-8f55a5335e01';

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

    public function testTheNewestJournalRowOfAVmNamesItsJob(): void
    {
        $vm = $this->insertVm('journal', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $withoutJournal = $this->insertVm('nojournal', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $first = $this->insertJob('succeeded', ['mode' => 'create']);
        $second = $this->insertJob('failed', ['mode' => 'export']);
        $this->journal($first, $vm, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $this->journal($second, $vm, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);

        $evidence = repo_vm_evidence($this->db, [$this->vmRow($vm), $this->vmRow($withoutJournal)]);

        self::assertSame($second, $evidence['jobs'][$vm]['id'], 'the newer journal row wins');
        self::assertSame('export', $evidence['jobs'][$vm]['mode']);
        self::assertSame('failed', $evidence['jobs'][$vm]['status']);
        self::assertArrayNotHasKey($withoutJournal, $evidence['jobs']);
    }

    public function testOnlyAnUncertainCreateUnitCountsAsAFinding(): void
    {
        $vm = $this->insertVm('create', VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_NOT_READY);
        $failedJob = $this->insertJob('failed', ['mode' => 'create']);
        $uncertainJob = $this->insertJob('failed', ['mode' => 'create']);
        $this->createUnit($failedJob, $vm, 'failed', 1);
        $this->createUnit($uncertainJob, $vm, 'uncertain', 1);

        $evidence = repo_vm_evidence($this->db, [$this->vmRow($vm)]);

        self::assertSame([['job_id' => $uncertainJob, 'position' => 1]], $evidence['create_uncertain'][$vm]);
    }

    public function testTheLatestClientEventPerPhaseIsKeptAndSilentPhasesStayNull(): void
    {
        $vm = $this->insertVm('phases', VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        repo_record_client_event($this->db, $vm, self::CLIENT_MAC, VIRTUSPHERE_CLIENT_PHASE_GETINFO, VIRTUSPHERE_CLIENT_EVENT_STARTED, null, '127.0.0.1');
        repo_record_client_event($this->db, $vm, self::CLIENT_MAC, VIRTUSPHERE_CLIENT_PHASE_GETINFO, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        repo_record_client_event($this->db, $vm, self::CLIENT_MAC, VIRTUSPHERE_CLIENT_PHASE_DISKS, VIRTUSPHERE_CLIENT_EVENT_STARTED, null, '127.0.0.1');
        repo_record_client_event($this->db, $vm, self::CLIENT_MAC, VIRTUSPHERE_CLIENT_PHASE_DISKS, VIRTUSPHERE_CLIENT_EVENT_FAILED, 'synthetic failure', '127.0.0.1');

        $row = $this->vmRow($vm);
        $evidence = repo_vm_evidence($this->db, [$row]);

        self::assertSame(VIRTUSPHERE_CLIENT_EVENT_FINISHED, $evidence['client_phases'][$vm][VIRTUSPHERE_CLIENT_PHASE_GETINFO]['event']);
        self::assertSame(VIRTUSPHERE_CLIENT_EVENT_FAILED, $evidence['client_phases'][$vm][VIRTUSPHERE_CLIENT_PHASE_DISKS]['event']);
        self::assertArrayNotHasKey(VIRTUSPHERE_CLIENT_PHASE_HOSTNAME, $evidence['client_phases'][$vm]);

        $forVm = repo_vm_evidence_for_vm($evidence, $row, false);
        self::assertNull($forVm['client_phases'][VIRTUSPHERE_CLIENT_PHASE_HOSTNAME]);
        self::assertSame(VIRTUSPHERE_CLIENT_EVENT_FINISHED, $forVm['client_phases'][VIRTUSPHERE_CLIENT_PHASE_GETINFO]['event']);
    }

    public function testAFreshAnsweredInventoryListsTheBoundVmsWithTheirPowerState(): void
    {
        $credential = $this->credential();
        $vm = $this->insertVm('inv', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, $credential, 'uuid-a');
        $freshAt = gmdate('Y-m-d H:i:s');
        $this->inventoryState($credential, $freshAt, VIRTUSPHERE_INVENTORY_QUERY_ANSWERED);
        $this->inventoryVm($credential, 'vm-a', 'uuid-a', 'poweredOff');
        $this->inventoryVm($credential, 'vm-b', 'uuid-b', 'poweredOn');

        $inventory = repo_vm_evidence($this->db, [$this->vmRow($vm)])['inventory'][$credential];

        self::assertTrue($inventory['fresh']);
        self::assertTrue($inventory['answered']);
        self::assertSame($freshAt, $inventory['fresh_at']);
        self::assertEqualsCanonicalizing(['uuid-a' => 'poweredOff', 'uuid-b' => 'poweredOn'], $inventory['vms']);
    }

    public function testAStaleInventoryIsNoEvidence(): void
    {
        $credential = $this->credential();
        $vm = $this->insertVm('stale', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, $credential, 'uuid-s');
        $this->inventoryState($credential, gmdate('Y-m-d H:i:s', time() - 30 * 86400), VIRTUSPHERE_INVENTORY_QUERY_ANSWERED);
        $this->inventoryVm($credential, 'vm-s', 'uuid-s', 'poweredOn');

        $inventory = repo_vm_evidence($this->db, [$this->vmRow($vm)])['inventory'][$credential];

        self::assertFalse($inventory['fresh']);
        self::assertTrue($inventory['answered']);
    }

    public function testAFailedInventoryPullIsNotAnswered(): void
    {
        $credential = $this->credential();
        $vm = $this->insertVm('failed-pull', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, $credential, 'uuid-f');
        $this->inventoryState($credential, gmdate('Y-m-d H:i:s'), 'failed');

        $inventory = repo_vm_evidence($this->db, [$this->vmRow($vm)])['inventory'][$credential];

        self::assertTrue($inventory['fresh']);
        self::assertFalse($inventory['answered']);
    }

    public function testTheNewestCompletedPackageRunCarriesItsReportedFailures(): void
    {
        $vm = $this->insertVm('package', VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        repo_execute($this->db, 'UPDATE deploy_vms SET mecm_rollout_revision = 3 WHERE id = ?', 'i', [$vm]);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss',
            [$vm, '10.0.0.21', '255.255.255.0', '10.0.0.1', self::PACKAGE_MAC, 'dhcp', 'vmxnet3']
        );

        $base = $this->packageBase($vm);
        self::assertSame(200, repo_package_report_record($this->db, $vm, $this->validated($base))['status']);

        $step = array_replace($base, [
            'event' => 'step_result', 'event_seq' => 2, 'event_at' => '2026-09-21T12:01:00Z',
            'step_index' => 1, 'script_name' => '001.ps1', 'result' => 'fail', 'is_first_failure' => true,
            'error_category' => 'child_exit', 'child_exit_code' => 1, 'duration_ms' => 1, 'detail_path' => null,
        ]);
        self::assertSame(200, repo_package_report_record($this->db, $vm, $this->validated($step))['status']);

        $completed = array_replace($base, [
            'event' => 'completed', 'event_seq' => 3, 'event_at' => '2026-09-21T12:05:00Z',
            'wrapper_result' => 'failed', 'wrapper_exit_code' => 1, 'detection_result' => 'not_attempted',
            'processed_count' => 1, 'ok_count' => 0, 'skip_count' => 0, 'fail_count' => 1, 'last_processed_index' => 1,
            'first_failure' => [
                'step_index' => 1, 'script_name' => '001.ps1',
                'error_category' => 'child_exit', 'child_exit_code' => 1, 'detail_path' => null,
            ],
            'payload_omitted_count' => 0, 'wrapper_log_path' => null, 'reporting_log_path' => null,
        ]);
        self::assertSame(200, repo_package_report_record($this->db, $vm, $this->validated($completed))['status']);

        $run = repo_vm_evidence($this->db, [$this->vmRow($vm)])['package_runs'][$vm];
        self::assertSame(self::PACKAGE_RUN, $run['run_id']);
        self::assertSame(1, (int) $run['reported_failures']);
        self::assertSame('failed', $run['wrapper_result']);
        self::assertNotNull($run['completed_received_at']);
    }

    /** @return array<string,mixed> */
    private function vmRow(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT * FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertIsArray($row);

        return $row;
    }

    private function insertVm(string $label, string $lifecycle, string $mecm, ?int $credentialId = null, ?string $uuid = null): int
    {
        $name = self::PREFIX . $label;
        $host = $credentialId === null ? null : 'esxi.example.com';
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid, vm_bound_credential_id, vm_bound_esxi_host) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'isssssis',
            [$this->missionId, $name, $name, $lifecycle, $mecm, $uuid, $credentialId, $host]
        );

        return (int) $this->db->insert_id;
    }

    /** @param array<string,mixed> $payload */
    private function insertJob(string $status, array $payload): int
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_jobs (mission_id, status, payload_json, started_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            'iss',
            [$this->missionId, $status, json_encode($payload)]
        );

        return (int) $this->db->insert_id;
    }

    private function journal(int $jobId, int $vmId, string $lifecycle, string $mecm): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, ?, ?)',
            'iiss',
            [$jobId, $vmId, $lifecycle, $mecm]
        );
    }

    private function createUnit(int $jobId, int $vmId, string $status, int $position): void
    {
        repo_execute(
            $this->db,
            "INSERT INTO deploy_create_vm_results (job_id, vm_id, vm_name, position, total, action, status, error_code, error_detail, finished_at) VALUES (?, ?, ?, ?, ?, 'create', ?, 'E_TEST', 'synthetic', UTC_TIMESTAMP())",
            'iisiis',
            [$jobId, $vmId, self::PREFIX . 'create', $position, $position, $status]
        );
    }

    private function credential(): int
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)',
            'sssiss',
            [VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, self::PREFIX . 'esxi', 'esxi.example.com', 443, 'svc', 'x']
        );

        return (int) $this->db->insert_id;
    }

    private function inventoryState(int $credentialId, string $freshAt, string $outcome): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_esxi_inventory_state WHERE credential_id = ?', 'i', [$credentialId]);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_esxi_inventory_state (credential_id, kind_freshness_json, kind_observation_json) VALUES (?, ?, ?)',
            'iss',
            [$credentialId, json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => $freshAt]), json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => ['outcome' => $outcome]])]
        );
    }

    private function inventoryVm(int $credentialId, string $name, string $uuid, string $powerState): void
    {
        repo_execute(
            $this->db,
            "INSERT INTO deploy_esxi_inventory (credential_id, kind, name, meta_json) VALUES (?, 'vm', ?, ?)",
            'iss',
            [$credentialId, self::PREFIX . $name, json_encode(['instance_uuid' => $uuid, 'power_state' => $powerState])]
        );
    }

    /** @return array<string,mixed> */
    private function packageBase(int $vmId): array
    {
        $device = (string) repo_scalar(
            $this->db,
            'SELECT LOWER(BIN_TO_UUID(package_report_generation)) FROM deploy_vms WHERE id = ?',
            'i',
            [$vmId]
        );
        $acceptance = (string) repo_scalar(
            $this->db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1'
        );

        return [
            'schema_version' => 1, 'run_id' => self::PACKAGE_RUN, 'event' => 'started', 'event_seq' => 1,
            'mac_candidates' => [self::PACKAGE_MAC], 'rollout_revision' => 3,
            'device_generation' => $device, 'acceptance_generation' => $acceptance,
            'project_name' => self::PACKAGE_PROJECT, 'package_version' => '1.0',
            'client_started_at' => '2026-09-21T12:00:00Z', 'event_at' => '2026-09-21T12:00:00Z',
            'context' => 'system', 'total' => 1,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> the validated report */
    private function validated(array $payload): array
    {
        $validated = package_run_report_validate($payload);
        self::assertArrayHasKey('report', $validated, json_encode($validated));

        return $validated['report'];
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        // The VMs cascade the package runs, so the markers can follow.
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        repo_execute($this->db, 'DELETE FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [self::PACKAGE_RUN]);

        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
