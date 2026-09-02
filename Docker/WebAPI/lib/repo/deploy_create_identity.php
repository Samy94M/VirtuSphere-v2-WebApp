<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_result.php';
require_once __DIR__ . '/deploy_create_results.php';
require_once __DIR__ . '/helpers.php';

/**
 * The two writes of a create unit that are not a plain state transition
 * (Etappe 14B, Teiletappe E): the atomic identity commit of a success, and the
 * reaper's convergence of a job whose worker is gone.
 *
 * Both live apart from lib/repo/deploy_create_results.php because both touch a
 * second table under the same lock, and because the module budget is the point
 * where a repository stops being readable.
 */

/**
 * Binds the verified live identity of one succeeded create unit to its portal
 * VM and closes the unit, in ONE transaction (plan section 9.6.7).
 *
 * The order is the contract. Job, unit and VM are locked before anything is
 * decided, so a cancel, a reaper and a second poll answer all queue behind this
 * instead of interleaving with it. The identity decision has exactly three
 * outcomes and no fourth:
 *
 *  - the portal has no stored UUID: the live one is bound, which is what makes
 *    a created VM identifiable from the next job onwards;
 *  - the stored UUID equals the live one: only the MOID is refreshed, because
 *    a MOID is a handle that moves and the UUID is the identity that does not;
 *  - the stored UUID differs: nothing is written at all. Not the VM, not the
 *    success. The module reported that it converged something, and the thing
 *    it converged is provably not the VM this row is about.
 *
 * A duplicated terminal answer is not an error. It reports the outcome the
 * first one committed and writes nothing, which is what lets the poll loop
 * retry a status call after a database outage without a second DONE line.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @return array{committed:bool,replayed:bool,outcome:?string,error_code:?string}
 */
function repo_deploy_create_commit_success(
    mysqli $db,
    int $jobId,
    int $position,
    bool $existedBefore,
    bool $changed,
    string $liveMoid,
    string $liveInstanceUuid,
    array $fence
): array {
    $liveMoid = trim($liveMoid);
    $liveInstanceUuid = trim($liveInstanceUuid);
    if ($liveMoid === '' || $liveInstanceUuid === '') {
        throw new InvalidArgumentException('A create success needs a live MOID and instance UUID.');
    }

    return repo_transaction($db, static function () use (
        $db,
        $jobId,
        $position,
        $existedBefore,
        $changed,
        $liveMoid,
        $liveInstanceUuid,
        $fence
    ): array {
        $running = VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING;
        $stmt = $db->prepare(
            'SELECT r.id, r.vm_id, r.status, r.outcome, r.vm_instance_uuid, j.mission_id'
            . ' FROM deploy_create_vm_results r JOIN deploy_jobs j ON j.id = r.job_id'
            . ' WHERE r.job_id = ? AND r.position = ?'
            . ' AND j.status IN (?, ?) AND j.locked_by = ? AND j.lock_token = ? AND j.worker_epoch = ?'
            . ' FOR UPDATE'
        );
        $jobRunning = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $jobCancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $workerId = (string) $fence['worker_id'];
        $lockToken = (string) $fence['lock_token'];
        $epoch = (int) $fence['worker_epoch'];
        $stmt->bind_param('iissssi', $jobId, $position, $jobRunning, $jobCancelling, $workerId, $lockToken, $epoch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row === null || $row === false) {
            return ['committed' => false, 'replayed' => false, 'outcome' => null, 'error_code' => null];
        }
        $status = (string) $row['status'];
        if ($status !== $running) {
            // A terminal replay of the SAME evidence is a no-op with the same
            // answer; a replay that contradicts the stored one is not, because
            // one of the two readings is wrong and neither may overwrite the
            // other silently.
            $replayed = in_array($status, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true)
                && strcasecmp((string) $row['vm_instance_uuid'], $liveInstanceUuid) === 0;

            return [
                'committed' => false,
                'replayed' => $replayed,
                'outcome' => $replayed ? (string) $row['outcome'] : null,
                'error_code' => $replayed ? null : VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
            ];
        }

        $vmId = $row['vm_id'] === null ? 0 : (int) $row['vm_id'];
        $missionId = (int) $row['mission_id'];
        if ($vmId <= 0) {
            return [
                'committed' => false,
                'replayed' => false,
                'outcome' => null,
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
            ];
        }
        $vm = repo_fetch_one(
            $db,
            'SELECT id, vm_name, vm_moid, vm_instance_uuid FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$vmId, $missionId]
        );
        if ($vm === null) {
            return [
                'committed' => false,
                'replayed' => false,
                'outcome' => null,
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
            ];
        }
        $stored = trim((string) ($vm['vm_instance_uuid'] ?? ''));
        if ($stored !== '' && strcasecmp($stored, $liveInstanceUuid) !== 0) {
            return [
                'committed' => false,
                'replayed' => false,
                'outcome' => null,
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
            ];
        }

        try {
            $outcome = deploy_create_outcome_for($existedBefore, $changed);
        } catch (DomainException) {
            // "Nothing was there and nothing changed" is the fourth, illegal
            // evidence combination: the module reported success for a VM the
            // live check had not seen and did not create.
            return [
                'committed' => false,
                'replayed' => false,
                'outcome' => null,
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
            ];
        }

        repo_execute(
            $db,
            'UPDATE deploy_vms SET vm_moid = ?, vm_instance_uuid = ?, updated_at = NOW() WHERE id = ? AND mission_id = ?',
            'ssii',
            [$liveMoid, $stored === '' ? $liveInstanceUuid : $stored, $vmId, $missionId]
        );

        $committed = repo_deploy_create_transition(
            $db,
            $jobId,
            $position,
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            [
                'outcome' => $outcome,
                'changed' => $changed ? 1 : 0,
                'existed_before' => $existedBefore ? 1 : 0,
                'vm_moid' => $liveMoid,
                'vm_instance_uuid' => $liveInstanceUuid,
            ],
            $fence
        );

        return [
            'committed' => $committed,
            'replayed' => false,
            'outcome' => $committed ? $outcome : null,
            'error_code' => $committed ? null : VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST,
        ];
    });
}

/**
 * The source row a verify_skip unit has to confirm live, resolved to the real
 * success rather than to another skip.
 *
 * A skip chain is not an identity proof: three retries in a row would each
 * point at the previous skip, and the actual evidence would be four jobs back.
 * The walk is bounded, and a chain that does not end in a success answers null,
 * which the worker turns into a refusal rather than into a create.
 *
 * @return array<string, mixed>|null
 */
function repo_deploy_create_skip_source(mysqli $db, int $resultId, int $maxDepth = 8): ?array
{
    for ($depth = 0; $depth < $maxDepth && $resultId > 0; $depth++) {
        $row = repo_fetch_one(
            $db,
            'SELECT id, job_id, vm_id, vm_name, status, outcome, vm_moid, vm_instance_uuid, resumed_from_result_id'
            . ' FROM deploy_create_vm_results WHERE id = ? LIMIT 1',
            'i',
            [$resultId]
        );
        if ($row === null) {
            return null;
        }
        if ((string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED) {
            return $row;
        }
        if ((string) $row['status'] !== VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED) {
            return null;
        }
        $resultId = $row['resumed_from_result_id'] === null ? 0 : (int) $row['resumed_from_result_id'];
    }

    return null;
}

/**
 * Converges the in-flight create units of a job whose worker stopped reporting,
 * inside the reaper's own transaction.
 *
 * This is the ONE writer of create rows outside the ownership CAS, and the
 * reason it exists is a difference between the plan and the code it was
 * written against. The plan hands a reaped job to a recovery claim that
 * reattaches the same async job id; that claim belongs to the remote execution
 * contract, which stays locked until its site acceptance. Under the legacy
 * transport nobody will ever poll this unit again, so leaving it `running`
 * would assert an active poll that no process performs - the same shape of lie
 * as the idle timeout this whole stage exists to remove.
 *
 * It therefore says what is established and nothing more: `uncertain`. That
 * asserts nothing about the VM, keeps the stored async job id and deadline as
 * the evidence a reconciliation needs, and is exactly the state the operator
 * release of section 10.5 consumes. Confirmed successes, failures and skips of
 * the same job are untouched, which is the property the incident lacked.
 *
 * @return int Number of units converged.
 */
function repo_deploy_create_converge_reaped(mysqli $db, int $jobId, string $detail): int
{
    $detail = trim($detail);
    if ($detail === '') {
        throw new InvalidArgumentException('A converged create unit needs its observation.');
    }
    $stmt = $db->prepare(
        'UPDATE deploy_create_vm_results SET status = ?, error_code = ?, error_detail = ?,'
        . ' finished_at = UTC_TIMESTAMP() WHERE job_id = ? AND status IN (?, ?)'
    );
    $uncertain = VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN;
    $code = VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST;
    $prepared = VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED;
    $running = VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING;
    $stmt->bind_param('sssiss', $uncertain, $code, $detail, $jobId, $prepared, $running);
    $stmt->execute();

    return $stmt->affected_rows > 0 ? $stmt->affected_rows : 0;
}
