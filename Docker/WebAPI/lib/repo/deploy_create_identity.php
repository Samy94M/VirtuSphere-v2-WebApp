<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_result.php';
require_once __DIR__ . '/deploy_create_results.php';
require_once __DIR__ . '/../deploy_create_release.php';
require_once __DIR__ . '/deploy_job_worker.php';
require_once __DIR__ . '/vm_network.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_create_release_unit.php';

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
 * instead of interleaving with it. The identity decision has exactly four
 * outcomes and no fifth:
 *
 *  - the portal has no stored UUID: the live one is bound, which is what makes
 *    a created VM identifiable from the next job onwards;
 *  - the stored UUID equals the live one: only the MOID is refreshed, because
 *    a MOID is a handle that moves and the UUID is the identity that does not;
 *  - the stored UUID differs, but this unit's preparation proved exactly that
 *    UUID absent live and the module created a new VM (IDR-P02): the new
 *    instance replaces the externally deleted one, whose imported MACs and
 *    report generation are reset in the same transaction. Create deletes
 *    nothing on the host; it only filled the gap the operator left;
 *  - the stored UUID differs in any other way: nothing is written at all. The
 *    module converged something that is provably not the VM this row is about.
 *
 * A duplicated terminal answer is not an error. It reports the outcome the
 * first one committed and writes nothing, which is what lets the poll loop
 * retry a status call after a database outage without a second DONE line.
 *
 * DF-E1: $fromStatus is `prepared` only for an existing own VM that was not
 * powered off and is therefore concluded unchanged without a launch. The locked
 * row must say the same: existed_before = 1 and the precheck instance UUID the
 * live one, otherwise the success is refused without a write.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @return array{committed:bool,replayed:bool,outcome:?string,error_code:?string,replaced_instance_uuid?:string}
 *         replaced_instance_uuid is present only on a committed replacement.
 */
function repo_deploy_create_commit_success(
    mysqli $db,
    int $jobId,
    int $position,
    bool $existedBefore,
    bool $changed,
    string $liveMoid,
    string $liveInstanceUuid,
    array $fence,
    string $fromStatus = VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING
): array {
    $liveMoid = trim($liveMoid);
    $liveInstanceUuid = trim($liveInstanceUuid);
    if ($liveMoid === '' || $liveInstanceUuid === '') {
        throw new InvalidArgumentException('A create success needs a live MOID and instance UUID.');
    }
    // DF-E1: a unit concluded without a launch only ever verified an existing
    // own VM, so its one legal answer is unchanged.
    if ($fromStatus !== VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING
        && !($fromStatus === VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED && $existedBefore && !$changed)
    ) {
        throw new InvalidArgumentException('A create success without a launch can only be an unchanged existing VM.');
    }

    return repo_transaction($db, static function () use (
        $db,
        $jobId,
        $position,
        $existedBefore,
        $changed,
        $liveMoid,
        $liveInstanceUuid,
        $fence,
        $fromStatus
    ): array {
        $running = $fromStatus;
        $stmt = $db->prepare(
            'SELECT r.id, r.vm_id, r.status, r.outcome, r.existed_before, r.precheck_instance_uuid, r.vm_instance_uuid, r.replaced_instance_uuid, j.mission_id, j.credential_esxi_id'
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

        // The launch-less edge is decided by the locked row, not by the caller's
        // arguments: only a unit the preparation proved to be an existing VM, and
        // whose proven instance is the live one, may conclude as unchanged.
        if ($fromStatus === VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED && (
            (int) ($row['existed_before'] ?? 0) !== 1
            || strcasecmp(trim((string) ($row['precheck_instance_uuid'] ?? '')), $liveInstanceUuid) !== 0
        )) {
            return [
                'committed' => false,
                'replayed' => false,
                'outcome' => null,
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
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
        $provenAbsent = trim((string) ($row['replaced_instance_uuid'] ?? ''));
        // IDR-P02: legal only for the very UUID the preparation proved absent and
        // a real creation. A binding written since then changes $stored.
        $replacement = $stored !== ''
            && strcasecmp($stored, $liveInstanceUuid) !== 0
            && $provenAbsent !== ''
            && strcasecmp($provenAbsent, $stored) === 0
            && !$existedBefore
            && $changed;
        if ($stored !== '' && strcasecmp($stored, $liveInstanceUuid) !== 0 && !$replacement) {
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

        // A replacement rotates the report generation (ADR-0044: late reports of
        // the deleted VM must not count for the new one) and forgets its
        // imported MACs. MECM stays with the operator: delete the old device
        // there, then reset the MECM ID in the portal.
        // K17: the identity was just proven through this job's access, so the
        // binding records that access and its current target with it.
        $credentialId = $row['credential_esxi_id'] === null ? null : (int) $row['credential_esxi_id'];
        repo_execute(
            $db,
            'UPDATE deploy_vms SET vm_moid = ?, vm_instance_uuid = ?, '
            . ($replacement ? 'package_report_generation = UUID_TO_BIN(UUID()), ' : '')
            . "vm_bound_credential_id = ?, vm_bound_esxi_host = (SELECT c.host FROM deploy_credentials c WHERE c.id = ? AND c.type = 'esxi'), "
            . 'updated_at = NOW() WHERE id = ? AND mission_id = ?',
            'ssiiii',
            [$liveMoid, ($stored === '' || $replacement) ? $liveInstanceUuid : $stored, $credentialId, $credentialId, $vmId, $missionId]
        );
        if ($replacement) {
            repo_vm_network_forget_observed_macs($db, $vmId);
        }

        $committed = repo_deploy_create_transition(
            $db,
            $jobId,
            $position,
            $fromStatus,
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

        $answer = [
            'committed' => $committed,
            'replayed' => false,
            'outcome' => $committed ? $outcome : null,
            'error_code' => $committed ? null : VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST,
        ];
        if ($committed && $replacement) {
            $answer['replaced_instance_uuid'] = $stored;
        }

        return $answer;
    });
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
 * K15 first closes journaled prepared units with no launch intent as a known
 * pre-launch failure. They have no remote work to reconcile. The remaining
 * started or historically unjournaled units say only what is established:
 * `uncertain`. That
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
    // K15 can prove no launch only for a captured VM with no durable intent.
    // Rows from before the journal retain their conservative historical rule.
    $prepared = VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED;
    $failed = VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED;
    $code = VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST;
    $stmt = $db->prepare(
        'UPDATE deploy_create_vm_results r JOIN deploy_job_vm_states s ON s.job_id = r.job_id AND s.vm_id = r.vm_id'
        . ' SET r.status = ?, r.error_code = ?, r.error_detail = ?, r.finished_at = UTC_TIMESTAMP()'
        . ' WHERE r.job_id = ? AND r.status = ? AND s.create_launch_intent = 0 AND COALESCE(r.async_jid, \'\') = \'\''
    );
    $stmt->bind_param('sssis', $failed, $code, $detail, $jobId, $prepared);
    $stmt->execute();
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
