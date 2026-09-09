<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/deploy_create_identity.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_vm_state.php';
require_once __DIR__ . '/deploy_worker_create_unit.php';

/**
 * The sequential per-VM create section of a mission deploy (Etappe 14B,
 * Teiletappe E; plan section 9).
 *
 * One deploy job creates its VMs one at a time, and every one of them has a
 * durable row before, during and after its own async job. That is the whole
 * repair: on 13.08.2026 a fifteen-VM create died on an idle timeout while
 * fourteen VMs existed on ESXi, and nothing in the database could say which
 * fourteen. The rows can now say it, and this module is what writes them.
 *
 * Two properties are load-bearing and neither is visible in a green run:
 *
 *  - the next unit starts only when no other unit of this job is prepared,
 *    running or uncertain, and that answer comes from the rows rather than
 *    from this process's memory of what it launched. A restarted worker
 *    therefore cannot start a second create for a VM whose first one may still
 *    be running on the host;
 *  - an `uncertain` unit stops the job. Always. It is the state in which
 *    VirtuSphere knows that it does not know, and continuing past it would
 *    build the rest of the run on an unestablished fact.
 */

/** Thrown for a defect in the job's own materialization; ends the job. */
final class DeployWorkerCreateProtocolError extends RuntimeException
{
}

/**
 * Runs the create section and returns what the job may do next.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $context credential, secret, remote_dir, verbose,
 *        secrets, options and the injectable clock/sleeper the tests drive.
 * @return array{all_successful:bool,summary:array<string,mixed>,stop_reason:?string}
 */
function deploy_worker_run_create_section(
    DeployWorkerDbChannel $channel,
    array $job,
    array $fence,
    array $context
): array {
    $jobId = (int) $job['id'];
    $db = $channel->connection();
    $rows = repo_deploy_create_results($db, $jobId);
    deploy_worker_create_assert_materialized($rows, $job);

    // Persisted, and only once: the budget of the create section runs from the
    // first RUN of the job, not from the claim and not from this process's
    // start. A recovered job re-enters here and must not restart a budget that
    // has been running since its first VM.
    repo_deploy_create_mark_started($db, $jobId);
    $context['create_started_at'] = deploy_worker_create_started_at($db, $jobId);

    $stopReason = null;
    // A bounded outer loop rather than `while (true)`: every pass either moves
    // a unit forward or stops, so the number of passes cannot exceed the number
    // of units by more than the states one unit walks through. A driver that
    // reported neither progress nor a stop would otherwise spin.
    $maxPasses = (count($rows) + 1) * 4;
    for ($pass = 0; $pass < $maxPasses; $pass++) {
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, (string) $fence['worker_id'], true, $job);
        $rows = repo_deploy_create_results($channel->connection(), $jobId);
        $unit = deploy_worker_create_next_unit($rows);
        if ($unit === null) {
            break;
        }
        if ((string) $unit['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN) {
            // Invariant 11. The unit is where the job stopped, and only a
            // reconciliation may move it; the job does not walk past it.
            $channel->log(
                VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                deploy_worker_create_progress_line($unit, 'HOLD', 'uncertain ' . (string) ($unit['error_code'] ?? ''))
            );
            $stopReason = VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN;
            break;
        }
        $verdict = deploy_worker_create_drive_unit($channel, $job, $fence, $unit, $context);
        if ($verdict['stop']) {
            $stopReason = $verdict['reason'];
            break;
        }
    }

    $summary = repo_deploy_create_summary($channel->connection(), $jobId);
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_summary_line($summary));

    return [
        'all_successful' => deploy_create_all_successful(repo_deploy_create_results($channel->connection(), $jobId)),
        'summary' => $summary,
        'stop_reason' => $stopReason,
    ];
}

/**
 * The materialized selection has to describe the job it belongs to, and the
 * check runs before anything is started rather than at the first surprise.
 *
 * A create-capable job without rows is refused instead of falling back to the
 * old whole-selection playbook. That playbook is gone, and a fallback would be
 * the one thing this stage may not have: a second create path with different
 * evidence. The rollout drains the queue first, and the message says so.
 *
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $job
 */
function deploy_worker_create_assert_materialized(array $rows, array $job): void
{
    if ($rows === []) {
        throw new DeployWorkerCreateProtocolError(
            'This create job was queued before the per-VM create contract and carries no result rows. '
            . 'Queue it again from the deploy form; it will then create its VMs one at a time with a durable result per VM.'
        );
    }
    $total = (int) $rows[0]['total'];
    $seenVmIds = [];
    $position = 0;
    foreach ($rows as $row) {
        $position++;
        if ((int) $row['position'] !== $position) {
            throw new DeployWorkerCreateProtocolError('Create positions of job ' . (int) $job['id'] . ' are not contiguous.');
        }
        if ((int) $row['total'] !== $total || $total !== count($rows)) {
            throw new DeployWorkerCreateProtocolError('Create units of job ' . (int) $job['id'] . ' disagree about their total.');
        }
        $vmId = $row['vm_id'] === null ? 0 : (int) $row['vm_id'];
        if ($vmId <= 0) {
            throw new DeployWorkerCreateProtocolError('Create unit ' . $position . ' has no VM; its VM was removed.');
        }
        if (isset($seenVmIds[$vmId])) {
            throw new DeployWorkerCreateProtocolError('Create units of job ' . (int) $job['id'] . ' name VM ' . $vmId . ' twice.');
        }
        $seenVmIds[$vmId] = true;
    }
}

/**
 * The unit to work on: the one that is already in flight, or else the lowest
 * pending position (plan section 9.2).
 *
 * An in-flight unit always wins, and there can be at most one of them, which is
 * the database invariant this reads rather than re-derives. That is what makes
 * recovery after a worker restart pick up the same VM instead of starting the
 * next one.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function deploy_worker_create_next_unit(array $rows): ?array
{
    foreach ($rows as $row) {
        if (in_array((string) $row['status'], VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
            return $row;
        }
    }
    $position = deploy_create_next_position($rows);
    if ($position === null) {
        return null;
    }
    foreach ($rows as $row) {
        if ((int) $row['position'] === $position) {
            return $row;
        }
    }

    return null;
}

/** The persisted UTC start of this job's create section. */
function deploy_worker_create_started_at(mysqli $db, int $jobId): ?string
{
    $row = repo_fetch_one($db, 'SELECT create_started_at FROM deploy_jobs WHERE id = ? LIMIT 1', 'i', [$jobId]);
    $value = $row === null ? null : $row['create_started_at'];

    return $value === null ? null : (string) $value;
}

/**
 * Seconds left of the create budget.
 *
 * Zero and negative are the same answer to the caller, but they are kept apart
 * from "unknown": a missing start time is not an expired budget, and treating
 * it as one would refuse to start a job that has not begun. It counts as the
 * full budget, because the write that would have set it is the very next thing
 * the caller does.
 */
function deploy_worker_create_remaining_seconds(?string $createStartedAt, ?int $now = null): int
{
    $budget = deploy_create_total_budget_seconds();
    if ($createStartedAt === null || trim($createStartedAt) === '') {
        return $budget;
    }
    $startedAt = strtotime($createStartedAt . ' UTC');
    if ($startedAt === false) {
        return $budget;
    }

    return $budget - (($now ?? time()) - $startedAt);
}

/**
 * The progress line format of the plan, section 9: `[n/total] VERB create name`.
 *
 * Technical and deliberately not localized. These are log lines an operator
 * correlates with the Ansible output above and below them, and the portal
 * progress card reads the rows rather than this text (plan 12.3).
 *
 * @param array<string, mixed> $unit
 */
function deploy_worker_create_progress_line(array $unit, string $verb, string $detail = ''): string
{
    $line = '[' . (int) $unit['position'] . '/' . (int) $unit['total'] . '] ' . $verb . ' create ' . (string) $unit['vm_name'];

    return $detail === '' ? $line : $line . ' ' . $detail;
}

/**
 * The one closing summary of the create section.
 *
 * Stable technical keys, never localized: an operator greps these, and a
 * translated key would make a job log unreadable in the other locale. The
 * portal's visible counters come from the same rows through the DE/EN
 * catalogs instead.
 *
 * @param array<string, mixed> $summary
 */
function deploy_worker_create_summary_line(array $summary): string
{
    $parts = [];
    foreach (['total', 'created', 'updated', 'unchanged', 'skipped', 'failed', 'uncertain', 'not_started'] as $key) {
        $parts[] = $key . '=' . (int) ($summary[$key] ?? 0);
    }

    return 'Create summary: ' . implode(' ', $parts);
}

/**
 * The job status a finished create section implies (plan section 9.8).
 *
 * A partial result is a real category here, not a rounding of failure: a job
 * that created fourteen of fifteen VMs changed the target host, and reporting
 * that as `failed` is what sends an operator to delete work that is fine.
 *
 * @param array<string, mixed> $summary
 */
function deploy_worker_create_job_status(array $summary): string
{
    $successful = (int) $summary['succeeded'] + (int) $summary['skipped'];
    if ($successful === (int) $summary['total']) {
        return VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED;
    }

    return $successful > 0 ? VIRTUSPHERE_DEPLOY_STATUS_PARTIAL : VIRTUSPHERE_DEPLOY_STATUS_FAILED;
}

/**
 * Concludes a job whose per-VM create section did not fully succeed
 * (Etappe 14B, plan section 9.8).
 *
 * The status matrix is the point: a job that created fourteen of fifteen VMs is
 * `partial`, not `failed`. It changed the target host, and calling that a
 * failure is what sends an operator to delete work that is fine - which is
 * precisely the situation the 13.08.2026 incident left behind, minus the
 * evidence that would have shown it.
 *
 * The lifecycle differs by mode, and deliberately so. Decision F3 says a
 * create-only job does not change the business VM lifecycle, so the bracket
 * painted at job start is taken back for every VM whatever the outcome: the
 * per-VM truth now lives in deploy_create_vm_results, and a second, coarser
 * copy of it in deploy_vms would be a second opinion. A full pipeline is a
 * different promise - power-cycle, export and start did not run - and keeps the
 * existing convergence for an incomplete run.
 *
 * @param int[] $vmIds
 * @param array<int,string> $priorLifecycles
 * @param array{all_successful:bool,summary:array<string,mixed>,stop_reason:?string} $createOutcome
 */
function deploy_worker_conclude_create_section(
    mysqli $db,
    array $job,
    string $workerId,
    array $vmIds,
    array $priorLifecycles,
    array $createOutcome
): void {
    $message = null;
    $terminalStatus = deploy_worker_owned_transaction($db, $job, static function (array $locked) use ($db, $job, $workerId, $vmIds, $priorLifecycles, $createOutcome, &$message): ?string {
        if ((string) $locked['locked_by'] !== $workerId) {
            return null;
        }
        if ((string) $locked['status'] === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
            return deploy_worker_confirm_owned_cancel($db, $job) ? VIRTUSPHERE_DEPLOY_STATUS_CANCELLED : null;
        }
        $jobId = (int) $job['id'];
        $mode = (string) deploy_worker_payload($job)['mode'];
        $status = deploy_worker_create_job_status($createOutcome['summary']);
        $summary = $createOutcome['summary'];
        $message = 'The per-VM create section ended without creating every VM: '
            . (int) $summary['succeeded'] . ' created, ' . (int) $summary['skipped'] . ' skipped, '
            . (int) $summary['failed'] . ' failed, ' . (int) $summary['uncertain'] . ' unresolved, '
            . (int) $summary['not_started'] . ' not started. No further playbook of this job was started.';

        // The literal is how this codebase names the mode everywhere else
        // (VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES, ansible_playbooks_for_mode);
        // there is no constant for it, and inventing one here would create a
        // second name for the same token.
        if ($mode === 'create') {
            deploy_worker_restore_deploying_vms(
                $db,
                $job,
                'deploy job ' . $jobId . ' create section ended; lifecycle restored',
                $vmIds,
                $priorLifecycles
            );
        } else {
            deploy_worker_mark_vms_failed(
                $db,
                $job,
                'deploy job ' . $jobId . ' stopped before its pipeline because the create section did not complete',
                $vmIds
            );
        }

        if ($status === VIRTUSPHERE_DEPLOY_STATUS_FAILED) {
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR, $message);
        }
        $terminalStatus = deploy_worker_finish_job(
            $db,
            $job,
            $workerId,
            $status,
            $status === VIRTUSPHERE_DEPLOY_STATUS_FAILED ? $message : null,
            $status === VIRTUSPHERE_DEPLOY_STATUS_PARTIAL
                ? VIRTUSPHERE_DEPLOY_TERMINAL_REASON_PARTIAL_RESULT
                : VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
            $message
        );
        return $terminalStatus;
    });
    if ($terminalStatus !== null) {
        deploy_worker_audit_outcome($db, $job, $terminalStatus, $message);
    }
    if ($terminalStatus === VIRTUSPHERE_DEPLOY_STATUS_PARTIAL) {
        // VMs were created, so the ESXi cache is out of date exactly as it is
        // after a fully successful job.
        deploy_worker_refresh_inventory_after_deploy($db, $job);
    }
}
