<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @return list<array<string, mixed>> Newest stored evidence first, never a guessed execution order. */
function repo_package_runs_for_vm(mysqli $db, int $vmId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare('SELECT r.id, LOWER(BIN_TO_UUID(r.run_id)) AS run_id,
            r.project_name, r.package_version, r.run_context, r.rollout_revision,
            r.first_received_at, r.last_evidence_at, r.completed_received_at,
            r.wrapper_result, r.wrapper_exit_code, r.detection_result,
            r.total, r.processed_count, r.ok_count, r.skip_count, r.fail_count,
            r.payload_omitted_count,
            (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id) AS stored_steps,
            (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id AND s.result = \'fail\') AS reported_failures,
            (SELECT CONCAT(s.step_index, \' · \', s.script_name) FROM deploy_package_step_results s
                WHERE s.package_run_id = r.id ORDER BY s.source_event_seq DESC LIMIT 1) AS last_reported_step,
            (SELECT s.result FROM deploy_package_step_results s
                WHERE s.package_run_id = r.id ORDER BY s.source_event_seq DESC LIMIT 1) AS last_reported_result
        FROM deploy_package_runs r
        JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        WHERE r.vm_id = ? AND m.expires_at > UTC_TIMESTAMP(6)
        ORDER BY r.last_evidence_at DESC, r.id DESC LIMIT ?');
    $stmt->bind_param('ii', $vmId, $limit);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/** @return array<string, mixed>|null Exact VM ownership is part of the read query. */
function repo_package_run_for_vm(mysqli $db, int $vmId, string $runId): ?array
{
    if (!repo_package_run_guid_valid($runId)) {
        return null;
    }

    return repo_fetch_one($db, 'SELECT r.id, LOWER(BIN_TO_UUID(r.run_id)) AS run_id,
            r.project_name, r.package_version, r.run_context, r.rollout_revision,
            r.client_started_at, r.client_completed_at,
            r.first_received_at, r.last_evidence_at, r.completed_received_at,
            r.wrapper_result, r.wrapper_exit_code, r.detection_result,
            r.total, r.processed_count, r.ok_count, r.skip_count, r.fail_count,
            r.last_processed_index, r.payload_omitted_count,
            r.wrapper_log_path, r.reporting_log_path,
            (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id) AS stored_steps,
            (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id AND s.result = \'fail\') AS reported_failures,
            (SELECT COUNT(*) FROM deploy_package_run_events e WHERE e.package_run_id = r.id AND e.event_kind = \'started\') AS stored_starts
        FROM deploy_package_runs r
        JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        WHERE r.vm_id = ? AND r.run_id = UUID_TO_BIN(?) AND m.expires_at > UTC_TIMESTAMP(6)',
        'is', [$vmId, $runId]);
}

function repo_package_run_guid_valid(string $runId): bool
{
    return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $runId) === 1;
}

/** @return array{mission_id:int,vm_id:int}|null */
function repo_package_run_location(mysqli $db, string $runId): ?array
{
    if (!repo_package_run_guid_valid($runId)) {
        return null;
    }
    $row = repo_fetch_one($db, 'SELECT v.mission_id, r.vm_id FROM deploy_package_runs r
        JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        JOIN deploy_vms v ON v.id = r.vm_id
        WHERE r.run_id = UUID_TO_BIN(?) AND m.expires_at > UTC_TIMESTAMP(6)', 's', [$runId]);
    return $row === null ? null : ['mission_id' => (int) $row['mission_id'], 'vm_id' => (int) $row['vm_id']];
}

/** @return list<array<string, mixed>> */
function repo_package_steps_for_vm_run(mysqli $db, int $vmId, int $runRowId): array
{
    $stmt = $db->prepare('SELECT step_index, script_name, result, storage_class, is_first_failure,
            error_category, child_exit_code, duration_ms, detail_path
        FROM deploy_package_step_results s
        JOIN deploy_package_runs r ON r.id = s.package_run_id
        JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        WHERE r.vm_id = ? AND r.id = ? AND m.expires_at > UTC_TIMESTAMP(6)
        ORDER BY s.step_index');
    $stmt->bind_param('ii', $vmId, $runRowId);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}
