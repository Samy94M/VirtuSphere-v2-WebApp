<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/repo/deploy_remote_mode_activation.php';

const VIRTUSPHERE_REMOTE_POLICY_MODES = [
    VIRTUSPHERE_DEPLOY_MODE_INVENTORY,
    'export',
    'start',
    VIRTUSPHERE_DEPLOY_MODE_AUTOSTART,
    'powercycle',
];

const VIRTUSPHERE_REMOTE_POWERCYCLE_PHASES = [
    'not_started',
    'stop_requested',
    'stopped_verified',
    'start_requested',
    'started_verified',
];

/**
 * Closed policy registry for the 8R-O modes. It describes the evidence a future
 * remote consumer must produce; it neither selects an execution contract nor
 * changes an activation. Runtime budgets deliberately remain site-owned.
 *
 * @return array<string, array{playbooks:string[], steps:array<string, array<string, mixed>>}>
 */
function remote_step_policy_registry(): array
{
    $policies = [
        VIRTUSPHERE_DEPLOY_MODE_INVENTORY => [
            'playbooks' => [VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY]],
            'steps' => [
                VIRTUSPHERE_DEPLOY_MODE_INVENTORY => remote_step_policy(
                    VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY],
                    'read_only',
                    'inventory_snapshot',
                    'none',
                    ['same_handle_reattach', 'rerun_only_after_controller_exit']
                ),
            ],
        ],
        'export' => [
            'playbooks' => ansible_playbooks_for_mode('export'),
            'steps' => [
                'export' => remote_step_policy(
                    VIRTUSPHERE_PLAYBOOKS['export'],
                    'inventory_export',
                    'job_bound_mac_import',
                    'db_import_mac',
                    ['same_job_callback', 'live_inventory_if_result_missing']
                ),
            ],
        ],
        'start' => [
            'playbooks' => ansible_playbooks_for_mode('start'),
            'steps' => [
                'start' => remote_step_policy(
                    VIRTUSPHERE_PLAYBOOKS['start'],
                    'vm_power_on',
                    'vm_identity_power_state',
                    'none',
                    ['uuid_or_moid', 'live_power_state', 'active_task_check', 'retry_only_after_controller_exit']
                ),
            ],
        ],
        VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => [
            'playbooks' => ansible_playbooks_for_mode(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART),
            'steps' => [
                VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => remote_step_policy(
                    VIRTUSPHERE_PLAYBOOKS['autostart'],
                    'host_autostart_policy',
                    'materialized_and_live_autostart_policy',
                    'none',
                    ['uuid_keyed_desired_policy', 'live_policy', 'ha_and_license_gate']
                ),
            ],
        ],
        'powercycle' => [
            'playbooks' => ansible_playbooks_for_mode('powercycle'),
            'steps' => [
                'powercycle' => remote_step_policy(
                    VIRTUSPHERE_PLAYBOOKS['powercycle'],
                    'vm_power_cycle',
                    'per_vm_powercycle_phase',
                    'none',
                    ['uuid_or_moid', 'initial_power_state', 'active_task_check', 'per_vm_phase_only'],
                    VIRTUSPHERE_REMOTE_POWERCYCLE_PHASES
                ),
                'export' => remote_step_policy(
                    VIRTUSPHERE_PLAYBOOKS['export'],
                    'inventory_export',
                    'job_bound_mac_import',
                    'db_import_mac',
                    ['same_job_callback', 'live_inventory_if_result_missing']
                ),
            ],
        ],
    ];

    foreach ($policies as $mode => $policy) {
        $expected = $mode === VIRTUSPHERE_DEPLOY_MODE_INVENTORY
            ? [VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY]]
            : ansible_playbooks_for_mode($mode);
        if ($policy['playbooks'] !== $expected
            || array_column(array_values($policy['steps']), 'playbook') !== $expected) {
            throw new LogicException('Remote step policy disagrees with the Ansible playbook SSoT.');
        }
    }

    return $policies;
}

/** @return array<string, mixed> */
function remote_step_policy(
    string $playbook,
    string $mutation,
    string $reconciliationOwner,
    string $callbackExpectation,
    array $recoveryEvidence,
    array $phases = []
): array {
    return [
        'playbook' => $playbook,
        'mutation' => $mutation,
        'reconciliation_owner' => $reconciliationOwner,
        'callback_expectation' => $callbackExpectation,
        'runtime_budget_source' => 'site_acceptance_required',
        'recovery_evidence' => $recoveryEvidence,
        'phases' => $phases,
    ];
}

/** @return array{allowed:bool,contract:?string,reason:string} */
function remote_mode_activation_verdict(array $activation, string $mode): array
{
    if (!in_array($mode, virtusphere_deploy_modes(), true)) {
        throw new InvalidArgumentException('Unknown deploy mode.');
    }
    if (!in_array($mode, VIRTUSPHERE_REMOTE_POLICY_MODES, true)) {
        return ['allowed' => false, 'contract' => null, 'reason' => 'mode_not_offline_prepared'];
    }
    if ((string) ($activation['mode'] ?? '') !== $mode) {
        throw new InvalidArgumentException('Activation and requested mode disagree.');
    }

    $state = (string) ($activation['state'] ?? '');
    $contract = remote_activation_contract($activation);
    if ($state === VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED) {
        return ['allowed' => false, 'contract' => null, 'reason' => 'disabled'];
    }
    if ($state === VIRTUSPHERE_REMOTE_ACTIVATION_LEGACY && $contract === VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY) {
        return ['allowed' => false, 'contract' => null, 'reason' => 'legacy_not_remote'];
    }
    if ($state === VIRTUSPHERE_REMOTE_ACTIVATION_ROLLBACK && $contract === VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
        return ['allowed' => false, 'contract' => null, 'reason' => 'rollback_pending'];
    }
    if (in_array($state, [VIRTUSPHERE_REMOTE_ACTIVATION_PILOT, VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED], true)
        && $contract === VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
        return ['allowed' => true, 'contract' => VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE, 'reason' => 'remote_only'];
    }
    throw new RuntimeException('Remote activation state and contract disagree.');
}
