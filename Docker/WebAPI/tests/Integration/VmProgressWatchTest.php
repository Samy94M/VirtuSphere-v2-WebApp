<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';

final class VmProgressWatchTest extends TestCase
{
    use ClientIpAllowlist;

    private const PREFIX = 'phpunit_vmwatch_';
    private const RESET_REVISION = VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL + 1;

    private mysqli $db;
    private int $missionId = 0;

    public function testReceivedPhaseFailureAppearsUntilANewerFinishAndSilenceDoesNot(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $other = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        repo_record_client_event($this->db, $vmId, '00:11:22:33:44:55', VIRTUSPHERE_CLIENT_PHASES[0], VIRTUSPHERE_CLIENT_EVENT_FAILED, 'synthetic failure', '127.0.0.1');
        $ids = array_column(repo_client_phase_failures($this->db), 'id');
        self::assertContains($vmId, array_map('intval', $ids));
        self::assertNotContains($other, array_map('intval', $ids));
        repo_record_client_event($this->db, $vmId, '00:11:22:33:44:55', VIRTUSPHERE_CLIENT_PHASES[0], VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        self::assertNotContains($vmId, array_map('intval', array_column(repo_client_phase_failures($this->db), 'id')));
    }

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
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testPendingWatchRestartChangesOnlyItsClockAndWritesHistory(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL 1 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ' . $vmId);
        $before = $this->row($vmId);

        self::assertSame(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId));

        $after = $this->row($vmId);
        self::assertSame($before['lifecycle_state'], $after['lifecycle_state']);
        self::assertSame($before['mecm_sync_state'], $after['mecm_sync_state']);
        self::assertSame($before['updated_at'], $after['updated_at'], 'an observation clock is not a VM edit');
        self::assertNotSame($before['mecm_pending_since'], $after['mecm_pending_since']);
        self::assertNull($after['os_install_watch_started_at']);
        self::assertSame(1, $this->eventCount($vmId, 'MECM pending observation restarted'));
    }

    public function testInstallWatchIsExplicitAndRepeatableWithoutChangingLifecycle(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);

        self::assertSame(VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING, repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId));
        $first = $this->row($vmId);
        self::assertNotNull($first['os_install_watch_started_at']);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $first['lifecycle_state']);

        $this->db->query('UPDATE deploy_vms SET os_install_watch_started_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $vmId);
        $beforeRestart = $this->row($vmId);
        repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId);
        self::assertNotSame($beforeRestart['os_install_watch_started_at'], $this->row($vmId)['os_install_watch_started_at']);
        self::assertSame(2, $this->eventCount($vmId, 'OS installation observation restarted'));
    }

    public function testUnrelatedStateCannotStartAWatch(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY);

        $this->expectException(RuntimeException::class);
        repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId);
    }

    public function testAttentionCountUsesDedicatedClocksAndNeverUpdatedAt(): void
    {
        $oldPending = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $freshPending = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $unwatchedInstall = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $oldInstall = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $oldPending);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = NOW(), updated_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ' . $freshPending);
        $this->db->query('UPDATE deploy_vms SET os_install_watch_started_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $oldInstall);

        self::assertSame(2, repo_vm_progress_attention_count($this->db));
        self::assertSame(2, repo_vm_progress_attention_count($this->db, $this->missionId));
        self::assertNull($this->row($unwatchedInstall)['os_install_watch_started_at']);
    }

    /**
     * VT-E1 C: a client ACK before the binding leaves os_installed / pending.
     * Count, per-mission count and restart all treat that as the pending watch,
     * the same answer virtusphere_vm_progress_watch_kind() gives.
     */
    public function testInstalledButStillPendingIsCountedAndRestartable(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $vmId);

        self::assertSame(1, repo_vm_progress_attention_count($this->db, $this->missionId));
        self::assertSame(1, repo_vm_progress_attention_counts_by_mission($this->db)[$this->missionId] ?? 0);
        self::assertSame(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, repo_restart_vm_progress_watch($this->db, $this->missionId, $vmId));
        self::assertSame(0, repo_vm_progress_attention_count($this->db, $this->missionId));
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $this->row($vmId)['lifecycle_state']);
    }

    // --- The automatic start on power-on evidence (ADR-0038 amendment 2) ----

    public function testAJobThatCompletedItsStartStepStartsAnEmptyClockAndNothingElse(): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $notRegistered = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
        $this->journal($jobId, $vmId);
        $this->journal($jobId, $notRegistered);
        $this->db->query('UPDATE deploy_vms SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE mission_id = ' . $this->missionId);
        $before = $this->row($vmId);
        $bystander = $this->row($notRegistered);

        self::assertSame(1, repo_start_install_watches_after_job($this->db, $jobId));

        $after = $this->row($vmId);
        self::assertNotNull($after['os_install_watch_started_at']);
        self::assertSame($before['lifecycle_state'], $after['lifecycle_state']);
        self::assertSame($before['mecm_sync_state'], $after['mecm_sync_state']);
        self::assertSame($before['mecm_pending_since'], $after['mecm_pending_since']);
        self::assertSame($before['updated_at'], $after['updated_at'], 'an observation clock is not a VM edit');
        self::assertSame(1, $this->eventCount($vmId, VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START), 'the status note is the only record of the source');
        self::assertSame($bystander, $this->row($notRegistered), 'a VM MECM has not registered is not observed');
        self::assertSame(0, $this->watchEventCount($notRegistered));

        // The same trigger again, and the other two, write nothing more.
        self::assertSame(0, repo_start_install_watches_after_job($this->db, $jobId));
        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $vmId, ['live_job_id' => $jobId]));
        self::assertSame($after, $this->row($vmId));
        self::assertSame(1, $this->watchEventCount($vmId));
    }

    public function testTheAutomaticStartNeverRestartsARunningClock(): void
    {
        $credential = $this->credential();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-running');
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
        $this->journal($jobId, $vmId);
        $this->inventory($credential, ['uuid-running' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON]);
        $this->db->query('UPDATE deploy_vms SET os_install_watch_started_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ' . $vmId);
        $before = $this->row($vmId);

        self::assertSame(0, repo_start_install_watches_after_job($this->db, $jobId));
        self::assertSame(0, repo_start_install_watches_after_inventory($this->db, $credential));
        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $vmId, ['live_job_id' => $jobId, 'live_inventory_credential_id' => $credential]));

        self::assertSame($before, $this->row($vmId), 'restarting stays the operator\'s action');
        self::assertSame(0, $this->watchEventCount($vmId));
    }

    /** @return iterable<string, array{0:string, 1:array<string,mixed>, 2:?string, 3:bool}> */
    public static function endedJobs(): iterable
    {
        yield 'full pipeline succeeded' => [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'full'], null, true];
        yield 'full pipeline failed only in autostart' => [VIRTUSPHERE_DEPLOY_STATUS_FAILED, ['mode' => 'full'], 'autostart', true];
        yield 'resumed start failed only in autostart' => [VIRTUSPHERE_DEPLOY_STATUS_FAILED, ['mode' => 'start', 'then_autostart' => true], 'autostart', true];
        yield 'the start step failed' => [VIRTUSPHERE_DEPLOY_STATUS_FAILED, ['mode' => 'start'], 'start', false];
        yield 'failed before the start step' => [VIRTUSPHERE_DEPLOY_STATUS_FAILED, ['mode' => 'full'], 'export', false];
        yield 'create only' => [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'create'], null, false];
        yield 'autostart policy only' => [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'autostart'], null, false];
        yield 'partial' => [VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, ['mode' => 'full'], null, false];
        yield 'cancelled' => [VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, ['mode' => 'start'], null, false];
        yield 'outcome unknown' => [VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, ['mode' => 'start'], null, false];
        yield 'unreadable payload' => [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['scope' => 'no mode'], null, false];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('endedJobs')]
    public function testOnlyAJobWhoseStartStepRanToItsEndIsEvidence(string $status, array $payload, ?string $failedStep, bool $expected): void
    {
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $jobId = $this->insertJob($status, $payload, $failedStep);
        $this->journal($jobId, $vmId);

        self::assertSame($expected ? 1 : 0, repo_start_install_watches_after_job($this->db, $jobId));
        self::assertSame($expected, $this->row($vmId)['os_install_watch_started_at'] !== null);
        self::assertSame($expected ? 1 : 0, $this->eventCount($vmId, VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START));
    }

    /**
     * The counterexample of VB1 step 1 at the writer. The VM was started, then
     * its MECM ID was reset, and MECM registered it again. Nothing proves that
     * it was switched on in this round, so the old job starts no clock. A VM
     * that was never reset has only one round, and there the same job counts.
     */
    public function testAStartFromTheRoundBeforeAMecmResetStartsNoClock(): void
    {
        $reset = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, null, null, self::RESET_REVISION);
        $neverReset = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
        $this->journal($jobId, $reset);
        $this->journal($jobId, $neverReset);

        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $reset));
        self::assertNull($this->row($reset)['os_install_watch_started_at']);
        self::assertSame(0, $this->watchEventCount($reset));

        self::assertSame(VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START, repo_start_vm_install_watch_on_evidence($this->db, $neverReset));

        // A job that ends now belongs to the current round: a reset is refused while a job runs.
        self::assertSame(VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START, repo_start_vm_install_watch_on_evidence($this->db, $reset, ['live_job_id' => $jobId]));
    }

    public function testAnAnsweredInventoryThatShowsTheVmPoweredOnStartsTheClock(): void
    {
        $credential = $this->credential();
        $otherAccess = $this->credential();
        $on = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-on');
        $off = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-off');
        $gone = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-gone');
        $suspended = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-suspended');
        $elsewhere = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $otherAccess, 'uuid-on');
        $unbound = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED);
        $this->inventory($credential, [
            'uuid-on' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON,
            'uuid-off' => VIRTUSPHERE_VM_INVENTORY_POWERED_OFF,
            'uuid-suspended' => 'suspended',
        ]);
        $this->db->query('UPDATE deploy_vms SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ' . $on);
        $before = $this->row($on);

        self::assertSame(1, repo_start_install_watches_after_inventory($this->db, $credential));

        $after = $this->row($on);
        self::assertNotNull($after['os_install_watch_started_at']);
        self::assertSame($before['updated_at'], $after['updated_at']);
        self::assertSame($before['lifecycle_state'], $after['lifecycle_state']);
        self::assertSame($before['mecm_sync_state'], $after['mecm_sync_state']);
        self::assertSame(1, $this->eventCount($on, VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY));
        foreach (['powered off' => $off, 'not on the host' => $gone, 'suspended' => $suspended, 'bound to another access' => $elsewhere, 'unbound' => $unbound] as $why => $vmId) {
            self::assertNull($this->row($vmId)['os_install_watch_started_at'], $why);
            self::assertSame(0, $this->watchEventCount($vmId), $why);
        }

        self::assertSame(0, repo_start_install_watches_after_inventory($this->db, $credential), 'the second pull adds nothing');
        self::assertSame(1, $this->watchEventCount($on));
    }

    /** @return iterable<string, array{0:int, 1:string}> */
    public static function readingsThatSayNothing(): iterable
    {
        yield 'stale' => [30 * 86400, VIRTUSPHERE_INVENTORY_QUERY_ANSWERED];
        yield 'the vm query did not answer' => [0, 'failed'];
    }

    #[DataProvider('readingsThatSayNothing')]
    public function testAStaleOrUnansweredInventoryStartsNoClock(int $ageSeconds, string $outcome): void
    {
        $credential = $this->credential();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-quiet');
        $this->inventory($credential, ['uuid-quiet' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON], $ageSeconds, $outcome);

        // Called without the live mark: a stored reading is judged by its own freshness.
        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $vmId));
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);
    }

    /** After a reset the stored reading may be from the round before; the pull being applied is not. */
    public function testAStoredInventoryReadingOfAResetVmCountsOnlyWhileItIsApplied(): void
    {
        $credential = $this->credential();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-reset', self::RESET_REVISION);
        $this->inventory($credential, ['uuid-reset' => VIRTUSPHERE_VM_INVENTORY_POWERED_ON]);

        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $vmId));
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);

        self::assertSame(1, repo_start_install_watches_after_inventory($this->db, $credential));
        self::assertSame(1, $this->eventCount($vmId, VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY));
    }

    /** The newer reading decides: an inventory taken after the job shows the VM switched off again. */
    public function testANewerPoweredOffReadingTakesThePortalStartBack(): void
    {
        $credential = $this->credential();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, $credential, 'uuid-back');
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
        $this->journal($jobId, $vmId, 3600);
        $this->inventory($credential, ['uuid-back' => VIRTUSPHERE_VM_INVENTORY_POWERED_OFF]);

        self::assertNull(repo_start_vm_install_watch_on_evidence($this->db, $vmId));
        self::assertSame(0, repo_start_install_watches_after_inventory($this->db, $credential));
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);

        // The reading is older than a start that ends now.
        self::assertSame(1, repo_start_install_watches_after_job($this->db, $jobId));
        self::assertSame(1, $this->eventCount($vmId, VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START));
    }

    public function testAVmOfATemplateMissionGetsNoClock(): void
    {
        $templateName = VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . bin2hex(random_bytes(3));
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [$templateName]);
        $templateMission = (int) $this->db->insert_id;
        try {
            $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, null, null, VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL, $templateMission);
            $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
            $this->journal($jobId, $vmId);

            self::assertSame(0, repo_start_install_watches_after_job($this->db, $jobId));
            self::assertNull($this->row($vmId)['os_install_watch_started_at']);
        } finally {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$templateMission]);
        }
    }

    // --- Trigger 1: the registration callback (mecm_updateid.php) -----------

    public function testTheRegistrationStartsTheClockOfAVmThePortalAlreadyStarted(): void
    {
        $this->requireWebStack();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = NOW() WHERE id = ' . $vmId);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'full']);
        $this->journal($jobId, $vmId);

        [$status, $body] = $this->register($vmId, VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL);

        self::assertSame(200, $status, $body);
        $row = $this->row($vmId);
        self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, $row['lifecycle_state']);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $row['mecm_sync_state']);
        self::assertNotNull($row['os_install_watch_started_at'], 'registered and started by the portal: observed without a click');
        self::assertSame(1, $this->eventCount($vmId, VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START));

        // The sync re-reporting the same ResourceID is a no-op for the clock too.
        [$status, $body] = $this->register($vmId, VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL);
        self::assertSame(200, $status, $body);
        self::assertSame($row['os_install_watch_started_at'], $this->row($vmId)['os_install_watch_started_at']);
        self::assertSame(1, $this->watchEventCount($vmId));
    }

    /** The fallback the button exists for: registered, but nothing says the VM is on. */
    public function testTheRegistrationWithoutEvidenceLeavesTheClockEmpty(): void
    {
        $this->requireWebStack();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'create']);
        $this->journal($jobId, $vmId);

        [$status, $body] = $this->register($vmId, VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL);

        self::assertSame(200, $status, $body);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $this->row($vmId)['mecm_sync_state']);
        self::assertNull($this->row($vmId)['os_install_watch_started_at']);
        self::assertSame(0, $this->watchEventCount($vmId));
    }

    /**
     * The counterexample through the wire: the callback reads the rollout start
     * (mecm_pending_since, written by the reset) before the registration clears
     * it. A start claimed before that moment belongs to the round before.
     *
     * @return iterable<string, array{0:int, 1:int, 2:bool}> seconds since the reset, seconds since the job's claim, clock expected
     */
    public static function startsAroundAReset(): iterable
    {
        yield 'the start is older than the reset' => [600, 3600, false];
        yield 'the start followed the reset' => [3600, 600, true];
    }

    #[DataProvider('startsAroundAReset')]
    public function testTheRegistrationOfAResetVmAcceptsOnlyAStartAfterTheReset(int $resetAgo, int $claimedAgo, bool $expected): void
    {
        $this->requireWebStack();
        $vmId = $this->insertVm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, null, null, self::RESET_REVISION);
        $this->db->query('UPDATE deploy_vms SET mecm_pending_since = DATE_SUB(NOW(), INTERVAL ' . $resetAgo . ' SECOND) WHERE id = ' . $vmId);
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, ['mode' => 'start']);
        $this->journal($jobId, $vmId, $claimedAgo);

        [$status, $body] = $this->register($vmId, self::RESET_REVISION);

        self::assertSame(200, $status, $body);
        $row = $this->row($vmId);
        self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $row['mecm_sync_state']);
        self::assertSame($expected, $row['os_install_watch_started_at'] !== null);
        self::assertSame($expected ? 1 : 0, $this->watchEventCount($vmId));
    }

    private function insertVm(string $lifecycle, string $mecm, ?int $credentialId = null, ?string $uuid = null, int $revision = VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL, ?int $missionId = null): int
    {
        $name = 'WATCH' . bin2hex(random_bytes(4));
        $host = $credentialId === null ? null : 'esxi.example.com';
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, mecm_rollout_revision, vm_instance_uuid, vm_bound_credential_id, vm_bound_esxi_host) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'issssisis',
            [$missionId ?? $this->missionId, $name, $name, $lifecycle, $mecm, $revision, $uuid, $credentialId, $host]
        );

        return (int) $this->db->insert_id;
    }

    /** @param array<string,mixed> $payload */
    private function insertJob(string $status, array $payload, ?string $failedStep = null): int
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_jobs (mission_id, status, payload_json, failed_step, started_at) VALUES (?, ?, ?, ?, NOW())',
            'isss',
            [$this->missionId, $status, json_encode($payload, JSON_THROW_ON_ERROR), $failedStep]
        );

        return (int) $this->db->insert_id;
    }

    /** The journal row of a claim, optionally claimed some seconds ago (captured_at is the immutable claim time). */
    private function journal(int $jobId, int $vmId, int $claimedSecondsAgo = 0): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before, captured_at) VALUES (?, ?, ?, ?, DATE_SUB(NOW(6), INTERVAL ? SECOND))',
            'iissi',
            [$jobId, $vmId, VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, $claimedSecondsAgo]
        );
    }

    private function credential(): int
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)',
            'sssiss',
            [VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, self::PREFIX . bin2hex(random_bytes(4)), 'esxi.example.com', 443, 'svc', 'x']
        );

        return (int) $this->db->insert_id;
    }

    /**
     * The cached `vm` inventory of one access the way the pull leaves it: the
     * per-kind freshness stamp and observation, and one row per VM.
     *
     * @param array<string,string> $vms instance UUID => power state
     */
    private function inventory(int $credentialId, array $vms, int $ageSeconds = 0, string $outcome = VIRTUSPHERE_INVENTORY_QUERY_ANSWERED): void
    {
        $freshAt = (string) repo_scalar($this->db, 'SELECT DATE_SUB(NOW(), INTERVAL ? SECOND)', 'i', [$ageSeconds]);
        repo_execute($this->db, 'DELETE FROM deploy_esxi_inventory_state WHERE credential_id = ?', 'i', [$credentialId]);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_esxi_inventory_state (credential_id, kind_freshness_json, kind_observation_json) VALUES (?, ?, ?)',
            'iss',
            [$credentialId, json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => $freshAt]), json_encode([VIRTUSPHERE_INVENTORY_KIND_VM => ['outcome' => $outcome]])]
        );
        foreach ($vms as $uuid => $powerState) {
            repo_execute(
                $this->db,
                "INSERT INTO deploy_esxi_inventory (credential_id, kind, name, meta_json) VALUES (?, 'vm', ?, ?)",
                'iss',
                [$credentialId, self::PREFIX . $uuid, json_encode(['instance_uuid' => $uuid, 'power_state' => $powerState])]
            );
        }
    }

    private function requireWebStack(): void
    {
        if (@file_get_contents(virtusphere_test_base_url() . '/portal/health.php') === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        $this->ensureClientIpAllowlisted($this->db);
    }

    /**
     * The device-sync's registration callback for one VM.
     *
     * @return array{0:int, 1:string} HTTP status and body
     */
    private function register(int $vmId, int $revision): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['deviceResourceID' => (string) (16800000 + $vmId), 'deviceid' => $vmId, 'rollout_revision' => $revision], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm_updateid.php?action=updateDevice', false, $context);
        self::assertIsString($body);
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, $body];
    }

    /** @return array<string,mixed> */
    private function row(int $vmId): array
    {
        $result = $this->db->query('SELECT lifecycle_state, mecm_sync_state, mecm_pending_since, os_install_watch_started_at, updated_at FROM deploy_vms WHERE id = ' . $vmId);
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        self::assertIsArray($row);

        return $row;
    }

    /** Status events of any of the three watch notes: how often a clock of this VM was set. */
    private function watchEventCount(int $vmId): int
    {
        $count = 0;
        foreach (array_keys(VIRTUSPHERE_VM_WATCH_NOTES) as $note) {
            $count += $this->eventCount($vmId, $note);
        }

        return $count;
    }

    private function eventCount(int $vmId, string $note): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_vm_status_events WHERE vm_id = ? AND note = ?');
        $stmt->bind_param('is', $vmId, $note);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        // The inventory state and rows of an access leave with it.
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
