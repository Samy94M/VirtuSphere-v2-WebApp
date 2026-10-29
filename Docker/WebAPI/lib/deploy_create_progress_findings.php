<?php

declare(strict_types=1);

/**
 * One stored create failure as the job log presents it: a closed label per
 * class, the technical code beside it, and for a module failure the host's
 * own message. Split from deploy_create_progress.php (ADR-0006 budget).
 */

require_once __DIR__ . '/deploy_create_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/deploy_log_severity.php';
require_once __DIR__ . '/network_mac_constants.php';

/**
 * Every stored create error code => the catalog key of its visible sentence.
 *
 * Closed on purpose and complete: CreateErrorReasonContractTest derives the
 * codes from VIRTUSPHERE_CREATE_ERROR_CODES and fails on a code without a key
 * in both locales (B9, 29.10.2026). Before that, a new code fell silently on
 * the "no closer classification" sentence; vm_not_powered_off did.
 */
const VIRTUSPHERE_CREATE_ERROR_REASON_KEYS = [
    VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED => 'deploy.create_progress_reason_host_identity',
    VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED => 'deploy.create_progress_reason_module_failed',
    VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT => 'deploy.create_progress_reason_identity_conflict',
    VIRTUSPHERE_CREATE_ERROR_IDENTITY_BOUND_VM_RENAMED => 'deploy.create_progress_reason_renamed',
    VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR => 'deploy.create_progress_reason_invalid_evidence',
    VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID => 'deploy.create_progress_reason_invalid_evidence',
    VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED => 'deploy.create_progress_reason_operator_released',
    VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED => 'deploy.create_progress_reason_unresolved_observation',
    VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING => 'deploy.create_progress_reason_unresolved_observation',
    VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST => 'deploy.create_progress_reason_unresolved_observation',
    VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT => 'deploy.create_progress_reason_unresolved_observation',
    VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST => 'deploy.create_progress_reason_unresolved_observation',
    VIRTUSPHERE_CREATE_ERROR_VM_NOT_POWERED_OFF => 'deploy_log.reason_vm_not_powered_off',
];

/** How the host message of a finding is labelled (B8). */
const VIRTUSPHERE_CREATE_HOST_DETAIL_FROM_HOST = 'host';
const VIRTUSPHERE_CREATE_HOST_DETAIL_FALLBACK = 'fallback';
const VIRTUSPHERE_CREATE_HOST_DETAIL_UNRECORDED = 'unrecorded';
// A protocol_error whose control call printed an Ansible failure line (K2).
const VIRTUSPHERE_CREATE_HOST_DETAIL_ANSIBLE_CAUSE = 'ansible_cause';

/**
 * A stored failure class presented without parsing prose or exposing it as the
 * visible explanation. The technical error code remains alongside the label so
 * operations can still correlate it with a runbook or support case; the stored
 * English text and the first attempt go to the technical details (E2).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function deploy_create_progress_finding(array $row): array
{
    $errorCode = (string) ($row['error_code'] ?? '');
    $reasonKey = VIRTUSPHERE_CREATE_ERROR_REASON_KEYS[$errorCode] ?? 'deploy.create_progress_reason_unknown';
    $origin = deploy_create_progress_host_origin($errorCode, $row['error_detail_from_host'] ?? null, (string) ($row['error_detail'] ?? ''));
    $firstCode = trim((string) ($row['first_error_code'] ?? ''));

    return [
        'position' => (int) $row['position'],
        'total' => (int) ($row['total'] ?? 0),
        'vm_name' => (string) $row['vm_name'],
        'vm_id' => (int) ($row['vm_id'] ?? 0),
        'error_code' => $errorCode,
        'reason_key' => $reasonKey,
        'reason_label' => __t($reasonKey),
        'severity' => (string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN
            ? VIRTUSPHERE_DEPLOY_LOG_SEVERITY_HOLD
            : VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL,
        'attempt' => deploy_create_unit_attempt($row),
        'host_origin' => $origin,
        'host_detail' => $origin === VIRTUSPHERE_CREATE_HOST_DETAIL_FALLBACK
            ? null
            : deploy_create_progress_host_detail($errorCode, (string) ($row['error_detail'] ?? '')),
        'detail' => trim((string) ($row['error_detail'] ?? '')),
        'first_error_code' => $firstCode,
        'first_error_detail' => $firstCode === '' ? '' : trim((string) ($row['first_error_detail'] ?? '')),
    ];
}

/**
 * Where the stored text of a module failure came from, as the worker recorded
 * it (B8): the host's own message, the inspector's fallback, or, for a row
 * from before migration 0067, not recorded. Never decided by comparing host
 * text; a protocol_error is recognised by the worker's own cause prefix. Null
 * for every other code, which has no host message at all.
 */
function deploy_create_progress_host_origin(string $errorCode, mixed $fromHost, string $detail = ''): ?string
{
    if ($errorCode === VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR) {
        return deploy_create_progress_control_cause($detail) !== null ? VIRTUSPHERE_CREATE_HOST_DETAIL_ANSIBLE_CAUSE : null;
    }
    if ($errorCode !== VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED) {
        return null;
    }

    return match (true) {
        $fromHost === null => VIRTUSPHERE_CREATE_HOST_DETAIL_UNRECORDED,
        (int) $fromHost === 1 => VIRTUSPHERE_CREATE_HOST_DETAIL_FROM_HOST,
        default => VIRTUSPHERE_CREATE_HOST_DETAIL_FALLBACK,
    };
}

/**
 * The label in front of the stored text of a module failure: "message of the
 * host" only when the worker recorded it as such, a neutral label for an
 * unrecorded origin, and for the fallback a sentence that stands alone.
 *
 * @param array<string,mixed> $finding
 */
function deploy_create_progress_host_label(array $finding): ?string
{
    return match ($finding['host_origin'] ?? null) {
        VIRTUSPHERE_CREATE_HOST_DETAIL_FROM_HOST => __t('deploy.create_progress_host_detail'),
        VIRTUSPHERE_CREATE_HOST_DETAIL_UNRECORDED => __t('deploy_log.stored_detail'),
        VIRTUSPHERE_CREATE_HOST_DETAIL_FALLBACK => __t('deploy_log.host_no_message'),
        VIRTUSPHERE_CREATE_HOST_DETAIL_ANSIBLE_CAUSE => __t('deploy_log.ansible_cause'),
        default => null,
    };
}

/**
 * The host's own words, for two classes: a module failure is ESXi refusing
 * the call, and its message ("The operation is not supported on the object",
 * "503 Service Unavailable") is the only thing that tells the operator what to
 * change; a protocol_error keeps the Ansible failure line its control call
 * printed before it broke off (K2). It stays a quoted technical detail next to
 * the label, never the explanation itself. Every other class has a closed,
 * portal-made sentence and no host message. The column is redacted at write time (FC2-08) and
 * bounded here.
 */
function deploy_create_progress_host_detail(string $errorCode, string $detail): ?string
{
    if ($errorCode === VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR) {
        $cause = deploy_create_progress_control_cause($detail);

        return $cause === null ? null : virtusphere_bounded_utf8_bytes($cause, 512);
    }
    if ($errorCode !== VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED) {
        return null;
    }
    $detail = trim($detail);

    return $detail !== '' ? virtusphere_bounded_utf8_bytes($detail, 512) : null;
}

/**
 * The Ansible failure line a protocol_error carries after the worker's fixed
 * prefix (deploy_worker_create_control_failure_cause), or null without one.
 * The prefix is the worker's own format, not host prose.
 */
function deploy_create_progress_control_cause(string $detail): ?string
{
    $at = strpos($detail, VIRTUSPHERE_CREATE_CONTROL_CAUSE_PREFIX);
    if ($at === false) {
        return null;
    }
    $cause = trim(substr($detail, $at + strlen(VIRTUSPHERE_CREATE_CONTROL_CAUSE_PREFIX)));

    return $cause === '' ? null : $cause;
}

/**
 * The host message line of one finding as HTML, shared by the progress card
 * and the result block so the two cannot word it differently.
 *
 * @param array<string,mixed> $finding
 */
function deploy_create_progress_host_html(array $finding): string
{
    $label = deploy_create_progress_host_label($finding);
    if ($label === null) {
        return '';
    }
    $detail = $finding['host_detail'] ?? null;
    if (!is_string($detail)) {
        return '<br><span class="muted">' . h($label) . '</span>';
    }

    return '<br><span class="muted">' . h($label) . '</span> <code class="log-line">' . h($detail) . '</code>';
}

/**
 * The sentence of one finding: VM, position, the closed reason, and from the
 * second attempt on how many attempts it had (in-job retry, migration 0066).
 *
 * @param array<string,mixed> $finding
 */
function deploy_create_progress_finding_text(array $finding): string
{
    $params = [
        'name' => (string) $finding['vm_name'],
        'position' => (string) (int) $finding['position'],
        'reason' => (string) $finding['reason_label'],
    ];
    if ((int) ($finding['attempt'] ?? 1) <= 1) {
        return __t('deploy.create_progress_finding', $params);
    }

    return __t('deploy_log.finding_attempts', $params + [
        'attempt' => (int) $finding['attempt'],
        'max' => VIRTUSPHERE_CREATE_MAX_ATTEMPTS,
    ]);
}
