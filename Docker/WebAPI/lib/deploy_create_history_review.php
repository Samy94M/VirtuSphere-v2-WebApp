<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/helpers.php';

const VIRTUSPHERE_CREATE_HISTORY_RISK_CREATED_RECORDED_FAILED = 'possible_created_recorded_failed';
const VIRTUSPHERE_CREATE_HISTORY_RISK_FAILURE_RECORDED_UNCHANGED = 'possible_module_failure_recorded_unchanged';

/**
 * Classifies only the two result shapes affected by the former async-result
 * flattening. A classification is deliberately a suspicion, never a corrected
 * status: the historical async file may already be gone and current inventory
 * cannot prove the original module result or hardware convergence.
 *
 * @param array<string,mixed> $row
 */
function deploy_create_history_review_classify(array $row): ?string
{
    if (
        (string) ($row['status'] ?? '') === 'failed'
        && (string) ($row['error_code'] ?? '') === 'identity_result_invalid'
        && (int) ($row['existed_before'] ?? -1) === 0
    ) {
        return VIRTUSPHERE_CREATE_HISTORY_RISK_CREATED_RECORDED_FAILED;
    }

    if (
        (string) ($row['status'] ?? '') === 'succeeded'
        && (string) ($row['outcome'] ?? '') === 'unchanged'
        && (int) ($row['changed'] ?? -1) === 0
        && (int) ($row['existed_before'] ?? -1) === 1
    ) {
        return VIRTUSPHERE_CREATE_HISTORY_RISK_FAILURE_RECORDED_UNCHANGED;
    }

    return null;
}

/** The read-only inventory query; filters are appended by the caller. */
function deploy_create_history_review_sql(): string
{
    return <<<'SQL'
SELECT
    r.job_id,
    r.position,
    r.total,
    r.vm_id,
    r.vm_name,
    r.status,
    r.outcome,
    r.changed,
    r.existed_before,
    r.precheck_moid,
    r.precheck_instance_uuid,
    r.async_jid,
    r.error_code,
    r.error_detail,
    r.started_at AS unit_started_at,
    r.finished_at AS unit_finished_at,
    r.updated_at AS unit_updated_at,
    j.status AS job_status,
    j.mission_id,
    j.credential_esxi_id,
    j.create_started_at,
    j.created_at AS job_created_at,
    j.updated_at AS job_updated_at,
    v.vm_moid AS current_vm_moid,
    v.vm_instance_uuid AS current_vm_instance_uuid,
    r.remote_execution_id,
    x.controller_state,
    x.effect_state,
    x.reconciliation_state,
    x.cleanup_state,
    x.remote_dir,
    x.launch_intent_at,
    x.started_at AS remote_started_at,
    x.last_observed_at AS remote_last_observed_at,
    x.finished_at AS remote_finished_at,
    x.exit_code,
    x.exit_signal,
    x.result_sha256,
    x.last_probe_category,
    x.last_probe_detail,
    s.last_success_at AS inventory_last_success_at,
    s.kind_freshness_json AS inventory_kind_freshness_json
FROM deploy_create_vm_results r
INNER JOIN deploy_jobs j ON j.id = r.job_id
LEFT JOIN deploy_vms v ON v.id = r.vm_id
LEFT JOIN deploy_remote_executions x ON x.id = r.remote_execution_id
LEFT JOIN deploy_esxi_inventory_state s ON s.credential_id = j.credential_esxi_id
WHERE (
    (r.status = 'failed' AND r.error_code = 'identity_result_invalid' AND r.existed_before = 0)
    OR
    (r.status = 'succeeded' AND r.outcome = 'unchanged' AND r.changed = 0 AND r.existed_before = 1)
)
SQL;
}

/**
 * Returns historical suspects without changing a result, job, VM or remote
 * handle. The optional UTC boundary lets an operator scope the review to a
 * known rollout window; omission intentionally lists every matching row.
 *
 * @return list<array<string,mixed>>
 */
function repo_deploy_create_history_review(mysqli $db, ?int $jobId = null, ?string $beforeUtc = null): array
{
    $sql = deploy_create_history_review_sql();
    $types = '';
    $params = [];
    if ($jobId !== null) {
        if ($jobId <= 0) {
            throw new InvalidArgumentException('job-id must be a positive integer.');
        }
        $sql .= ' AND r.job_id = ?';
        $types .= 'i';
        $params[] = $jobId;
    }
    if ($beforeUtc !== null) {
        $sql .= ' AND r.started_at < ?';
        $types .= 's';
        $params[] = $beforeUtc;
    }
    $sql .= ' ORDER BY r.job_id, r.position';

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    return array_map('deploy_create_history_review_present', repo_fetch_all($stmt->get_result()));
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function deploy_create_history_review_present(array $row): array
{
    $riskClass = deploy_create_history_review_classify($row);
    if ($riskClass === null) {
        throw new InvalidArgumentException('The row is not an affected historical result shape.');
    }

    return [
        'assessment' => 'suspect_only',
        'risk_class' => $riskClass,
        'job_id' => (int) ($row['job_id'] ?? 0),
        'job_status' => (string) ($row['job_status'] ?? ''),
        'mission_id' => isset($row['mission_id']) ? (int) $row['mission_id'] : null,
        'credential_esxi_id' => isset($row['credential_esxi_id']) ? (int) $row['credential_esxi_id'] : null,
        'position' => (int) ($row['position'] ?? 0),
        'total' => (int) ($row['total'] ?? 0),
        'vm_id' => isset($row['vm_id']) ? (int) $row['vm_id'] : null,
        'vm_name' => (string) ($row['vm_name'] ?? ''),
        'stored_result' => [
            'status' => (string) ($row['status'] ?? ''),
            'outcome' => $row['outcome'] ?? null,
            'changed' => deploy_create_history_review_nullable_bool($row['changed'] ?? null),
            'error_code' => $row['error_code'] ?? null,
            'error_detail' => $row['error_detail'] ?? null,
        ],
        'prepare_evidence' => [
            'existed_before' => deploy_create_history_review_nullable_bool($row['existed_before'] ?? null),
            'precheck_moid' => $row['precheck_moid'] ?? null,
            'precheck_instance_uuid' => $row['precheck_instance_uuid'] ?? null,
        ],
        'current_stored_identity' => [
            'vm_moid' => $row['current_vm_moid'] ?? null,
            'vm_instance_uuid' => $row['current_vm_instance_uuid'] ?? null,
        ],
        'execution_times' => [
            'job_created_at' => $row['job_created_at'] ?? null,
            'create_started_at' => $row['create_started_at'] ?? null,
            'unit_started_at' => $row['unit_started_at'] ?? null,
            'unit_finished_at' => $row['unit_finished_at'] ?? null,
            'unit_updated_at' => $row['unit_updated_at'] ?? null,
            'job_updated_at' => $row['job_updated_at'] ?? null,
        ],
        'async_jid' => $row['async_jid'] ?? null,
        'remote_evidence' => [
            'remote_execution_id' => isset($row['remote_execution_id']) ? (int) $row['remote_execution_id'] : null,
            'controller_state' => $row['controller_state'] ?? null,
            'effect_state' => $row['effect_state'] ?? null,
            'reconciliation_state' => $row['reconciliation_state'] ?? null,
            'cleanup_state' => $row['cleanup_state'] ?? null,
            'remote_dir' => $row['remote_dir'] ?? null,
            'launch_intent_at' => $row['launch_intent_at'] ?? null,
            'started_at' => $row['remote_started_at'] ?? null,
            'last_observed_at' => $row['remote_last_observed_at'] ?? null,
            'finished_at' => $row['remote_finished_at'] ?? null,
            'exit_code' => isset($row['exit_code']) ? (int) $row['exit_code'] : null,
            'exit_signal' => isset($row['exit_signal']) ? (int) $row['exit_signal'] : null,
            'result_sha256' => $row['result_sha256'] ?? null,
            'last_probe_category' => $row['last_probe_category'] ?? null,
            'last_probe_detail' => $row['last_probe_detail'] ?? null,
        ],
        'inventory_observation' => [
            'last_success_at' => $row['inventory_last_success_at'] ?? null,
            'kind_freshness_json' => $row['inventory_kind_freshness_json'] ?? null,
        ],
    ];
}

function deploy_create_history_review_nullable_bool(mixed $value): ?bool
{
    return $value === null ? null : (bool) (int) $value;
}
