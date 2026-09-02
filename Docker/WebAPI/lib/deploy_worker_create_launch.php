<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/deploy_worker_create_remote.php';
require_once __DIR__ . '/deploy_worker_runtime.php';

/**
 * Bringing one create unit into flight (Etappe 14B, Teiletappe E; plan 9.3 and
 * 9.4): the read-only live identity check, and the async launch whose job id is
 * stored before anything is asked about it.
 *
 * Separated from the unit driver because these two steps change for their own
 * reason: what has to be true about a VM BEFORE it is mutated. Everything after
 * the launch - observing, concluding, cleaning up - is the poll module's, and
 * the difference matters most where the two meet: a launch call that dies
 * without an answer may already have started a mutation, so it hands over to a
 * search for the job id rather than to a second launch.
 */

/**
 * The read-only live identity check that turns `pending` into `prepared`
 * (plan section 9.3).
 *
 * It runs immediately before ITS OWN VM rather than once for all of them: a
 * namesake that appears between VM 1 and VM 15 is then found before the
 * mutation it would collide with, which a single check at the start of the job
 * cannot do.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,continue:bool,reason:?string,unit:array<string,mixed>}
 */
function deploy_worker_create_prepare_unit(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    array $context
): array {
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'RUN'));
    $remaining = deploy_worker_create_remaining_seconds($context['create_started_at'] ?? null);
    if ($remaining <= 0) {
        // Nothing was started for this unit, so there is nothing uncertain
        // about it; the budget simply ran out before its turn. That is a
        // global stop: the units after it would meet the same wall.
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
            'The create budget of this job was exhausted before this VM was started.'
        );
    }

    $result = deploy_worker_create_control_call(
        $channel,
        $context,
        VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE,
        $unit,
        [
            'vs_portal_vm_id' => (int) $unit['vm_id'],
            'vs_result_file' => ansible_create_result_file((string) $context['remote_dir'], (int) $unit['position'], 'prepare'),
        ]
    );
    if ($result['transport_error'] !== null) {
        // Nothing was mutated: a prepare call is read-only, so a broken
        // transport before it returned leaves the unit exactly where it was.
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST,
            'The read-only preparation of this VM could not be completed: ' . $result['transport_error']
        );
    }
    $marker = $result['marker'];
    if ($marker === null) {
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            (string) $result['protocol_error']
        );
    }
    if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_REJECTED) {
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            (string) $marker['error_code'],
            (string) $marker['error']
        );
    }
    if ($marker['event'] !== VIRTUSPHERE_CREATE_EVENT_PREPARED) {
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            'The preparation of this VM answered with the event ' . (string) $marker['event'] . '.'
        );
    }

    $fields = [
        'existed_before' => $marker['existed_before'] ? 1 : 0,
        'precheck_moid' => $marker['precheck_moid'],
        'precheck_instance_uuid' => $marker['precheck_instance_uuid'],
    ];
    if (!repo_deploy_create_transition(
        $channel->connection(),
        (int) $unit['job_id'],
        (int) $unit['position'],
        VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED,
        $fields,
        $fence
    )) {
        return ['stop' => true, 'continue' => false, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, 'unit' => $unit];
    }

    return [
        'stop' => false,
        'continue' => true,
        'reason' => null,
        'unit' => array_merge($unit, $fields, ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED]),
    ];
}

/**
 * Starts exactly one async create and stores its job id before the first poll
 * (plan section 9.4).
 *
 * The order matters more than anything else in this module: the launch call
 * returns a job id, and that id is written to the row before this worker asks
 * anything about it. A worker that died between the two would leave a `running`
 * row whose id nobody knows, which is the incident one level down.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,continue:bool,reason:?string,unit:array<string,mixed>}
 */
function deploy_worker_create_launch_unit(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    array $context
): array {
    $remaining = deploy_worker_create_remaining_seconds($context['create_started_at'] ?? null);
    if ($remaining <= 0) {
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
            'The create budget of this job was exhausted before this VM was started.'
        );
    }
    $remoteDir = (string) $context['remote_dir'];
    $position = (int) $unit['position'];
    $existedBefore = (int) ($unit['existed_before'] ?? 0) === 1;

    $result = deploy_worker_create_control_call(
        $channel,
        $context,
        VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH,
        $unit,
        [
            'vs_portal_vm_id' => (int) $unit['vm_id'],
            'vs_result_file' => ansible_create_result_file($remoteDir, $position, 'launch'),
            'vs_async_dir' => ansible_create_async_dir($remoteDir, $position),
            'vs_async_timeout' => $remaining,
            'vs_expected_existed_before' => $existedBefore,
            'vs_expected_moid' => (string) ($unit['precheck_moid'] ?? ''),
            'vs_expected_instance_uuid' => (string) ($unit['precheck_instance_uuid'] ?? ''),
        ]
    );

    $jid = null;
    if ($result['transport_error'] !== null) {
        // The channel broke around a call that MAY have started a mutation.
        // Never a second launch on suspicion: the async directory of this one
        // unit is asked whether a job id appeared there.
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            deploy_worker_create_progress_line($unit, 'POLL', 'launch channel lost; looking for a started async job')
        );
        $discovery = deploy_worker_create_discover_jid($channel, $context, $unit);
        if ($discovery['error_code'] !== null) {
            return deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
                $discovery['error_code'],
                $discovery['detail'] . ' Transport: ' . $result['transport_error']
            );
        }
        $jid = $discovery['jid'];
    } else {
        $marker = $result['marker'];
        if ($marker === null) {
            return deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
                VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
                'The launch of this VM produced no usable marker: ' . (string) $result['protocol_error']
            );
        }
        if ($marker['event'] === VIRTUSPHERE_CREATE_EVENT_REJECTED) {
            // Refused before the module ran, so no async job exists and the
            // unit is a confirmed failure rather than an unknown.
            return deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
                (string) $marker['error_code'],
                (string) $marker['error']
            );
        }
        if ($marker['event'] !== VIRTUSPHERE_CREATE_EVENT_LAUNCHED) {
            return deploy_worker_create_terminate_unit(
                $channel,
                $fence,
                $unit,
                VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
                VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
                'The launch of this VM answered with the event ' . (string) $marker['event'] . '.'
            );
        }
        $jid = (string) $marker['async_jid'];
    }

    if ($jid === null || !deploy_create_jid_is_valid($jid)) {
        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            'The launch of this VM reported an async job id outside its allowed character class.'
        );
    }

    $fields = [
        'async_jid' => $jid,
        // The deadline is immutable from here on: it belongs to this async job,
        // and a later recovery of the same unit must not extend it.
        'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + $remaining),
        // Deliberately absent: remote_execution_id. The generic remote handle
        // is part of the remote execution contract, which stays locked until
        // its site acceptance; a create unit under the legacy transport derives
        // its async directory from the job's deterministic remote directory.
    ];
    if (!repo_deploy_create_transition(
        $channel->connection(),
        (int) $unit['job_id'],
        (int) $unit['position'],
        VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED,
        VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
        $fields,
        $fence
    )) {
        return ['stop' => true, 'continue' => false, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, 'unit' => $unit];
    }
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'POLL', 'started'));

    return [
        'stop' => false,
        'continue' => true,
        'reason' => null,
        'unit' => array_merge($unit, $fields, ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING]),
    ];
}
