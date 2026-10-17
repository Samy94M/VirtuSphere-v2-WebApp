<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * The confirmed cancellation state machine (Entscheidung 4, ADR-0033).
 *
 * repo_cancel_deploy_job() used to set even a running job straight to
 * `cancelled` and null its lock and heartbeat. The worker only honours a stop
 * at step boundaries, so for the length of the current playbook the portal
 * showed a terminal job whose playbook was still creating VMs: the mission
 * could be deleted and a new job enqueued OVER a sequence that was still
 * running, and a valid MAC callback of that sequence bounced with 409 (B4).
 *
 * Now: queued cancels directly; running becomes `cancelling` and keeps lock,
 * heartbeat and every protective effect of an active job until the worker
 * confirms at a step boundary (ownership CAS) or the reaper converges a dead
 * worker. cancelled_at means the END state; the wish carries its own
 * timestamp and actor.
 */
final class DeployCancellationStateMachineTest extends TestCase
{
    private const WORKER = 'phpunit:cancel-worker';

    private mysqli $db;
    private string $prefix;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_cancel_' . bin2hex(random_bytes(4));
        $this->missionId = $this->insertMission();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->missionId > 0) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $this->missionId);
            $stmt->execute();
        }
        $like = $this->prefix . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }

    // --- the two cancel transitions -----------------------------------------

    public function testAQueuedCancelIsImmediatelyTerminal(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, null);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, repo_cancel_deploy_job($this->db, $jobId, 42));

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at'], 'a queued job never started; the wish IS the end state');
        self::assertNotNull($job['cancel_requested_at']);
        self::assertSame(42, (int) $job['cancel_requested_by']);
        self::assertNull($job['last_error']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_OPERATOR_CANCELLED, (string) $job['terminal_reason_code']);
        self::assertSame(1, $this->cancelLogCount($jobId));
    }

    public function testARunningCancelBecomesCancellingAndKeepsLockAndHeartbeat(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, repo_cancel_deploy_job($this->db, $jobId, 42));

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, (string) $job['status']);
        // The worker still owns the sequence: lock and heartbeat stay, and
        // cancelled_at stays NULL until somebody CONFIRMS the end state.
        self::assertSame(self::WORKER, (string) $job['locked_by']);
        self::assertNotNull($job['heartbeat_at']);
        self::assertNull($job['cancelled_at']);
        self::assertNotNull($job['cancel_requested_at']);
        self::assertSame(42, (int) $job['cancel_requested_by']);
        self::assertNull($job['last_error']);
        self::assertNull($job['terminal_reason_code']);
        self::assertSame(1, $this->cancelLogCount($jobId));
    }

    public function testASecondCancelOfACancellingJobIsIdempotent(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);
        $firstRequestAt = (string) $this->job($jobId)['cancel_requested_at'];

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, repo_cancel_deploy_job($this->db, $jobId, 43));

        $job = $this->job($jobId);
        self::assertSame($firstRequestAt, (string) $job['cancel_requested_at'], 'the first wish keeps its timestamp');
        self::assertSame(42, (int) $job['cancel_requested_by'], 'and its actor');
        self::assertSame(1, $this->cancelLogCount($jobId), 'a repeated request writes no second cancellation line');
    }

    public function testATerminalJobCannotBeCancelled(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, null);

        $this->expectException(RuntimeException::class);
        repo_cancel_deploy_job($this->db, $jobId, 42);
    }

    public function testGroupCancelUsesTheSameMetadataAndLeavesRunningSlotAlone(): void
    {
        $groupId = 'grp' . substr(bin2hex(random_bytes(5)), 0, 9);
        $queuedA = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, null);
        $queuedB = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_QUEUED, null);
        $running = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        foreach ([$queuedA, $queuedB, $running] as $jobId) {
            $stmt = $this->db->prepare('UPDATE deploy_jobs SET group_id = ? WHERE id = ?');
            $stmt->bind_param('si', $groupId, $jobId);
            $stmt->execute();
        }

        self::assertSame(2, repo_cancel_deploy_group($this->db, $groupId, 42));
        foreach ([$queuedA, $queuedB] as $jobId) {
            $job = $this->job($jobId);
            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
            self::assertSame(42, (int) $job['cancel_requested_by']);
            self::assertNotNull($job['cancel_requested_at']);
            self::assertNotNull($job['cancelled_at']);
            self::assertNull($job['last_error']);
            self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_OPERATOR_CANCELLED, (string) $job['terminal_reason_code']);
            self::assertSame(1, $this->cancelLogCount($jobId));
        }
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, (string) $this->job($running)['status']);
        self::assertNull($this->job($running)['cancel_requested_at']);
    }

    // --- the worker side ----------------------------------------------------

    public function testTheHeartbeatKeepsBeatingWhileCancelling(): void
    {
        // An aged heartbeat, so the touch visibly changes the row (an
        // identical-value UPDATE reports 0 affected rows in the same second).
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER, staleHeartbeat: true);

        // A cancelling job still runs its current step; without the heartbeat
        // the reaper would fail a perfectly alive worker mid-confirmation.
        self::assertTrue(repo_touch_deploy_job_heartbeat($this->db, $jobId, self::WORKER));
        self::assertFalse(repo_touch_deploy_job_heartbeat($this->db, $jobId, 'phpunit:other'));
    }

    public function testTheWorkerConfirmsCancellingViaOwnershipCas(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);

        // The wrong worker cannot confirm somebody else's stop.
        self::assertFalse(repo_confirm_deploy_job_cancelled($this->db, $jobId, 'phpunit:other'));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, (string) $this->job($jobId)['status']);

        self::assertTrue(repo_confirm_deploy_job_cancelled($this->db, $jobId, self::WORKER));
        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at'], 'confirmation IS the end state');
        self::assertNull($job['locked_by']);
        self::assertNull($job['heartbeat_at']);
    }

    public function testAssertJobIsOursConfirmsTheCancelAndStopsTheWorker(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        try {
            deploy_worker_assert_job_is_ours($this->db, $jobId, self::WORKER);
            self::fail('a cancelling job must stop the worker at the step boundary');
        } catch (DeployWorkerCancelled $stop) {
            self::assertStringContainsString('step boundary', $stop->getMessage());
        }

        // The stop is also the confirmation: the job is terminal afterwards.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $this->job($jobId)['status']);
    }

    // --- the last-step race -------------------------------------------------

    /**
     * The race the previously read status cannot decide (Etappe 8): the last
     * playbook succeeds at the same moment a cancel is committed.
     *
     * The terminal write is a compare-and-swap from `running` under this
     * worker's lock, so it finds zero rows here. What must NOT happen is what
     * happened before: the worker logged a lost lock (the lock was still ours)
     * and walked away, leaving the job in `cancelling` until the reaper noticed
     * a heartbeat that had stopped - minutes of a job that is neither running
     * nor finished.
     */
    public function testACancelThatWinsTheRaceIsConfirmedInsteadOfTheSuccess(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);

        deploy_worker_finish_job($this->db, $this->job($jobId), self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertNotNull($job['cancelled_at']);
        self::assertNull($job['locked_by']);
        self::assertNull($job['last_error']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_OPERATOR_CANCELLED, (string) $job['terminal_reason_code']);

        // The accepted request is the one immutable cancel SYSTEM line. The
        // race detail belongs to bounded terminal metadata, not a second action.
        self::assertSame(1, $this->cancelLogCount($jobId));
        self::assertStringContainsString('ran to its end', (string) $job['terminal_reason_detail']);
        self::assertStringContainsString('no further step was started', (string) $job['terminal_reason_detail']);
        // LG-08: the confirmed cancel wrote its outcome row, and the success path that lost the race wrote none.
        self::assertSame(1, $this->outcomeRows($jobId));
    }

    /**
     * The other side of the same race: the terminal swap won first. A cancel
     * POST arriving afterwards must not touch an already finished job, and the
     * result stays the one the run actually produced.
     */
    public function testATerminalSwapThatWinsFirstSurvivesALateCancel(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);

        deploy_worker_finish_job($this->db, $this->job($jobId), self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, (string) $this->job($jobId)['status']);

        try {
            repo_cancel_deploy_job($this->db, $jobId, 1);
            self::fail('a job that already finished must not accept a cancel');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString('active', $refused->getMessage());
        }
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, (string) $this->job($jobId)['status']);
    }

    /** A job somebody else concluded is immutable, including its final log. */
    public function testAForeignTerminalStateIsNotOverwrittenOrAppendedTo(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED, 'phpunit:other');

        deploy_worker_finish_job($this->db, $this->job($jobId), self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 'ours');

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, (string) $job['status']);
        $log = $this->logText($jobId);
        self::assertSame('', $log, 'a losing worker must not append after the foreign terminal boundary');
        self::assertStringNotContainsString('ours', $log);
    }

    /**
     * The third reason the terminal swap can find zero rows: the job is gone.
     *
     * `deploy_job_logs` references `deploy_jobs`, so an append about a deleted
     * job fails on a foreign key - which would turn a clean stop into an
     * unexplained crash the operator reads as an ESXi problem. The finish path
     * therefore reports through the guarded helper.
     */
    public function testAVanishedJobIsReportedWithoutWritingAgainstAForeignKey(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        $claim = $this->job($jobId);
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        deploy_worker_finish_job($this->db, $claim, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);

        self::assertSame('', $this->logText($jobId), 'a deleted job has nowhere to log; the line goes to error_log.');
    }

    private function logText(int $jobId): string
    {
        $stmt = $this->db->prepare('SELECT line FROM deploy_job_logs WHERE job_id = ? ORDER BY seq');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $lines = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $lines[] = (string) $row['line'];
        }

        return implode("\n", $lines);
    }

    private function cancelLogCount(int $jobId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM deploy_job_logs WHERE job_id = ? AND stream = 'system' AND (line LIKE 'Cancel%' OR line LIKE 'Cancelled%')");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    // --- reaper convergence -------------------------------------------------

    public function testTheReaperConvergesAStaleCancellingJobToCancelledNotFailed(): void
    {
        $cancellingId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER, staleHeartbeat: true);
        repo_cancel_deploy_job($this->db, $cancellingId, 42);
        $runningId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER, staleHeartbeat: true);

        // The reaper only trusts an observer that has been connected longer than
        // its grace; an unset one counts as blind, which is the production
        // default on a fresh connection. Declare this observer as long-established.
        deploy_reap_observer_since(time() - VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS - 1);

        deploy_worker_reap_stale_jobs($this->db);

        // The operator asked for the cancel; a dead worker must not repaint
        // that wish as a failure...
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $this->job($cancellingId)['status']);
        self::assertNotNull($this->job($cancellingId)['cancelled_at']);
        self::assertNull($this->job($cancellingId)['last_error']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_CANCEL_CONVERGED, (string) $this->job($cancellingId)['terminal_reason_code']);
        self::assertSame(1, $this->cancelLogCount($cancellingId));
        // ...while a stale plain running job keeps its failed verdict.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, (string) $this->job($runningId)['status']);
        self::assertSame(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT, (string) $this->job($runningId)['terminal_reason_code']);
        // LG-07: each reaped job writes exactly one outcome row, and it names the reaper's reason.
        self::assertSame(1, $this->outcomeRows($cancellingId));
        self::assertSame(1, $this->outcomeRows($runningId));
        self::assertSame('stale_heartbeat', $this->outcomeContext($runningId)['reason'] ?? null);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->outcomeContext($runningId)['status'] ?? null);
    }

    // --- protective effects stay while cancelling ----------------------------

    public function testCancellingStillBlocksDeleteAndEnqueue(): void
    {
        $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        // Both the mission-delete guard and the enqueue guard read this
        // predicate: while the playbook may still be running, both stay shut.
        self::assertTrue(repo_transaction($this->db, fn (): bool => repo_deploy_active_job_exists($this->db, $this->missionId)));
    }

    public function testASecondSystemJobIsRefusedWhileOneIsCancelling(): void
    {
        $esxiId = $this->insertCredential('esxi', VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleId = $this->insertCredential('ansible', VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $first = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxiId, $ansibleId);
        self::assertNotNull($first);
        $this->setJobStatus($first, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING, self::WORKER);

        self::assertNull(
            repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $esxiId, $ansibleId),
            'a cancelling pull still owns its credential'
        );
    }

    // --- outcome row, pause and failure under a cancel ----------------------

    /** LG-08: the confirmed cancel writes its outcome row once, and a repeated confirmation writes none. */
    public function testAConfirmedCancelWritesExactlyOneOutcomeRow(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);

        self::assertTrue(deploy_worker_confirm_owned_cancel($this->db, $this->job($jobId)));
        self::assertFalse(deploy_worker_confirm_owned_cancel($this->db, $this->job($jobId)), 'a second confirmation finds no cancelling job');

        self::assertSame(1, $this->outcomeRows($jobId));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $this->outcomeContext($jobId)['status'] ?? null);
    }

    /**
     * WK-01: a step that fails while a cancel is pending writes its error line
     * into the job log, and the confirmation names the exit code. Before the fix
     * the job ended cancelled with no trace of the failed step.
     */
    public function testAFailedStepUnderACancelIsLoggedAndNamesItsExitCode(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        repo_cancel_deploy_job($this->db, $jobId, 42);

        deploy_worker_handle_failure(
            $this->db,
            $this->job($jobId),
            self::WORKER,
            [],
            'Ansible command failed with exit code 2 (playbook step: start).',
            VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
            true,
            'TASK [Start the VMs] fatal: Module failed on VM-01',
            2
        );

        $job = $this->job($jobId);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, (string) $job['status']);
        self::assertStringContainsString('the step ended with exit code 2', (string) $job['terminal_reason_detail']);
        $log = $this->logText($jobId);
        self::assertStringContainsString('Ansible command failed with exit code 2 (playbook step: start).', $log);
        self::assertStringContainsString('TASK [Start the VMs] fatal: Module failed on VM-01', $log);
        self::assertSame(1, $this->outcomeRows($jobId));
    }

    /**
     * WK-08: a worker whose job a successor took over drops its failure, and says
     * so once in its error log. The job log and the job row are not written.
     */
    public function testAWorkerThatLostItsJobLogsTheDiscardedFailureAndWritesNothing(): void
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
        $claim = $this->job($jobId);
        $stmt = $this->db->prepare("UPDATE deploy_jobs SET locked_by = 'phpunit:successor', lock_token = REPEAT(CHAR(98), 32) WHERE id = ?");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $logBefore = $this->logText($jobId);

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'vs-wk08-');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $errorLog);
        try {
            deploy_worker_handle_failure($this->db, $claim, self::WORKER, [], 'late failure of the old worker');
        } finally {
            ini_set('error_log', $previous);
        }
        $written = (string) file_get_contents($errorLog);
        @unlink($errorLog);

        self::assertStringContainsString('job ' . $jobId . ' is no longer owned by this worker; discarded failure: late failure of the old worker', $written);
        self::assertSame($logBefore, $this->logText($jobId), 'a job this worker does not own is not written to');
        self::assertSame('phpunit:successor', (string) $this->job($jobId)['locked_by']);
    }

    /**
     * WK-03: a pause requested while the job runs becomes a pause when the cancel
     * that ends the job is confirmed, exactly as it does for a successful job.
     */
    public function testAConfirmedCancelTurnsARequestedPauseIntoAPause(): void
    {
        $previous = $this->claimRow();
        $userId = $this->insertUser();
        try {
            repo_deploy_resume_claims($this->db, $userId);
            $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER);
            self::assertSame(VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT, repo_deploy_request_claim_pause($this->db, $userId)['state']);

            repo_cancel_deploy_job($this->db, $jobId, 42);
            self::assertTrue(repo_confirm_deploy_job_cancelled($this->db, $jobId, self::WORKER));

            self::assertSame(VIRTUSPHERE_DEPLOY_CLAIM_PAUSED, $this->claimRow()['claim_state']);
        } finally {
            $this->restoreClaimRow($previous);
            $this->deleteUser($userId);
        }
    }

    /** WK-03 (reaper): a stale job that is reaped also turns a requested pause into a pause. */
    public function testAReapedJobTurnsARequestedPauseIntoAPause(): void
    {
        $previous = $this->claimRow();
        $userId = $this->insertUser();
        try {
            repo_deploy_resume_claims($this->db, $userId);
            $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, self::WORKER, staleHeartbeat: true);
            repo_deploy_request_claim_pause($this->db, $userId);
            deploy_reap_observer_since(time() - VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS - 1);

            deploy_worker_reap_stale_jobs($this->db);

            self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, (string) $this->job($jobId)['status']);
            self::assertSame(VIRTUSPHERE_DEPLOY_CLAIM_PAUSED, $this->claimRow()['claim_state']);
        } finally {
            $this->restoreClaimRow($previous);
            $this->deleteUser($userId);
        }
    }

    // --- helpers ------------------------------------------------------------

    private function outcomeRows(int $jobId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?');
        $event = VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME;
        $type = 'deploy_job';
        $id = (string) $jobId;
        $stmt->bind_param('sss', $event, $type, $id);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /** @return array<string, mixed> The context of the newest outcome row of the job. */
    private function outcomeContext(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 1');
        $event = VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME;
        $type = 'deploy_job';
        $id = (string) $jobId;
        $stmt->bind_param('sss', $event, $type, $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? [];
        $context = json_decode((string) ($row['context_json'] ?? '[]'), true);

        return is_array($context) ? $context : [];
    }

    /** @return array{claim_state:string,claim_changed_at:?string,claim_changed_by:?string} */
    private function claimRow(): array
    {
        $row = repo_fetch_one($this->db, 'SELECT claim_state, claim_changed_at, claim_changed_by FROM deploy_runtime_identity WHERE id = 1');
        self::assertNotNull($row);

        return [
            'claim_state' => (string) $row['claim_state'],
            'claim_changed_at' => $row['claim_changed_at'] === null ? null : (string) $row['claim_changed_at'],
            'claim_changed_by' => $row['claim_changed_by'] === null ? null : (string) $row['claim_changed_by'],
        ];
    }

    /** A real account for the claim writes: claim_changed_by is a foreign key to deploy_users. */
    private function insertUser(): int
    {
        $name = $this->prefix . '_user';
        $password = password_hash('irrelevant', PASSWORD_DEFAULT);
        $email = $this->prefix . '@example.invalid';
        $stmt = $this->db->prepare("INSERT INTO deploy_users (name, password, email, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param('sss', $name, $password, $email);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function deleteUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    /** @param array{claim_state:string,claim_changed_at:?string,claim_changed_by:?string} $row */
    private function restoreClaimRow(array $row): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_runtime_identity SET claim_state = ?, claim_changed_at = ?, claim_changed_by = ? WHERE id = 1');
        $stmt->bind_param('sss', $row['claim_state'], $row['claim_changed_at'], $row['claim_changed_by']);
        $stmt->execute();
    }

    private function insertMission(): int
    {
        $name = $this->prefix . '_m';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertJob(string $status, ?string $lockedBy, bool $staleHeartbeat = false): int
    {
        $payload = json_encode(['mode' => 'full', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $heartbeat = $staleHeartbeat ? 'DATE_SUB(NOW(), INTERVAL 7200 SECOND)' : 'NOW()';
        if ($lockedBy === null) {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $this->missionId, $status, $payload);
        } else {
            $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, lock_token, worker_epoch, heartbeat_at) VALUES (?, ?, ?, NOW(), ?, REPEAT(CHAR(97), 32), 1, ' . $heartbeat . ')');
            $stmt->bind_param('isss', $this->missionId, $status, $payload, $lockedBy);
        }
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function insertCredential(string $suffix, string $type): int
    {
        $name = $this->prefix . '_' . $suffix;
        $host = 'host.example.com';
        $port = 443;
        $userName = 'svc';
        $secret = 'x';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $userName, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function setJobStatus(int $jobId, string $status, string $lockedBy): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_jobs SET status = ?, locked_at = NOW(), locked_by = ?, heartbeat_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $status, $lockedBy, $jobId);
        $stmt->execute();
    }

    /** @return array<string, mixed> */
    private function job(int $jobId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT * FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
        self::assertNotNull($row);

        return $row;
    }
}
