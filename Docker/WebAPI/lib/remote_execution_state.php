<?php

declare(strict_types=1);

require_once __DIR__ . '/remote_execution_constants.php';

/** @return array<string, list<string>> */
function remote_execution_state_edges(string $axis): array
{
    return match ($axis) {
        'controller' => [
            'prepared' => ['active', 'never_started', 'protocol_error'],
            'active' => ['exited_0', 'exited_nonzero', 'exited_signal', 'lost_after_start', 'protocol_error'],
            'lost_after_start' => ['exited_0', 'exited_nonzero', 'exited_signal', 'protocol_error'],
        ],
        'effect' => [
            'not_started' => ['active_or_possible', 'goal_verified', 'divergence_verified'],
            'active_or_possible' => ['not_started', 'goal_verified', 'divergence_verified', 'unknown'],
            'unknown' => ['goal_verified', 'divergence_verified'],
        ],
        'reconciliation' => [
            'not_required' => ['pending'],
            'pending' => ['running', 'manual_required'],
            'running' => ['pending', 'resolved_success', 'resolved_failure', 'manual_required'],
            'manual_required' => ['pending', 'resolved_success', 'resolved_failure'],
        ],
        'cleanup' => [
            'pending' => ['eligible'],
            'eligible' => ['running', 'pending'],
            'running' => ['cleaned', 'failed'],
            'failed' => ['eligible'],
        ],
        default => throw new InvalidArgumentException('Unknown remote execution state axis.'),
    };
}

function remote_execution_assert_transition(string $axis, string $from, string $to): void
{
    if ($from === $to) {
        return;
    }
    if (!in_array($to, remote_execution_state_edges($axis)[$from] ?? [], true)) {
        throw new RuntimeException('Forbidden remote ' . $axis . ' transition: ' . $from . ' -> ' . $to . '.');
    }
}

/** @return array{controller_state:string,effect_state:string,reconciliation_state:string,cleanup_state:string,exit_code:?int,output_truncated:bool} */
function remote_inventory_observation(array $current, ?array $launch, ?array $started, ?array $result, bool $transportLost): array
{
    $controller = (string) ($current['controller_state'] ?? '');
    $effect = (string) ($current['effect_state'] ?? '');
    $reconciliation = (string) ($current['reconciliation_state'] ?? '');
    $cleanup = (string) ($current['cleanup_state'] ?? '');
    foreach ([
        [$controller, VIRTUSPHERE_REMOTE_CONTROLLER_STATES],
        [$effect, VIRTUSPHERE_REMOTE_EFFECT_STATES],
        [$reconciliation, VIRTUSPHERE_REMOTE_RECONCILIATION_STATES],
        [$cleanup, VIRTUSPHERE_REMOTE_CLEANUP_STATES],
    ] as [$value, $registry]) {
        if (!in_array($value, $registry, true)) {
            throw new RuntimeException('Stored remote execution state is unknown.');
        }
    }
    $nextController = $controller;
    $nextEffect = $effect;
    $nextReconciliation = $reconciliation;
    $exitCode = null;
    $truncated = false;

    if ($result !== null) {
        $exitCode = (int) $result['exit_code'];
        $nextController = $exitCode === 0 ? 'exited_0' : ($exitCode < 0 ? 'exited_signal' : 'exited_nonzero');
        $truncated = (bool) $result['output_truncated'];
        $nextEffect = $effect === 'not_started' ? 'active_or_possible' : $effect;
        $nextReconciliation = $reconciliation === 'not_required' ? 'pending' : $reconciliation;
    } elseif ($started !== null || in_array($launch['decision'] ?? null, ['launched', 'already_running'], true)) {
        $nextController = 'active';
        $nextEffect = $effect === 'not_started' ? 'active_or_possible' : $effect;
    } elseif (($launch['decision'] ?? null) === 'recovery_required' || ($transportLost && $controller === 'active')) {
        $nextController = 'lost_after_start';
        $nextEffect = $effect === 'not_started' ? 'active_or_possible' : $effect;
        $nextReconciliation = $reconciliation === 'not_required' ? 'pending' : $reconciliation;
    }
    if ($result !== null && $controller === 'prepared' && str_starts_with($nextController, 'exited_')) {
        // Reattach may observe the terminal result after the DB crashed between
        // launch and the `active` write. The result proves both edges; it does
        // not justify another launch.
        remote_execution_assert_transition('controller', 'prepared', 'active');
        remote_execution_assert_transition('controller', 'active', $nextController);
    } else {
        remote_execution_assert_transition('controller', $controller, $nextController);
    }
    remote_execution_assert_transition('effect', $effect, $nextEffect);
    remote_execution_assert_transition('reconciliation', $reconciliation, $nextReconciliation);
    return [
        'controller_state' => $nextController,
        'effect_state' => $nextEffect,
        'reconciliation_state' => $nextReconciliation,
        'cleanup_state' => $cleanup,
        'exit_code' => $exitCode,
        'output_truncated' => $truncated,
    ];
}
