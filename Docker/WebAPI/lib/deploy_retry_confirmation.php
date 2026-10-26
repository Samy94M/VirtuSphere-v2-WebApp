<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/deploy_host_move.php';

/** The confirmation describes the same evaluated plan that insertion consumes. */
function deploy_retry_confirmation(array $evaluation, string $name): string
{
    $plan = $evaluation['plan'] ?? null;
    if (is_array($plan) && ($plan['scope'] ?? '') === 'create_units') {
        $retryConfirm = __t('deploy.confirm_retry_create', ['name' => $name]);
    } elseif (!empty($evaluation['external_confirmation'])) {
        $retryConfirm = __t('deploy.confirm_retry_external', ['name' => $name]);
    } elseif (is_array($plan) && ($evaluation['effective_mode'] ?? '') === 'export') {
        $failedCount = ($plan['scope'] ?? '') === 'failed_vms' ? count($evaluation['scope_vm_ids']) : 0;
        if ($failedCount === 1) {
            $retryConfirm = __t('deploy.confirm_retry_partial_one', ['name' => $name]);
        } elseif ($failedCount > 1) {
            $retryConfirm = __t('deploy.confirm_retry_partial_many', ['name' => $name, 'count' => $failedCount]);
        } else {
            $retryConfirm = __t('deploy.confirm_retry_partial', ['name' => $name]);
        }
    } else {
        $retryConfirm = __t('deploy.confirm_retry', ['name' => $name]);
    }

    // K17: a create retry that would build bound VMs anew on another host says
    // so in the same dialog; the job log also shows it beside the button.
    $hostMoves = $evaluation['host_moves'] ?? [];
    if (is_array($hostMoves) && $hostMoves !== []) {
        $retryConfirm .= ' ' . deploy_host_move_message($hostMoves);
    }

    // WK-04/WK-07 A: the source job's remote outcome is unknown. The warning
    // comes first, the question about the evaluated plan follows unchanged; the
    // dialog informs, it never authorizes (deploy_retry_blockers() decides).
    if (deploy_retry_has_outcome_unknown_finding($evaluation)) {
        $retryConfirm = __t('deploy.confirm_retry_outcome_unknown') . ' ' . $retryConfirm;
    }

    return $retryConfirm;
}

/** Whether the evaluation carries the outcome_unknown history finding. */
function deploy_retry_has_outcome_unknown_finding(array $evaluation): bool
{
    foreach ((array) ($evaluation['findings'] ?? []) as $finding) {
        if (is_array($finding) && (string) ($finding['code'] ?? '') === 'retry_remote_outcome_unknown') {
            return true;
        }
    }

    return false;
}
