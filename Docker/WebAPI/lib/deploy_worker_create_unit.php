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
            return deploy_worker_create_verify_skip($channel, $fence, $unit, $context);
        }
        $prepared = deploy_worker_create_prepare_unit($channel, $fence, $unit, $context);
        if ($prepared['stop'] || !$prepared['continue']) {
            return ['stop' => $prepared['stop'], 'reason' => $prepared['reason']];
        }
        $unit = $prepared['unit'];
        $status = VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED;
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

/**
 * A retry unit that stands for an earlier confirmed success (plan 9.2 and
 * 10.2): the same read-only prepare call, and a skip only when the live UUID is
 * the one the source row proved.
 *
 * It never creates anything. A missing VM, an empty UUID or a different one is
 * a failure of this unit, because the alternative would be a second create for
 * a VM whose first one is on record as having succeeded.
 *
 * @param array{worker_id:string,lock_token:string,worker_epoch:int} $fence
 * @param array<string, mixed> $unit
 * @param array<string, mixed> $context
 * @return array{stop:bool,reason:?string}
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
    $detail = deploy_worker_redact_secrets(trim($detail), []);
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
