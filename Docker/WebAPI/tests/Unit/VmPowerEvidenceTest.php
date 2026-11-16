<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_progress.php';
require_once dirname(__DIR__, 2) . '/lib/mecm_rollout_fence.php';

/**
 * The pure evidence rule behind the automatic observation start (ADR-0038
 * amendment 2, plan VB1): when a registered VM counts as switched on.
 *
 * Two proofs exist. E1: the newest journal job of the VM ran its start step to
 * the end. E2: a fresh, answered inventory of the bound access shows the VM
 * powered on. Both must belong to the VM's CURRENT rollout: a start from the
 * round before a MECM-ID reset is not evidence, and that counterexample is the
 * first thing this test pins.
 */
final class VmPowerEvidenceTest extends TestCase
{
    private const UUID = '4207072c-edd8-3bd5-64dc-903fd3a0db04';
    private const RESET_AT = '2026-11-16 10:00:00';
    private const BEFORE_RESET = '2026-11-16 09:00:00';
    private const AFTER_RESET = '2026-11-16 11:00:00';

    /** @param array<string,mixed> $overrides */
    private static function vm(array $overrides = []): array
    {
        return $overrides + [
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
            'os_install_watch_started_at' => null,
            'mecm_rollout_revision' => VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL,
            'mecm_pending_since' => null,
            'vm_instance_uuid' => self::UUID,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private static function job(array $overrides = []): array
    {
        return $overrides + [
            'status' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
            'mode' => 'start',
            'failed_step' => null,
            'then_autostart' => false,
            'captured_at' => self::AFTER_RESET,
            'updated_at' => self::AFTER_RESET,
            'live' => false,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private static function inventory(array $overrides = []): array
    {
        return $overrides + [
            'fresh' => true,
            'answered' => true,
            'fresh_at' => self::AFTER_RESET,
            'vms' => [self::UUID => VIRTUSPHERE_VM_INVENTORY_POWERED_ON],
            'live' => false,
        ];
    }

    private const UNFENCED = ['fenced' => false, 'since' => ''];
    private const FENCED_SINCE_RESET = ['fenced' => true, 'since' => self::RESET_AT];
    private const FENCED_UNKNOWN = ['fenced' => true, 'since' => ''];

    // --- The fence: which evidence may be older than the rollout ------------

    public function testAVmThatWasNeverResetHasNoFence(): void
    {
        self::assertSame(self::UNFENCED, virtusphere_vm_rollout_fence(self::vm()));
    }

    public function testARaisedRevisionIsFencedByThePendingClockWhileTheVmIsPending(): void
    {
        $pending = self::vm([
            'mecm_rollout_revision' => VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL + 1,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
            'mecm_pending_since' => self::RESET_AT,
        ]);

        self::assertSame(self::FENCED_SINCE_RESET, virtusphere_vm_rollout_fence($pending));
    }

    /**
     * After the registration the pending clock is gone and the start of the
     * rollout is not stored anywhere. The fence then admits no past evidence,
     * and a leftover clock value of a VM that is not pending is never read.
     */
    public function testARaisedRevisionWithoutAPendingClockAdmitsNoPastEvidence(): void
    {
        $registered = self::vm(['mecm_rollout_revision' => 2, 'mecm_pending_since' => self::BEFORE_RESET]);

        self::assertSame(self::FENCED_UNKNOWN, virtusphere_vm_rollout_fence($registered));
    }

    /**
     * One definition of "never reset": a stored NULL is the initial revision,
     * as the owner of the wire fence reads it. A row read without the column
     * says nothing about resets and stays fenced.
     */
    public function testAStoredNullRevisionIsTheInitialOneAndAnUnreadRevisionIsFenced(): void
    {
        self::assertSame(self::UNFENCED, virtusphere_vm_rollout_fence(self::vm(['mecm_rollout_revision' => null])));
        self::assertTrue(mecm_rollout_fence_revision_current(null, null, null), 'the fence owner no longer reads NULL as the initial revision');

        $unread = self::vm();
        unset($unread['mecm_rollout_revision']);
        self::assertSame(self::FENCED_UNKNOWN, virtusphere_vm_rollout_fence($unread));
    }

    public function testEvidenceInRolloutNeedsBothTimesUnlessItIsLiveOrUnfenced(): void
    {
        self::assertTrue(virtusphere_vm_evidence_in_rollout(self::UNFENCED, '', false), 'no fence, no time needed');
        self::assertTrue(virtusphere_vm_evidence_in_rollout(self::FENCED_UNKNOWN, '', true), 'live evidence is current by construction');
        self::assertTrue(virtusphere_vm_evidence_in_rollout(self::FENCED_SINCE_RESET, self::RESET_AT, false), 'the exact boundary belongs to the rollout');
        self::assertTrue(virtusphere_vm_evidence_in_rollout(self::FENCED_SINCE_RESET, self::AFTER_RESET, false));
        self::assertFalse(virtusphere_vm_evidence_in_rollout(self::FENCED_SINCE_RESET, self::BEFORE_RESET, false));
        self::assertFalse(virtusphere_vm_evidence_in_rollout(self::FENCED_UNKNOWN, self::AFTER_RESET, false), 'an unknown rollout start proves nothing');
        self::assertFalse(virtusphere_vm_evidence_in_rollout(self::FENCED_SINCE_RESET, '', false), 'evidence without a time proves nothing');
        self::assertFalse(virtusphere_vm_evidence_in_rollout(self::FENCED_SINCE_RESET, 'not a time', false));
        self::assertFalse(virtusphere_vm_evidence_in_rollout(['fenced' => true, 'since' => 'not a time'], self::AFTER_RESET, false));
    }

    // --- The counterexample of step 1 ---------------------------------------

    /**
     * The start happened in the round before the MECM-ID reset. The VM is
     * registered again, but nothing proves that it was switched on since.
     */
    public function testAStartFromTheRoundBeforeAMecmResetIsNoEvidence(): void
    {
        $evidence = [
            'start_job' => self::job(['captured_at' => self::BEFORE_RESET]),
            'inventory' => null,
            'fence' => self::FENCED_SINCE_RESET,
        ];

        self::assertNull(virtusphere_vm_power_evidence(self::vm(['mecm_rollout_revision' => 2]), $evidence));
        self::assertNull(
            virtusphere_vm_power_evidence(self::vm(['mecm_rollout_revision' => 2]), ['fence' => self::FENCED_UNKNOWN] + $evidence),
            'after the registration the rollout start is unknown; the old start still proves nothing'
        );
    }

    public function testAnInventoryReadingFromBeforeTheResetIsNoEvidenceEither(): void
    {
        $evidence = [
            'start_job' => null,
            'inventory' => self::inventory(['fresh_at' => self::BEFORE_RESET]),
            'fence' => self::FENCED_SINCE_RESET,
        ];

        self::assertNull(virtusphere_vm_power_evidence(self::vm(['mecm_rollout_revision' => 2]), $evidence));
    }

    public function testEvidenceAfterTheResetCounts(): void
    {
        $vm = self::vm(['mecm_rollout_revision' => 2]);

        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
            virtusphere_vm_power_evidence($vm, ['start_job' => self::job(), 'inventory' => null, 'fence' => self::FENCED_SINCE_RESET])
        );
        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
            virtusphere_vm_power_evidence($vm, ['start_job' => null, 'inventory' => self::inventory(), 'fence' => self::FENCED_SINCE_RESET])
        );
    }

    /** The job that is ending and the inventory that is being applied are current by construction. */
    public function testLiveEvidenceCountsWhenTheRolloutStartIsUnknown(): void
    {
        $vm = self::vm(['mecm_rollout_revision' => 2]);

        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
            virtusphere_vm_power_evidence($vm, ['start_job' => self::job(['live' => true]), 'inventory' => null, 'fence' => self::FENCED_UNKNOWN])
        );
        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
            virtusphere_vm_power_evidence($vm, ['start_job' => null, 'inventory' => self::inventory(['live' => true]), 'fence' => self::FENCED_UNKNOWN])
        );
    }

    public function testWithoutAFenceInTheEvidenceNothingPastIsAccepted(): void
    {
        self::assertNull(virtusphere_vm_power_evidence(self::vm(), ['start_job' => self::job(), 'inventory' => self::inventory()]));
    }

    // --- E1: the start step -------------------------------------------------

    /** @return iterable<string, array{0:array<string,mixed>|null, 1:bool}> */
    public static function startStepJobs(): iterable
    {
        yield 'no job' => [null, false];
        yield 'succeeded full pipeline' => [['status' => 'succeeded', 'mode' => 'full'], true];
        yield 'succeeded start' => [['status' => 'succeeded', 'mode' => 'start'], true];
        yield 'succeeded start resuming a full pipeline' => [['status' => 'succeeded', 'mode' => 'start', 'then_autostart' => true], true];
        foreach (['create', 'powercycle', 'export', 'autostart'] as $mode) {
            yield 'succeeded ' . $mode . ' has no start step' => [['status' => 'succeeded', 'mode' => $mode], false];
        }
        yield 'full failed only in autostart' => [['status' => 'failed', 'mode' => 'full', 'failed_step' => 'autostart'], true];
        yield 'resumed start failed only in autostart' => [['status' => 'failed', 'mode' => 'start', 'failed_step' => 'autostart', 'then_autostart' => true], true];
        yield 'autostart mode failed in autostart: no start step before it' => [['status' => 'failed', 'mode' => 'autostart', 'failed_step' => 'autostart'], false];
        yield 'plain start cannot have failed in autostart' => [['status' => 'failed', 'mode' => 'start', 'failed_step' => 'autostart'], false];
        yield 'the start step itself failed (full)' => [['status' => 'failed', 'mode' => 'full', 'failed_step' => 'start'], false];
        yield 'the start step itself failed (start)' => [['status' => 'failed', 'mode' => 'start', 'failed_step' => 'start'], false];
        foreach (['powercycle', 'export'] as $step) {
            yield 'full failed before the start step, in ' . $step => [['status' => 'failed', 'mode' => 'full', 'failed_step' => $step], false];
        }
        yield 'failed without a remote step (preflight, create)' => [['status' => 'failed', 'mode' => 'full', 'failed_step' => null], false];
        // A create section that built only some VMs ends partial BEFORE the start step.
        yield 'partial' => [['status' => 'partial', 'mode' => 'full'], false];
        foreach (['queued', 'running', 'cancelling', 'cancelled', 'outcome_unknown'] as $status) {
            yield $status . ' proves no completed start' => [['status' => $status, 'mode' => 'full'], false];
        }
        yield 'unreadable mode' => [['status' => 'succeeded', 'mode' => null], false];
        yield 'empty mode' => [['status' => 'succeeded', 'mode' => ''], false];
        yield 'system inventory job' => [['status' => 'succeeded', 'mode' => 'inventory'], false];
        yield 'unknown mode never falls back to the full sequence' => [['status' => 'succeeded', 'mode' => 'somethingelse'], false];
    }

    /** @param array<string,mixed>|null $job */
    #[DataProvider('startStepJobs')]
    public function testOnlyACompletedStartStepIsEvidence(?array $job, bool $expected): void
    {
        self::assertSame($expected, virtusphere_vm_job_completed_start_step($job));
    }

    /** The mode list is derived from the sequence, so a new mode with a start step is covered. */
    public function testEveryUserModeWithTheStartPlaybookIsAccepted(): void
    {
        $withStart = [];
        foreach (virtusphere_user_deploy_modes() as $mode) {
            $inSequence = in_array(VIRTUSPHERE_PLAYBOOKS['start'], ansible_playbooks_for_mode($mode), true);
            self::assertSame($inSequence, virtusphere_vm_job_completed_start_step(['status' => 'succeeded', 'mode' => $mode]), $mode);
            if ($inSequence) {
                $withStart[] = $mode;
            }
        }
        self::assertNotSame([], $withStart, 'no mode runs the start playbook; this test would prove nothing');
    }

    // --- E2: the inventory ---------------------------------------------------

    /** @return iterable<string, array{0:array<string,mixed>, 1:array<string,mixed>|null, 2:?string}> */
    public static function inventoryReadings(): iterable
    {
        yield 'fresh, answered, powered on' => [self::vm(), self::inventory(), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY];
        yield 'stale' => [self::vm(), self::inventory(['fresh' => false]), null];
        yield 'unanswered' => [self::vm(), self::inventory(['answered' => false]), null];
        yield 'no inventory for the access' => [self::vm(), null, null];
        yield 'powered off' => [self::vm(), self::inventory(['vms' => [self::UUID => VIRTUSPHERE_VM_INVENTORY_POWERED_OFF]]), null];
        yield 'suspended' => [self::vm(), self::inventory(['vms' => [self::UUID => 'suspended']]), null];
        yield 'power state not reported' => [self::vm(), self::inventory(['vms' => [self::UUID => null]]), null];
        yield 'not on the host' => [self::vm(), self::inventory(['vms' => ['other-uuid' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON]]), null];
        yield 'VM not bound to an ESXi instance' => [self::vm(['vm_instance_uuid' => '']), self::inventory(), null];
    }

    /**
     * @param array<string,mixed> $vm
     * @param array<string,mixed>|null $inventory
     */
    #[DataProvider('inventoryReadings')]
    public function testOnlyAFreshAnsweredPoweredOnReadingIsEvidence(array $vm, ?array $inventory, ?string $expected): void
    {
        self::assertSame($expected, virtusphere_vm_power_evidence($vm, ['start_job' => null, 'inventory' => $inventory, 'fence' => self::UNFENCED]));
    }

    // --- Both sources: the newer reading decides ------------------------------

    public function testAFailedStartStepLeavesTheDecisionToTheInventory(): void
    {
        $failedStart = self::job(['status' => 'failed', 'mode' => 'full', 'failed_step' => 'start']);

        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
            virtusphere_vm_power_evidence(self::vm(), ['start_job' => $failedStart, 'inventory' => self::inventory(), 'fence' => self::UNFENCED])
        );
        self::assertNull(
            virtusphere_vm_power_evidence(self::vm(), ['start_job' => $failedStart, 'inventory' => self::inventory(['fresh' => false]), 'fence' => self::UNFENCED])
        );
    }

    public function testAConfirmedPortalStartKeepsThePortalAsItsSource(): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
            virtusphere_vm_power_evidence(self::vm(), ['start_job' => self::job(), 'inventory' => self::inventory(), 'fence' => self::UNFENCED])
        );
    }

    /** @return iterable<string, array{0:array<string,mixed>, 1:array<string,mixed>, 2:?string}> */
    public static function contradictions(): iterable
    {
        $off = [self::UUID => VIRTUSPHERE_VM_INVENTORY_POWERED_OFF];
        $gone = ['other-uuid' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON];
        // Claimed an hour before it ended: a reading from in between saw the VM before the start step.
        $claimed = ['captured_at' => self::BEFORE_RESET, 'updated_at' => self::RESET_AT];

        yield 'off, read after the job ended' => [self::job($claimed), self::inventory(['vms' => $off, 'fresh_at' => self::AFTER_RESET]), null];
        yield 'off, read as the job ended' => [self::job($claimed), self::inventory(['vms' => $off, 'fresh_at' => self::RESET_AT]), null];
        yield 'gone from the host after the job ended' => [self::job($claimed), self::inventory(['vms' => $gone, 'fresh_at' => self::AFTER_RESET]), null];
        yield 'off, but read while the job still ran' => [
            self::job($claimed), self::inventory(['vms' => $off, 'fresh_at' => '2026-11-16 09:30:00']), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'off, but read before the job was claimed' => [
            self::job($claimed), self::inventory(['vms' => $off, 'fresh_at' => '2026-11-16 08:00:00']), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'off, and the end of the job is not known' => [
            self::job(['updated_at' => null] + $claimed), self::inventory(['vms' => $off, 'fresh_at' => self::AFTER_RESET]), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'off in the inventory that is being applied' => [
            self::job($claimed), self::inventory(['vms' => $off, 'fresh_at' => self::AFTER_RESET, 'live' => true, 'read_since' => self::RESET_AT]), null,
        ];
        yield 'off in a pull that was claimed while the job still ran' => [
            self::job($claimed),
            self::inventory(['vms' => $off, 'fresh_at' => self::AFTER_RESET, 'live' => true, 'read_since' => '2026-11-16 09:30:00']),
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'the job that is ending is newer than any stored reading' => [
            self::job($claimed + ['live' => true]), self::inventory(['vms' => $off, 'fresh_at' => self::AFTER_RESET]), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'a stale powered-off reading says nothing' => [
            self::job($claimed), self::inventory(['vms' => $off, 'fresh' => false]), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'an unanswered pull says nothing' => [
            self::job($claimed), self::inventory(['vms' => $off, 'answered' => false]), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
        yield 'a suspended VM does not take the start back' => [
            self::job($claimed), self::inventory(['vms' => [self::UUID => 'suspended']]), VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $inventory
     */
    #[DataProvider('contradictions')]
    public function testANewerPoweredOffReadingTakesThePortalStartBack(array $job, array $inventory, ?string $expected): void
    {
        self::assertSame($expected, virtusphere_vm_power_evidence(self::vm(), ['start_job' => $job, 'inventory' => $inventory, 'fence' => self::UNFENCED]));
    }

    /**
     * A power cycle switches a VM on and off again. A reading that caught it
     * switched on in between, or before a later job ended, is outdated: it must
     * not start a clock for a VM the job left switched off.
     *
     * @return iterable<string, array{0:array<string,mixed>, 1:array<string,mixed>, 2:?string}>
     */
    public static function poweredOnReadingsAroundAJob(): iterable
    {
        $noStart = ['status' => 'succeeded', 'mode' => 'powercycle', 'captured_at' => self::BEFORE_RESET, 'updated_at' => self::RESET_AT];

        yield 'read after the job ended' => [self::job($noStart), self::inventory(['fresh_at' => self::AFTER_RESET]), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY];
        yield 'read as the job ended' => [self::job($noStart), self::inventory(['fresh_at' => self::RESET_AT]), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY];
        yield 'read while the job ran' => [self::job($noStart), self::inventory(['fresh_at' => '2026-11-16 09:30:00']), null];
        yield 'read before the job was claimed' => [self::job($noStart), self::inventory(['fresh_at' => '2026-11-16 08:00:00']), null];
        yield 'applied now, its pull claimed after the job ended' => [
            self::job($noStart), self::inventory(['live' => true, 'read_since' => self::RESET_AT]), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
        ];
        yield 'applied now, the claim of its pull not known: the apply time counts' => [
            self::job($noStart), self::inventory(['live' => true]), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
        ];
        // The pull read the host mid power cycle and was applied after the job had switched the VM off.
        yield 'applied now, but its pull was claimed while the job ran' => [
            self::job($noStart), self::inventory(['live' => true, 'read_since' => '2026-11-16 09:30:00']), null,
        ];
        yield 'the claim time of a pull is ignored for a stored reading' => [
            self::job($noStart), self::inventory(['read_since' => '2026-11-16 09:30:00']), VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
        ];
        yield 'the end of the job is not known' => [self::job(['updated_at' => ''] + $noStart), self::inventory(['fresh_at' => self::AFTER_RESET]), null];
        foreach (VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES as $status) {
            yield 'applied now, but the newest job is still ' . $status => [
                self::job(['status' => $status] + $noStart), self::inventory(['live' => true]), null,
            ];
        }
        yield 'a failed start step that ended after the reading' => [
            self::job(['status' => 'failed', 'mode' => 'full', 'failed_step' => 'start'] + $noStart), self::inventory(['fresh_at' => '2026-11-16 09:30:00']), null,
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $inventory
     */
    #[DataProvider('poweredOnReadingsAroundAJob')]
    public function testAPoweredOnReadingCountsOnlyAfterTheNewestJobEnded(array $job, array $inventory, ?string $expected): void
    {
        self::assertSame($expected, virtusphere_vm_power_evidence(self::vm(), ['start_job' => $job, 'inventory' => $inventory, 'fence' => self::UNFENCED]));
    }

    // --- The gate around the evidence -----------------------------------------

    /** @return iterable<string, array{0:array<string,mixed>, 1:bool}> */
    public static function vmsThatMustNotStart(): iterable
    {
        yield 'template' => [self::vm(), true];
        yield 'clock already running: the automatic start never restarts' => [self::vm(['os_install_watch_started_at' => self::AFTER_RESET]), false];
        yield 'still waiting for MECM' => [self::vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING]), false];
        yield 'Windows already reported' => [self::vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED]), false];
        yield 'registered but not installing' => [self::vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED]), false];
        yield 'installing but not registered' => [self::vm(['mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED]), false];
        yield 'failed' => [self::vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED]), false];
    }

    /** @param array<string,mixed> $vm */
    #[DataProvider('vmsThatMustNotStart')]
    public function testTheAutomaticStartIsOnlyForARegisteredInstallingVmWithAnEmptyClock(array $vm, bool $isTemplate): void
    {
        $evidence = ['is_template' => $isTemplate, 'start_job' => self::job(), 'inventory' => self::inventory(), 'fence' => self::UNFENCED];

        self::assertNull(virtusphere_vm_install_watch_auto_source($vm, $evidence));
    }

    public function testARegisteredInstallingVmWithEvidenceStarts(): void
    {
        $evidence = ['is_template' => false, 'start_job' => self::job(), 'inventory' => null, 'fence' => self::UNFENCED];

        self::assertSame(VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START, virtusphere_vm_install_watch_auto_source(self::vm(), $evidence));
        self::assertNull(virtusphere_vm_install_watch_auto_source(self::vm(), ['start_job' => null] + $evidence), 'no evidence, no clock');
    }

    // --- The source shown in the editor ---------------------------------------

    public function testEveryWatchNoteNamesOneSourceAndTheSourcesAreClosed(): void
    {
        self::assertSame(
            [VIRTUSPHERE_VM_WATCH_SOURCE_OPERATOR, VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START, VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY],
            array_values(VIRTUSPHERE_VM_WATCH_NOTES)
        );
        foreach (VIRTUSPHERE_VM_POWER_EVIDENCE_SOURCES as $source) {
            self::assertContains($source, VIRTUSPHERE_VM_WATCH_NOTES, 'the automatic source ' . $source . ' has no status note; its clock would be written without a record');
        }
    }

    public function testTheSourceIsTheNewestWatchNoteWrittenWithTheClock(): void
    {
        $since = '2026-11-16 12:00:00';
        $events = [
            ['note' => 'mecm update id', 'created_at' => '2026-11-16 12:00:05'],
            ['note' => VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY, 'created_at' => '2026-11-16 12:00:01'],
            ['note' => VIRTUSPHERE_VM_WATCH_NOTE_OPERATOR, 'created_at' => '2026-11-15 08:00:00'],
        ];

        self::assertSame(VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY, virtusphere_vm_install_watch_source($events, $since));
        self::assertSame(
            VIRTUSPHERE_VM_WATCH_SOURCE_OPERATOR,
            virtusphere_vm_install_watch_source([['note' => VIRTUSPHERE_VM_WATCH_NOTE_OPERATOR, 'created_at' => $since]], $since)
        );
        self::assertSame(
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
            virtusphere_vm_install_watch_source([['note' => VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START, 'created_at' => $since]], $since)
        );
    }

    /**
     * A history row is best effort and ages out. When the newest watch note
     * belongs to an earlier clock, the source is unknown and nothing is claimed.
     */
    public function testANoteOfAnEarlierClockNamesNoSource(): void
    {
        $since = '2026-11-16 12:00:00';
        $older = [['note' => VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START, 'created_at' => '2026-11-10 12:00:00']];

        self::assertNull(virtusphere_vm_install_watch_source($older, $since));
        self::assertNull(virtusphere_vm_install_watch_source([], $since));
        self::assertNull(virtusphere_vm_install_watch_source([['note' => 'mecm update id', 'created_at' => $since]], $since));
        self::assertNull(virtusphere_vm_install_watch_source($older, ''), 'no clock, no source');
        self::assertNull(virtusphere_vm_install_watch_source($older, 'not a time'));
        self::assertNull(virtusphere_vm_install_watch_source([['note' => VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY, 'created_at' => 'not a time']], $since));
    }
}
