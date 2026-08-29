<?php

declare(strict_types=1);

require_once __DIR__ . '/../errors.php';
require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/../remote_execution_state.php';
require_once __DIR__ . '/../remote_recovery_policy.php';
require_once __DIR__ . '/deploy_remote_execution.php';
require_once __DIR__ . '/deploy_remote_recovery.php';
require_once __DIR__ . '/deploy_runtime_identity.php';
require_once __DIR__ . '/helpers.php';

/**
 * The three operator recovery actions (Etappe 13R).
 *
 * All three are pure database actions. None of them reaches the Ansible host,
 * none kills a remote process and none deletes remote material: an HTTP request
 * that promised to reach across the network would be promising something it
 * cannot keep while the host is unreachable, which is the exact situation these
 * exist for. What they do is move DURABLE state so the worker's next pass acts
 * on it, or record what a person established outside this system.
 *
 * The recovery POLICY is not re-implemented here. remote_recovery_decision()
 * stays the one classifier; these functions only apply what it decided, which
 * is why a state it calls manual cannot be talked out of that by a button.
 */

/**
 * "Check recovery now": re-runs the policy over every stale active job and
 * requests recovery for those it says are recoverable.
 *
 * It requests, it does not perform. Reconciliation is the worker's job and
 * needs the remote host; this only makes sure the flag the worker reads is set,
 * so an operator does not have to wait out a reaper interval to get a case
 * moving. It is therefore idempotent by construction: a job whose recovery was
 * already requested keeps its original `recovery_requested_at`.
 *
 * @return array{reviewed:int,requested:int,manual:int,skipped:int}
 */
function repo_deploy_review_recovery(mysqli $db, int $staleAfterSeconds = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS): array
{
    $runtime = repo_deploy_runtime_identity($db)['current_generation_id'];
    $candidates = repo_remote_recovery_candidates($db, $staleAfterSeconds);
    $counts = ['reviewed' => 0, 'requested' => 0, 'manual' => 0, 'skipped' => 0];

    foreach ($candidates as $row) {
        $counts['reviewed']++;
        $execution = $row['remote_execution_id'] === null ? null : [
            'generation_id' => (string) ($row['remote_generation_id'] ?? ''),
            'controller_state' => (string) ($row['controller_state'] ?? ''),
            'effect_state' => (string) ($row['effect_state'] ?? ''),
            'reconciliation_state' => (string) ($row['reconciliation_state'] ?? ''),
            'cleanup_state' => (string) ($row['cleanup_state'] ?? ''),
        ];
        $decision = remote_recovery_decision($row, $execution, $runtime);

        if ($decision['action'] === VIRTUSPHERE_REMOTE_RECOVERY_MANUAL) {
            // A case the policy already sent to a person stays with that person.
            // Requesting recovery for it would reset nothing and would put a
            // second, contradicting signal on the same job.
            $counts['manual']++;
            continue;
        }
        if ($decision['reason'] === null) {
            $counts['skipped']++;
            continue;
        }

        try {
            repo_request_remote_recovery(
                $db,
                (int) $row['job_id'],
                $row['remote_execution_id'] === null ? null : (int) $row['remote_execution_id'],
                (string) $decision['reason']
            );
            $counts['requested']++;
        } catch (RuntimeException $exception) {
            // The job reached a terminal state between the candidate read and
            // this write, or its reconciliation is already concluded. Both are
            // races this action is allowed to lose; neither is an error worth
            // failing the whole review over.
            $counts['skipped']++;
        }
    }

    return $counts;
}

/**
 * "Retry cleanup": puts a failed remote cleanup back in the queue.
 *
 * Two conditions, both load-bearing. The CAS is on `failed`, so an operator
 * cannot restart a cleanup that is currently running or already done. And the
 * evidence must be unchanged since they looked: `result_sha256` is what the
 * decision to retry was made against, and a cleanup whose result changed in
 * between is a different case that deserves a fresh look.
 *
 * Only the AUTOMATIC attempt counter is reset. The cumulative one keeps
 * counting, because "this has now failed eleven times" is exactly the fact a
 * reset would erase and exactly the fact somebody needs on the twelfth.
 */
function repo_deploy_retry_remote_cleanup(mysqli $db, int $executionId, ?string $expectedResultHash): bool
{
    return repo_transaction($db, static function () use ($db, $executionId, $expectedResultHash): bool {
        $execution = repo_deploy_remote_execution($db, $executionId, true);
        if ($execution === null || (string) $execution['cleanup_state'] !== 'failed') {
            return false;
        }
        $stored = $execution['result_sha256'] === null ? null : (string) $execution['result_sha256'];
        if ($stored !== $expectedResultHash) {
            return false;
        }
        remote_execution_assert_transition('cleanup', 'failed', 'eligible');
        repo_execute(
            $db,
            "UPDATE deploy_remote_executions
             SET cleanup_state = 'eligible', cleanup_auto_attempts = 0, cleanup_lease_until = NULL,
                 cleanup_due_at = NOW(6)
             WHERE id = ? AND cleanup_state = 'failed'",
            'i',
            [$executionId]
        );

        return true;
    });
}

/**
 * "Document an external check": records what a person established outside this
 * system about a job whose outcome it could not observe.
 *
 * Append-only, and deliberately so. The table is evidence: a later reader has
 * to be able to see that somebody looked, what they found and what the state
 * was at that moment, even after a subsequent entry says something else. An
 * UPDATE would let the second account quietly replace the first.
 *
 * `previous_state` is captured here rather than passed in, so what is stored is
 * what the database held at the moment of the write and not what a form
 * believed several minutes earlier.
 */
function repo_deploy_record_external_review(
    mysqli $db,
    int $jobId,
    ?int $executionId,
    int $actorId,
    string $resolutionCode,
    string $reason,
    ?string $reference = null
): int {
    if ($actorId <= 0) {
        throw new InvalidArgumentException('An external review needs an actor.');
    }
    if (!in_array($resolutionCode, VIRTUSPHERE_RECOVERY_RESOLUTION_CODES, true)) {
        throw new InvalidArgumentException('Unknown recovery resolution code.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new ValidationException(['reason' => __t('validate.required')]);
    }

    return repo_transaction($db, static function () use ($db, $jobId, $executionId, $actorId, $resolutionCode, $reason, $reference): int {
        $job = repo_fetch_one(
            $db,
            'SELECT id, status, recovery_reason, execution_contract FROM deploy_jobs WHERE id = ? FOR UPDATE',
            'i',
            [$jobId]
        );
        if ($job === null) {
            throw new RuntimeException('Unknown deploy job.');
        }
        $execution = $executionId === null ? null : repo_deploy_remote_execution($db, $executionId, true);
        if ($executionId !== null && ($execution === null || (int) $execution['job_id'] !== $jobId)) {
            throw new RuntimeException('Remote execution handle does not belong to the job.');
        }

        $previous = [
            'job_status' => (string) $job['status'],
            'recovery_reason' => $job['recovery_reason'] === null ? null : (string) $job['recovery_reason'],
            'controller_state' => $execution === null ? null : (string) $execution['controller_state'],
            'effect_state' => $execution === null ? null : (string) $execution['effect_state'],
            'reconciliation_state' => $execution === null ? null : (string) $execution['reconciliation_state'],
        ];
        $encoded = json_encode($previous, JSON_THROW_ON_ERROR);
        // The fingerprint binds the entry to the state it was written about, so
        // an entry cannot later be read as covering a state it never saw.
        $fingerprint = hash('sha256', $encoded);
        $scope = $executionId === null ? 'legacy_job' : 'remote_execution';

        repo_execute(
            $db,
            'INSERT INTO deploy_recovery_resolutions
                (job_id, remote_execution_id, resolution_scope, resolution_code, reason, reference, evidence_fingerprint, actor_id, previous_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            // job, execution, scope, code, reason, reference, fingerprint, actor, state
            'iisssssis',
            [$jobId, $executionId, $scope, $resolutionCode, mb_substr($reason, 0, 1024), $reference, $fingerprint, $actorId, $encoded]
        );

        return (int) $db->insert_id;
    });
}
