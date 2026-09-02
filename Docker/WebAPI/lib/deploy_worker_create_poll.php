<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/deploy_create_identity.php';
require_once __DIR__ . '/deploy_worker_create_remote.php';
require_once __DIR__ . '/deploy_worker_db_recovery.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_stream.php';
require_once __DIR__ . '/ssh.php';

/**
 * Observing one running create unit until its outcome is established
 * (Etappe 14B, Teiletappe E; plan sections 9.5 to 9.7).
 *
 * The async job runs on the Ansible host and keeps changing ESXi whether or
 * not this worker can reach it. Everything here follows from that one fact: a
 * broken transport retries the SAME job id instead of starting a second one, a
 * cancel is honoured after the current VM rather than in the middle of it, and
 * a database outage delays the next VM instead of ending the current one.
 */

/**
 * The poll loop of one running unit.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,reason:?string}
 */
function deploy_worker_create_poll_unit(
    DeployWorkerDbChannel $channel,
    array $job,
    array $fence,
    array $unit,
    array $context
): array {
    $jobId = (int) $unit['job_id'];
    $workerId = (string) $fence['worker_id'];
    $jid = (string) ($unit['async_jid'] ?? '');
    if (!deploy_create_jid_is_valid($jid)) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            'This unit is running without a usable async job id.'
        );

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }
    $remoteDir = (string) $context['remote_dir'];
    $position = (int) $unit['position'];
    $backoff = VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS;
    $cancelRequested = false;

    while (true) {
        $ownership = deploy_worker_create_ownership($channel->connection(), $jobId, $workerId);
        if ($ownership === 'lost') {
            // Somebody else owns this job's conclusion now. Stop without
            // publishing anything: the stored job id is what lets the new owner
            // establish the outcome, and our guess would only overwrite it.
            return ['stop' => true, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST];
        }
        if ($ownership === 'cancelling' && !$cancelRequested) {
            $cancelRequested = true;
            // Said once, and said exactly: the VM being created is not stopped.
            // The portal promises "after the current VM", and this is the line
            // that makes the job log agree with it.
            $channel->log(
                VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                deploy_worker_create_progress_line($unit, 'POLL', 'cancel requested; this VM is observed to its end, no further VM starts')
            );
        }

        $remaining = deploy_worker_create_remaining_seconds($context['create_started_at'] ?? null);
        if ($remaining <= 0) {
            // The budget is a limit on VirtuSphere's waiting, never a statement
            // about ESXi: the vSphere task may well still be running, and the
            // text must not claim it was ended.
            $verdict = deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
                VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
                'The create budget of this job was reached while this VM was still being created. '
                . 'The task on ESXi may still be running; nothing here ended it.'
            );

            return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
        }

        $result = deploy_worker_create_control_call(
            $channel,
            $context,
            VIRTUSPHERE_CREATE_PLAYBOOK_STATUS,
            $unit,
            [
                'vs_portal_vm_id' => (int) $unit['vm_id'],
                'vs_result_file' => ansible_create_result_file($remoteDir, $position, 'status'),
                'vs_async_dir' => ansible_create_async_dir($remoteDir, $position),
                'vs_async_jid' => $jid,
            ],
            $jid
        );
        // The remote step is over; from here a result has to become durable
        // before anything else happens. `--once` stays bounded and reports a
        // non-persistable outcome instead of hanging.
        deploy_worker_settle_db_channel($channel, (array) ($context['options'] ?? []), null);
        if ($channel->hasLostOwnership()) {
            return ['stop' => true, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST];
        }

        if ($result['transport_error'] !== null) {
            $channel->log(
                VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                deploy_worker_create_progress_line($unit, 'POLL', 'transport_lost; retrying the same async job id: ' . $result['transport_error'])
            );
            deploy_worker_create_sleep($channel, $context, $backoff);
            $backoff = min($backoff * 2, VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MAX_SECONDS);
            continue;
        }
        $backoff = VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS;

        $marker = $result['marker'];
        if ($marker === null) {
            $verdict = deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
                VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
                'The status of this VM could not be read: ' . (string) $result['protocol_error']
            );

            return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
        }

        if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_RUNNING) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'POLL', 'running'));
            repo_deploy_create_touch_running($channel->connection(), $jobId, $position, $fence);
            deploy_worker_create_sleep($channel, $context, VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS);
            continue;
        }

        $verdict = deploy_worker_create_finish_unit($channel, $fence, $unit, $context, $marker);
        if ($cancelRequested && !$verdict['stop']) {
            // The unit ended, its outcome is stored, and the operator's stop is
            // now the next thing to honour. No further unit starts.
            return ['stop' => true, 'reason' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLED];
        }

        return $verdict;
    }
}

/**
 * Turns a terminal status marker into the unit's durable outcome.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @param array<string, mixed> $marker
 * @return array{stop:bool,reason:?string}
 */
function deploy_worker_create_finish_unit(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    array $context,
    array $marker
): array {
    if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_SUCCEEDED) {
        $commit = repo_deploy_create_commit_success(
            $channel->connection(),
            (int) $unit['job_id'],
            (int) $unit['position'],
            (int) ($unit['existed_before'] ?? 0) === 1,
            (bool) $marker['changed'],
            (string) $marker['moid'],
            (string) $marker['instance_uuid'],
            $fence
        );
        if ($commit['committed'] || $commit['replayed']) {
            // Only after the commit: a DONE line before it would report a
            // success that a failing transaction then never made durable.
            if ($commit['committed']) {
                $channel->log(
                    VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                    deploy_worker_create_progress_line($unit, 'DONE', (string) $commit['outcome'])
                );
            }
            deploy_worker_create_cleanup_async($channel, $context, $unit);

            return ['stop' => false, 'reason' => null];
        }
        if ($commit['error_code'] === VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST || $commit['error_code'] === null) {
            return ['stop' => true, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST];
        }
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            (string) $commit['error_code'],
            'The create module reported success, but the live identity does not match what this VM is bound to.'
        );
        deploy_worker_create_cleanup_async($channel, $context, $unit);

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }

    if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_FAILED) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
            (string) $marker['error']
        );
        deploy_worker_create_cleanup_async($channel, $context, $unit);

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }

    if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_REJECTED) {
        // The async state file is gone, or the identity after a successful
        // module call is unusable. Neither says what happened to the VM, and
        // the async directory stays as the only remaining evidence.
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            (string) $marker['error_code'],
            (string) $marker['error']
        );

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }

    $verdict = deploy_worker_create_terminate_unit(
        $channel,
        $fence,
        $unit,
        VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
        VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
        'The status of this VM answered with the event ' . (string) $marker['event'] . '.'
    );

    return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
}
