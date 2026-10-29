<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/deploy_job_vm_states.php';
require_once __DIR__ . '/deploy_worker_ownership.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_create_remote.php';
require_once __DIR__ . '/deploy_worker_create_unit.php';

/**
 * The in-job retry pass of the create section (prod job 1527, 29.10.2026).
 *
 * `vmware_guest` for one VM of a fifteen-VM full deploy broke off with
 * "503 Service Unavailable" from the ESXi host. The next unit carried on, and
 * the job ended `partial`: power-cycle, export and start never ran over a
 * selection that was one VM short, and the only help was "run again" by hand.
 *
 * After the last unit, every unit that ended `module_failed` gets at most one
 * more attempt, after one pause of VIRTUSPHERE_CREATE_RETRY_PAUSE_SECONDS. The
 * attempt is not a second path. It is the same unit walking the same Prepare,
 * Launch and Status calls (ADR-0041), one unit after the other:
 *
 *  - its read-only Prepare is the availability check. A host that still does
 *    not answer leaves the unit `failed` (transport_lost or protocol_error)
 *    with nothing changed on ESXi;
 *  - the identity matrix of that Prepare refuses a VM the first attempt left
 *    behind: a namesake without the stored UUID is `identity_conflict`, so a
 *    second VM cannot come into existence;
 *  - its own derived directory (`create.vm.<p>.attempt2`) keeps the job-id
 *    search after a lost launch from finding the first attempt's job.
 *
 * Never `prepared`, `running` or `uncertain`: the pass starts only when every
 * unit is terminal, and only for the auto-retryable code. A cancel, a lost
 * claim and the create budget come first, at every boundary.
 */

/**
 * The units the pass may take on. None while any unit is still pending or in
 * flight: the pass runs after the LAST unit, and it is the rows that say so.
 *
 * A candidate is a create unit on an attempt below the maximum whose first
 * attempt ended with an auto-retryable code and whose async job id is known:
 * module_failed comes only from a terminal status answer of a stored job.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function deploy_worker_create_retry_candidates(array $rows): array
{
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        if ($status === VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING
            || in_array($status, VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
            return [];
        }
    }
    $candidates = [];
    foreach ($rows as $row) {
        if ((string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED
            && in_array((string) ($row['error_code'] ?? ''), VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES, true)
            && (string) $row['action'] === VIRTUSPHERE_CREATE_ACTION_CREATE
            && deploy_create_unit_attempt($row) < VIRTUSPHERE_CREATE_MAX_ATTEMPTS
            && deploy_create_jid_is_valid((string) ($row['async_jid'] ?? ''))
            && (int) ($row['vm_id'] ?? 0) > 0) {
            $candidates[] = $row;
        }
    }

    return $candidates;
}

/**
 * Runs the retry pass and returns the reason the create section stopped, or
 * null when it may conclude from its rows as usual.
 *
 * A cancel at a boundary throws DeployWorkerCancelled from the same check the
 * unit loop uses, so "no further VM starts" holds for a second attempt too.
 *
 * @param array<string, mixed> $job the claim
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $context
 */
function deploy_worker_run_create_retry_pass(
    DeployWorkerDbChannel $channel,
    array $job,
    array $fence,
    array $context
): ?string {
    $jobId = (int) $job['id'];
    $workerId = (string) $fence['worker_id'];
    $candidates = deploy_worker_create_retry_candidates(repo_deploy_create_results($channel->connection(), $jobId));
    if ($candidates === []) {
        return null;
    }
    $pause = VIRTUSPHERE_CREATE_RETRY_PAUSE_SECONDS;
    if (deploy_worker_create_remaining_seconds($context['create_started_at'] ?? null) <= $pause) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Create retry not started: the create budget of this job leaves no room for the ' . $pause
            . ' s pause. ' . count($candidates) . ' unit(s) keep their first result.'
        );

        return null;
    }
    foreach ($candidates as $unit) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            deploy_worker_create_progress_line(
                $unit,
                'RETRY',
                (string) $unit['error_code'] . '; attempt ' . (deploy_create_unit_attempt($unit) + 1)
                . ' of ' . VIRTUSPHERE_CREATE_MAX_ATTEMPTS . ' after ' . $pause . ' s'
            )
        );
    }
    deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId, true, $job);
    deploy_worker_create_sleep($channel, $context, $pause);

    foreach ($candidates as $candidate) {
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId, true, $job);
        if (deploy_worker_create_remaining_seconds($context['create_started_at'] ?? null) <= 0) {
            $channel->log(
                VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                'Create retry stopped: the create budget of this job is exhausted; the remaining units keep their first result.'
            );

            return null;
        }
        $unit = deploy_worker_create_requeue_unit($channel->connection(), $job, $fence, (int) $candidate['position']);
        if ($unit === null) {
            // Refused under the lock: a cancel that arrived since the check
            // above, or a claim this worker lost. The conclusion decides the
            // first; the second is not ours to conclude.
            return deploy_worker_create_ownership($channel->connection(), $jobId, $workerId) === 'lost'
                ? VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST
                : null;
        }
        $verdict = deploy_worker_create_drive_unit($channel, $job, $fence, $unit, $context);
        if ($verdict['stop']) {
            return $verdict['reason'];
        }
    }

    return null;
}

/**
 * The one owner of the in-job edge `failed -> pending`. It is not in the
 * closed transition table on purpose: the generic CAS must never re-open a
 * terminal unit, and this writer does so only for a candidate of the pass.
 *
 * One transaction under Mission -> Job -> Runtime -> create rows -> journal,
 * the order every create and convergence writer takes. Inside it: the claim
 * and fence still hold and the job is `running` (a cancelling job starts no
 * further VM), the unit is still a candidate among ALL locked rows of the job,
 * and its journal row exists. Then the first attempt's code, detail and async
 * job id move to prior_*, the prepare evidence is cleared for the new
 * Prepare, and the durable launch intent goes back to 0. That last write is
 * what lets the second attempt launch at all: the intent belonged to an async
 * job whose terminal failure is established, and its id stays as
 * prior_async_jid, which keeps the K15 convergence on "fail" for this VM.
 *
 * @param array<string, mixed> $claim
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @return array<string, mixed>|null the re-opened unit, or null when refused
 */
function deploy_worker_create_requeue_unit(mysqli $db, array $claim, array $fence, int $position): ?array
{
    $jobId = (int) ($claim['id'] ?? 0);
    $done = deploy_worker_owned_transaction($db, $claim, static function (array $job) use ($db, $jobId, $position, $fence): ?bool {
        if ((string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING
            || (string) $job['locked_by'] !== (string) $fence['worker_id']
            || (string) $job['lock_token'] !== (string) $fence['lock_token']
            || (int) $job['worker_epoch'] !== (int) $fence['worker_epoch']) {
            return null;
        }
        $rows = repo_deploy_create_results($db, $jobId, true);
        $unit = null;
        foreach (deploy_worker_create_retry_candidates($rows) as $candidate) {
            if ((int) $candidate['position'] === $position) {
                $unit = $candidate;
            }
        }
        if ($unit === null) {
            return null;
        }
        $vmId = (int) $unit['vm_id'];
        if (!isset(repo_deploy_job_vm_states($db, $jobId)[$vmId])) {
            return null;
        }
        $attempt = deploy_create_unit_attempt($unit);
        $pending = VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING;
        $failed = VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED;
        $rowId = (int) $unit['id'];
        // prior_* are assigned before the columns they copy are cleared;
        // MySQL evaluates single-table SET assignments left to right.
        $stmt = $db->prepare(
            'UPDATE deploy_create_vm_results SET status = ?, attempt = attempt + 1,'
            . ' prior_error_code = error_code, prior_error_detail = error_detail, prior_async_jid = async_jid,'
            . ' error_code = NULL, error_detail = NULL, async_jid = NULL, async_deadline_at = NULL, finished_at = NULL,'
            . ' existed_before = NULL, precheck_moid = NULL, precheck_instance_uuid = NULL, replaced_instance_uuid = NULL'
            . ' WHERE id = ? AND status = ? AND attempt = ?'
        );
        $stmt->bind_param('sisi', $pending, $rowId, $failed, $attempt);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new LogicException('The locked create unit ' . $position . ' of job ' . $jobId . ' changed under its lock.');
        }
        $stmt = $db->prepare('UPDATE deploy_job_vm_states SET create_launch_intent = 0 WHERE job_id = ? AND vm_id = ?');
        $stmt->bind_param('ii', $jobId, $vmId);
        $stmt->execute();

        return true;
    });
    if ($done !== true) {
        return null;
    }
    foreach (repo_deploy_create_results($db, $jobId) as $row) {
        if ((int) $row['position'] === $position) {
            return $row;
        }
    }

    return null;
}
