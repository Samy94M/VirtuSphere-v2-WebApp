<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_ownership.php';
require_once __DIR__ . '/deploy_worker_vm_journal.php';
require_once __DIR__ . '/repo/deploy_job_input.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/mac_import_binding.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/status_events.php';

/** @param int[] $vmIds @return array<int,string> Compatibility return; persistence is authoritative. */
function deploy_worker_mark_vms_deploying(mysqli $db, array $job, string $note, array $vmIds = []): array
{
    return deploy_worker_owned_transaction($db, $job, static function (array $job) use ($db, $note, $vmIds): array {
        if ((string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_RUNNING) {
            return [];
        }
        $payload = deploy_worker_payload($job);
        if (deploy_job_payload_has_explicit_empty_scope($payload)) {
            return [];
        }
        repo_deploy_job_vm_capture($db, $job, $vmIds);
        $mode = (string) $payload['mode'];
        if (deploy_worker_mode_preserves_vm_state($mode)) {
            return [];
        }
        $vms = deploy_worker_scope_vms($db, (int) $job['mission_id'], $vmIds, true);
        $states = repo_deploy_job_vm_states($db, (int) $job['id']);
        $keep = deploy_worker_locked_imported_vm_ids($job);
        $expectsMac = ansible_mode_expects_mac_result($mode);
        $priorLifecycles = [];
        foreach ($vms as $vm) {
            $vmId = (int) $vm['id'];
            $state = $states[$vmId];
            if (in_array($vmId, $keep, true) || ($expectsMac && virtusphere_vm_is_mecm_bound($vm))
                || (string) $vm['lifecycle_state'] === VIRTUSPHERE_LIFECYCLE_DEPLOYING
                || (string) $vm['lifecycle_state'] !== (string) $state['lifecycle_before']
                || (string) $vm['mecm_sync_state'] !== (string) $state['mecm_before']) {
                continue;
            }
            $prior = (string) $state['lifecycle_before'];
            $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
            $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ?');
            $stmt->bind_param('sis', $lifecycle, $vmId, $prior);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                continue;
            }
            $jobId = (int) $job['id'];
            $stmt = $db->prepare('UPDATE deploy_job_vm_states SET marked_deploying = 1 WHERE job_id = ? AND vm_id = ?');
            $stmt->bind_param('ii', $jobId, $vmId);
            $stmt->execute();
            $priorLifecycles[$vmId] = $prior;
            repo_record_vm_status_event($db, $vmId, $lifecycle, (string) $vm['mecm_sync_state'], (string) $vm['vm_status'], $note);
        }
        return $priorLifecycles;
    }) ?? [];
}

/** @param int[] $vmIds @param array<int,string> $priorLifecycles Legacy argument, never authority after process loss. */
function deploy_worker_restore_deploying_vms(mysqli $db, array $job, string $note, array $vmIds, array $priorLifecycles): int
{
    return deploy_worker_owned_transaction($db, $job, static fn (array $locked): int =>
        deploy_worker_restore_locked_vm_journal($db, $locked, $note, $vmIds)) ?? 0;
}

/** @param int[] $vmIds @param int[] $keepVmIds */
function deploy_worker_mark_vms_failed(mysqli $db, array $job, string $note, array $vmIds = [], array $keepVmIds = [], bool $onlyDeploying = false): int
{
    return deploy_worker_owned_transaction($db, $job, static fn (array $locked): int =>
        deploy_worker_fail_locked_job_vms($db, $locked, $note, $vmIds, $onlyDeploying, $keepVmIds)) ?? 0;
}

/** Active owner rule shared by worker and reaper. @param int[] $vmIds @param int[] $keepVmIds */
function deploy_worker_fail_locked_job_vms(mysqli $db, array $job, string $note, array $vmIds = [], bool $onlyDeploying = false, array $keepVmIds = []): int
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('VM convergence requires the locked job transaction.');
    }
    if (!in_array((string) $job['status'], [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)) {
        return 0;
    }
    return deploy_worker_apply_locked_vm_journal($db, $job, $note, $vmIds, $onlyDeploying, $keepVmIds);
}

/** @return int[] Always the current locked result, never an earlier snapshot. */
function deploy_worker_locked_imported_vm_ids(array $job): array
{
    $result = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
    return $result !== null ? $result['successful_vm_ids'] : [];
}

/**
 * VM convergence and cancellation terminality share one ownership transaction.
 *
 * Every confirmed cancel passes here (step boundary, failure, conclusion, create
 * section), so this is also where its outcome row is written (LG-08). It lives in
 * the same transaction as the cancelled status: a cancel never exists without its
 * row, and a row never exists for a cancel that did not happen.
 */
function deploy_worker_confirm_owned_cancel(mysqli $db, array $claim, ?string $message = null): bool
{
    return deploy_worker_owned_transaction($db, $claim, static function (array $job) use ($db, $claim, $message): bool {
        if ((string) $job['status'] !== VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
            return false;
        }
        $jobId = (int) $job['id'];
        $payload = json_decode((string) $job['payload_json'], true);
        $vmIds = is_array($payload) && is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
        deploy_worker_fail_locked_job_vms($db, $job, 'deploy job ' . $jobId . ' cancelled', $vmIds);
        if (!repo_confirm_deploy_job_cancelled($db, $jobId, (string) $job['locked_by'], $message)) {
            return false;
        }
        // The owned row lacks user_id; the claim row carries it (the operator who queued the job).
        if (!deploy_worker_write_outcome_audit($db, $job + ['user_id' => $claim['user_id'] ?? null], VIRTUSPHERE_DEPLOY_STATUS_CANCELLED)) {
            throw new RuntimeException('Deploy outcome audit could not be stored; the cancellation was rolled back.');
        }

        return true;
    }) ?? false;
}

/** @param int[] $vmIds @return array<int,array<string,mixed>> */
function deploy_worker_scope_vms(mysqli $db, int $missionId, array $vmIds, bool $lock = false): array
{
    return repo_deploy_job_vm_scope($db, $missionId, $vmIds, $lock);
}

/**
 * @return array{version:int,outcome:string,successful_vm_ids:list<int>,failed_vm_ids:list<int>,counts:array<string,int>,errors:list<mixed>,retry:array<mixed>,vm_results:list<mixed>,callback_fingerprint:string}|null
 */
function deploy_worker_job_mac_result(mysqli $db, int $jobId): ?array
{
    $stmt = $db->prepare('SELECT result_json FROM deploy_jobs WHERE id = ? LIMIT 1'
        . (repo_transaction_depth($db) > 0 ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return mac_import_decode_result(is_array($row) && $row['result_json'] !== null ? (string) $row['result_json'] : null);
}
