<?php

declare(strict_types=1);

require_once __DIR__ . '/../package_run_report_constants.php';
require_once __DIR__ . '/helpers.php';

final class PackageReportRefusal extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $wireError)
    {
        parent::__construct($wireError);
    }
}

/** @return array{status:int,error?:string,vm_id?:int} */
function repo_package_report_resolve_vm(mysqli $db, array $macs): array
{
    if ($macs === []) {
        return ['status' => 400, 'error' => 'invalid_mac_candidates'];
    }
    $in = implode(',', array_fill(0, count($macs), '?'));
    $stmt = $db->prepare(
        "SELECT DISTINCT i.vm_id FROM deploy_interfaces i WHERE i.mac IN ({$in}) ORDER BY i.vm_id LIMIT 2"
    );
    $stmt->bind_param(str_repeat('s', count($macs)), ...$macs);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($rows) === 0) {
        return ['status' => 404, 'error' => 'unknown_device'];
    }
    if (count($rows) !== 1) {
        return ['status' => 409, 'error' => 'ambiguous_device'];
    }

    return ['status' => 200, 'vm_id' => (int) $rows[0]['vm_id']];
}

/**
 * Stores one already validated V1 event. Expected protocol refusals are values;
 * database/integrity failures throw so the endpoint can return its generic 500.
 *
 * @return array{status:int,accepted?:bool,deduplicated?:bool,error?:string}
 */
function repo_package_report_record(mysqli $db, int $vmId, array $report): array
{
    try {
        return repo_transaction($db, static function () use ($db, $vmId, $report): array {
        $state = repo_fetch_one(
            $db,
            'SELECT LOWER(CONCAT_WS(\'-\', HEX(SUBSTR(acceptance_generation,1,4)), HEX(SUBSTR(acceptance_generation,5,2)), HEX(SUBSTR(acceptance_generation,7,2)), HEX(SUBSTR(acceptance_generation,9,2)), HEX(SUBSTR(acceptance_generation,11,6)))) AS acceptance_generation FROM deploy_package_report_state WHERE id = 1 FOR UPDATE'
        );
        if ($state === null) {
            throw new RuntimeException('Package report acceptance generation is missing.');
        }
        $currentAcceptance = strtolower((string) $state['acceptance_generation']);
        if (!hash_equals($currentAcceptance, (string) $report['acceptance_generation'])) {
            repo_package_report_reject(409, 'acceptance_generation_mismatch');
        }

        $vm = repo_fetch_one(
            $db,
            'SELECT LOWER(CONCAT_WS(\'-\', HEX(SUBSTR(package_report_generation,1,4)), HEX(SUBSTR(package_report_generation,5,2)), HEX(SUBSTR(package_report_generation,7,2)), HEX(SUBSTR(package_report_generation,9,2)), HEX(SUBSTR(package_report_generation,11,6)))) AS device_generation, mecm_rollout_revision FROM deploy_vms WHERE id = ? FOR UPDATE',
            'i',
            [$vmId]
        );
        if ($vm === null) {
            repo_package_report_reject(404, 'unknown_device');
        }
        $resolved = repo_package_report_resolve_vm($db, $report['mac_candidates']);
        if ($resolved['status'] !== 200) {
            repo_package_report_reject($resolved['status'], (string) ($resolved['error'] ?? 'device_identity_conflict'));
        }
        if ((int) ($resolved['vm_id'] ?? 0) !== $vmId) {
            repo_package_report_reject(409, 'device_identity_conflict');
        }
        if (!hash_equals((string) $vm['device_generation'], (string) $report['device_generation'])
            || (int) ($vm['mecm_rollout_revision'] ?? 0) !== (int) $report['rollout_revision']
        ) {
            repo_package_report_reject(409, 'device_generation_mismatch');
        }

        $now = (string) repo_scalar($db, 'SELECT UTC_TIMESTAMP(6)');
        $marker = repo_package_report_marker($db, (string) $report['run_id']);
        $run = repo_package_report_run($db, (string) $report['run_id']);
        if ($marker !== null && $run === null) {
            if ((string) $marker['expires_at'] <= $now) {
                repo_package_report_reject(410, 'report_expired');
            }
            throw new RuntimeException('Package report marker exists without its live run before expiry.');
        }
        if ($marker !== null && (string) $marker['expires_at'] <= $now) {
            repo_package_report_reject(410, 'report_expired');
        }
        if ($run === null) {
            $run = repo_package_report_create_run($db, $vmId, $report, $now);
        } elseif (!repo_package_report_metadata_matches($run, $vmId, $report)) {
            repo_package_report_reject(409, 'run_metadata_conflict');
        }

        $totalResult = repo_package_report_adopt_total($db, $run, $report['total']);
        if ($totalResult === false) {
            repo_package_report_reject(409, 'run_metadata_conflict');
        }
        $run['total'] = $totalResult;

        $eventKey = repo_package_report_event_key($report);
        $fingerprint = repo_package_report_fingerprint($report);
        $existing = repo_package_report_existing_event($db, (int) $run['id'], (int) $report['event_seq'], $eventKey);
        if ($existing !== null) {
            if ((string) $existing['event_key'] === $eventKey
                && hash_equals((string) $existing['semantic_sha256'], $fingerprint)
            ) {
                return ['status' => 200, 'accepted' => false, 'deduplicated' => true];
            }
            repo_package_report_reject(409, 'event_conflict');
        }

        if ($report['event'] === VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP) {
            return repo_package_report_store_step($db, $run, $report, $eventKey, $fingerprint, $now);
        }
        if ($report['event'] === VIRTUSPHERE_PACKAGE_REPORT_EVENT_COMPLETED) {
            return repo_package_report_store_completed($db, $run, $report, $eventKey, $fingerprint, $now);
        }

        repo_package_report_insert_event($db, (int) $run['id'], $report, $eventKey, $fingerprint, $now);
        repo_execute($db, 'UPDATE deploy_package_runs SET last_evidence_at = ? WHERE id = ?', 'si', [$now, (int) $run['id']]);

        return ['status' => 200, 'accepted' => true, 'deduplicated' => false];
        });
    } catch (PackageReportRefusal $refusal) {
        return ['status' => $refusal->status, 'error' => $refusal->wireError];
    }
}

function repo_package_report_marker(mysqli $db, string $runId): ?array
{
    return repo_fetch_one($db, 'SELECT expires_at FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?) FOR UPDATE', 's', [$runId]);
}

function repo_package_report_run(mysqli $db, string $runId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT id, vm_id, LOWER(BIN_TO_UUID(run_id)) AS run_id, LOWER(BIN_TO_UUID(device_generation)) AS device_generation, LOWER(BIN_TO_UUID(acceptance_generation)) AS acceptance_generation, rollout_revision, project_name, package_version, run_context, mac_candidates, client_started_at, total, completed_received_at, ok_count, skip_count, fail_count, last_processed_index FROM deploy_package_runs WHERE run_id = UUID_TO_BIN(?) FOR UPDATE',
        's',
        [$runId]
    );
}

function repo_package_report_create_run(mysqli $db, int $vmId, array $report, string $now): array
{
    $expires = (string) repo_scalar($db, 'SELECT DATE_ADD(CAST(? AS DATETIME(6)), INTERVAL ? DAY)', 'si', [
        $now, VIRTUSPHERE_PACKAGE_REPORT_RETENTION_DAYS,
    ]);
    repo_execute($db, 'INSERT INTO deploy_package_run_markers (run_id, expires_at, created_at) VALUES (UUID_TO_BIN(?), ?, ?)', 'sss', [
        $report['run_id'], $expires, $now,
    ]);
    $catalogId = repo_package_report_catalog_id($db, (string) $report['project_name'], (string) $report['package_version']);
    $macJson = json_encode($report['mac_candidates'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $started = repo_package_report_sql_time((string) $report['client_started_at']);
    $stmt = $db->prepare('INSERT INTO deploy_package_runs
        (run_id, vm_id, catalog_id, device_generation, acceptance_generation, rollout_revision, project_name, package_version, run_context, mac_candidates, client_started_at, total, first_received_at, last_evidence_at)
        VALUES (UUID_TO_BIN(?), ?, ?, UUID_TO_BIN(?), UUID_TO_BIN(?), ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('siississsssiss', $report['run_id'], $vmId, $catalogId, $report['device_generation'],
        $report['acceptance_generation'], $report['rollout_revision'], $report['project_name'], $report['package_version'],
        $report['context'], $macJson, $started, $report['total'], $now, $now);
    $stmt->execute();

    return repo_package_report_run($db, (string) $report['run_id'])
        ?? throw new RuntimeException('Created package run cannot be read.');
}

function repo_package_report_catalog_id(mysqli $db, string $project, string $version): ?int
{
    $stmt = $db->prepare('SELECT id FROM deploy_packages WHERE BINARY package_basename = BINARY ? AND BINARY package_version = BINARY ? ORDER BY id LIMIT 2');
    $stmt->bind_param('ss', $project, $version);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return count($rows) === 1 ? (int) $rows[0]['id'] : null;
}

function repo_package_report_metadata_matches(array $run, int $vmId, array $report): bool
{
    return (int) $run['vm_id'] === $vmId
        && hash_equals((string) $run['device_generation'], (string) $report['device_generation'])
        && hash_equals((string) $run['acceptance_generation'], (string) $report['acceptance_generation'])
        && (int) $run['rollout_revision'] === (int) $report['rollout_revision']
        && (string) $run['project_name'] === (string) $report['project_name']
        && (string) $run['package_version'] === (string) $report['package_version']
        && (string) $run['run_context'] === (string) $report['context']
        && (string) $run['mac_candidates'] === json_encode($report['mac_candidates'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        && (string) $run['client_started_at'] === repo_package_report_sql_time((string) $report['client_started_at']);
}

function repo_package_report_adopt_total(mysqli $db, array $run, ?int $incoming): int|null|false
{
    $stored = $run['total'] === null ? null : (int) $run['total'];
    if ($stored !== null && $incoming !== null && $stored !== $incoming) {
        return false;
    }
    if ($stored === null && $incoming !== null) {
        repo_execute($db, 'UPDATE deploy_package_runs SET total = ? WHERE id = ? AND total IS NULL', 'ii', [$incoming, (int) $run['id']]);
        return $incoming;
    }

    return $stored;
}

function repo_package_report_existing_event(mysqli $db, int $runId, int $seq, string $key): ?array
{
    $stmt = $db->prepare('SELECT event_seq, event_key, semantic_sha256 FROM deploy_package_run_events WHERE package_run_id = ? AND (event_seq = ? OR event_key = ?) FOR UPDATE');
    $stmt->bind_param('iis', $runId, $seq, $key);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($rows) > 1) {
        return ['event_seq' => -1, 'event_key' => '', 'semantic_sha256' => ''];
    }

    return $rows[0] ?? null;
}

/** @return array{status:int,accepted?:bool,deduplicated?:bool,error?:string} */
function repo_package_report_store_step(mysqli $db, array $run, array $report, string $key, string $hash, string $now): array
{
    $normal = (int) $report['step_index'] <= VIRTUSPHERE_PACKAGE_REPORT_NORMAL_DETAIL_LIMIT;
    if (!$normal && !$report['is_first_failure']) {
        repo_package_report_reject(422, 'detail_limit');
    }
    if ($report['is_first_failure'] && repo_scalar($db, 'SELECT COUNT(*) FROM deploy_package_step_results WHERE package_run_id = ? AND is_first_failure = 1', 'i', [(int) $run['id']]) > 0) {
        repo_package_report_reject(409, 'first_failure_conflict');
    }
    if ($run['completed_received_at'] !== null) {
        $countField = $report['result'] . '_count';
        $storedOfKind = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_package_step_results WHERE package_run_id = ? AND result = ?', 'is', [(int) $run['id'], $report['result']]);
        if ((int) $report['step_index'] > (int) ($run['last_processed_index'] ?? 0)
            || $storedOfKind >= (int) ($run[$countField] ?? 0)
        ) {
            repo_package_report_reject(409, 'completion_conflict');
        }
    }
    repo_package_report_insert_event($db, (int) $run['id'], $report, $key, $hash, $now);
    repo_package_report_insert_step($db, (int) $run['id'], $report, $normal ? 'normal' : 'first_failure');
    repo_execute($db, 'UPDATE deploy_package_runs SET last_evidence_at = ? WHERE id = ?', 'si', [$now, (int) $run['id']]);

    return ['status' => 200, 'accepted' => true, 'deduplicated' => false];
}

/** @return array{status:int,accepted?:bool,deduplicated?:bool,error?:string} */
function repo_package_report_store_completed(mysqli $db, array $run, array $report, string $key, string $hash, string $now): array
{
    if ($run['completed_received_at'] !== null) {
        repo_package_report_reject(409, 'event_conflict');
    }
    $stored = repo_fetch_one($db, "SELECT
        SUM(result = 'ok') AS ok_count,
        SUM(result = 'skip') AS skip_count,
        SUM(result = 'fail') AS fail_count,
        COUNT(*) AS stored_count,
        MAX(step_index) AS last_index
        FROM deploy_package_step_results WHERE package_run_id = ?", 'i', [(int) $run['id']]);
    if ($stored === null
        || (int) $stored['ok_count'] > (int) $report['ok_count']
        || (int) $stored['skip_count'] > (int) $report['skip_count']
        || (int) $stored['fail_count'] > (int) $report['fail_count']
        || (int) $stored['stored_count'] > (int) $report['processed_count']
        || ($stored['last_index'] !== null && (int) $stored['last_index'] > (int) ($report['last_processed_index'] ?? 0))
    ) {
        repo_package_report_reject(409, 'completion_conflict');
    }
    $first = $report['first_failure'];
    $existingFirst = repo_fetch_one($db, 'SELECT step_index, script_name, error_category, child_exit_code, detail_path FROM deploy_package_step_results WHERE package_run_id = ? AND is_first_failure = 1 FOR UPDATE', 'i', [(int) $run['id']]);
    if ($existingFirst !== null && !repo_package_report_first_failure_matches($existingFirst, $first)) {
        repo_package_report_reject(409, 'first_failure_conflict');
    }

    repo_package_report_insert_event($db, (int) $run['id'], $report, $key, $hash, $now);
    if ($first !== null && $existingFirst === null) {
        $step = $first + ['result' => 'fail', 'is_first_failure' => true, 'duration_ms' => null,
            'event_seq' => $report['event_seq']];
        repo_package_report_insert_step($db, (int) $run['id'], $step,
            (int) $first['step_index'] <= VIRTUSPHERE_PACKAGE_REPORT_NORMAL_DETAIL_LIMIT ? 'normal' : 'first_failure');
    }
    $clientCompleted = repo_package_report_sql_time((string) $report['event_at']);
    $stmt = $db->prepare('UPDATE deploy_package_runs SET last_evidence_at = ?, completed_received_at = ?, client_completed_at = ?, wrapper_result = ?, wrapper_exit_code = ?, detection_result = ?, processed_count = ?, ok_count = ?, skip_count = ?, fail_count = ?, last_processed_index = ?, payload_omitted_count = ?, wrapper_log_path = ?, reporting_log_path = ? WHERE id = ?');
    $stmt->bind_param('ssssisiiiiiissi', $now, $now, $clientCompleted, $report['wrapper_result'],
        $report['wrapper_exit_code'], $report['detection_result'], $report['processed_count'], $report['ok_count'],
        $report['skip_count'], $report['fail_count'], $report['last_processed_index'], $report['payload_omitted_count'],
        $report['wrapper_log_path'], $report['reporting_log_path'], $run['id']);
    $stmt->execute();

    return ['status' => 200, 'accepted' => true, 'deduplicated' => false];
}

function repo_package_report_insert_event(mysqli $db, int $runId, array $report, string $key, string $hash, string $now): void
{
    repo_execute($db, 'INSERT INTO deploy_package_run_events (package_run_id, event_seq, event_key, event_kind, semantic_sha256, received_at) VALUES (?, ?, ?, ?, ?, ?)',
        'iissss', [$runId, $report['event_seq'], $key, $report['event'], $hash, $now]);
}

function repo_package_report_insert_step(mysqli $db, int $runId, array $step, string $class): void
{
    repo_execute($db, 'INSERT INTO deploy_package_step_results (package_run_id, step_index, script_name, result, storage_class, is_first_failure, error_category, child_exit_code, duration_ms, detail_path, source_event_seq) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iisssisiisi', [$runId, $step['step_index'], $step['script_name'], $step['result'], $class,
            $step['is_first_failure'] ? 1 : 0, $step['error_category'], $step['child_exit_code'], $step['duration_ms'],
            $step['detail_path'], $step['event_seq']]);
}

function repo_package_report_first_failure_matches(array $stored, ?array $incoming): bool
{
    return $incoming !== null && (int) $stored['step_index'] === (int) $incoming['step_index']
        && (string) $stored['script_name'] === (string) $incoming['script_name']
        && ($stored['error_category'] ?? null) === ($incoming['error_category'] ?? null)
        && ($stored['child_exit_code'] === null ? null : (int) $stored['child_exit_code']) === ($incoming['child_exit_code'] ?? null)
        && ($stored['detail_path'] ?? null) === ($incoming['detail_path'] ?? null);
}

function repo_package_report_event_key(array $report): string
{
    return $report['event'] === VIRTUSPHERE_PACKAGE_REPORT_EVENT_STEP
        ? 'step:' . $report['step_index'] : (string) $report['event'];
}

function repo_package_report_fingerprint(array $report): string
{
    return hash('sha256', json_encode(repo_package_report_canonical($report), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
}

function repo_package_report_canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = repo_package_report_canonical($item);
    }

    return $value;
}

function repo_package_report_sql_time(string $wire): string
{
    return (new DateTimeImmutable($wire))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

/** @return never */
function repo_package_report_reject(int $status, string $error): never
{
    throw new PackageReportRefusal($status, $error);
}
