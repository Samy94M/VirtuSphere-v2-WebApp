<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/helpers.php';

/**
 * Runs a worker mutation under the ORIGINAL claim, never an adopted fresh
 * token. Mission -> Job -> Runtime precedes every VM lock. The callback and
 * terminal publication share these locks, so a successor cannot start between
 * the ownership decision and a VM write. Lost ownership is a silent no-op.
 *
 * @template T
 * @param callable(array): T $write Receives the current, locked job row.
 * @return T|null
 */
function deploy_worker_owned_transaction(mysqli $db, array $claim, callable $write): mixed
{
    return repo_transaction($db, static function () use ($db, $claim, $write): mixed {
        $missionId = (int) ($claim['mission_id'] ?? 0);
        if ($missionId > 0 && repo_fetch_one($db,
            'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$missionId]) === null) {
            return null;
        }
        $job = repo_fetch_one($db,
            'SELECT id, mission_id, status, locked_by, lock_token, worker_epoch, attempts, payload_json, result_json,'
            . ' LOWER(HEX(execution_generation_id)) AS execution_generation_id'
            . ' FROM deploy_jobs WHERE id = ? FOR UPDATE', 'i', [(int) ($claim['id'] ?? 0)]);
        if ($job === null || !deploy_worker_claim_matches($claim, $job)) {
            return null;
        }
        $runtime = repo_fetch_one($db,
            'SELECT LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
        $generation = (string) ($job['execution_generation_id'] ?? '');
        if ($runtime === null || ($generation !== '' && $generation !== (string) $runtime['generation_id'])) {
            return null;
        }

        return $write($job);
    });
}

/** A process name alone cannot distinguish two claims by the same worker. */
function deploy_worker_claim_matches(array $claim, array $current): bool
{
    return in_array((string) ($current['status'] ?? ''), [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)
        && (int) ($claim['id'] ?? 0) > 0
        && (int) $claim['id'] === (int) ($current['id'] ?? 0)
        && (int) ($claim['mission_id'] ?? 0) === (int) ($current['mission_id'] ?? 0)
        && (string) ($claim['locked_by'] ?? '') !== ''
        && (string) $claim['locked_by'] === (string) ($current['locked_by'] ?? '')
        && (string) ($claim['lock_token'] ?? '') !== ''
        && hash_equals((string) $claim['lock_token'], (string) ($current['lock_token'] ?? ''))
        && ($claim['worker_epoch'] ?? null) !== null
        && (int) $claim['worker_epoch'] === (int) ($current['worker_epoch'] ?? -1)
        && (int) ($claim['attempts'] ?? -1) === (int) ($current['attempts'] ?? -2)
        && (string) ($claim['execution_generation_id'] ?? '') === (string) ($current['execution_generation_id'] ?? '');
}
