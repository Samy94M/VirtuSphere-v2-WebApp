<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string,mixed> $filter @return array{rows:list<array<string,mixed>>,run_count:int,vm_count:int,next:?array{at:string,id:int}} */
function repo_package_run_search(mysqli $db, array $filter): array
{
    if (($filter['errors'] ?? []) !== []) {
        throw new InvalidArgumentException('Invalid package report filter.');
    }
    $where = ['m.expires_at > UTC_TIMESTAMP(6)'];
    $types = '';
    $params = [];
    if ($filter['project'] !== '') {
        $where[] = 'BINARY r.project_name = BINARY ? AND BINARY r.package_version = BINARY ?';
        $types .= 'ss';
        $params[] = $filter['project'];
        $params[] = $filter['version'];
    }
    if ($filter['name'] !== '') {
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['name']);
        $where[] = "(r.project_name COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '!' OR v.vm_name COLLATE utf8mb4_0900_ai_ci LIKE ? ESCAPE '!')";
        $types .= 'ss';
        $params[] = '%' . $literal . '%';
        $params[] = '%' . $literal . '%';
    }
    if ($filter['exclude_vm'] !== null) {
        $where[] = 'r.vm_id <> ?';
        $types .= 'i';
        $params[] = $filter['exclude_vm'];
    }
    if ($filter['error_category'] !== '') {
        $stepWhere = ['similar.package_run_id = r.id', 'similar.is_first_failure = 1',
            'BINARY similar.error_category = BINARY ?'];
        $types .= 's';
        $params[] = $filter['error_category'];
        if ($filter['child_exit'] !== null) {
            $stepWhere[] = 'similar.child_exit_code = ?';
            $types .= 'i';
            $params[] = $filter['child_exit'];
        }
        $where[] = 'EXISTS (SELECT 1 FROM deploy_package_step_results similar WHERE '
            . implode(' AND ', $stepWhere) . ')';
    }
    if ($filter['wrapper_exit'] !== null) {
        $where[] = 'r.wrapper_exit_code = ?';
        $types .= 'i';
        $params[] = $filter['wrapper_exit'];
    }
    if ($filter['state'] === 'error') {
        $where[] = "(r.wrapper_result = 'failed' OR EXISTS
            (SELECT 1 FROM deploy_package_step_results err WHERE err.package_run_id = r.id AND err.result = 'fail'))";
    } elseif ($filter['state'] === 'no_completion') {
        $where[] = 'r.completed_received_at IS NULL';
    }
    if ($filter['from_utc'] !== null) {
        $where[] = 'r.first_received_at >= ?';
        $types .= 's';
        $params[] = $filter['from_utc'];
    }
    if ($filter['to_utc'] !== null) {
        $where[] = 'r.first_received_at < ?';
        $types .= 's';
        $params[] = $filter['to_utc'];
    }
    $from = ' FROM deploy_package_runs r
        JOIN deploy_package_run_markers m ON m.run_id = r.run_id
        JOIN deploy_vms v ON v.id = r.vm_id
        JOIN deploy_missions mission ON mission.id = v.mission_id
        WHERE ' . implode(' AND ', $where);

    return repo_transaction($db, static function () use ($db, $filter, $from, $types, $params): array {
        $count = repo_fetch_one($db, 'SELECT /*+ MAX_EXECUTION_TIME(1000) */
            COUNT(*) AS run_count, COUNT(DISTINCT r.vm_id) AS vm_count' . $from, $types, $params);
        $listWhere = '';
        $listTypes = $types;
        $listParams = $params;
        if ($filter['before_at'] !== null) {
            $listWhere = ' AND (r.first_received_at < ? OR (r.first_received_at = ? AND r.id < ?))';
            $listTypes .= 'ssi';
            array_push($listParams, $filter['before_at'], $filter['before_at'], $filter['before_id']);
        }
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ r.id, LOWER(BIN_TO_UUID(r.run_id)) AS run_id,
            r.vm_id, v.mission_id, v.vm_name, mission.mission_name,
            r.project_name, r.package_version, r.rollout_revision,
            r.first_received_at, r.last_evidence_at, r.completed_received_at,
            r.wrapper_result, r.wrapper_exit_code, r.fail_count,
            (SELECT COUNT(*) FROM deploy_package_step_results s WHERE s.package_run_id = r.id AND s.result = \'fail\') AS reported_failures'
            . $from . $listWhere . ' ORDER BY r.first_received_at DESC, r.id DESC LIMIT 51';
        $stmt = $db->prepare($sql);
        if ($listTypes !== '') {
            $stmt->bind_param($listTypes, ...$listParams);
        }
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());
        $next = null;
        if (count($rows) > 50) {
            $rows = array_slice($rows, 0, 50);
            $last = $rows[49];
            $next = ['at' => (string) $last['first_received_at'], 'id' => (int) $last['id']];
        }
        return [
            'rows' => $rows,
            'run_count' => (int) ($count['run_count'] ?? 0),
            'vm_count' => (int) ($count['vm_count'] ?? 0),
            'next' => $next,
        ];
    });
}
