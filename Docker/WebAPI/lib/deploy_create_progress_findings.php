<?php

declare(strict_types=1);

/**
 * One stored create failure as the job log presents it: a closed label per
 * class, the technical code beside it, and for a module failure the host's
 * own message. Split from deploy_create_progress.php (ADR-0006 budget).
 */

require_once __DIR__ . '/deploy_create_constants.php';
require_once __DIR__ . '/network_mac_constants.php';

/**
 * A stored failure class presented without parsing prose or exposing it as the
 * visible explanation. The technical error code remains alongside the label so
 * operations can still correlate it with a runbook or support case.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function deploy_create_progress_finding(array $row): array
{
    $errorCode = (string) ($row['error_code'] ?? '');
    $reasonKey = match ($errorCode) {
        VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED => 'deploy.create_progress_reason_host_identity',
        VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED => 'deploy.create_progress_reason_module_failed',
        VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT => 'deploy.create_progress_reason_identity_conflict',
        VIRTUSPHERE_CREATE_ERROR_IDENTITY_BOUND_VM_RENAMED => 'deploy.create_progress_reason_renamed',
        VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
        VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID => 'deploy.create_progress_reason_invalid_evidence',
        VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED => 'deploy.create_progress_reason_operator_released',
        VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED,
        VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING,
        VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST,
        VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
        VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST => 'deploy.create_progress_reason_unresolved_observation',
        default => 'deploy.create_progress_reason_unknown',
    };

    return [
        'position' => (int) $row['position'],
        'vm_name' => (string) $row['vm_name'],
        'error_code' => $errorCode,
        'reason_key' => $reasonKey,
        'reason_label' => __t($reasonKey),
        'host_detail' => deploy_create_progress_host_detail($errorCode, (string) ($row['error_detail'] ?? '')),
    ];
}

/**
 * The host's own words, for exactly one class: a module failure is ESXi
 * refusing the call, and its message ("The operation is not supported on the
 * object") is the only thing that tells the operator what to change. It stays
 * a quoted technical detail next to the label, never the explanation itself.
 * Every other class has a closed, portal-made sentence and no host message.
 * The column is redacted at write time (FC2-08) and bounded here.
 */
function deploy_create_progress_host_detail(string $errorCode, string $detail): ?string
{
    if ($errorCode !== VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED) {
        return null;
    }
    $detail = trim($detail);

    return $detail !== '' ? virtusphere_bounded_utf8_bytes($detail, 512) : null;
}
