<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_release.php';
require_once __DIR__ . '/helpers.php';

/**
 * The operator release of an uncertain create unit, re-decided under the lock. Split from
 * lib/repo/deploy_create_identity.php for the ADR-0006 file budget.
 */

/**
 * The operator release of one unresolved create unit (plan section 10.5).
 *
 * Everything is re-decided under the lock, because the eligibility a person saw
 * on a page is a statement about the moment they looked. Between that render
 * and this write an inventory pull can have found the VM, another job can have
 * started, or a recovery can have resolved the unit. The transaction therefore
 * repeats the whole check and refuses rather than trusting the form.
 *
 * What it writes: the append-only resolution carrying the operator's own words
 * (behind `system.config`, never in the audit row every `users.manage` holder
 * reads), and the unit's transition `uncertain -> failed` with
 * `operator_released`. What it does NOT write: anything about the VM, and
 * nothing in the source job's log. This action adopts nothing, deletes nothing
 * and creates nothing. It only ends the state in which a retry was forbidden.
 *
 * @param string $reason What the operator established, kept in the resolution.
 * @param string|null $reference Their evidence or ticket, if they have one.
 * @return array{released:bool,blockers:list<string>,resolution_id:?int}
 */
function repo_deploy_create_release_unit(
    mysqli $db,
    int $jobId,
    int $position,
    int $actorId,
    string $reason,
    ?string $reference
): array {
    if ($actorId <= 0) {
        throw new InvalidArgumentException('A create release needs an actor.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new ValidationException(['reason' => __t('validate.required')]);
    }

    return repo_transaction($db, static function () use ($db, $jobId, $position, $actorId, $reason, $reference): array {
        $decision = deploy_create_release_blockers($db, $jobId, $position, true);
        if (!$decision['eligible']) {
            return ['released' => false, 'blockers' => $decision['blockers'], 'resolution_id' => null];
        }
        $unit = (array) $decision['unit'];

        // The state the operator's judgement was made about, hashed with it, so
        // the entry cannot later be read as covering a state it never saw.
        $previous = [
            'create_result_id' => (int) $unit['id'],
            'position' => (int) $unit['position'],
            'vm_name' => (string) $unit['vm_name'],
            'status' => (string) $unit['status'],
            'error_code' => $unit['error_code'] === null ? null : (string) $unit['error_code'],
            'async_jid' => $unit['async_jid'] === null ? null : (string) $unit['async_jid'],
            'inventory' => $decision['evidence'],
        ];
        $encoded = json_encode($previous, JSON_THROW_ON_ERROR);
        $scope = VIRTUSPHERE_RECOVERY_RESOLUTION_SCOPE_CREATE_UNIT;
        $code = VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_NOT_APPLIED;
        $fingerprint = hash('sha256', $encoded);
        $resultId = (int) $unit['id'];
        $boundedReason = mb_substr($reason, 0, 1024);
        repo_execute(
            $db,
            'INSERT INTO deploy_recovery_resolutions
                (job_id, remote_execution_id, create_result_id, resolution_scope, resolution_code, reason, reference, evidence_fingerprint, actor_id, previous_state)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
            // job, result, scope, code, reason, reference, fingerprint, actor, state
            'iisssssis',
            [$jobId, $resultId, $scope, $code, $boundedReason, $reference, $fingerprint, $actorId, $encoded]
        );
        $resolutionId = (int) $db->insert_id;

        // The transition is a plain UPDATE rather than the worker's CAS: no
        // worker owns a terminal job, so there is no ownership fence to compare
        // against. The status condition IS the fence here, and the decision
        // above already re-read the row under the same lock.
        $stmt = $db->prepare(
            'UPDATE deploy_create_vm_results SET status = ?, error_code = ?, error_detail = ?,'
            . ' finished_at = UTC_TIMESTAMP() WHERE id = ? AND status = ?'
        );
        $failed = VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED;
        $releaseCode = VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED;
        $detail = 'Released by an operator who confirmed on the ESXi host that this VM was not created; '
            . 'recovery resolution ' . $resolutionId . '.';
        $uncertain = VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN;
        $stmt->bind_param('sssis', $failed, $releaseCode, $detail, $resultId, $uncertain);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('The create unit changed while it was being released.');
        }

        // Deliberately NO line in the source job's log. That log is terminal
        // evidence and the append path refuses it (Etappe 8), which is the
        // right refusal: a job's log is the account of what the job did, and a
        // decision a person made hours later did not happen during the run. The
        // release is recorded three times without it - in the create row, which
        // now says operator_released; in the append-only resolution above, with
        // the operator's own words; and in the audit trail, with who and when.
        return ['released' => true, 'blockers' => [], 'resolution_id' => $resolutionId];
    });
}
