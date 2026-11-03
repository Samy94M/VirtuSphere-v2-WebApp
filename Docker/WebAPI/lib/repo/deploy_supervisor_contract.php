<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_constants.php';
require_once __DIR__ . '/../deploy_supervisor_constants.php';
require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_runtime_identity.php';
require_once __DIR__ . '/deploy_job_service_state.php';

/**
 * The closed reasons a process-contract switch is refused (Etappe 14C, plan
 * section 21.1).
 *
 * Closed rather than free text because a refusal here is an operator's next
 * task, and "something was not ready" is not a task. Every code names one
 * condition and only one, so the answer says which of them to go and fix.
 */
const VIRTUSPHERE_SUPERVISOR_SWITCH_BLOCKERS = [
    'switch_activation_not_settled',
    'switch_legacy_job_unresolved',
    'switch_claims_not_paused',
    'switch_not_drained',
    'switch_supervisor_still_reporting',
    'switch_already_applied',
];

/**
 * Why this switch may not happen right now, as a complete list.
 *
 * Complete, not first-match: an operator preparing a maintenance window needs
 * to know everything that is in the way, not the first thing. The five
 * conditions come straight from the plan and each one has a mechanical reason:
 *
 *  - Every activation row of every active Ansible credential must be
 *    `remote_enabled` or `disabled`. A mode parked in `legacy_explicit`,
 *    `pilot_remote` or `rollback_pending` is a mode whose recovery contract is
 *    mid-change, and a supervisor that restarts a child under it would be
 *    restarting into a shape nobody has decided yet.
 *  - No active, unresolved or foreign-generation job may still hold
 *    `legacy_v1`. A restarted worker has to be able to pick such a job back up;
 *    while one is unresolved, nobody can say whether a restart would resume it
 *    or repeat it.
 *  - Claims must be paused and the queue drained, so nothing starts during the
 *    window.
 *  - No supervisor may already be reporting, which would mean the process shape
 *    changed before the contract did.
 *
 * @return list<string>
 */
function deploy_supervisor_switch_blockers(mysqli $db, string $target): array
{
    $blockers = [];
    $identity = repo_deploy_runtime_identity($db);
    if ($identity['supervisor_contract'] === $target) {
        return ['switch_already_applied'];
    }

    if ($target === VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR) {
        $unsettled = (int) (repo_scalar(
            $db,
            'SELECT COUNT(*) FROM deploy_remote_mode_activations a
             JOIN deploy_credentials c ON c.id = a.credential_ansible_id
             WHERE c.type = ? AND a.state NOT IN (?, ?)',
            'sss',
            ['ansible', VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED, VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED]
        ) ?? 0);
        if ($unsettled > 0) {
            $blockers[] = 'switch_activation_not_settled';
        }
    }

    // Active, unresolved or foreign-generation legacy work.
    //
    // Three kinds, and the third one is the reason this check is not just
    // "status is active": since Etappe 14B a create unit can end `uncertain`,
    // which means nobody established what happened to that VM on the host. Its
    // job may well be terminal. Restarting a child under an open question like
    // that is precisely the situation where a resume and a repeat look the same
    // from inside this process, so the window stays shut until a person has
    // decided it.
    $activePlaceholders = implode(',', array_fill(0, count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES), '?'));
    $unresolved = (int) (repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_jobs j
         WHERE j.execution_contract = ?
           AND (j.status IN (' . $activePlaceholders . ')
                OR j.recovery_reason IN (?, ?)
                OR EXISTS (
                    SELECT 1 FROM deploy_create_vm_results r
                    WHERE r.job_id = j.id AND r.status = ?
                ))',
        's' . str_repeat('s', count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)) . 'sss',
        array_merge(
            [VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY],
            VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES,
            [
                VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN,
                VIRTUSPHERE_DEPLOY_RECOVERY_FOREIGN_GENERATION,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            ]
        )
    ) ?? 0);
    if ($unresolved > 0) {
        $blockers[] = 'switch_legacy_job_unresolved';
    }

    $claim = repo_deploy_claim_state($db);
    if ($claim['state'] !== VIRTUSPHERE_DEPLOY_CLAIM_PAUSED) {
        $blockers[] = 'switch_claims_not_paused';
    }

    $active = (int) (repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_jobs WHERE status IN (' . $activePlaceholders . ')',
        str_repeat('s', count(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES)),
        VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES
    ) ?? 0);
    if ($active > 0) {
        $blockers[] = 'switch_not_drained';
    }

    // Rolling BACK also requires that no supervisor is still publishing: the
    // process has to be gone before the contract says it is.
    if ($target === VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER) {
        $reporting = (int) (repo_scalar(
            $db,
            'SELECT COUNT(*) FROM deploy_runtime_identity
             WHERE id = 1 AND supervisor_heartbeat_at IS NOT NULL
               AND supervisor_heartbeat_at > (NOW() - INTERVAL ? SECOND)',
            'i',
            [supervisor_heartbeat_max_age_seconds()]
        ) ?? 0);
        if ($reporting > 0) {
            $blockers[] = 'switch_supervisor_still_reporting';
        }
    }

    return $blockers;
}

/**
 * Applies the switch, or reports why not. Never automatic: the only caller is
 * the maintenance-window CLI, and it asks for the target explicitly.
 *
 * The CAS condition is the CURRENT contract, so a concurrent switch loses
 * rather than being overwritten, and the answer then reports the value that
 * actually stands instead of the one this call wanted.
 *
 * @return array{changed:bool,contract:string,blockers:list<string>}
 */
function repo_deploy_switch_supervisor_contract(mysqli $db, string $target, ?int $userId): array
{
    if (!in_array($target, VIRTUSPHERE_SUPERVISOR_CONTRACTS, true)) {
        throw new InvalidArgumentException('Unknown supervisor contract: ' . $target);
    }

    return repo_transaction($db, static function () use ($db, $target, $userId): array {
        $blockers = deploy_supervisor_switch_blockers($db, $target);
        if ($blockers !== []) {
            return [
                'changed' => false,
                'contract' => repo_deploy_runtime_identity($db)['supervisor_contract'],
                'blockers' => $blockers,
            ];
        }

        $current = repo_deploy_runtime_identity($db)['supervisor_contract'];
        $stmt = $db->prepare(
            'UPDATE deploy_runtime_identity
             SET supervisor_contract = ?, supervisor_contract_changed_at = NOW(), supervisor_contract_changed_by = ?
             WHERE id = 1 AND supervisor_contract = ?'
        );
        $actor = $userId !== null && $userId > 0 ? $userId : null;
        $stmt->bind_param('sis', $target, $actor, $current);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            return [
                'changed' => false,
                'contract' => repo_deploy_runtime_identity($db)['supervisor_contract'],
                'blockers' => ['switch_already_applied'],
            ];
        }

        return ['changed' => true, 'contract' => $target, 'blockers' => []];
    });
}
