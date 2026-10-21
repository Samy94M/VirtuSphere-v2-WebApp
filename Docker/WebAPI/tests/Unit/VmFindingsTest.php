<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * Findings are read-time observations on stored evidence (plan "Phase und
 * Befund", section 4). Each test names one rule of the list and its boundary.
 * The clock is passed in, so every boundary is exact. Nothing here writes, and
 * no elapsed time becomes a failure of the VM (ADR-0038).
 */
final class VmFindingsTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const UUID = '42422d12-0000-4000-8000-000000000001';

    /**
     * A server timestamp that is not usable as a clock: empty, malformed, or
     * beyond the skew tolerance of virtusphere_evidence_timestamp().
     *
     * @return iterable<string, array{0:string}>
     */
    public static function unusableMecmClocks(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['not-a-timestamp'];
        yield 'future beyond the skew tolerance' => [gmdate('Y-m-d H:i:s', self::NOW + 3600)];
    }

    /** @return iterable<string, array{0:int,1:list<string>}> */
    public static function startedClientPhaseAges(): iterable
    {
        yield 'exactly at the threshold' => [VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS, []];
        yield 'one second past the threshold' => [VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS + 1, [VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED]];
        yield 'young' => [60, []];
    }

    /** @return iterable<string, array{0:bool,1:bool}> */
    public static function staleOrUnansweredInventories(): iterable
    {
        yield 'stale' => [false, true];
        yield 'unanswered' => [true, false];
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function poweredOffWhileWindowsShouldWork(): iterable
    {
        yield 'installing and registered' => [VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED];
        yield 'installed' => [VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED];
    }

    /** @return iterable<string, array{0:?string}> */
    public static function quietClientStates(): iterable
    {
        yield 'finished' => ['finished'];
        yield 'silent' => [null];
    }

    public function testATemplateHasNoFindingsEvenWithEveryEvidenceSet(): void
    {
        $vm = $this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_FAILED,
            'vm_instance_uuid' => self::UUID,
            'vm_bound_esxi_host' => 'esxi01',
            'mecm_previous_id' => 'OLD-R1',
            'mecm_pending_since' => $this->at(-7201),
            'os_install_watch_started_at' => $this->at(-30000),
        ]);
        $evidence = $this->evidence([
            'is_template' => true,
            'last_job' => $this->job(['status' => 'failed']),
            'create_uncertain' => [['job_id' => 7, 'position' => 2]],
            'client_phases' => $this->clientPhases([VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => $this->phase('failed')]),
            'last_package_run' => $this->packageRun(['reported_failures' => 1]),
            'inventory' => $this->inventory(['vms' => []]),
        ]);

        self::assertSame([], virtusphere_vm_findings($vm, $evidence, self::NOW));
    }

    public function testAnUncertainCreateUnitIsTheFirstFindingAndLinksToItsJob(): void
    {
        $findings = virtusphere_vm_findings($this->vm(), $this->evidence([
            'create_uncertain' => [['job_id' => 7, 'position' => 2]],
        ]), self::NOW);

        self::assertSame(VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN, $findings[0]['kind']);
        self::assertSame(['job_id' => 7, 'position' => 2], $findings[0]['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => 7], $findings[0]['link']);
    }

    public function testAFailedJobWithATerminalStatusNamesModeTimeAndItsLog(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED]),
            $this->evidence(['last_job' => $this->job(['status' => 'failed', 'mode' => 'create', 'updated_at' => $this->at(-60)])]),
            self::NOW
        );

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_JOB_FAILED);
        self::assertSame(['job_id' => 7, 'mode' => 'create', 'time' => $this->at(-60)], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => 7], $finding['link']);
    }

    public function testAFailedLifecycleWithAStillRunningJobHasNoTerminalParams(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED]),
            $this->evidence(['last_job' => $this->job(['status' => 'running'])]),
            self::NOW
        );

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_JOB_FAILED);
        self::assertSame([], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY], $finding['link']);
    }

    public function testAFailedLifecycleWithoutAJobLinksToTheDeployPage(): void
    {
        $findings = virtusphere_vm_findings($this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED]), $this->evidence(), self::NOW);

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_JOB_FAILED);
        self::assertSame([], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY], $finding['link']);
    }

    public function testAFailedMecmSyncIsTheLastFindingAfterTheFailedJob(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_FAILED]),
            $this->evidence(['last_job' => $this->job(['status' => 'failed'])]),
            self::NOW
        );

        self::assertSame([VIRTUSPHERE_VM_FINDING_JOB_FAILED, VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED], $this->kinds($findings));
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => 7], $findings[1]['link']);
    }

    public function testADeployingVmWithoutAJobIsRunningWithoutParams(): void
    {
        $findings = virtusphere_vm_findings($this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYING]), $this->evidence(), self::NOW);

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_JOB_RUNNING);
        self::assertSame([], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY], $finding['link']);
    }

    public function testARunningJobIsSinceItsStartedAt(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYING]),
            $this->evidence(['last_job' => $this->job(['status' => 'running', 'started_at' => $this->at(-300)])]),
            self::NOW
        );

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_JOB_RUNNING);
        self::assertSame(['job_id' => 7, 'mode' => 'create', 'since' => $this->at(-300)], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => 7], $finding['link']);
    }

    public function testACancellingJobIsActiveAndACancelledOneIsNot(): void
    {
        $active = virtusphere_vm_findings($this->vm(), $this->evidence(['last_job' => $this->job(['status' => 'cancelling'])]), self::NOW);
        self::assertSame([VIRTUSPHERE_VM_FINDING_JOB_RUNNING], $this->kinds($active));

        $done = virtusphere_vm_findings($this->vm(), $this->evidence(['last_job' => $this->job(['status' => 'cancelled'])]), self::NOW);
        self::assertSame([], $done);
    }

    public function testAPreviousMecmDeviceIsATombstone(): void
    {
        $findings = virtusphere_vm_findings($this->vm(['mecm_previous_id' => 'OLD-R1']), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE], $this->kinds($findings));
        self::assertSame(['id' => 'OLD-R1'], $findings[0]['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM], $findings[0]['link']);
    }

    public function testAPendingMecmClockAtTheThresholdIsNotYetOverdue(): void
    {
        $since = $this->at(-VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS);
        $findings = virtusphere_vm_findings($this->pendingVm($since), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_MECM_PENDING], $this->kinds($findings));
        self::assertSame(['since' => $since], $findings[0]['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM], $findings[0]['link']);
    }

    public function testAPendingMecmClockOneSecondPastTheThresholdIsOverdue(): void
    {
        $since = $this->at(-VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS - 1);
        $findings = virtusphere_vm_findings($this->pendingVm($since), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE], $this->kinds($findings));
        self::assertSame(['since' => $since, 'hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600)], $findings[0]['params']);
    }

    /** @param string $since an unusable clock never produces an overdue warning */
    #[PHPUnit\Framework\Attributes\DataProvider('unusableMecmClocks')]
    public function testAPendingMecmWithoutAUsableClockSaysNoTimeAndNoOverdue(string $since): void
    {
        $findings = virtusphere_vm_findings($this->pendingVm($since), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_MECM_PENDING], $this->kinds($findings));
        self::assertSame(['since' => ''], $findings[0]['params']);
    }

    /**
     * The skew tolerance of virtusphere_evidence_timestamp() (300 s) still
     * accepts a clock a minute ahead; the raw value is shown, and it is not overdue.
     */
    public function testAFutureClockWithinTheSkewToleranceIsShownAsRecorded(): void
    {
        $since = $this->at(60);
        $findings = virtusphere_vm_findings($this->pendingVm($since), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_MECM_PENDING], $this->kinds($findings));
        self::assertSame(['since' => $since], $findings[0]['params']);
    }

    public function testAVmMissingFromAFreshAnsweredInventoryIsDanger(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['vm_instance_uuid' => self::UUID, 'vm_bound_esxi_host' => 'esxi01', 'vm_bound_credential_id' => 5]),
            $this->evidence(['inventory' => $this->inventory(['vms' => ['other-uuid' => 'poweredOn']])]),
            self::NOW
        );

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY);
        self::assertSame(['host' => 'esxi01', 'time' => $this->at(-60)], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_ESXI], $finding['link']);
        self::assertSame('danger', $finding['severity']);
    }

    /** @param bool $fresh @param bool $answered a stale or unanswered pull is not evidence for an absence */
    #[PHPUnit\Framework\Attributes\DataProvider('staleOrUnansweredInventories')]
    public function testAStaleOrUnansweredInventoryProvesNoAbsence(bool $fresh, bool $answered): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['vm_instance_uuid' => self::UUID, 'vm_bound_esxi_host' => 'esxi01']),
            $this->evidence(['inventory' => $this->inventory(['fresh' => $fresh, 'answered' => $answered, 'vms' => []])]),
            self::NOW
        );

        self::assertNotContains(VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY, $this->kinds($findings));
    }

    public function testAnUnboundVmIsNotLookedUpInTheInventory(): void
    {
        $findings = virtusphere_vm_findings($this->vm(), $this->evidence(['inventory' => $this->inventory()]), self::NOW);

        self::assertSame([], $findings);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('poweredOffWhileWindowsShouldWork')]
    public function testAPoweredOffBoundVmWhileWindowsShouldBeWorkingIsAFinding(string $lifecycle, string $mecm): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm([
                'lifecycle_state' => $lifecycle,
                'mecm_sync_state' => $mecm,
                'vm_instance_uuid' => self::UUID,
                'vm_bound_esxi_host' => 'esxi01',
            ]),
            $this->evidence(['inventory' => $this->inventory(['vms' => [self::UUID => 'poweredOff']])]),
            self::NOW
        );

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_POWERED_OFF);
        self::assertSame(['time' => $this->at(-60)], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR], $finding['link']);
        self::assertNotContains(VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY, $this->kinds($findings));
    }

    public function testAPoweredOffVmInTheMecmPhaseWithoutAnObservationIsNoFinding(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm([
                'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
                'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
                'vm_instance_uuid' => self::UUID,
                'vm_bound_esxi_host' => 'esxi01',
            ]),
            $this->evidence(['inventory' => $this->inventory(['vms' => [self::UUID => 'poweredOff']])]),
            self::NOW
        );

        self::assertNotContains(VIRTUSPHERE_VM_FINDING_POWERED_OFF, $this->kinds($findings));
    }

    public function testAPoweredOnVmIsNoPowerFinding(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm([
                'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
                'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
                'vm_instance_uuid' => self::UUID,
                'vm_bound_esxi_host' => 'esxi01',
            ]),
            $this->evidence(['inventory' => $this->inventory(['vms' => [self::UUID => 'poweredOn']])]),
            self::NOW
        );

        self::assertNotContains(VIRTUSPHERE_VM_FINDING_POWERED_OFF, $this->kinds($findings));
    }

    public function testAnInstallingVmWithoutItsObservationClockIsUnwatched(): void
    {
        $findings = virtusphere_vm_findings($this->installingVm(), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED], $this->kinds($findings));
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR], $findings[0]['link']);
    }

    public function testAnObservationAtTheThresholdIsWatchingNotOverdue(): void
    {
        $since = $this->at(-VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS);
        $findings = virtusphere_vm_findings($this->installingVm(['os_install_watch_started_at' => $since]), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING], $this->kinds($findings));
        self::assertSame(['since' => $since], $findings[0]['params']);
    }

    public function testAnObservationOneSecondPastTheThresholdIsOverdue(): void
    {
        $since = $this->at(-VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS - 1);
        $findings = virtusphere_vm_findings($this->installingVm(['os_install_watch_started_at' => $since]), $this->evidence(), self::NOW);

        self::assertSame([VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE], $this->kinds($findings));
        self::assertSame(['hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600)], $findings[0]['params']);
    }

    public function testAFutureObservationClockIsNoObservationFinding(): void
    {
        $findings = virtusphere_vm_findings(
            $this->installingVm(['os_install_watch_started_at' => $this->at(3600)]),
            $this->evidence(),
            self::NOW
        );

        self::assertSame([], $findings);
    }

    public function testAnAckedVmHasNoObservationFinding(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm([
                'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
                'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
                'os_install_watch_started_at' => $this->at(-30000),
            ]),
            $this->evidence(),
            self::NOW
        );

        self::assertSame([], $findings);
    }

    public function testAFailedClientPhaseNamesItsPhase(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED]),
            $this->evidence(['client_phases' => $this->clientPhases([VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => $this->phase('failed')])]),
            self::NOW
        );

        self::assertSame([VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED], $this->kinds($findings));
        self::assertSame(['phase' => VIRTUSPHERE_CLIENT_PHASE_HOSTNAME], $findings[0]['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR], $findings[0]['link']);
    }

    /** @param int $age seconds since the started event; the threshold itself is still running */
    #[PHPUnit\Framework\Attributes\DataProvider('startedClientPhaseAges')]
    public function testAStartedClientPhaseIsUnconfirmedOnlyPastItsThreshold(int $age, array $expectedKinds): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED]),
            $this->evidence(['client_phases' => $this->clientPhases([VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => $this->phase('started', -$age)])]),
            self::NOW
        );

        self::assertSame($expectedKinds, $this->kinds($findings));
        if ($expectedKinds !== []) {
            $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED);
            self::assertSame(
                ['phase' => VIRTUSPHERE_CLIENT_PHASE_HOSTNAME, 'minutes' => intdiv(VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS, 60)],
                $finding['params']
            );
        }
    }

    /** @param ?string $event the latest event of the phase; silence and a finish both say nothing */
    #[PHPUnit\Framework\Attributes\DataProvider('quietClientStates')]
    public function testAFinishedOrSilentClientPhaseIsNoFinding(?string $event): void
    {
        $phases = $event === null ? [] : [VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => $this->phase($event)];

        $findings = virtusphere_vm_findings(
            $this->vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED]),
            $this->evidence(['client_phases' => $this->clientPhases($phases)]),
            self::NOW
        );

        self::assertSame([], $findings);
    }

    public function testAReportedFailureInACompletedRunNamesThePackage(): void
    {
        $findings = virtusphere_vm_findings($this->vm(), $this->evidence(['last_package_run' => $this->packageRun(['reported_failures' => 1])]), self::NOW);

        $finding = $this->only($findings, VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED);
        self::assertSame(['package' => 'Paket 1.0'], $finding['params']);
        self::assertSame(['target' => VIRTUSPHERE_VM_FINDING_LINK_PACKAGE_RUN, 'run_id' => 'run-1'], $finding['link']);
    }

    public function testAFailedWrapperResultCountsEvenWithoutAReportedStep(): void
    {
        $findings = virtusphere_vm_findings(
            $this->vm(),
            $this->evidence(['last_package_run' => $this->packageRun(['reported_failures' => 0, 'wrapper_result' => 'failed'])]),
            self::NOW
        );

        self::assertSame([VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED], $this->kinds($findings));
    }

    public function testAnIncompleteRunOrOneWithoutFailuresIsNoFinding(): void
    {
        $incomplete = virtusphere_vm_findings(
            $this->vm(),
            $this->evidence(['last_package_run' => $this->packageRun(['completed_received_at' => null, 'reported_failures' => 2])]),
            self::NOW
        );
        self::assertSame([], $incomplete);

        $clean = virtusphere_vm_findings(
            $this->vm(),
            $this->evidence(['last_package_run' => $this->packageRun(['reported_failures' => 0, 'wrapper_result' => 'ok'])]),
            self::NOW
        );
        self::assertSame([], $clean);
    }

    public function testSeveralFindingsFollowTheSeverityOrderOfTheList(): void
    {
        $vm = $this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
            'mecm_previous_id' => 'OLD-R1',
            'mecm_pending_since' => $this->at(-3 * 3600),
        ]);
        $findings = virtusphere_vm_findings($vm, $this->evidence([
            'create_uncertain' => [['job_id' => 7, 'position' => 1]],
        ]), self::NOW);

        self::assertSame(
            [VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN, VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE, VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE],
            $this->kinds($findings)
        );

        $order = array_flip(array_keys(VIRTUSPHERE_VM_FINDINGS));
        $positions = array_map(static fn (string $kind): int => $order[$kind], $this->kinds($findings));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'the list is in severity order');

        foreach ($findings as $finding) {
            self::assertSame(VIRTUSPHERE_VM_FINDINGS[$finding['kind']], $finding['severity'], $finding['kind']);
        }
    }

    /** @return array<string,mixed> */
    private function vm(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_READY,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_NOT_READY,
            'vm_instance_uuid' => null,
            'vm_bound_esxi_host' => null,
            'vm_bound_credential_id' => null,
            'mecm_previous_id' => null,
            'mecm_pending_since' => null,
            'os_install_watch_started_at' => null,
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function pendingVm(string $since): array
    {
        return $this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
            'mecm_pending_since' => $since,
        ]);
    }

    /** @return array<string,mixed> */
    private function installingVm(array $overrides = []): array
    {
        return $this->vm($overrides + [
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
        ]);
    }

    /** @return array<string,mixed> */
    private function evidence(array $overrides = []): array
    {
        return $overrides + [
            'is_template' => false,
            'last_job' => null,
            'create_uncertain' => [],
            'client_phases' => $this->clientPhases([]),
            'last_package_run' => null,
            'inventory' => null,
        ];
    }

    /**
     * @param array<string,array<string,mixed>|null> $phases
     * @return array<string,array<string,mixed>|null>
     */
    private function clientPhases(array $phases): array
    {
        return array_replace(array_fill_keys(VIRTUSPHERE_CLIENT_PHASES, null), $phases);
    }

    /** @return array<string,mixed> */
    private function phase(string $event, int $offset = -60): array
    {
        return ['event' => $event, 'created_at' => $this->at($offset)];
    }

    /** @return array<string,mixed> */
    private function job(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'status' => 'running',
            'mode' => 'create',
            'started_at' => $this->at(-300),
            'created_at' => $this->at(-400),
            'updated_at' => $this->at(-60),
        ];
    }

    /** @return array<string,mixed> */
    private function packageRun(array $overrides = []): array
    {
        return $overrides + [
            'run_id' => 'run-1',
            'project_name' => 'Paket',
            'package_version' => '1.0',
            'completed_received_at' => $this->at(-30),
            'wrapper_result' => 'ok',
            'reported_failures' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function inventory(array $overrides = []): array
    {
        return $overrides + [
            'fresh' => true,
            'answered' => true,
            'fresh_at' => $this->at(-60),
            'vms' => [],
        ];
    }

    private function at(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW + $offset);
    }

    /** @param list<array{kind:string}> $findings @return list<string> */
    private function kinds(array $findings): array
    {
        return array_column($findings, 'kind');
    }

    /** @param list<array{kind:string}> $findings @return array<string,mixed> the one finding of this kind */
    private function only(array $findings, string $kind): array
    {
        $matches = array_values(array_filter($findings, static fn (array $finding): bool => $finding['kind'] === $kind));
        self::assertCount(1, $matches, $kind . ' must be listed exactly once');

        return $matches[0];
    }
}
