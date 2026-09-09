<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/lang.php';

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

    return $retryConfirm;
}
