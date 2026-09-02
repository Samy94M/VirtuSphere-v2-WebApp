<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_supervisor_policy.php';
require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deploy_runtime_identity.php';

/**
 * The supervisor's published state, as the portal can read it (Etappe 14C).
 *
 * Published rather than authoritative: the supervisor DECIDES on its child's
 * local liveness file and never on this row. A worker sitting out a database
 * outage is healthy, so a supervisor that consulted the database would answer a
 * database outage by killing the one process that is correctly surviving it.
 * This row exists because the portal runs in a different container and cannot
 * read that file at all.
 *
 * @return array{
 *     heartbeat_at:?string, pid:?int, phase:?string, child_pid:?int,
 *     child_started_at:?string, restart_window_started_at:?string,
 *     restart_count:int, next_retry_at:?string,
 *     contract_changed_at:?string, contract_changed_by:?int
 * }
 */
function repo_deploy_supervisor_state(mysqli $db): array
{
    $row = repo_fetch_one(
        $db,
        'SELECT supervisor_heartbeat_at, supervisor_pid, supervisor_phase, supervisor_child_pid,
                supervisor_child_started_at, supervisor_restart_window_started_at, supervisor_restart_count,
                supervisor_next_retry_at, supervisor_contract_changed_at, supervisor_contract_changed_by
         FROM deploy_runtime_identity WHERE id = 1 LIMIT 1'
    ) ?? [];

    $phase = $row['supervisor_phase'] === null ? null : (string) $row['supervisor_phase'];
    if ($phase !== null && !in_array($phase, VIRTUSPHERE_SUPERVISOR_PHASES, true)) {
        // An unknown phase is read as "no phase" rather than passed on. The
        // snapshot fails closed on an unobservable contract, and a value this
        // build cannot interpret is exactly that case one level down.
        $phase = null;
    }

    return [
        'heartbeat_at' => $row['supervisor_heartbeat_at'] === null ? null : (string) $row['supervisor_heartbeat_at'],
        'pid' => $row['supervisor_pid'] === null ? null : (int) $row['supervisor_pid'],
        'phase' => $phase,
        'child_pid' => $row['supervisor_child_pid'] === null ? null : (int) $row['supervisor_child_pid'],
        'child_started_at' => $row['supervisor_child_started_at'] === null ? null : (string) $row['supervisor_child_started_at'],
        'restart_window_started_at' => $row['supervisor_restart_window_started_at'] === null ? null : (string) $row['supervisor_restart_window_started_at'],
        'restart_count' => (int) ($row['supervisor_restart_count'] ?? 0),
        'next_retry_at' => $row['supervisor_next_retry_at'] === null ? null : (string) $row['supervisor_next_retry_at'],
        'contract_changed_at' => $row['supervisor_contract_changed_at'] === null ? null : (string) $row['supervisor_contract_changed_at'],
        'contract_changed_by' => $row['supervisor_contract_changed_by'] === null ? null : (int) $row['supervisor_contract_changed_by'],
    ];
}

/**
 * The supervisor publishes what it just decided.
 *
 * Best effort by contract: the caller wraps this and swallows a database
 * failure, because a status row is a side channel and a side channel must never
 * end the work it only describes. The supervisor keeps watching its child
 * whether or not this write lands.
 *
 * @param array<string, mixed> $state
 */
function repo_deploy_supervisor_publish(mysqli $db, array $state, ?int $supervisorPid, ?int $childPid): void
{
    $phase = (string) $state['phase'];
    if (!in_array($phase, VIRTUSPHERE_SUPERVISOR_PHASES, true)) {
        throw new InvalidArgumentException('Unknown supervisor phase: ' . $phase);
    }

    $stmt = $db->prepare(
        'UPDATE deploy_runtime_identity
         SET supervisor_heartbeat_at = NOW(),
             supervisor_pid = ?,
             supervisor_phase = ?,
             supervisor_child_pid = ?,
             supervisor_child_started_at = ?,
             supervisor_restart_window_started_at = ?,
             supervisor_restart_count = ?,
             supervisor_next_retry_at = ?
         WHERE id = 1'
    );
    $childStartedAt = repo_supervisor_timestamp($state['child_started_at'] ?? null);
    $windowStartedAt = repo_supervisor_timestamp($state['restart_window_started_at'] ?? null);
    $nextRetryAt = repo_supervisor_timestamp($state['next_retry_at'] ?? null);
    $restartCount = (int) ($state['restart_count'] ?? 0);
    $stmt->bind_param('isissis', $supervisorPid, $phase, $childPid, $childStartedAt, $windowStartedAt, $restartCount, $nextRetryAt);
    $stmt->execute();
}

/**
 * Clears the published state, so a stopped supervisor does not leave a row
 * claiming it is still watching something.
 */
function repo_deploy_supervisor_clear(mysqli $db): void
{
    $db->query(
        'UPDATE deploy_runtime_identity
         SET supervisor_heartbeat_at = NULL, supervisor_pid = NULL, supervisor_phase = NULL,
             supervisor_child_pid = NULL, supervisor_child_started_at = NULL,
             supervisor_next_retry_at = NULL
         WHERE id = 1'
    );
}

/** Unix seconds to the MySQL timestamp literal, or null. */
function repo_supervisor_timestamp(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    return date('Y-m-d H:i:s', (int) $value);
}
