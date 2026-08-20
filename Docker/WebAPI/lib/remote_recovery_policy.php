<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/remote_execution_constants.php';

const VIRTUSPHERE_REMOTE_RECOVERY_NONE = 'none';
const VIRTUSPHERE_REMOTE_RECOVERY_TERMINAL_NOT_STARTED = 'terminal_not_started';
const VIRTUSPHERE_REMOTE_RECOVERY_REATTACH = 'reattach';
const VIRTUSPHERE_REMOTE_RECOVERY_IMPORT_RESULT = 'import_result';
const VIRTUSPHERE_REMOTE_RECOVERY_RECONCILE = 'reconcile';
const VIRTUSPHERE_REMOTE_RECOVERY_MANUAL = 'manual_required';
const VIRTUSPHERE_REMOTE_RECOVERY_LEGACY = 'legacy_uncertain';
const VIRTUSPHERE_REMOTE_RECOVERY_FOREIGN = 'foreign_generation';
const VIRTUSPHERE_REMOTE_RECOVERY_ACTIONS = [
    VIRTUSPHERE_REMOTE_RECOVERY_NONE,
    VIRTUSPHERE_REMOTE_RECOVERY_TERMINAL_NOT_STARTED,
    VIRTUSPHERE_REMOTE_RECOVERY_REATTACH,
    VIRTUSPHERE_REMOTE_RECOVERY_IMPORT_RESULT,
    VIRTUSPHERE_REMOTE_RECOVERY_RECONCILE,
    VIRTUSPHERE_REMOTE_RECOVERY_MANUAL,
    VIRTUSPHERE_REMOTE_RECOVERY_LEGACY,
    VIRTUSPHERE_REMOTE_RECOVERY_FOREIGN,
];

/** @return array{action:string,reason:?string,may_terminalize:bool,may_cleanup:bool} */
function remote_recovery_decision(array $job, ?array $execution, string $runtimeGeneration): array
{
    if (!in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_NONE);
    }
    $contract = $job['execution_contract'] ?? null;
    $jobGeneration = (string) ($job['execution_generation_id'] ?? '');
    if ($contract !== VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_LEGACY, VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN);
    }
    if ($runtimeGeneration === '' || !hash_equals($runtimeGeneration, $jobGeneration)) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_FOREIGN, VIRTUSPHERE_DEPLOY_RECOVERY_FOREIGN_GENERATION);
    }
    if ($execution === null) {
        return [
            'action' => VIRTUSPHERE_REMOTE_RECOVERY_TERMINAL_NOT_STARTED,
            'reason' => null,
            'may_terminalize' => true,
            'may_cleanup' => false,
        ];
    }
    if (!hash_equals($runtimeGeneration, (string) ($execution['generation_id'] ?? ''))) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_FOREIGN, VIRTUSPHERE_DEPLOY_RECOVERY_FOREIGN_GENERATION);
    }
    $controller = (string) ($execution['controller_state'] ?? '');
    $reconciliation = (string) ($execution['reconciliation_state'] ?? '');
    $cleanup = (string) ($execution['cleanup_state'] ?? '');
    if (!in_array($controller, VIRTUSPHERE_REMOTE_CONTROLLER_STATES, true)
        || !in_array($reconciliation, VIRTUSPHERE_REMOTE_RECONCILIATION_STATES, true)
        || !in_array($cleanup, VIRTUSPHERE_REMOTE_CLEANUP_STATES, true)) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_MANUAL, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
    }
    if ($controller === 'protocol_error' || $reconciliation === 'manual_required') {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_MANUAL, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
    }
    if (in_array($controller, ['active', 'lost_after_start'], true)) {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_REATTACH, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
    }
    if ($controller === 'prepared') {
        return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_REATTACH, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
    }
    if (str_starts_with($controller, 'exited_')) {
        $action = $reconciliation === 'not_required'
            ? VIRTUSPHERE_REMOTE_RECOVERY_IMPORT_RESULT
            : VIRTUSPHERE_REMOTE_RECOVERY_RECONCILE;
        return remote_recovery_verdict($action, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION, $cleanup === 'eligible');
    }
    if ($controller === 'never_started') {
        return [
            'action' => VIRTUSPHERE_REMOTE_RECOVERY_TERMINAL_NOT_STARTED,
            'reason' => null,
            'may_terminalize' => true,
            'may_cleanup' => false,
        ];
    }
    return remote_recovery_verdict(VIRTUSPHERE_REMOTE_RECOVERY_MANUAL, VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION);
}

/** @return array{action:string,reason:?string,may_terminalize:bool,may_cleanup:bool} */
function remote_recovery_verdict(string $action, ?string $reason = null, bool $mayCleanup = false): array
{
    if (!in_array($action, VIRTUSPHERE_REMOTE_RECOVERY_ACTIONS, true)) {
        throw new InvalidArgumentException('Unknown remote recovery action.');
    }
    return ['action' => $action, 'reason' => $reason, 'may_terminalize' => false, 'may_cleanup' => $mayCleanup];
}
