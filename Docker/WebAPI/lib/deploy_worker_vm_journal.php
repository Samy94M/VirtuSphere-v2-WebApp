<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/deploy_job_vm_states.php';
require_once __DIR__ . '/repo/status_events.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/deploy_worker_ownership.php';

/** Original claim only. New intent=true, existing intent=false, refusal=null. */
function deploy_worker_vm_create_launch_intent(mysqli $db, array $claim, int $vmId, bool $intent): ?bool
{
    return deploy_worker_owned_transaction($db, $claim, static function (array $job) use ($db, $vmId, $intent): ?bool {
        if ($intent && (string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING) {
            return null;
        }
        $vms = repo_deploy_job_vm_scope($db, (int) $job['mission_id'], [$vmId], true);
        if ($vms === []) {
            return null;
        }
        $states = repo_deploy_job_vm_states($db, (int) $job['id']);
        if (!isset($states[$vmId])) {
            return null;
        }
        if ($intent && (int) $states[$vmId]['create_launch_intent'] === 1) {
            return false;
        }
        $value = $intent ? 1 : 0;
        $jobId = (int) $job['id'];
        $stmt = $db->prepare('UPDATE deploy_job_vm_states SET create_launch_intent = ? WHERE job_id = ? AND vm_id = ?');
        $stmt->bind_param('iii', $value, $jobId, $vmId);
        $stmt->execute();
        return true;
    });
}

/** These operational modes never own Lifecycle or MECM state writes. */
function deploy_worker_mode_preserves_vm_state(string $mode): bool
{
    return in_array($mode, ['start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART], true);
}

/** Caller already holds Mission -> Job -> Runtime; an old generation owns no VM convergence. */
function deploy_worker_vm_journal_generation_matches(mysqli $db, array $job): bool
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('VM journal convergence requires the locked job transaction.');
    }
    $runtime = repo_fetch_one($db, 'SELECT LOWER(HEX(current_generation_id)) AS generation FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
    $generation = (string) ($job['execution_generation_id'] ?? '');
    return $runtime !== null && $generation !== '' && hash_equals((string) $runtime['generation'], $generation);
}

/** @return array<int,array<string,mixed>> Read before VM locks, not a process-memory guess. */
function deploy_worker_vm_create_evidence(mysqli $db, int $jobId): array
{
    $stmt = $db->prepare('SELECT vm_id, status, outcome, async_jid FROM deploy_create_vm_results WHERE job_id = ? ORDER BY position FOR UPDATE');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $units = [];
    foreach (repo_fetch_all($stmt->get_result()) as $unit) {
        if ($unit['vm_id'] !== null) {
            $units[(int) $unit['vm_id']] = $unit;
        }
    }
    return $units;
}

/** Unknown is deliberately neither failure nor proof of no remote effects. */
function deploy_worker_vm_journal_disposition(array $state, ?array $unit): string
{
    if ($unit !== null) {
        $status = (string) $unit['status'];
        if (in_array($status, ['running', 'uncertain'], true)
            || ($status === 'prepared' && (int) ($state['create_launch_intent'] ?? 0) === 1)) {
            return 'unknown';
        }
        if ((string) ($unit['outcome'] ?? '') !== 'unchanged'
            && $status !== 'skipped' && (string) ($unit['async_jid'] ?? '') !== '') {
            return 'fail';
        }
    }
    return (int) $state['esxi_touched'] === 1 ? 'fail' : 'restore';
}

/**
 * Shared failure/cancel/reaper/sweep rule, under the caller's ownership fence.
 * No journal means no authority to repair a historical VM. @param int[] $vmIds @param int[] $keepVmIds
 */
function deploy_worker_apply_locked_vm_journal(mysqli $db, array $job, string $note, array $vmIds = [], bool $onlyDeploying = false, array $keepVmIds = []): int
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('VM convergence requires the locked job transaction.');
    }
    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
    $mode = is_array($payload) ? (string) ($payload['mode'] ?? '') : '';
    if (deploy_worker_mode_preserves_vm_state($mode)) {
        return 0;
    }
    if (!deploy_worker_vm_journal_generation_matches($db, $job)) {
        return 0;
    }
    $jobId = (int) $job['id'];
    $units = deploy_worker_vm_create_evidence($db, $jobId);
    $vms = repo_deploy_job_vm_scope($db, (int) ($job['mission_id'] ?? 0), $vmIds, true);
    $states = repo_deploy_job_vm_states($db, $jobId);
    $result = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
    $keep = array_flip(array_merge($keepVmIds, $result !== null ? $result['successful_vm_ids'] : []));
    $changed = 0;
    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        if (isset($keep[$vmId]) || !isset($states[$vmId])) {
            continue;
        }
        $lifecycle = (string) $vm['lifecycle_state'];
        $mecm = (string) $vm['mecm_sync_state'];
        $state = $states[$vmId];
        $disposition = deploy_worker_vm_journal_disposition($state, $units[$vmId] ?? null);
        if ($disposition === 'unknown') {
            continue;
        }
        if ($disposition === 'restore') {
            if ((int) $state['marked_deploying'] !== 1 || $lifecycle !== VIRTUSPHERE_LIFECYCLE_DEPLOYING
                || $mecm !== (string) $state['mecm_before']) {
                continue;
            }
            $nextLifecycle = (string) $state['lifecycle_before'];
            $nextMecm = (string) $state['mecm_before'];
        } else {
            // A journaled touch owns its failure even for a bound VM that was
            // deliberately never marked deploying. The legacy filter is no authority.
            $nextLifecycle = VIRTUSPHERE_LIFECYCLE_FAILED;
            $nextMecm = VIRTUSPHERE_MECM_SYNC_FAILED;
        }
        if ($lifecycle === $nextLifecycle && $mecm === $nextMecm) {
            continue;
        }
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ? AND mecm_sync_state = ?');
        $stmt->bind_param('ssiss', $nextLifecycle, $nextMecm, $vmId, $lifecycle, $mecm);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            repo_record_vm_status_event($db, $vmId, $nextLifecycle, $nextMecm, (string) $vm['vm_status'], $note);
            $changed++;
        }
    }
    return $changed;
}

/** Known successful non-MAC completion restores only its own journaled bracket. @param int[] $vmIds */
function deploy_worker_restore_locked_vm_journal(mysqli $db, array $job, string $note, array $vmIds): int
{
    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
    if (is_array($payload) && deploy_worker_mode_preserves_vm_state((string) ($payload['mode'] ?? ''))) {
        return 0;
    }
    if (!deploy_worker_vm_journal_generation_matches($db, $job)) {
        return 0;
    }
    $units = deploy_worker_vm_create_evidence($db, (int) $job['id']);
    $vms = repo_deploy_job_vm_scope($db, (int) ($job['mission_id'] ?? 0), $vmIds, true);
    $states = repo_deploy_job_vm_states($db, (int) $job['id']);
    $result = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
    $keep = $result !== null ? $result['successful_vm_ids'] : [];
    $restored = 0;
    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $state = $states[$vmId] ?? null;
        if ($state === null || in_array($vmId, $keep, true) || (int) $state['marked_deploying'] !== 1
            || deploy_worker_vm_journal_disposition($state, $units[$vmId] ?? null) === 'unknown'
            || (string) $vm['lifecycle_state'] !== VIRTUSPHERE_LIFECYCLE_DEPLOYING
            || (string) $vm['mecm_sync_state'] !== (string) $state['mecm_before']) {
            continue;
        }
        $lifecycle = (string) $state['lifecycle_before'];
        $mecm = (string) $state['mecm_before'];
        $deploying = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ? AND mecm_sync_state = ?');
        $stmt->bind_param('ssiss', $lifecycle, $mecm, $vmId, $deploying, $mecm);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            repo_record_vm_status_event($db, $vmId, $lifecycle, $mecm, (string) $vm['vm_status'], $note);
            $restored++;
        }
    }
    return $restored;
}
