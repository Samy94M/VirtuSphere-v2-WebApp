<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Deploy job reads: the list and detail rows the portal renders, the streamed
 * log tail, the mission-scoped VM resolution both the enqueue and its schedule
 * preview share, and the payload summary a log line carries.
 *
 * Read-only by construction. The one deliberate throw is in
 * repo_deploy_filter_mission_vm_ids(): a selection that filters down to nothing
 * must not read as "whole mission".
 */

/**
 * Computes the per-VM start times for the schedule preview without creating any
 * jobs. Returns Unix epochs so the page formats them in the portal timezone.
 *
 * @return array<int, array{vm_name:string, epoch:int}>
 */
function deploy_preview_rows(mysqli $db, int $missionId, array $payloadData, array $schedule): array
{
    $vmIds = is_array($payloadData['vm_ids'] ?? null) ? $payloadData['vm_ids'] : [];
    $vms = repo_deploy_group_vm_list($db, $missionId, $vmIds);
    $baseEpoch = (int) $schedule['base_epoch'];
    $stagger = $schedule['stagger'];

    $rows = [];
    foreach (array_values($vms) as $i => $vm) {
        $epoch = $baseEpoch + ($stagger !== null ? $i * $stagger * 60 : 0);
        $rows[] = ['vm_name' => (string) $vm['vm_name'], 'epoch' => $epoch];
    }

    return $rows;
}

function deploy_job_payload_summary(?string $payloadJson): string
{
    if ($payloadJson === null || trim($payloadJson) === '') {
        return VIRTUSPHERE_DEPLOY_MODE_FULL;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return 'invalid payload';
    }

    $mode = (string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL);
    $verbose = !empty($payload['verbose']) ? ' -vvv' : '';
    $vmIds = is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
    $scope = $vmIds === [] ? '' : ' (' . count($vmIds) . ' VMs)';

    return $mode . $verbose . $scope;
}

function repo_deploy_jobs(mysqli $db, int $limit = 100, ?int $missionId = null): array
{
    $limit = max(1, min(500, $limit));
    if ($missionId !== null && $missionId > 0) {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             WHERE j.mission_id = ?
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $missionId, $limit);
    } else {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_deploy_job(mysqli $db, int $jobId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.correlation_id, j.created_at, j.updated_at
         FROM deploy_jobs j
         LEFT JOIN deploy_missions m ON m.id = j.mission_id
         LEFT JOIN deploy_users u ON u.id = j.user_id
         LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
         LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
         WHERE j.id = ?
         LIMIT 1',
        'i',
        [$jobId]
    );
}

function repo_deploy_job_logs(mysqli $db, int $jobId, int $afterSeq = 0, int $limit = 500): array
{
    $limit = max(1, min(1000, $limit));
    $afterSeq = max(0, $afterSeq);
    $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? AND seq > ? ORDER BY seq ASC LIMIT ?');
    $stmt->bind_param('iii', $jobId, $afterSeq, $limit);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * @param int[] $vmIds
 * @return int[] VM ids that belong to the mission (order preserved)
 *
 * An empty input stays empty and is read as "whole mission" one level up. But a
 * non-empty input that filters down to nothing is a selection whose VMs have all
 * been deleted (or never belonged here) since the form was rendered: silently
 * returning [] would widen that job to the entire mission. Throw instead, with
 * the exact wording of the worker-side gate (ansible_prepare_job_artifacts), so
 * the same condition reads the same one stage earlier.
 */
function repo_deploy_filter_mission_vm_ids(mysqli $db, int $missionId, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }

    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $owned = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $owned[(int) $row['id']] = true;
    }

    $filtered = array_values(array_filter($vmIds, static fn (int $id): bool => isset($owned[$id])));
    if ($filtered === []) {
        throw new RuntimeException('None of the selected VMs belong to this mission.');
    }

    return $filtered;
}

/**
 * Ordered VM list for a stagger group / its preview. An explicit selection
 * (filtered to the mission) keeps its order; an empty selection means the whole
 * mission ordered by vm_name.
 *
 * @param int[] $selectedVmIds
 * @return array<int, array{id:int, vm_name:string}>
 */
function repo_deploy_group_vm_list(mysqli $db, int $missionId, array $selectedVmIds): array
{
    $selected = repo_deploy_filter_mission_vm_ids($db, $missionId, $selectedVmIds);
    if ($selected === []) {
        $stmt = $db->prepare('SELECT id, vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'vm_name' => (string) $r['vm_name']],
            repo_fetch_all($stmt->get_result())
        );
    }

    $vms = [];
    foreach ($selected as $vmId) {
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        $vms[] = ['id' => $vmId, 'vm_name' => $name];
    }

    return $vms;
}
