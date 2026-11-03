<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/remote_execution.php';
require_once __DIR__ . '/repo/deploy_remote_execution.php';
require_once __DIR__ . '/ssh.php';

const VIRTUSPHERE_REMOTE_LAUNCHER_PATH = '~/.local/libexec/virtusphere/virtusphere_remote_launcher.py';
const VIRTUSPHERE_REMOTE_OBSERVER_PATH = '~/.local/libexec/virtusphere/virtusphere_remote_observer.py';

function remote_inventory_launch_command(array $execution): string
{
    $manifest = (string) ($execution['remote_dir'] ?? '') . '/manifest.json';
    $token = (string) ($execution['run_token'] ?? '');
    if ($manifest === '/manifest.json' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        throw new InvalidArgumentException('Remote inventory launch identity is incomplete.');
    }
    return VIRTUSPHERE_REMOTE_LAUNCHER_PATH . ' ' . ansible_sh_quote($manifest) . ' ' . ansible_sh_quote($token);
}

function remote_inventory_observer_command(array $execution): string
{
    $manifest = (string) ($execution['remote_dir'] ?? '') . '/manifest.json';
    $token = (string) ($execution['run_token'] ?? '');
    $offset = (int) ($execution['log_offset'] ?? -1);
    if ($manifest === '/manifest.json' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || $offset < 0) {
        throw new InvalidArgumentException('Remote inventory observation identity is incomplete.');
    }
    return VIRTUSPHERE_REMOTE_OBSERVER_PATH . ' ' . ansible_sh_quote($manifest) . ' ' . ansible_sh_quote($token) . ' ' . $offset;
}

function remote_inventory_upload(array $credential, string $secret, string $localDir, array $execution, callable $logger): void
{
    $remoteDir = (string) ($execution['remote_dir'] ?? '');
    if ($localDir === '' || !is_dir($localDir) || $remoteDir === '') {
        throw new InvalidArgumentException('Remote inventory upload paths are incomplete.');
    }
    ssh_sftp_upload_directory($credential, $secret, $localDir, $remoteDir, $logger);
}

/** Launch is idempotent in the remote launcher; exit 3 means reattach required. */
function remote_inventory_launch(array $credential, string $secret, array $execution): int
{
    $capture = ssh_execute_capture($credential, $secret, remote_inventory_launch_command($execution), 30);
    $exitCode = (int) $capture['exit_code'];
    if (!in_array($exitCode, [0, 3], true)) {
        throw new RuntimeException('Remote inventory launcher rejected the durable handle.');
    }
    return $exitCode;
}

/** @param array<string, mixed> $fence @param list<string> $secrets @return array<string, mixed> */
function remote_inventory_poll(
    mysqli $db,
    array $credential,
    string $secret,
    array $execution,
    array $fence,
    array $secrets = [],
    ?string $schemaPath = null
): array {
    $capture = ssh_execute_capture($credential, $secret, remote_inventory_observer_command($execution), 30);
    if ((int) $capture['exit_code'] !== 0) {
        throw new RuntimeException('Remote inventory observer rejected the durable handle.');
    }
    $expected = [
        'instance_id' => (string) $execution['instance_id'],
        'generation_id' => (string) $execution['generation_id'],
        'run_token' => (string) $execution['run_token'],
        'unit_name' => (string) $execution['unit_name'],
    ];
    $observation = remote_protocol_decode('observation', trim((string) $capture['output']), $expected, $schemaPath);
    $offset = (int) $observation['offset'];
    if ($offset !== (int) $execution['log_offset']) {
        throw new RuntimeException('Remote inventory observation starts at a foreign log offset.');
    }
    $chunk = base64_decode((string) $observation['output_b64'], true);
    if (!is_string($chunk) || strlen($chunk) > VIRTUSPHERE_REMOTE_OUTPUT_CHUNK_MAX_BYTES
        || (int) $observation['next_offset'] !== $offset + strlen($chunk)) {
        throw new RuntimeException('Remote inventory observation has an invalid output range.');
    }
    if ($chunk !== '') {
        repo_import_remote_inventory_output($db, (int) $execution['id'], $offset, $chunk, $fence, array_values(array_unique(array_merge($secrets, [$secret]))));
    }
    if (isset($observation['heartbeat_json'])) {
        remote_protocol_decode('heartbeat', (string) $observation['heartbeat_json'], ['run_token' => (string) $execution['run_token']], $schemaPath);
    }
    $unitLost = in_array((string) $observation['unit_state'], ['inactive', 'not_found'], true)
        && isset($observation['started_json']) && !isset($observation['result_json']);
    return repo_observe_remote_inventory_execution(
        $db,
        (int) $execution['id'],
        isset($observation['launch_json']) ? (string) $observation['launch_json'] : null,
        isset($observation['started_json']) ? (string) $observation['started_json'] : null,
        isset($observation['result_json']) ? (string) $observation['result_json'] : null,
        $unitLost,
        $fence,
        $schemaPath
    );
}

/** @param array<string, mixed> $fence */
function remote_inventory_cleanup(mysqli $db, array $credential, string $secret, array $execution, array $fence): void
{
    repo_begin_remote_inventory_cleanup($db, (int) $execution['id'], $fence);
    try {
        $exitCode = ssh_execute_capture($credential, $secret, ansible_remote_cleanup_command((string) $execution['remote_dir']), VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS)['exit_code'];
        if ((int) $exitCode !== 0) {
            throw new RuntimeException('Remote inventory cleanup command failed.');
        }
        repo_record_remote_inventory_cleanup($db, (int) $execution['id'], true, $fence);
    } catch (Throwable $exception) {
        $error = deploy_worker_redact_secrets($exception->getMessage(), [$secret]);
        repo_record_remote_inventory_cleanup($db, (int) $execution['id'], false, $fence, mb_strcut($error, 0, 1024, 'UTF-8'));
        throw $exception;
    }
}
