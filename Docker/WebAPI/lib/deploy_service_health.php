<?php

declare(strict_types=1);

// The one snapshot of the deploy service (Etappe 13R).
//
// Dashboard, deploy page, System status and the anonymous health endpoint all
// read THIS. Four surfaces deriving "is the service alright" from four
// combinations of heartbeat, queue and job rows is four chances to disagree,
// and the one that disagreed was always the one the operator happened to open.
//
// Three axes, because they are independently true (see the constants). The
// derivations below are pure functions over a fact struct: a state machine that
// can only be exercised through a database is a state machine whose corners are
// never tested, and every corner here is a corner an operator meets on a bad
// day.
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/remote_execution_constants.php';
require_once __DIR__ . '/integration_health.php';
require_once __DIR__ . '/deploy_supervisor_constants.php';
require_once __DIR__ . '/deploy_supervisor_policy.php';
require_once __DIR__ . '/repo/deploy_job_service_state.php';
require_once __DIR__ . '/repo/deploy_supervisor_state.php';
require_once __DIR__ . '/repo/deploy_jobs.php';

/**
 * The availability axis, by fixed precedence. The first matching rule wins.
 *
 * The order encodes what an operator has to know FIRST. A dead process beats
 * everything, because nothing else in the snapshot means anything while nobody
 * is executing. An inconsistent or overdue queue beats "busy", because a
 * service that looks occupied while a due job waits is the failure that reads
 * like normal operation. `busy` beats `ready` for the obvious reason.
 *
 * Three things deliberately do NOT change this axis:
 *  - a claim pause, which is its own axis and does not mean the service broke,
 *  - a job scheduled for later, which is not late until it is due,
 *  - a purely historical failure, which is over.
 *
 * @param array{
 *     supervisor_contract: string,
 *     process_alive: bool,
 *     child_alive: bool,
 *     shape_matches_contract: bool,
 *     active_job: bool,
 *     active_job_consistent: bool,
 *     overdue_seconds: int,
 *     claim_state: string,
 *     restart_cooldown: bool
 * } $facts
 */
function deploy_service_availability(array $facts): string
{
    // Fail closed on a contract this build cannot observe. Guessing would mean
    // reporting the health of a process shape that is not the one running.
    if (!in_array($facts['supervisor_contract'], VIRTUSPHERE_SUPERVISOR_CONTRACTS, true)) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED;
    }
    // The same rule one level down: the contract is a claim about which process
    // shape is running, and a claim that the observation contradicts is not a
    // basis for any other answer. A supervisor reporting while the row says
    // `worker_v1` is exactly that case, and it is the state a half-finished
    // maintenance window leaves behind.
    if (!$facts['shape_matches_contract']) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED;
    }
    if (!$facts['process_alive']) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE;
    }
    if ($facts['supervisor_contract'] === VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR) {
        // Cooldown BEFORE the missing child, and the order is load-bearing: a
        // supervisor in its restart window legitimately holds no child, and
        // reporting that as a fault would make every planned restart look like
        // a breakage.
        if ($facts['restart_cooldown']) {
            return VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN;
        }
        if (!$facts['child_alive']) {
            return VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED;
        }
    }
    if ($facts['active_job'] && !$facts['active_job_consistent']) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED;
    }
    // A job past its due time while the service claims to be accepting and is
    // holding nothing: something is stopping the claim that the claim axis does
    // not explain. A pause explains it, so a paused service is not degraded.
    if (!$facts['active_job']
        && deploy_claim_state_allows_new_work($facts['claim_state'])
        && $facts['overdue_seconds'] > VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS
    ) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED;
    }
    if ($facts['active_job']) {
        return VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY;
    }

    return VIRTUSPHERE_DEPLOY_AVAILABILITY_READY;
}

/**
 * The attention axis: manual review beats recovering beats none.
 *
 * Unresolved create units count even after their job ends. Resolved history
 * and routine cleanup retries are not attention: a signal that lights up for
 * things nobody can act on is a signal people learn to ignore, and then it is
 * not there on the day it matters.
 *
 * @param array{manual_required: int, legacy_uncertain_active: int, recovering: int} $facts
 */
function deploy_service_recovery_attention(array $facts): string
{
    if ($facts['manual_required'] > 0 || $facts['legacy_uncertain_active'] > 0) {
        return VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW;
    }
    if ($facts['recovering'] > 0) {
        return VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING;
    }

    return VIRTUSPHERE_DEPLOY_ATTENTION_NONE;
}

/**
 * The compact badge variant, derived from all three axes at once.
 *
 * It exists for a table cell that has room for one thing. Every DETAIL view
 * still shows all three, because the badge is a summary and a summary is not
 * allowed to be the only place a fact appears: `busy + pause_after_current` and
 * `offline + manual_review` are both real and both matter.
 */
function deploy_service_badge_variant(string $availability, string $attention): string
{
    if ($availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE
        || $attention === VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW
    ) {
        return 'danger';
    }
    if ($availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED
        || $availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN
        || $attention === VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING
    ) {
        return 'warning';
    }
    if ($availability === VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY) {
        return 'info';
    }

    return 'success';
}

/**
 * The full snapshot.
 *
 * @return array{
 *     availability: string,
 *     claim_state: string,
 *     recovery_attention: string,
 *     source_contract: string,
 *     badge: string,
 *     queue: array{due:int,scheduled:int,oldest_due_at:?string,overdue_seconds:int},
 *     active: array{job_id:?int,mission_id:?int,mission_name:?string,status:?string,heartbeat_at:?string,consistent:bool},
 *     claim: array{state:string,changed_at:?string,changed_by:?int,changed_by_name:?string},
 *     recovery: array{manual_required:int,legacy_uncertain_active:int,recovering:int}
 * }
 */
function deploy_service_health_snapshot(mysqli $db, ?int $now = null): array
{
    $now ??= time();
    $identity = repo_deploy_runtime_identity($db);
    $claim = repo_deploy_claim_state($db);
    $queue = repo_deploy_queue_pressure($db, $now);
    $active = repo_deploy_active_job_summary($db, $now);
    $recovery = repo_deploy_recovery_attention_counts($db);
    $supervisor = repo_deploy_supervisor_state($db);

    $contract = $identity['supervisor_contract'];
    $supervisorFresh = deploy_supervisor_state_is_fresh($supervisor, $now);
    // The child's liveness is read the way every other surface reads it, from
    // the worker's own status row. That is not a second opinion about the file
    // the supervisor watches: the two live in different containers, and each
    // layer uses the only source it can actually reach.
    $childAlive = integration_deploy_worker_alive_now($db, $now);

    $availability = deploy_service_availability([
        'supervisor_contract' => $contract,
        // Which process has to be alive depends on which shape is running: under
        // `worker_v1` it is the worker itself, under `supervisor_v1` it is the
        // supervisor, and a missing child there is `degraded` or `cooldown`,
        // never `offline`.
        'process_alive' => $contract === VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR
            ? $supervisorFresh
            : $childAlive,
        'child_alive' => $childAlive,
        // A supervisor reporting under `worker_v1` means the container was
        // started into the new shape without the window that decides it.
        'shape_matches_contract' => $contract === VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR || !$supervisorFresh,
        'active_job' => $active['job_id'] !== null,
        'active_job_consistent' => $active['consistent'],
        'overdue_seconds' => $queue['overdue_seconds'],
        'claim_state' => $claim['state'],
        'restart_cooldown' => $supervisor['phase'] !== null
            && deploy_supervisor_is_cooling_down(['phase' => $supervisor['phase']]),
    ]);
    $attention = deploy_service_recovery_attention($recovery);

    return [
        'availability' => $availability,
        'claim_state' => $claim['state'],
        'recovery_attention' => $attention,
        'source_contract' => $contract,
        'badge' => deploy_service_badge_variant($availability, $attention),
        'queue' => $queue,
        'active' => $active,
        'claim' => $claim,
        'recovery' => $recovery,
        'supervisor' => $supervisor + ['fresh' => $supervisorFresh, 'child_alive' => $childAlive],
    ];
}

/**
 * Whether the supervisor's published heartbeat is recent enough to count.
 *
 * The published copy is written on a slower cadence than the local file, so the
 * bound is the publish interval and not the tick: judging a row that is written
 * every thirty seconds against a four-tick file window would call a healthy
 * supervisor dead between two of its own writes.
 *
 * @param array<string,mixed> $supervisor
 */
function deploy_supervisor_state_is_fresh(array $supervisor, int $now): bool
{
    if ($supervisor['heartbeat_at'] === null) {
        return false;
    }
    $seen = strtotime((string) $supervisor['heartbeat_at']);
    if ($seen === false) {
        return false;
    }

    return ($now - $seen) <= (3 * VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS);
}

/**
 * What an operator may expect of a job they queue right now.
 *
 * Five closed answers over the three axes, with a fixed precedence, so the
 * sentence above the button and the state on the System status card cannot
 * disagree. A pause is named before a fault, because a paused service is the
 * one case where the wait is somebody's decision and the operator can go and
 * undo it.
 *
 * @param array<string,mixed> $snapshot
 */
function deploy_service_queue_expectation(array $snapshot): string
{
    if ($snapshot['claim_state'] !== VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING) {
        return __t('deploy.service_expect_paused');
    }

    return match ($snapshot['availability']) {
        VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE => __t('deploy.service_expect_offline'),
        // Cooldown gets its own sentence rather than sharing the degraded one.
        // It is a planned, ending state, and "something is wrong" would suggest
        // an action that would be the wrong one here: waiting is the action.
        VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN => __t('deploy.service_expect_cooldown'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED => __t('deploy.service_expect_degraded'),
        VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY => __t('deploy.service_expect_busy'),
        default => $snapshot['recovery_attention'] === VIRTUSPHERE_DEPLOY_ATTENTION_NONE
            ? __t('deploy.service_expect_ready')
            : __t('deploy.service_expect_recovering'),
    };
}
