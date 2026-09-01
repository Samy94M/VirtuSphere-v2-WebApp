<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_result.php';
require_once __DIR__ . '/helpers.php';

/**
 * Storage and compare-and-swap for per-VM create results (Etappe 14B).
 *
 * The rule this module exists to enforce: a create unit changes state exactly
 * once per real event, and only while this worker still owns the job. Every
 * transition therefore re-reads its row FOR UPDATE inside the surrounding
 * transaction, checks the previous status, the job status and the worker's
 * lock, and only then writes. A duplicate poll answer, a worker that lost the
 * job while a playbook was running, and a reaper that already converged the
 * job all lose that race instead of overwriting a terminal state.
 *
 * The decisions themselves (which transition exists, which fields a target
 * status requires, what the outcome of an evidence pair is) live in
 * lib/deploy_create_result.php and are asked before anything is written.
 */

/**
 * The VM selection of a create-capable job, resolved and ordered exactly once.
 *
 * An empty selection means the whole mission, and it is materialized here
 * rather than left as "everything, decided later": a VM added to the mission
 * after queueing must not silently widen a job that is already waiting to run.
 * The order is `vm_name, id` and nothing else, so position 7 means the same VM
 * on every read, in the portal, in the log and in a retry.
 *
 * @param int[] $vmIds
 * @return list<array{id:int,vm_name:string}>
 */
function deploy_create_resolve_selection(mysqli $db, int $missionId, array $vmIds): array
{
    if ($missionId <= 0) {
        throw new InvalidArgumentException('Mission is required.');
    }
    $sql = 'SELECT id, vm_name FROM deploy_vms WHERE mission_id = ?';
    $types = 'i';
    $params = [$missionId];
    $ids = array_values(array_unique(array_map('intval', $vmIds)));
    if ($ids !== []) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        $params = array_merge($params, $ids);
    }
    $sql .= ' ORDER BY vm_name, id';

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());

    return array_map(
        static fn (array $row): array => ['id' => (int) $row['id'], 'vm_name' => (string) $row['vm_name']],
        $rows
    );
}

/**
 * Writes one result row per selected VM. Called inside the queue transaction
 * that created the job, so job and rows commit together or not at all: a job
 * with half its units would look like a job that already processed the rest.
 *
 * @param list<array{id:int,vm_name:string}> $selection
 * @return int The total, which every row of this job carries.
 */
function repo_deploy_create_materialize(mysqli $db, int $jobId, array $selection): int
{
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Job is required.');
    }
    if ($selection === []) {
        throw new InvalidArgumentException('A create job cannot be materialized without VMs.');
    }
    $total = count($selection);
    $position = 0;
    foreach ($selection as $vm) {
        $position++;
        repo_insert_from_values($db, 'deploy_create_vm_results', [
            'job_id' => $jobId,
            'vm_id' => (int) $vm['id'],
            'vm_name' => (string) $vm['vm_name'],
            'position' => $position,
            'total' => $total,
            'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
            'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]);
    }

    return $total;
}

/**
 * All result rows of a job in position order.
 *
 * An empty answer is not an error and not an empty job: every job queued before
 * this stage has no rows at all, and those stay readable as legacy. A caller
 * that needs the difference asks repo_deploy_job_is_create_tracked().
 *
 * @return list<array<string, mixed>>
 */
function repo_deploy_create_results(mysqli $db, int $jobId, bool $lock = false): array
{
    $stmt = $db->prepare(
        'SELECT id, job_id, vm_id, vm_name, position, total, action, status, outcome, changed, existed_before,'
        . ' precheck_moid, precheck_instance_uuid, async_jid, remote_execution_id, async_deadline_at, vm_moid,'
        . ' vm_instance_uuid, error_code, error_detail, resumed_from_result_id, started_at, finished_at, updated_at'
        . ' FROM deploy_create_vm_results WHERE job_id = ? ORDER BY position'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $jobId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/** Whether this job carries per-VM create rows at all (false for legacy jobs). */
function repo_deploy_job_is_create_tracked(mysqli $db, int $jobId): bool
{
    return (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_create_vm_results WHERE job_id = ?',
        'i',
        [$jobId]
    ) > 0;
}

/** @return array{total:int,processed:int,succeeded:int,skipped:int,failed:int,uncertain:int,not_started:int,created:int,updated:int,unchanged:int,current:?array{position:int,vm_name:string,status:string,started_at:?string}} */
function repo_deploy_create_summary(mysqli $db, int $jobId): array
{
    return deploy_create_summary(repo_deploy_create_results($db, $jobId));
}

/**
 * Marks the create section of a job as started, once. COALESCE rather than a
 * conditional write: recovery of the same job re-enters this path, and a second
 * write would restart a budget that has been running since the first VM.
 */
function repo_deploy_create_mark_started(mysqli $db, int $jobId): void
{
    repo_execute(
        $db,
        'UPDATE deploy_jobs SET create_started_at = COALESCE(create_started_at, UTC_TIMESTAMP()) WHERE id = ?',
        'i',
        [$jobId]
    );
}

/**
 * The compare-and-swap of one unit.
 *
 * Returns true when this call performed the transition and false when it lost
 * the race, which is a normal outcome and never an exception: a duplicated poll
 * answer arriving after the job was cancelled, or a worker whose lock was taken
 * over by a reaper, must simply not write. A malformed transition is different
 * and throws, because that is a defect in the caller rather than a race.
 *
 * $fence is the worker's ownership triple. It is required, not optional: an
 * ownership check that a caller can forget is an ownership check that will be
 * forgotten in the one path where it matters.
 *
 * @param array<string, mixed> $fields
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 */
function repo_deploy_create_transition(
    mysqli $db,
    int $jobId,
    int $position,
    string $from,
    string $to,
    array $fields,
    array $fence
): bool {
    if (!in_array($from, VIRTUSPHERE_CREATE_RESULT_STATUSES, true) || !in_array($to, VIRTUSPHERE_CREATE_RESULT_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown create result status.');
    }
    if (!deploy_create_transition_allowed($from, $to)) {
        throw new DomainException('Create transition ' . $from . ' -> ' . $to . ' does not exist.');
    }
    deploy_create_assert_transition_fields($to, $fields);
    if (trim($fence['worker_id']) === '' || trim($fence['lock_token']) === '') {
        throw new InvalidArgumentException('Create transition needs the worker ownership fence.');
    }

    return (bool) repo_transaction($db, static function () use ($db, $jobId, $position, $from, $to, $fields, $fence): bool {
        $row = repo_fetch_one(
            $db,
            'SELECT r.id, r.status, r.action FROM deploy_create_vm_results r'
            . ' JOIN deploy_jobs j ON j.id = r.job_id'
            . ' WHERE r.job_id = ? AND r.position = ? AND r.status = ?'
            . ' AND j.status IN (?, ?) AND j.locked_by = ? AND j.lock_token = ? AND j.worker_epoch = ?'
            . ' FOR UPDATE',
            'iisssssi',
            [
                $jobId,
                $position,
                $from,
                VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
                VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
                (string) $fence['worker_id'],
                (string) $fence['lock_token'],
                (int) $fence['worker_epoch'],
            ]
        );
        if ($row === null) {
            return false;
        }
        if ($to === VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED && (string) $row['action'] !== VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP) {
            // Invariant 5: only a unit that was materialized as a verification
            // of an earlier success may end as skipped. Anything else claiming
            // it would be a create that never ran, recorded as one that did.
            throw new DomainException('Only a verify_skip unit may end as skipped.');
        }

        $values = ['status' => $to];
        foreach (['outcome', 'changed', 'existed_before', 'precheck_moid', 'precheck_instance_uuid', 'async_jid',
                  'remote_execution_id', 'async_deadline_at', 'vm_moid', 'vm_instance_uuid', 'error_code',
                  'error_detail', 'resumed_from_result_id'] as $column) {
            if (array_key_exists($column, $fields)) {
                $values[$column] = $fields[$column];
            }
        }
        $timestamps = [];
        if ($from === VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING) {
            $timestamps[] = 'started_at = COALESCE(started_at, UTC_TIMESTAMP())';
        }
        if (in_array($to, VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES, true)) {
            $timestamps[] = 'finished_at = UTC_TIMESTAMP()';
        }
        if ($to === VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING && $from === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN) {
            // Invariant 14: a resumed unit is open again, so the marks of its
            // former uncertainty go in the same statement. The uncertainty
            // itself stays in the job log and the audit trail, where a later
            // reader can still see that it happened.
            $timestamps[] = 'finished_at = NULL';
            $values['error_code'] = null;
            $values['error_detail'] = null;
        }

        $sets = [];
        $params = [];
        $types = '';
        foreach ($values as $column => $value) {
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value;
            $types .= repo_bind_type($value);
        }
        $sql = 'UPDATE deploy_create_vm_results SET ' . implode(', ', array_merge($sets, $timestamps))
            . ' WHERE id = ? AND status = ?';
        $params[] = (int) $row['id'];
        $params[] = $from;
        $types .= 'is';
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return $stmt->affected_rows > 0;
    });
}

/**
 * Poll heartbeat of a running unit. Its own function rather than a
 * running -> running transition through the CAS above, because nothing about
 * the row changes: an UPDATE whose values are identical reports zero affected
 * rows in MySQL, and a caller reading that as "I lost the race" would abandon a
 * unit it still owns.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 */
function repo_deploy_create_touch_running(mysqli $db, int $jobId, int $position, array $fence): bool
{
    $stmt = $db->prepare(
        'UPDATE deploy_create_vm_results r JOIN deploy_jobs j ON j.id = r.job_id'
        . ' SET r.updated_at = UTC_TIMESTAMP()'
        . ' WHERE r.job_id = ? AND r.position = ? AND r.status = ?'
        . ' AND j.status IN (?, ?) AND j.locked_by = ? AND j.lock_token = ? AND j.worker_epoch = ?'
    );
    $running = VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING;
    $jobRunning = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $jobCancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
    $workerId = (string) $fence['worker_id'];
    $lockToken = (string) $fence['lock_token'];
    $epoch = (int) $fence['worker_epoch'];
    $stmt->bind_param('iisssssi', $jobId, $position, $running, $jobRunning, $jobCancelling, $workerId, $lockToken, $epoch);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}

/**
 * Materializes the retry of a create job: a confirmed success becomes a
 * verify_skip unit bound to the row that proved it, everything else becomes a
 * fresh create unit. Positions are renumbered over the whole original
 * selection, so the retry still creates the VMs in the same order.
 *
 * @param list<array<string, mixed>> $sourceRows
 */
function repo_deploy_create_materialize_retry(mysqli $db, int $newJobId, array $sourceRows): int
{
    if ($sourceRows === []) {
        throw new InvalidArgumentException('A create retry needs the source job rows.');
    }
    $plan = deploy_create_retry_plan($sourceRows);
    if ($plan['blocked']) {
        throw new DomainException('The source job still has unresolved create units.');
    }
    $total = count($sourceRows);
    $position = 0;
    foreach ($sourceRows as $row) {
        $position++;
        $successful = in_array((string) $row['status'], VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true);
        repo_insert_from_values($db, 'deploy_create_vm_results', [
            'job_id' => $newJobId,
            'vm_id' => $row['vm_id'] !== null ? (int) $row['vm_id'] : null,
            'vm_name' => (string) $row['vm_name'],
            'position' => $position,
            'total' => $total,
            // A proven success is not copied as a success. It is queued as the
            // work of proving it again live, because "it existed an hour ago"
            // is not evidence that it exists now, and the skip is only written
            // once that check passed in THIS job.
            'action' => $successful ? VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP : VIRTUSPHERE_CREATE_ACTION_CREATE,
            'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
            'resumed_from_result_id' => $successful ? (int) $row['id'] : null,
        ]);
    }

    return $total;
}

/**
 * Units whose remote handle is ready to be cleaned up: terminal in this table,
 * bound to a handle, and that handle eligible according to its own state
 * machine. The cleanup decision itself belongs to the handle; this query only
 * says which create units point at one.
 *
 * An uncertain unit is deliberately absent whatever its deadline says. Its
 * async directory is the only remaining evidence about a VM whose fate nobody
 * has established, and a safety margin is not a proof of completion.
 *
 * @return list<array<string, mixed>>
 */
function repo_deploy_create_units_awaiting_cleanup(mysqli $db, int $limit = VIRTUSPHERE_REMOTE_CLEANUP_BATCH_SIZE): array
{
    $limit = max(1, min($limit, VIRTUSPHERE_REMOTE_CLEANUP_BATCH_SIZE));
    $stmt = $db->prepare(
        'SELECT r.id, r.job_id, r.position, r.status, r.async_jid, r.remote_execution_id,'
        . ' e.remote_dir, e.cleanup_state, e.cleanup_auto_attempts, e.cleanup_due_at'
        . ' FROM deploy_create_vm_results r'
        . ' JOIN deploy_remote_executions e ON e.id = r.remote_execution_id'
        . ' WHERE r.status IN (?, ?, ?)'
        . ' AND e.cleanup_state = ? AND e.cleanup_auto_attempts < ?'
        . ' AND (e.cleanup_due_at IS NULL OR e.cleanup_due_at <= UTC_TIMESTAMP(6))'
        . ' ORDER BY r.id LIMIT ?'
    );
    $succeeded = VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED;
    $failed = VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED;
    $skipped = VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED;
    $eligible = VIRTUSPHERE_REMOTE_CLEANUP_STATE_ELIGIBLE;
    $maxAttempts = VIRTUSPHERE_REMOTE_CLEANUP_MAX_AUTO_ATTEMPTS;
    $stmt->bind_param('ssssii', $succeeded, $failed, $skipped, $eligible, $maxAttempts, $limit);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}
