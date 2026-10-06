<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_create.php';
require_once __DIR__ . '/ansible_create_protocol.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/deploy_create_identity.php';
require_once __DIR__ . '/deploy_worker_create_launch.php';
require_once __DIR__ . '/deploy_worker_create_poll.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_stream.php';
require_once __DIR__ . '/ssh.php';

/**
 * One create unit, from its current durable state to its next durable state
 * (Etappe 14B, Teiletappe E; plan sections 9.2 to 9.7).
 *
 * The unit's state is read from the row, never from a variable this process
 * kept: `pending` prepares, `prepared` launches, `running` polls, and a unit
 * that is already terminal is not touched again inside its job. That is what
 * makes a worker restart resume the same VM instead of starting a second one.
 *
 * Failure vocabulary is closed and comes from the branch that established the
 * failure, never from Ansible prose. A confirmed per-VM failure continues with
 * the next VM (decision F5); the global stop classes of section 9.7 do not.
 */

/** The error codes that stop the whole job rather than only their unit. */
const VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES = [
    VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED,
    VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
    VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST,
    VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
];

/**
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,reason:?string}
 */
function deploy_worker_create_drive_unit(
    DeployWorkerDbChannel $channel,
    array $job,
    array $fence,
    array $unit,
    array $context
): array {
    $status = (string) $unit['status'];
    if ($status === VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING) {
        if ((string) $unit['action'] === VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP) {
            $verified = deploy_worker_create_verify_skip($channel, $fence, $unit, $context);
            if (!isset($verified['unit'])) {
                return $verified;
            }
            $unit = $verified['unit'];
            $status = VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED;
        } else {
            $prepared = deploy_worker_create_prepare_unit($channel, $fence, $unit, $context);
            if ($prepared['stop'] || !$prepared['continue']) {
                return ['stop' => $prepared['stop'], 'reason' => $prepared['reason']];
            }
            $unit = $prepared['unit'];
            if (!deploy_worker_create_prepared_needs_launch($unit)) {
                $verified = deploy_worker_create_conclude_unchanged(
                    $channel,
                    $fence,
                    $unit,
                    isset($unit['precheck_power_state']) ? (string) $unit['precheck_power_state'] : null
                );

                return ['stop' => $verified['stop'], 'reason' => $verified['reason']];
            }
            $status = VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED;
        }
    }
    if ($status === VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED) {
        $launched = deploy_worker_create_launch_unit($channel, $fence, $unit, $context);
        if ($launched['stop'] || !$launched['continue']) {
            return ['stop' => $launched['stop'], 'reason' => $launched['reason']];
        }
        $unit = $launched['unit'];
    }

    return deploy_worker_create_poll_unit($channel, $job, $fence, $unit, $context);
}

/** @param array<string,mixed> $unit @param array<string,mixed> $source @param array<string,mixed> $marker */
function deploy_worker_create_skip_proves_absence(array $unit, array $source, array $marker): bool
{
    $expected = trim((string) ($source['vm_instance_uuid'] ?? ''));

    return $marker['event'] === VIRTUSPHERE_CREATE_EVENT_PREPARED
        && $marker['existed_before'] === false
        && (int) ($unit['vm_id'] ?? 0) > 0
        && (int) ($source['vm_id'] ?? 0) === (int) $unit['vm_id']
        && $expected !== ''
        && strcasecmp($expected, (string) ($marker['replaced_instance_uuid'] ?? '')) === 0;
}

/**
 * A retry unit that stands for an earlier confirmed success (plan 9.2 and
 * 10.2): the same read-only prepare call, and a skip only when the live UUID is
 * the one the source row proved.
 *
 * A proven missing prior instance becomes a create under the same worker fence.
 * Unknown inventory, a renamed bound VM or a foreign namesake never does.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,reason:?string,unit?:array<string,mixed>}
 */
function deploy_worker_create_verify_skip(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    array $context
): array {
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'RUN', 'verify'));
    $sourceId = $unit['resumed_from_result_id'] === null ? 0 : (int) $unit['resumed_from_result_id'];
    $source = $sourceId > 0 ? repo_deploy_create_skip_source($channel->connection(), $sourceId) : null;
    if ($source === null) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT,
            'This unit claims an earlier success, but no successful source result can be resolved for it.'
        );

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }

    $result = deploy_worker_create_control_call(
        $channel,
        $context,
        VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE,
        $unit,
        [
            'vs_portal_vm_id' => (int) $unit['vm_id'],
            'vs_result_file' => ansible_create_result_file((string) $context['remote_dir'], (int) $unit['position'], 'verify'),
        ]
    );
    $marker = $result['marker'];
    $expected = trim((string) $source['vm_instance_uuid']);
    if (($result['host_identity_error'] ?? null) !== null) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED,
            $result['host_identity_error']
        );
        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }
    if ($result['transport_error'] !== null || $marker === null) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            $result['transport_error'] !== null ? VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST : VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            'The verification of this already created VM could not be completed: '
            . (string) ($result['transport_error'] ?? $result['protocol_error'])
        );

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }
    if (deploy_worker_create_skip_proves_absence($unit, $source, $marker)) {
        $fields = [
            'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
            'existed_before' => 0,
            'precheck_moid' => $marker['precheck_moid'],
            'precheck_instance_uuid' => $marker['precheck_instance_uuid'],
            'replaced_instance_uuid' => $expected,
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
            return ['stop' => true, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST];
        }
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'POLL', 'bound instance absent; creating replacement'));

        return ['stop' => false, 'reason' => null, 'unit' => array_merge($unit, $fields, ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED])];
    }
    $live = $marker['event'] === VIRTUSPHERE_CREATE_EVENT_PREPARED ? (string) ($marker['precheck_instance_uuid'] ?? '') : '';
    if ($expected === '' || $live === '' || strcasecmp($expected, $live) !== 0) {
        $verdict = deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT,
            'The VM this unit was to skip does not currently carry the instance uuid its earlier success recorded.'
        );

        return ['stop' => $verdict['stop'], 'reason' => $verdict['reason']];
    }

    if (!repo_deploy_create_transition(
        $channel->connection(),
        (int) $unit['job_id'],
        (int) $unit['position'],
        VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
        [
            // Nothing was created and nothing changed, which is exactly what
            // `unchanged` means; the evidence pair says the same thing.
            'outcome' => VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED,
            'changed' => 0,
            'existed_before' => 1,
            'vm_moid' => (string) $marker['precheck_moid'],
            'vm_instance_uuid' => $live,
            'resumed_from_result_id' => (int) $source['id'],
        ],
        $fence
    )) {
        return ['stop' => true, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST];
    }
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'DONE', 'skipped'));

    return ['stop' => false, 'reason' => null];
}

/**
 * Writes the terminal (or uncertain) state of one unit and says whether the job
 * continues.
 *
 * A confirmed per-VM failure continues with the next VM; `uncertain` and the
 * global stop classes do not. The decision is derived from the code rather than
 * from the call site, so a new code has to be classified once instead of at
 * every place that can produce it.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @return array{stop:bool,continue:bool,reason:?string,unit:array<string,mixed>}
 */
function deploy_worker_create_terminate_unit(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    string $status,
    string $errorCode,
    string $detail
): array {
    $detail = $channel->redact(trim($detail));
    if ($detail === '') {
        $detail = 'No further detail was established.';
    }
    $written = repo_deploy_create_transition(
        $channel->connection(),
        (int) $unit['job_id'],
        (int) $unit['position'],
        (string) $unit['status'],
        $status,
        ['error_code' => $errorCode, 'error_detail' => $detail],
        $fence
    );
    if (!$written) {
        return ['stop' => true, 'continue' => false, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, 'unit' => $unit];
    }
    $channel->log(
        VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
        deploy_worker_create_progress_line($unit, $status === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN ? 'HOLD' : 'FAIL', $errorCode)
    );
    $stop = $status === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN
        || in_array($errorCode, VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES, true);

    return [
        'stop' => $stop,
        'continue' => false,
        'reason' => $stop ? $errorCode : null,
        'unit' => array_merge($unit, ['status' => $status, 'error_code' => $errorCode]),
    ];
}

/**
 * DF-E1: a fresh create unit launches unless it met an existing own VM that is
 * not provably powered off. A new VM always launches; an existing one only in
 * the state `poweredOff`. Suspended, powered on and unknown count as on: the
 * launch would align the hardware of a running machine.
 *
 * @param array<string, mixed> $prepared the prepared unit or marker
 */
function deploy_worker_create_prepared_needs_launch(array $prepared): bool
{
    if (!(bool) ($prepared['existed_before'] ?? false)) {
        return true;
    }

    return ($prepared['precheck_power_state'] ?? null) === 'poweredOff';
}

/**
 * DF-E1: concludes a prepared unit for an existing own VM that is not powered
 * off. Nothing is launched: the identity the preparation proved is committed
 * as `succeeded` / `unchanged`, through the one success commit, and the job log
 * names the VM whose portal hardware was not aligned.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @return array{stop:bool,continue:bool,reason:?string,unit:array<string,mixed>}
 */
function deploy_worker_create_conclude_unchanged(
    DeployWorkerDbChannel $channel,
    array $fence,
    array $unit,
    ?string $powerState
): array {
    $commit = repo_deploy_create_commit_success(
        $channel->connection(),
        (int) $unit['job_id'],
        (int) $unit['position'],
        true,
        false,
        (string) ($unit['precheck_moid'] ?? ''),
        (string) ($unit['precheck_instance_uuid'] ?? ''),
        $fence,
        VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED
    );
    if (!$commit['committed'] && !$commit['replayed']) {
        if ($commit['error_code'] === null || $commit['error_code'] === VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST) {
            return ['stop' => true, 'continue' => false, 'reason' => VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, 'unit' => $unit];
        }

        return deploy_worker_create_terminate_unit(
            $channel,
            $fence,
            $unit,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            (string) $commit['error_code'],
            'The existing VM was verified without a launch, but its identity does not match what this VM is bound to.'
        );
    }
    if ($commit['committed']) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Hardware of VM ' . (string) $unit['vm_name'] . ' not aligned: the existing VM is '
            . ($powerState ?? 'not provably powered off')
            . '. Identity verified, unit concluded unchanged. Power it off and run create again to align it.'
        );
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, deploy_worker_create_progress_line($unit, 'DONE', VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED));
    }

    return [
        'stop' => false,
        'continue' => false,
        'reason' => null,
        'unit' => array_merge($unit, ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED]),
    ];
}
