<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_vm_states.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/status_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms_overview.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_live.php';
require_once dirname(__DIR__, 2) . '/lib/vm_status_live_views.php';

/**
 * VB4 "Phase aus Belegen": a failed or cancelled job does not move the phase of
 * a VM backwards. Every case drives the real journal (mark, touch, failure or
 * cancel, restore) and the real state writers, and reads the phase the way the
 * pages do: the PHP rule over the evidence batch and the SQL twin. The expected
 * phase is written out per case and never taken from the rule under test.
 */
final class VmPhaseAfterFailedJobTest extends TestCase
{
    private const PREFIX = 'phpunit_vb4_';
    private const WORKER = 'phpunit:vb4-worker';
    private const OLD_UUID = '52677625-4986-ed5b-89b5-ee9597f0f4cb';
    private const NEW_UUID = '52b3bff3-ed69-665f-faa3-fa8ea7a2b4bb';

    private mysqli $db;
    private int $missionId = 0;
    private int $userId = 0;
    /** @var list<int> */
    private array $credentialIds = [];
    private int $macCounter = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        Lang::load('de');
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        $this->missionId = $this->mission(self::PREFIX . bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            Lang::load('de');
        }
    }

    /**
     * The evidence a VM held before its job, the phase that evidence means,
     * whether all four client phases reported `finished`, and whether a
     * powercycle marks the VM `deploying`: a mode with a MAC result leaves a VM
     * that carries a ResourceID unmarked (virtusphere_vm_is_mecm_bound()).
     *
     * @return array<string, array{0:string,1:string,2:bool,3:bool}>
     */
    public static function evidenceBeforeTheJob(): array
    {
        return [
            'client done' => ['ack', VIRTUSPHERE_VM_PHASE_CLIENT_DONE, true, false],
            'windows reports' => ['ack', VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, false, false],
            'ACK before the device sync' => ['ack_before_sync', VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, false, true],
            'handed to MECM' => ['device_sync', VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM, false, false],
            'MAC known' => ['mac_import', VIRTUSPHERE_VM_PHASE_MAC_KNOWN, false, true],
        ];
    }

    #[DataProvider('evidenceBeforeTheJob')]
    public function testATouchedVmKeepsThePhaseOfItsLastEvidenceWhenItsJobFails(string $evidence, string $phase, bool $clientDone, bool $marked): void
    {
        $vm = $this->vmWithEvidence($evidence, $clientDone);
        $this->assertPhase($phase, $vm, 'before the job');
        $stored = $this->stored($vm);

        $jobId = $this->runningJob('powercycle', [$vm]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 mark', [$vm]);
        self::assertSame(
            $marked ? VIRTUSPHERE_LIFECYCLE_DEPLOYING : $stored['lifecycle_state'],
            $this->stored($vm)['lifecycle_state'],
            'a powercycle marks a VM only while it carries no ResourceID'
        );
        $this->assertPhase($phase, $vm, 'while the job runs');

        $this->touch($jobId, $vm, 'powercycle');
        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$vm], 'powercycle failed after the access');

        $after = $this->stored($vm);
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED],
            [$after['lifecycle_state'], $after['mecm_sync_state']],
            'the journal verdict is written as before'
        );
        self::assertSame($stored['vm_status'], $after['vm_status'], 'the stored x/5 value is not written by the journal');
        $this->assertPhase($phase, $vm, 'after the failed job');
        self::assertContains(VIRTUSPHERE_VM_FINDING_JOB_FAILED, $this->findingKinds($vm), 'the failure stays a finding');
        self::assertContains(VIRTUSPHERE_VM_FINDING_MECM_SYNC_DEPLOY_FAILED, $this->findingKinds($vm));
    }

    public function testACancelledJobKeepsThePhaseExactlyLikeAFailedOne(): void
    {
        $vm = $this->vmWithEvidence('ack', true);
        $jobId = $this->runningJob('powercycle', [$vm]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 mark', [$vm]);
        $this->touch($jobId, $vm, 'powercycle');
        repo_cancel_deploy_job($this->db, $jobId, $this->userId);

        self::assertTrue(deploy_worker_confirm_owned_cancel($this->db, $this->job($jobId)));

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $this->job($jobId)['status']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->stored($vm)['lifecycle_state'], 'a touched VM fails on a cancel too');
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after the cancelled job');
        self::assertContains(VIRTUSPHERE_VM_FINDING_JOB_FAILED, $this->findingKinds($vm));
    }

    public function testAnUntouchedVmIsRestoredAndShowsItsPhaseWithoutAFinding(): void
    {
        $vm = $this->vmWithEvidence('ack', false);
        $jobId = $this->runningJob('powercycle', [$vm]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 mark', [$vm]);

        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$vm], 'failed before any access');

        $after = $this->stored($vm);
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED],
            [$after['lifecycle_state'], $after['mecm_sync_state']],
            'disposition restore is unchanged'
        );
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, $vm, 'after the restore');
        self::assertNotContains(VIRTUSPHERE_VM_FINDING_JOB_FAILED, $this->findingKinds($vm));
    }

    public function testEvidenceThatArrivesWhileTheJobRunsCountsAfterItsFailure(): void
    {
        $vm = $this->vmWithEvidence('mac_import', false);
        $jobId = $this->runningJob('powercycle', [$vm]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 mark', [$vm]);
        // The device sync reports the ResourceID while the job still runs.
        self::assertTrue(repo_set_vm_state_forward($this->db, $vm, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', '4873'));
        $this->touch($jobId, $vm, 'powercycle');

        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$vm], 'powercycle failed after the access');

        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->stored($vm)['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYED, (string) repo_deploy_job_vm_states($this->db, $jobId)[$vm]['lifecycle_before'], 'the journal still names the state at the claim');
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM, $vm, 'the registration arrived, so it counts');
    }

    public function testAFailedCreateOfAVmThatWasNeverOnEsxiInventsNoEvidence(): void
    {
        $vm = $this->vm();
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CREATED, $vm, 'before the job');
        $jobId = $this->runningJob('create', [$vm]);
        $claim = $this->job($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'VB4 create mark', [$vm]);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_IN_PROGRESS, $vm, 'while the create runs');
        $this->createUnit($jobId, $vm);
        $fence = $this->fence($claim);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', ['async_jid' => '1234567890.4', 'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'running', 'failed', ['error_code' => VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED, 'error_detail' => 'confirmed module failure'], $fence));

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'create sequence failed');

        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->stored($vm)['lifecycle_state']);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CREATED, $vm, 'no report ever arrived for this VM');
        self::assertContains(VIRTUSPHERE_VM_FINDING_JOB_FAILED, $this->findingKinds($vm));
    }

    public function testAnUnresolvedCreateStaysInProgress(): void
    {
        $vm = $this->vm();
        $jobId = $this->runningJob('create', [$vm]);
        $claim = $this->job($jobId);
        deploy_worker_mark_vms_deploying($this->db, $claim, 'VB4 create mark', [$vm]);
        $this->createUnit($jobId, $vm);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $this->fence($claim)));
        self::assertSame(true, deploy_worker_vm_create_launch_intent($this->db, $claim, $vm, true));

        deploy_worker_handle_failure($this->db, $claim, self::WORKER, [$vm], 'worker lost after the launch intent');

        self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->stored($vm)['lifecycle_state'], 'disposition unknown is unchanged');
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_IN_PROGRESS, $vm, 'an unknown create outcome proves nothing');
    }

    public function testALaterJobDoesNotMoveThePhaseWhetherItRunsSucceedsOrFails(): void
    {
        $vm = $this->vmWithEvidence('ack', true);
        $this->failTouchedJob($vm);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after the first failure');

        // A create job marks every VM, also one with a ResourceID. Its success
        // restores the journal's prestate, which is the failure mark of the first job.
        $second = $this->runningJob('create', [$vm]);
        $prior = deploy_worker_mark_vms_deploying($this->db, $this->job($second), 'VB4 second mark', [$vm]);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYING, $this->stored($vm)['lifecycle_state']);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'while the second job runs');
        self::assertSame(1, deploy_worker_restore_deploying_vms($this->db, $this->job($second), 'deploy job ' . $second . ' succeeded', [$vm], $prior));
        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ?, locked_by = NULL, lock_token = NULL WHERE id = ?', 'si', [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $second]);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->stored($vm)['lifecycle_state']);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after the second job succeeded');

        $this->failTouchedJob($vm);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after a third job failed again');
    }

    public function testAMarkedVmShowsItsEvidenceWhileTheJobRunsAndAfterItsSuccess(): void
    {
        $vm = $this->vmWithEvidence('ack', true);
        $jobId = $this->runningJob('create', [$vm]);
        $prior = deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 create mark', [$vm]);

        $marked = $this->stored($vm);
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_DEPLOYING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLED],
            [$marked['lifecycle_state'], $marked['mecm_sync_state'], $marked['vm_status']],
            'the mark replaces the lifecycle and nothing else'
        );
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'while the create job runs');
        self::assertContains(VIRTUSPHERE_VM_FINDING_JOB_RUNNING, $this->findingKinds($vm), 'the running job is the finding');

        self::assertSame(1, deploy_worker_restore_deploying_vms($this->db, $this->job($jobId), 'deploy job ' . $jobId . ' succeeded', [$vm], $prior));
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $this->stored($vm)['lifecycle_state']);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after the restore');
    }

    public function testAMecmIdResetIsTheFenceOldEvidenceDoesNotCross(): void
    {
        $vm = $this->vmWithEvidence('ack', true);
        $this->failTouchedJob($vm);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $vm, 'after the failure');

        $reset = repo_reset_vm_mecm_id($this->db, $this->missionId, $vm, $this->userId);
        self::assertTrue($reset['changed'], json_encode($reset, JSON_THROW_ON_ERROR));
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, $vm, 'the reset starts a new rollout');

        $this->failTouchedJob($vm);
        // All four client phases of the old rollout are still stored as finished.
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, $vm, 'a failure in the new rollout shows the new rollout');
    }

    /**
     * IDR-P02 leaves lifecycle, MECM axis and x/5 of a replaced instance with the
     * operator ("reset the MECM ID"). VB4 does not change that: whether the job
     * that replaced the instance then fails or succeeds, the VM shows one phase.
     */
    public function testAReplacedInstanceShowsTheSamePhaseAfterAFailedAndAfterASucceededJob(): void
    {
        $phases = [];
        foreach (['failed', 'succeeded'] as $ending) {
            $vm = $this->vmWithEvidence('ack', false, self::OLD_UUID);
            [$jobId, $job, $fence] = $this->claimedCreateJob($vm);
            $prior = deploy_worker_mark_vms_deploying($this->db, $job, 'VB4 replace mark', [$vm]);
            self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0, 'replaced_instance_uuid' => self::OLD_UUID], $fence));
            self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', ['async_jid' => '123456789.7', 'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $fence));
            $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-2', self::NEW_UUID, $fence);
            self::assertSame(self::OLD_UUID, $commit['replaced_instance_uuid'] ?? null);

            // The VM convergence of the two endings, without the job's terminal
            // bookkeeping: that would queue an inventory pull for the fixture access.
            if ($ending === 'failed') {
                self::assertSame(1, deploy_worker_mark_vms_failed($this->db, $this->job($jobId), 'deploy job ' . $jobId . ' failed', [$vm]));
                self::assertSame(VIRTUSPHERE_LIFECYCLE_FAILED, $this->stored($vm)['lifecycle_state']);
            } else {
                self::assertSame(1, deploy_worker_restore_deploying_vms($this->db, $this->job($jobId), 'deploy job ' . $jobId . ' succeeded', [$vm], $prior));
                self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $this->stored($vm)['lifecycle_state']);
            }
            self::assertSame(self::NEW_UUID, $this->stored($vm)['vm_instance_uuid']);
            $phases[$ending] = $this->phase($vm);
            self::assertSame(virtusphere_vm_phase_rank($phases[$ending]), $this->twinRank($vm), 'SQL twin after a ' . $ending . ' replacement job');
            // Fixture teardown of this round: the mission must be idle for the next queue entry.
            repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
            repo_execute($this->db, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$vm]);
        }

        self::assertSame(VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, $phases['succeeded'], 'the replacement leaves the stored state with the operator');
        self::assertSame($phases['succeeded'], $phases['failed'], 'the failed job shows what the succeeded one shows');
    }

    public function testATemplateVmHasNoPhaseAndIsNotCounted(): void
    {
        $template = $this->mission(VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . bin2hex(random_bytes(3)));
        $vm = $this->vmWithEvidence('ack', true, null, $template);
        repo_execute($this->db, 'UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ? WHERE id = ?', 'ssi', [VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, $vm]);

        $rows = repo_vm_overview_rows_by_ids($this->db, [$vm]);
        $slice = repo_vm_evidence_for_vm(repo_vm_evidence($this->db, $rows), $rows[0], mission_name_is_template((string) $rows[0]['mission_name']));
        self::assertSame(VIRTUSPHERE_VM_PHASE_TEMPLATE, virtusphere_vm_phase($rows[0], $slice));
        self::assertSame([], virtusphere_vm_findings($rows[0], $slice));
        self::assertArrayNotHasKey($template, repo_vm_phase_counts_by_mission($this->db));
        self::assertSame([], vm_live_rows($this->db, $rows, []), 'a template yields no poll content');
    }

    public function testCountersSortingAndLiveCellsShowTheSamePhaseAsThePage(): void
    {
        $done = $this->vmWithEvidence('ack', true);
        $onEsxi = $this->vm('52000000-0000-4000-8000-00000000b401');
        $this->failTouchedJob($done);
        $this->failTouchedJob($onEsxi);
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, $done, 'evidence up to the client phases');
        $this->assertPhase(VIRTUSPHERE_VM_PHASE_ON_ESXI, $onEsxi, 'only the binding');

        self::assertSame(
            [VIRTUSPHERE_VM_PHASE_ON_ESXI => 1, VIRTUSPHERE_VM_PHASE_CLIENT_DONE => 1],
            repo_vm_phase_counts_by_mission($this->db)[$this->missionId] ?? [],
            'the phase distribution of the mission list'
        );

        $page = repo_vm_overview_page($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED, 'status', 'desc', 1, 100000);
        $order = array_values(array_filter(
            array_map(static fn (array $row): int => (int) $row['id'], $page['rows']),
            static fn (int $id): bool => in_array($id, [$done, $onEsxi], true)
        ));
        self::assertSame([$done, $onEsxi], $order, 'the overview filter keeps both failed VMs and sorts the further one first');

        $rows = repo_vm_overview_rows_by_ids($this->db, [$done, $onEsxi]);
        $ranks = [];
        $live = vm_live_rows($this->db, $rows, [], $ranks);
        self::assertSame(virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_CLIENT_DONE), $ranks[$done]);
        self::assertSame(virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_ON_ESXI), $ranks[$onEsxi]);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $phase = $id === $done ? VIRTUSPHERE_VM_PHASE_CLIENT_DONE : VIRTUSPHERE_VM_PHASE_ON_ESXI;
            self::assertSame(vm_phase_cell($row, $phase), $live[$id]['phase']['html'], 'one cell, one renderer');
            self::assertStringContainsString(h(portal_vm_phase_label($phase)), $live[$id]['phase']['html']);
        }

        $bundle = repo_get_vm_bundle($this->db, $done);
        self::assertNotNull($bundle);
        self::assertSame(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, vm_edit_status_view($this->db, $bundle)['phase'], 'the editor status block');
    }

    // --- fixture ---------------------------------------------------------------

    private function mission(string $name): int
    {
        return repo_create_mission($this->db, [
            'mission_name' => $name,
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
    }

    /** A VM as the portal creates it: ready, not ready for MECM, 2/5. */
    private function vm(?string $instanceUuid = null, ?int $missionId = null): int
    {
        $this->macCounter++;
        $name = 'VB4' . strtoupper(bin2hex(random_bytes(4)));
        $vmId = repo_save_vm(
            $this->db,
            $missionId ?? $this->missionId,
            null,
            ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '192.0.2.' . (10 + $this->macCounter), 'subnet' => '255.255.255.0', 'gateway' => '192.0.2.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN10', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            $this->userId
        );
        if ($instanceUuid !== null) {
            repo_execute($this->db, 'UPDATE deploy_vms SET vm_instance_uuid = ?, vm_moid = ? WHERE id = ?', 'ssi', [$instanceUuid, 'vm-1', $vmId]);
        }

        return $vmId;
    }

    /**
     * A bound VM that received evidence through the stored-state writers of the
     * machine endpoints, in the order a rollout delivers it: the MAC import, the
     * device sync and the client ACK. `updated_at` and the clocks are theirs.
     */
    private function vmWithEvidence(string $upTo, bool $clientDone, ?string $instanceUuid = null, ?int $missionId = null): int
    {
        $vmId = $this->vm($instanceUuid ?? sprintf('52000000-0000-4000-8000-%012d', random_int(1, 999999999)), $missionId);
        repo_execute($this->db, 'UPDATE deploy_interfaces SET mac = ? WHERE vm_id = ?', 'si', [sprintf('02:AA:BB:CC:%02X:%02X', random_int(0, 255), random_int(0, 255)), $vmId]);
        self::assertTrue(repo_set_vm_state($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_DEPLOYED, 1, 'ansible mac import'));
        if ($upTo === 'mac_import') {
            return $vmId;
        }
        if ($upTo === 'ack_before_sync') {
            // VT-E1 C: the ACK writes the lifecycle and passes the MECM axis through.
            self::assertTrue(repo_set_vm_state_forward($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_STATUS_OS_INSTALLED, null, 'mecm client ready acknowledgement'));

            return $vmId;
        }
        self::assertTrue(repo_set_vm_state_forward($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', (string) random_int(100000, 999999)));
        if ($upTo === 'device_sync') {
            return $vmId;
        }
        self::assertTrue(repo_set_vm_state_forward($this->db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLED, null, 'mecm client ready acknowledgement'));
        if ($clientDone) {
            foreach (VIRTUSPHERE_CLIENT_PHASES as $clientPhase) {
                repo_record_client_event($this->db, $vmId, '02:00:00:00:B4:01', $clientPhase, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
            }
        }

        return $vmId;
    }

    /** A job that marks the VM, reaches it on ESXi and then fails: disposition `fail`. */
    private function failTouchedJob(int $vmId): void
    {
        $jobId = $this->runningJob('powercycle', [$vmId]);
        deploy_worker_mark_vms_deploying($this->db, $this->job($jobId), 'VB4 mark', [$vmId]);
        $this->touch($jobId, $vmId, 'powercycle');
        deploy_worker_handle_failure($this->db, $this->job($jobId), self::WORKER, [$vmId], 'powercycle failed after the access');
        self::assertSame(
            [VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED],
            [$this->stored($vmId)['lifecycle_state'], $this->stored($vmId)['mecm_sync_state']]
        );
    }

    /** @param list<int> $vmIds */
    private function runningJob(string $mode, array $vmIds): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => $vmIds], JSON_THROW_ON_ERROR);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, locked_at, locked_by, lock_token, worker_epoch, attempts, heartbeat_at, execution_contract, execution_generation_id) '
            . 'SELECT ?, ?, ?, ?, NOW(), ?, ?, 1, 1, NOW(), ?, current_generation_id FROM deploy_runtime_identity WHERE id = 1',
            'iisssss',
            [$this->missionId, $this->userId, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $payload, self::WORKER, bin2hex(random_bytes(16)), VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * A create job through the real queue and claim, as the replacement commit
     * needs its access.
     *
     * @return array{0:int,1:array<string,mixed>,2:array{worker_id:string,lock_token:string,worker_epoch:int}}
     */
    private function claimedCreateJob(int $vmId): array
    {
        if ($this->credentialIds === []) {
            foreach (['esxi', 'ansible'] as $type) {
                $name = self::PREFIX . $type . '_' . bin2hex(random_bytes(3));
                repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $name, $name . '.invalid', 'fixture', 'unused-ciphertext']);
                $this->credentialIds[] = (int) $this->db->insert_id;
            }
        }
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->credentialIds[0], $this->credentialIds[1], ['mode' => 'create', 'vm_ids' => [$vmId], 'verbose' => false, 'powercycle_wait' => 5, 'start_wait' => 30]);
        $worker = self::WORKER . '-' . bin2hex(random_bytes(3));
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($jobId, (int) $job['id']);

        return [$jobId, $this->job($jobId), deploy_worker_job_fence($this->db, $jobId, $worker, $job)];
    }

    private function createUnit(int $jobId, int $vmId): void
    {
        $vm = repo_fetch_one($this->db, 'SELECT id, vm_name FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        repo_deploy_create_materialize($this->db, $jobId, [['id' => $vmId, 'vm_name' => (string) $vm['vm_name']]]);
    }

    /** @return array<string,mixed> */
    private function job(int $jobId): array
    {
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);

        return $job;
    }

    /** @return array{worker_id:string,lock_token:string,worker_epoch:int} */
    private function fence(array $job): array
    {
        return ['worker_id' => (string) $job['locked_by'], 'lock_token' => (string) $job['lock_token'], 'worker_epoch' => (int) $job['worker_epoch']];
    }

    private function touch(int $jobId, int $vmId, string $phase): void
    {
        $job = $this->job($jobId);
        self::assertTrue(repo_deploy_job_vm_admit_touch($this->db, $jobId, $vmId, (int) $job['attempts'], (string) $job['execution_generation_id'], $phase));
    }

    // --- readers ---------------------------------------------------------------

    /** @return array<string,mixed> */
    private function stored(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state, vm_status, vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} the row and its evidence slice, as the pages read them */
    private function read(int $vmId): array
    {
        $rows = repo_vm_overview_rows_by_ids($this->db, [$vmId]);
        self::assertCount(1, $rows);

        return [$rows[0], repo_vm_evidence_for_vm(repo_vm_evidence($this->db, $rows), $rows[0], false)];
    }

    private function phase(int $vmId): string
    {
        [$row, $slice] = $this->read($vmId);

        return virtusphere_vm_phase($row, $slice);
    }

    private function twinRank(int $vmId): int
    {
        return (int) repo_scalar($this->db, 'SELECT ' . virtusphere_vm_phase_sql('v') . ' FROM deploy_vms v WHERE v.id = ?', 'i', [$vmId]);
    }

    private function assertPhase(string $expected, int $vmId, string $when): void
    {
        self::assertSame($expected, $this->phase($vmId), 'PHP rule ' . $when);
        self::assertSame(virtusphere_vm_phase_rank($expected), $this->twinRank($vmId), 'SQL twin ' . $when);
    }

    /** @return list<string> */
    private function findingKinds(int $vmId): array
    {
        [$row, $slice] = $this->read($vmId);

        return array_column(virtusphere_vm_findings($row, $slice), 'kind');
    }

    private function cleanup(): void
    {
        foreach ([self::PREFIX . '%', VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . '%'] as $like) {
            foreach (['DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                      'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                      'DELETE FROM deploy_missions WHERE mission_name LIKE ?'] as $sql) {
                repo_execute($this->db, $sql, 's', [$like]);
            }
        }
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE name LIKE ?', 's', [self::PREFIX . '%']);
        $this->credentialIds = [];
    }
}
