<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../ansible_command_modes.php';
require_once __DIR__ . '/../remote_execution_constants.php';

/** @param int[] $vmIds @return array<int,array<string,mixed>> */
function repo_deploy_job_vm_scope(mysqli $db, int $missionId, array $vmIds, bool $lock = false): array
{
    if ($missionId <= 0) {
        return [];
    }
    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    $sql = 'SELECT id, lifecycle_state, mecm_sync_state, vm_status, mecm_id FROM deploy_vms WHERE mission_id = ?';
    $values = [$missionId];
    if ($vmIds !== []) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($vmIds), '?')) . ')';
        $values = array_merge($values, $vmIds);
    }
    $stmt = $db->prepare($sql . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param(str_repeat('i', count($values)), ...$values);
    $stmt->execute();
    return repo_fetch_all($stmt->get_result());
}

/** Immutable capture under Mission -> Job -> Runtime, before any VM write. @param int[] $vmIds */
function repo_deploy_job_vm_capture(mysqli $db, array $job, array $vmIds = []): void
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('VM prestate capture requires the locked job transaction.');
    }
    $jobId = (int) $job['id'];
    foreach (repo_deploy_job_vm_scope($db, (int) ($job['mission_id'] ?? 0), $vmIds, true) as $vm) {
        $vmId = (int) $vm['id'];
        $lifecycle = (string) $vm['lifecycle_state'];
        $mecm = (string) $vm['mecm_sync_state'];
        $stmt = $db->prepare('INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE job_id = deploy_job_vm_states.job_id');
        $stmt->bind_param('iiss', $jobId, $vmId, $lifecycle, $mecm);
        $stmt->execute();
    }
}

/** @return array<int,array<string,mixed>> */
function repo_deploy_job_vm_states(mysqli $db, int $jobId): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_job_vm_states WHERE job_id = ? ORDER BY vm_id FOR UPDATE');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $states[(int) $row['vm_id']] = $row;
    }
    return $states;
}

/**
 * Synchronous per-VM admission; grants no worker claim or final MAC result.
 * Attempt/generation are fences, not authentication. The endpoint owns IP admission.
 */
function repo_deploy_job_vm_admit_touch(mysqli $db, int $jobId, int $vmId, int $attempt, string $generation, string $phase): bool
{
    if (!in_array($phase, ['powercycle', 'export'], true) || $attempt <= 0
        || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1) {
        return false;
    }
    $discovery = repo_fetch_one($db, 'SELECT mission_id FROM deploy_jobs WHERE id = ?', 'i', [$jobId]);
    $missionId = (int) ($discovery['mission_id'] ?? 0);
    if ($missionId <= 0) {
        return false;
    }
    return repo_transaction($db, static function () use ($db, $jobId, $vmId, $attempt, $generation, $phase, $missionId): bool {
        if (repo_fetch_one($db, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$missionId]) === null) {
            return false;
        }
        $job = repo_fetch_one($db, 'SELECT *, LOWER(HEX(execution_generation_id)) AS generation FROM deploy_jobs WHERE id = ? FOR UPDATE', 'i', [$jobId]);
        $runtime = repo_fetch_one($db, 'SELECT LOWER(HEX(current_generation_id)) AS generation FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
        if ($job === null || $runtime === null || (int) $job['mission_id'] !== $missionId
            || !in_array((string) $job['status'], [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)
            || (int) $job['attempts'] !== $attempt
            || !hash_equals($generation, (string) $job['generation'])
            || !hash_equals($generation, (string) $runtime['generation'])) {
            return false;
        }
        $payload = json_decode((string) $job['payload_json'], true);
        $mode = is_array($payload) ? (string) ($payload['mode'] ?? '') : '';
        if (!in_array($mode, virtusphere_user_deploy_modes(), true)
            || !in_array(VIRTUSPHERE_PLAYBOOKS[$phase], ansible_playbooks_for_mode($mode), true)) {
            return false;
        }
        $contract = (string) ($job['execution_contract'] ?? '');
        if ($contract === VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
            $handle = repo_fetch_one($db, 'SELECT job_attempt, step_key, controller_state, LOWER(HEX(generation_id)) AS generation FROM deploy_remote_executions WHERE job_id = ? AND job_attempt = ? AND step_key = ? LIMIT 1 FOR UPDATE', 'iis', [$jobId, $attempt, $phase]);
            if ($handle === null || (int) $handle['job_attempt'] !== $attempt
                || (string) $handle['controller_state'] !== 'active'
                || !hash_equals($generation, (string) $handle['generation'])) {
                return false;
            }
        } elseif ($contract !== VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY) {
            return false;
        }
        if (ansible_mode_creates_vms($mode)) {
            $stmt = $db->prepare('SELECT status FROM deploy_create_vm_results WHERE job_id = ? ORDER BY position FOR UPDATE');
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $units = repo_fetch_all($stmt->get_result());
            if ($units === []) {
                return false;
            }
            foreach ($units as $unit) {
                if (!in_array((string) $unit['status'], ['succeeded', 'skipped'], true)) {
                    return false;
                }
            }
        }
        if (repo_fetch_one($db, 'SELECT id FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE', 'ii', [$vmId, $missionId]) === null) {
            return false;
        }
        $state = repo_fetch_one($db, 'SELECT id FROM deploy_job_vm_states WHERE job_id = ? AND vm_id = ? FOR UPDATE', 'ii', [$jobId, $vmId]);
        if ($state === null) {
            return false;
        }
        $stmt = $db->prepare('UPDATE deploy_job_vm_states SET esxi_touched = 1, touch_phase = COALESCE(touch_phase, ?) WHERE job_id = ? AND vm_id = ?');
        $stmt->bind_param('sii', $phase, $jobId, $vmId);
        $stmt->execute();
        return true;
    });
}
