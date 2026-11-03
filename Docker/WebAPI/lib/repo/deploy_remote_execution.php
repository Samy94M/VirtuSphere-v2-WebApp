<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../remote_execution.php';
require_once __DIR__ . '/deploy_job_worker.php';
require_once __DIR__ . '/deploy_remote_mode_activation.php';
require_once __DIR__ . '/helpers.php';

/** @return array<string, mixed>|null */
function repo_deploy_remote_execution(mysqli $db, int $executionId, bool $lock = false): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT id, job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir, LOWER(HEX(instance_id)) AS instance_id, LOWER(HEX(generation_id)) AS generation_id, controller_state, effect_state, reconciliation_state, cleanup_state, launch_intent_at, started_at, last_observed_at, finished_at, exit_code, exit_signal, result_sha256, log_offset, output_truncated, recovery_count, last_probe_category, last_probe_detail, cleanup_due_at, cleanup_lease_until, cleanup_attempts, cleanup_auto_attempts, cleanup_last_error, cleanup_finished_at, created_at, updated_at FROM deploy_remote_executions WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''),
        'i',
        [$executionId]
    );
}

/** @param array<string, mixed> $fence */
function repo_assert_remote_execution_fence(mysqli $db, int $jobId, string $generationId, array $fence): bool
{
    if (($fence['worker_id'] ?? '') === '' || preg_match('/^[a-f0-9]{32}$/', (string) ($fence['lock_token'] ?? '')) !== 1 || (int) ($fence['worker_epoch'] ?? -1) < 0) {
        throw new InvalidArgumentException('Remote execution fence is malformed.');
    }
    $row = repo_fetch_one(
        $db,
        "SELECT j.status, j.locked_by, j.lock_token, j.worker_epoch, LOWER(HEX(j.execution_generation_id)) AS job_generation_id, l.epoch AS lease_epoch, l.owner_token, l.claims_paused, LOWER(HEX(r.current_generation_id)) AS runtime_generation_id FROM deploy_jobs j JOIN deploy_worker_leases l ON l.lease_name = 'deploy-worker' JOIN deploy_runtime_identity r ON r.id = 1 WHERE j.id = ? FOR UPDATE",
        'i',
        [$jobId]
    );
    if ($row === null || !in_array((string) $row['status'], [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)
        || !hash_equals((string) $fence['worker_id'], (string) $row['locked_by'])
        || !hash_equals((string) $fence['lock_token'], (string) $row['lock_token'])
        || !hash_equals((string) $fence['lock_token'], (string) $row['owner_token'])
        || (int) $fence['worker_epoch'] !== (int) $row['worker_epoch']
        || (int) $fence['worker_epoch'] !== (int) $row['lease_epoch']
        || !hash_equals($generationId, (string) $row['job_generation_id'])
        || !hash_equals($generationId, (string) $row['runtime_generation_id'])) {
        throw new RuntimeException('Remote execution fence is stale or foreign.');
    }
    return (int) $row['claims_paused'] === 1;
}

/** @return array{run_token:string,unit_name:string,remote_dir:string} */
function remote_inventory_identifiers(int $jobId, int $attempt, string $instanceId, string $generationId, string $stateRoot, ?string $runToken = null): array
{
    if ($jobId <= 0 || $attempt <= 0 || preg_match('/^[a-f0-9]{32}$/', $instanceId) !== 1 || preg_match('/^[a-f0-9]{32}$/', $generationId) !== 1) {
        throw new InvalidArgumentException('Remote inventory identity is incomplete.');
    }
    if ($stateRoot === '' || $stateRoot[0] !== '/' || str_contains($stateRoot, '..') || str_ends_with($stateRoot, '/')) {
        throw new InvalidArgumentException('Remote inventory state root is not canonical.');
    }
    $token = $runToken ?? bin2hex(random_bytes(16));
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        throw new InvalidArgumentException('Remote inventory run token is malformed.');
    }
    return [
        'run_token' => $token,
        'unit_name' => 'virtusphere-j' . $jobId . '-a' . $attempt . '-inventory-' . substr($token, 0, 12) . '.service',
        'remote_dir' => $stateRoot . '/' . $instanceId . '/' . $generationId . '/jobs/' . $jobId . '/' . $attempt . '/inventory/' . $token,
    ];
}

/**
 * Creates the unique durable handle only after every later 8R-S value is
 * explicitly supplied. There is intentionally no current worker call site.
 *
 * @return array<string, mixed>
 */
function repo_prepare_remote_inventory_execution(
    mysqli $db,
    int $jobId,
    int $ansibleCredentialId,
    string $instanceId,
    string $stateRoot,
    array $fence
): array {
    return repo_transaction($db, static function () use ($db, $jobId, $ansibleCredentialId, $instanceId, $stateRoot, $fence): array {
        $job = repo_fetch_one(
            $db,
            "SELECT id, attempts, status, payload_json, credential_ansible_id, execution_contract, LOWER(HEX(execution_generation_id)) AS execution_generation_id FROM deploy_jobs WHERE id = ? FOR UPDATE",
            'i',
            [$jobId]
        );
        if ($job === null || !in_array((string) $job['status'], [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)) {
            throw new RuntimeException('Remote inventory job is not active.');
        }
        $payload = json_decode((string) $job['payload_json'], true);
        if (!is_array($payload) || ($payload['mode'] ?? null) !== VIRTUSPHERE_DEPLOY_MODE_INVENTORY || (int) $job['credential_ansible_id'] !== $ansibleCredentialId) {
            throw new RuntimeException('Remote inventory job scope does not match.');
        }
        $activation = repo_fetch_one(
            $db,
            'SELECT state, contract_version FROM deploy_remote_mode_activations WHERE credential_ansible_id = ? AND mode = ? FOR UPDATE',
            'is',
            [$ansibleCredentialId, VIRTUSPHERE_DEPLOY_MODE_INVENTORY]
        );
        $activationContract = $activation === null ? null : remote_activation_contract($activation);
        if ($activationContract !== VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE
            || !in_array((string) ($activation['state'] ?? ''), [VIRTUSPHERE_REMOTE_ACTIVATION_PILOT, VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED], true)
            || (string) $job['execution_contract'] !== VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
            throw new RuntimeException('Remote inventory activation is not site-approved.');
        }
        $runtime = repo_fetch_one($db, 'SELECT LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE');
        $generation = (string) ($runtime['generation_id'] ?? '');
        if ($generation === '' || !hash_equals($generation, (string) $job['execution_generation_id'])) {
            throw new RuntimeException('Remote inventory generation is stale or missing.');
        }
        if (repo_assert_remote_execution_fence($db, $jobId, $generation, $fence)) {
            throw new RuntimeException('Remote inventory claims are paused.');
        }
        $attempt = (int) $job['attempts'];
        $identity = remote_inventory_identifiers($jobId, $attempt, $instanceId, $generation, $stateRoot);
        $step = VIRTUSPHERE_DEPLOY_MODE_INVENTORY;
        $protocol = VIRTUSPHERE_REMOTE_PROTOCOL_VERSION;
        $controller = 'prepared';
        $effect = 'not_started';
        $reconciliation = 'not_required';
        $cleanup = 'pending';
        $instanceBinary = hex2bin($instanceId);
        $generationBinary = hex2bin($generation);
        $stmt = $db->prepare('INSERT INTO deploy_remote_executions (job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir, instance_id, generation_id, controller_state, effect_state, reconciliation_state, cleanup_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisisssssssss', $jobId, $attempt, $step, $protocol, $identity['run_token'], $identity['unit_name'], $identity['remote_dir'], $instanceBinary, $generationBinary, $controller, $effect, $reconciliation, $cleanup);
        $stmt->execute();
        $execution = repo_deploy_remote_execution($db, (int) $db->insert_id, false);
        if ($execution === null) {
            throw new RuntimeException('Prepared remote inventory handle cannot be read back.');
        }
        return $execution;
    });
}

/** @return array<string, mixed> */
function repo_observe_remote_inventory_execution(
    mysqli $db,
    int $executionId,
    ?string $launchJson,
    ?string $startedJson,
    ?string $resultJson,
    bool $transportLost,
    array $fence,
    ?string $schemaPath = null
): array {
    $outcome = repo_transaction($db, static function () use ($db, $executionId, $launchJson, $startedJson, $resultJson, $transportLost, $fence, $schemaPath): array {
        $row = repo_deploy_remote_execution($db, $executionId, true);
        if ($row === null || (string) $row['step_key'] !== VIRTUSPHERE_DEPLOY_MODE_INVENTORY) {
            throw new RuntimeException('Remote inventory execution is missing.');
        }
        repo_assert_remote_execution_fence($db, (int) $row['job_id'], (string) $row['generation_id'], $fence);
        $expected = ['run_token' => (string) $row['run_token'], 'unit_name' => (string) $row['unit_name']];
        try {
            $launch = $launchJson === null ? null : remote_protocol_decode('launch', $launchJson, $expected, $schemaPath);
            $started = $startedJson === null ? null : remote_protocol_decode('started', $startedJson, $expected, $schemaPath);
            $result = $resultJson === null ? null : remote_protocol_decode('result', $resultJson, $expected, $schemaPath);
            $next = remote_inventory_observation($row, $launch, $started, $result, $transportLost);
        } catch (Throwable $exception) {
            repo_execute($db, "UPDATE deploy_remote_executions SET controller_state = 'protocol_error', effect_state = 'unknown', reconciliation_state = 'manual_required', last_probe_category = 'protocol', last_probe_detail = ?, last_observed_at = NOW(6) WHERE id = ?", 'si', [mb_strcut($exception->getMessage(), 0, 1024, 'UTF-8'), $executionId]);
            return [
                'row' => repo_deploy_remote_execution($db, $executionId, false),
                'error' => $exception,
            ];
        }
        $exitCode = $next['exit_code'];
        $truncated = $next['output_truncated'] ? 1 : 0;
        $resultSha256 = $resultJson === null ? null : hash('sha256', $resultJson);
        $hasLaunch = $launch !== null ? 1 : 0;
        $hasStarted = $started !== null ? 1 : 0;
        $finished = str_starts_with($next['controller_state'], 'exited_') ? 'NOW(6)' : 'NULL';
        $sql = 'UPDATE deploy_remote_executions SET controller_state = ?, effect_state = ?, reconciliation_state = ?, cleanup_state = ?, exit_code = ?, output_truncated = ?, result_sha256 = COALESCE(?, result_sha256), last_observed_at = NOW(6), launch_intent_at = CASE WHEN ? = 1 THEN COALESCE(launch_intent_at, NOW(6)) ELSE launch_intent_at END, started_at = CASE WHEN ? = 1 THEN COALESCE(started_at, NOW(6)) ELSE started_at END, finished_at = ' . $finished . ' WHERE id = ?';
        repo_execute($db, $sql, 'ssssiisiii', [$next['controller_state'], $next['effect_state'], $next['reconciliation_state'], $next['cleanup_state'], $exitCode, $truncated, $resultSha256, $hasLaunch, $hasStarted, $executionId]);
        return [
            'row' => repo_deploy_remote_execution($db, $executionId, false),
            'error' => null,
        ];
    });
    if ($outcome['error'] instanceof Throwable) {
        throw $outcome['error'];
    }
    return is_array($outcome['row']) ? $outcome['row'] : throw new RuntimeException('Observed remote execution disappeared.');
}

/** @param list<string> $secrets */
function repo_import_remote_inventory_output(mysqli $db, int $executionId, int $offset, string $chunk, array $fence, array $secrets = []): int
{
    if ($offset < 0 || $chunk === '' || strlen($chunk) > VIRTUSPHERE_REMOTE_OUTPUT_CHUNK_MAX_BYTES) {
        throw new InvalidArgumentException('Remote output chunk is empty, oversized, or has an invalid offset.');
    }
    $outcome = repo_transaction($db, static function () use ($db, $executionId, $offset, $chunk, $fence, $secrets): array {
        $row = repo_deploy_remote_execution($db, $executionId, true);
        if ($row === null) {
            throw new RuntimeException('Remote execution is missing.');
        }
        repo_assert_remote_execution_fence($db, (int) $row['job_id'], (string) $row['generation_id'], $fence);
        $stored = (int) $row['log_offset'];
        if ($offset < $stored) {
            return ['offset' => $stored, 'gap' => false];
        }
        if ($offset > $stored) {
            repo_execute($db, "UPDATE deploy_remote_executions SET controller_state = 'protocol_error', effect_state = 'unknown', reconciliation_state = 'manual_required', last_probe_category = 'log_gap' WHERE id = ?", 'i', [$executionId]);
            return ['offset' => $stored, 'gap' => true];
        }
        $redacted = deploy_worker_redact_secrets($chunk, $secrets);
        repo_insert_deploy_job_log_unlocked($db, (int) $row['job_id'], VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $redacted);
        $next = $stored + strlen($chunk);
        repo_execute($db, 'UPDATE deploy_remote_executions SET log_offset = ?, last_observed_at = NOW(6) WHERE id = ? AND log_offset = ?', 'iii', [$next, $executionId, $stored]);
        return ['offset' => $next, 'gap' => false];
    });
    if ($outcome['gap']) {
        throw new RuntimeException('Remote output offset contains a gap.');
    }
    return (int) $outcome['offset'];
}

function repo_mark_remote_inventory_reconciled(mysqli $db, int $executionId, bool $success, array $fence): void
{
    repo_transaction($db, static function () use ($db, $executionId, $success, $fence): void {
        $row = repo_deploy_remote_execution($db, $executionId, true);
        if ($row === null || !str_starts_with((string) $row['controller_state'], 'exited_') || (string) $row['reconciliation_state'] !== 'pending') {
            throw new RuntimeException('Remote inventory result is not ready for reconciliation.');
        }
        repo_assert_remote_execution_fence($db, (int) $row['job_id'], (string) $row['generation_id'], $fence);
        remote_execution_assert_transition('reconciliation', 'pending', 'running');
        $effect = $success ? 'goal_verified' : 'divergence_verified';
        remote_execution_assert_transition('effect', (string) $row['effect_state'], $effect);
        $resolution = $success ? 'resolved_success' : 'resolved_failure';
        remote_execution_assert_transition('reconciliation', 'running', $resolution);
        remote_execution_assert_transition('cleanup', (string) $row['cleanup_state'], 'eligible');
        repo_execute($db, 'UPDATE deploy_remote_executions SET effect_state = ?, reconciliation_state = ?, cleanup_state = ? WHERE id = ?', 'sssi', [$effect, $resolution, 'eligible', $executionId]);
    });
}

function repo_begin_remote_inventory_cleanup(mysqli $db, int $executionId, array $fence): void
{
    repo_transaction($db, static function () use ($db, $executionId, $fence): void {
        $row = repo_deploy_remote_execution($db, $executionId, true);
        if ($row === null || (string) $row['cleanup_state'] !== 'eligible' || !in_array((string) $row['reconciliation_state'], ['resolved_success', 'resolved_failure'], true)) {
            throw new RuntimeException('Remote inventory cleanup is not eligible.');
        }
        repo_assert_remote_execution_fence($db, (int) $row['job_id'], (string) $row['generation_id'], $fence);
        remote_execution_assert_transition('cleanup', 'eligible', 'running');
        repo_execute($db, "UPDATE deploy_remote_executions SET cleanup_state = 'running', cleanup_attempts = cleanup_attempts + 1, cleanup_auto_attempts = cleanup_auto_attempts + 1, cleanup_last_error = NULL WHERE id = ?", 'i', [$executionId]);
    });
}

function repo_record_remote_inventory_cleanup(mysqli $db, int $executionId, bool $cleaned, array $fence, ?string $error = null): void
{
    repo_transaction($db, static function () use ($db, $executionId, $cleaned, $fence, $error): void {
        $row = repo_deploy_remote_execution($db, $executionId, true);
        if ($row === null || (string) $row['cleanup_state'] !== 'running') {
            throw new RuntimeException('Remote inventory cleanup is not reserved.');
        }
        repo_assert_remote_execution_fence($db, (int) $row['job_id'], (string) $row['generation_id'], $fence);
        $state = $cleaned ? 'cleaned' : 'failed';
        remote_execution_assert_transition('cleanup', 'running', $state);
        repo_execute($db, 'UPDATE deploy_remote_executions SET cleanup_state = ?, cleanup_last_error = ?, cleanup_finished_at = CASE WHEN ? = \'cleaned\' THEN NOW(6) ELSE NULL END WHERE id = ?', 'sssi', [$state, $error, $state, $executionId]);
    });
}
