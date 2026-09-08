<?php

declare(strict_types=1);

require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_constants.php';
require_once __DIR__ . '/deploy_runtime_identity.php';
require_once __DIR__ . '/helpers.php';

const VIRTUSPHERE_DEPLOY_SERVICE_CASE_LIMIT = 10;

/** @return list<int> */
function repo_deploy_attention_job_ids(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    return array_map(static fn (array $row): int => (int) $row['id'], repo_fetch_all($stmt->get_result()));
}

/**
 * The claim axis of the deploy service (Etappe 13R).
 *
 * Three transitions, all compare-and-swap against the singleton runtime row:
 *
 *   accepting            -> pause_after_current   operator, while a job is active
 *   accepting            -> paused                operator, while nothing is active
 *   pause_after_current  -> paused                the WORKER, after its job ended
 *   paused | pause_after_current -> accepting     operator
 *
 * Every one of them is a CAS rather than a plain UPDATE, and that is the whole
 * design: two tabs, an operator and the worker all write this row, and a
 * last-write-wins UPDATE would let a resume issued during a job be undone a
 * second later by the worker completing the pause it was told to abandon.
 *
 * Lock order is fixed and one-way: deploy_jobs first, deploy_runtime_identity
 * second. The pause locks the active jobs before it writes the runtime row, and
 * the worker's confirmation runs inside repo_finish_deploy_job(), which already
 * holds its job row. Claim first rejects a paused snapshot, then rechecks the
 * current runtime state under lock after locking its queued job.
 *
 * A pause is deliberately NOT a lock on the active job. The playbook keeps
 * running and keeps changing ESXi; pausing claims only means no further job is
 * taken. Recovery and reconciliation claims of an already-owned job stay
 * allowed, because refusing those would strand exactly the job a pause was
 * meant to let finish.
 */

/**
 * @return array{state:string,changed_at:?string,changed_by:?int,changed_by_name:?string}
 */
function repo_deploy_claim_state(mysqli $db): array
{
    $row = repo_fetch_one(
        $db,
        'SELECT r.claim_state, r.claim_changed_at, r.claim_changed_by, u.name AS claim_changed_by_name
         FROM deploy_runtime_identity r
         LEFT JOIN deploy_users u ON u.id = r.claim_changed_by
         WHERE r.id = 1 LIMIT 1'
    );
    if ($row === null) {
        throw new RuntimeException('Deploy runtime identity is missing.');
    }
    $state = (string) $row['claim_state'];
    if (!in_array($state, VIRTUSPHERE_DEPLOY_CLAIM_STATES, true)) {
        // Fail closed on an unknown value rather than guessing: reading an
        // unrecognised state as `accepting` would resume a service somebody
        // deliberately stopped.
        throw new RuntimeException('Deploy claim state contains an unknown value.');
    }

    return [
        'state' => $state,
        'changed_at' => $row['claim_changed_at'] === null ? null : (string) $row['claim_changed_at'],
        'changed_by' => $row['claim_changed_by'] === null ? null : (int) $row['claim_changed_by'],
        'changed_by_name' => $row['claim_changed_by_name'] === null ? null : (string) $row['claim_changed_by_name'],
    ];
}

/**
 * Requests a pause and reports the state it actually reached.
 *
 * The decision between the two targets is made INSIDE the transaction, against
 * a locked read of the active jobs, because "is a job running" is exactly the
 * fact that can change between the operator's click and this write. Choosing it
 * in the portal would produce `paused` for a job that started in between, and
 * the worker would then be holding a job under a state that says it holds none.
 *
 * Idempotent: requesting a pause twice is not an error and does not write a
 * second row, so a double click cannot produce a second audit line.
 */
function repo_deploy_request_claim_pause(mysqli $db, int $userId): array
{
    return repo_transaction($db, static function () use ($db, $userId): array {
        $before = repo_deploy_claim_state($db);
        $current = $before['state'];
        if ($current !== VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING) {
            return $before + ['changed' => false, 'job_ids' => []];
        }

        $placeholders = implode(',', array_fill(0, count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES), '?'));
        $stmt = $db->prepare('SELECT id FROM deploy_jobs WHERE status IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
        $stmt->bind_param(str_repeat('s', count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)), ...VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES);
        $stmt->execute();
        $jobIds = array_map(static fn (array $row): int => (int) $row['id'], repo_fetch_all($stmt->get_result()));

        $target = $jobIds !== []
            ? VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT
            : VIRTUSPHERE_DEPLOY_CLAIM_PAUSED;

        $update = $db->prepare(
            'UPDATE deploy_runtime_identity SET claim_state = ?, claim_changed_at = NOW(), claim_changed_by = ?
             WHERE id = 1 AND claim_state = ?'
        );
        $accepting = VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING;
        $actor = $userId > 0 ? $userId : null;
        $update->bind_param('sis', $target, $actor, $accepting);
        $update->execute();

        $changed = $update->affected_rows === 1;
        $after = repo_deploy_claim_state($db);

        return $after + ['changed' => $changed, 'job_ids' => $changed ? $jobIds : []];
    });
}

/**
 * Resumes claiming. Reports whether this call is what changed the state, so the
 * caller can write exactly one audit line for an actual change.
 */
function repo_deploy_resume_claims(mysqli $db, int $userId): bool
{
    return repo_transaction($db, static function () use ($db, $userId): bool {
        $stmt = $db->prepare(
            'UPDATE deploy_runtime_identity SET claim_state = ?, claim_changed_at = NOW(), claim_changed_by = ?
             WHERE id = 1 AND claim_state <> ?'
        );
        $accepting = VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING;
        $actor = $userId > 0 ? $userId : null;
        $stmt->bind_param('sis', $accepting, $actor, $accepting);
        $stmt->execute();

        return $stmt->affected_rows === 1;
    });
}

/**
 * The worker's half of the pause: once its job reached a terminal state, the
 * requested pause becomes a real one.
 *
 * The CAS condition is the requested state itself. A resume issued while the
 * job was still running therefore wins: the row is already `accepting`, this
 * update matches nothing, and the service keeps working. That ordering is the
 * reason this is not an unconditional write.
 */
function repo_deploy_confirm_claim_pause(mysqli $db): bool
{
    $stmt = $db->prepare(
        'UPDATE deploy_runtime_identity SET claim_state = ?, claim_changed_at = NOW()
         WHERE id = 1 AND claim_state = ?'
    );
    $paused = VIRTUSPHERE_DEPLOY_CLAIM_PAUSED;
    $requested = VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT;
    $stmt->bind_param('ss', $paused, $requested);
    $stmt->execute();

    return $stmt->affected_rows === 1;
}

/**
 * Whether a NORMAL queue claim is currently allowed.
 *
 * Only `accepting` allows one. `pause_after_current` already refuses new work:
 * its remaining job is one the worker is holding, not one it may take.
 */
function deploy_claim_state_allows_new_work(string $claimState): bool
{
    return $claimState === VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING;
}

/**
 * How much work is waiting, and how late the latest of it is.
 *
 * `due` and `scheduled` are counted apart because a job scheduled for tonight
 * is not a backlog, and reporting it as one would make the dashboard warn every
 * time somebody plans ahead. `overdue_seconds` is measured from the OLDEST due
 * job, which is the one an operator would ask about first.
 *
 * @return array{due:int,scheduled:int,oldest_due_at:?string,overdue_seconds:int}
 */
function repo_deploy_queue_pressure(mysqli $db, ?int $now = null): array
{
    $now ??= time();
    $row = repo_fetch_one(
        $db,
        'SELECT
            SUM(CASE WHEN scheduled_at IS NULL OR scheduled_at <= clock.eval_now THEN 1 ELSE 0 END) AS due,
            SUM(CASE WHEN scheduled_at IS NOT NULL AND scheduled_at > clock.eval_now THEN 1 ELSE 0 END) AS scheduled,
            MIN(CASE WHEN scheduled_at IS NULL OR scheduled_at <= clock.eval_now THEN COALESCE(scheduled_at, created_at) END) AS oldest_due_at
         FROM deploy_jobs CROSS JOIN (SELECT FROM_UNIXTIME(?) AS eval_now) clock WHERE status = ?',
        'is',
        [$now, VIRTUSPHERE_DEPLOY_STATUS_QUEUED]
    );

    $oldest = $row === null || $row['oldest_due_at'] === null ? null : (string) $row['oldest_due_at'];
    $overdue = 0;
    if ($oldest !== null) {
        $overdue = max(0, $now - (int) strtotime($oldest . ' UTC'));
    }

    return [
        'due' => (int) ($row['due'] ?? 0),
        'scheduled' => (int) ($row['scheduled'] ?? 0),
        'oldest_due_at' => $oldest,
        'overdue_seconds' => $overdue,
    ];
}

/**
 * Every active job and whether its ownership shape is legitimate.
 *
 * "Consistent" means the job holds a lock and its heartbeat is younger than the
 * stale limit. An active job without either is the shape a dead worker leaves
 * behind, and it is what separates `busy` from `degraded`: the difference
 * between a service that is working and one that only looks like it.
 *
 * @return array{job_id:?int,mission_id:?int,mission_name:?string,status:?string,heartbeat_at:?string,consistent:bool}
 */
function repo_deploy_active_job_summary(mysqli $db, ?int $now = null): array
{
    $now ??= time();
    $placeholders = implode(',', array_fill(0, count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES), '?'));
    $stmt = $db->prepare(
        'SELECT j.id, j.mission_id, m.mission_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.recovery_requested_at
         FROM deploy_jobs j
         LEFT JOIN deploy_missions m ON m.id = j.mission_id
         WHERE j.status IN (' . $placeholders . ')
         ORDER BY j.id'
    );
    $stmt->bind_param(str_repeat('s', count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)), ...VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());

    if ($rows === []) {
        return [
            'job_id' => null,
            'mission_id' => null,
            'mission_name' => null,
            'status' => null,
            'heartbeat_at' => null,
            'consistent' => true,
            'count' => 0,
            'inconsistent_count' => 0,
            'recovery_waiting_count' => 0,
            'jobs' => [],
        ];
    }

    $jobs = [];
    $inconsistent = 0;
    $recoveryWaiting = 0;
    foreach ($rows as $row) {
        $heartbeat = $row['heartbeat_at'] === null ? null : (string) $row['heartbeat_at'];
        $seen = $heartbeat === null ? false : strtotime($heartbeat . ' UTC');
        $fresh = $seen !== false && $seen <= $now
            && ($now - $seen) <= VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS;
        $owned = $row['locked_at'] !== null && trim((string) ($row['locked_by'] ?? '')) !== '';
        $waitingForRecovery = !$owned && $row['recovery_requested_at'] !== null;
        $consistent = ($owned && $fresh) || $waitingForRecovery;
        if (!$consistent) {
            $inconsistent++;
        }
        if ($waitingForRecovery) {
            $recoveryWaiting++;
        }
        $jobs[] = [
            'job_id' => (int) $row['id'],
            'mission_id' => $row['mission_id'] === null ? null : (int) $row['mission_id'],
            'mission_name' => $row['mission_name'] === null ? null : (string) $row['mission_name'],
            'status' => (string) $row['status'],
            'heartbeat_at' => $heartbeat,
            'consistent' => $consistent,
            'ownership' => $waitingForRecovery ? 'recovery_waiting' : ($owned ? 'owned' : 'missing'),
        ];
    }
    $row = $jobs[0];

    return [
        'job_id' => $row['job_id'],
        'mission_id' => $row['mission_id'],
        'mission_name' => $row['mission_name'],
        'status' => $row['status'],
        'heartbeat_at' => $row['heartbeat_at'],
        'consistent' => $inconsistent === 0,
        'count' => count($jobs),
        'inconsistent_count' => $inconsistent,
        'recovery_waiting_count' => $recoveryWaiting,
        'jobs' => $jobs,
    ];
}

/**
 * The counts behind the attention axis.
 *
 * Every one of them is scoped to something UNRESOLVED and currently bound. A
 * resolved remote execution and routine cleanup retries are outside. An
 * uncertain create unit remains actionable even when its job is terminal.
 *
 * @return array{manual_required:int,legacy_uncertain_active:int,recovering:int,cases:list<array{job_id:int,kind:string}>,case_total:int,case_omitted:int}
 */
function repo_deploy_recovery_attention_counts(mysqli $db): array
{
    $placeholders = implode(',', array_fill(0, count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES), '?'));
    $activeTypes = str_repeat('s', count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES));

    $manualIds = repo_deploy_attention_job_ids(
        $db,
        'SELECT j.id FROM deploy_jobs j
         WHERE (j.status IN (' . $placeholders . ') AND EXISTS (
             SELECT 1 FROM deploy_remote_executions e WHERE e.job_id = j.id
               AND e.job_attempt = j.attempts AND e.generation_id = j.execution_generation_id
               AND e.reconciliation_state = ?
         )) OR EXISTS (
             SELECT 1 FROM deploy_create_vm_results c WHERE c.job_id = j.id AND c.status = ?
         ) ORDER BY j.id',
        $activeTypes . 'ss',
        array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, ['manual_required', VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN])
    );

    $legacyIds = repo_deploy_attention_job_ids(
        $db,
        'SELECT id FROM deploy_jobs WHERE recovery_reason = ? AND status IN (' . $placeholders . ') ORDER BY id',
        's' . $activeTypes,
        array_merge([VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)
    );

    $recoveringIds = repo_deploy_attention_job_ids(
        $db,
        'SELECT j.id FROM deploy_jobs j
         WHERE j.status IN (' . $placeholders . ')
           AND (j.recovery_requested_at IS NOT NULL OR EXISTS (
               SELECT 1 FROM deploy_remote_executions e WHERE e.job_id = j.id
                 AND e.job_attempt = j.attempts AND e.generation_id = j.execution_generation_id
                 AND e.reconciliation_state IN (?, ?)
           )) ORDER BY j.id',
        $activeTypes . 'ss',
        array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, ['pending', 'running'])
    );

    $casesByJob = [];
    foreach ([
        'manual' => $manualIds,
        'legacy' => $legacyIds,
        'recovering' => $recoveringIds,
    ] as $kind => $ids) {
        foreach ($ids as $id) {
            $casesByJob[$id] ??= ['job_id' => $id, 'kind' => $kind];
        }
    }
    ksort($casesByJob, SORT_NUMERIC);
    $caseTotal = count($casesByJob);
    $cases = array_slice(array_values($casesByJob), 0, VIRTUSPHERE_DEPLOY_SERVICE_CASE_LIMIT);

    return [
        'manual_required' => count($manualIds),
        'legacy_uncertain_active' => count($legacyIds),
        'manual_total' => count(array_unique(array_merge($manualIds, $legacyIds))),
        'recovering' => count($recoveringIds),
        'cases' => $cases,
        'case_total' => $caseTotal,
        'case_omitted' => $caseTotal - count($cases),
    ];
}
