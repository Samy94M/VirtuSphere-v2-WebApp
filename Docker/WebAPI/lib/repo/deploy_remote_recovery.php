<?php

declare(strict_types=1);

require_once __DIR__ . '/../remote_recovery_policy.php';
require_once __DIR__ . '/deploy_remote_execution.php';
require_once __DIR__ . '/helpers.php';

/** @return list<array<string, mixed>> */
function repo_remote_recovery_candidates(mysqli $db, int $staleAfterSeconds): array
{
    $staleAfterSeconds = max(60, $staleAfterSeconds);
    $stmt = $db->prepare("SELECT j.id AS job_id, j.status, j.execution_contract, LOWER(HEX(j.execution_generation_id)) AS execution_generation_id, j.recovery_reason, j.recovery_requested_at, r.id AS remote_execution_id, LOWER(HEX(r.generation_id)) AS remote_generation_id, r.controller_state, r.effect_state, r.reconciliation_state, r.cleanup_state, r.launch_intent_at, r.result_sha256 FROM deploy_jobs j LEFT JOIN deploy_remote_executions r ON r.job_id = j.id AND r.job_attempt = j.attempts WHERE j.status IN ('running','cancelling') AND (j.heartbeat_at IS NULL OR j.heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)) ORDER BY j.id, r.id");
    $stmt->bind_param('i', $staleAfterSeconds);
    $stmt->execute();
    return repo_fetch_all($stmt->get_result());
}

function repo_request_remote_recovery(mysqli $db, int $jobId, ?int $executionId, string $reason): void
{
    if (!in_array($reason, VIRTUSPHERE_DEPLOY_RECOVERY_REASONS, true)) {
        throw new InvalidArgumentException('Unknown remote recovery reason.');
    }
    repo_transaction($db, static function () use ($db, $jobId, $executionId, $reason): void {
        $job = repo_fetch_one($db, "SELECT id, status FROM deploy_jobs WHERE id = ? FOR UPDATE", 'i', [$jobId]);
        if ($job === null || !in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) {
            throw new RuntimeException('Only an active job can enter recovery.');
        }
        if ($executionId !== null) {
            $execution = repo_deploy_remote_execution($db, $executionId, true);
            if ($execution === null || (int) $execution['job_id'] !== $jobId) {
                throw new RuntimeException('Remote recovery handle does not belong to the job.');
            }
            $state = (string) $execution['reconciliation_state'];
            if ($state === 'not_required') {
                remote_execution_assert_transition('reconciliation', 'not_required', 'pending');
                repo_execute($db, "UPDATE deploy_remote_executions SET reconciliation_state = 'pending', recovery_count = recovery_count + 1 WHERE id = ?", 'i', [$executionId]);
            } elseif (!in_array($state, ['pending', 'running', 'manual_required'], true)) {
                throw new RuntimeException('Terminal reconciliation cannot be requested again.');
            }
        } elseif ($reason === VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION) {
            throw new RuntimeException('Remote observation recovery requires a durable handle.');
        }
        repo_execute($db, 'UPDATE deploy_jobs SET locked_at = NULL, locked_by = NULL, lock_token = NULL, worker_epoch = NULL, heartbeat_at = NULL, recovery_count = recovery_count + IF(recovery_requested_at IS NULL, 1, 0), recovery_reason = ?, recovery_requested_at = COALESCE(recovery_requested_at, NOW()), updated_at = NOW() WHERE id = ?', 'si', [$reason, $jobId]);
    });
}

/** Read-only candidate set for the later replacement of the legacy VM sweep. */
function repo_remote_safe_vm_sweep_candidates(mysqli $db): array
{
    $stmt = $db->prepare("SELECT v.id, v.mission_id, v.vm_name FROM deploy_vms v WHERE v.lifecycle_state = 'deploying' AND NOT EXISTS (SELECT 1 FROM deploy_jobs j LEFT JOIN deploy_remote_executions r ON r.job_id = j.id WHERE j.mission_id = v.mission_id AND (j.status IN ('queued','running','cancelling') OR j.recovery_requested_at IS NOT NULL OR r.reconciliation_state IN ('pending','running','manual_required'))) ORDER BY v.id");
    $stmt->execute();
    return repo_fetch_all($stmt->get_result());
}
