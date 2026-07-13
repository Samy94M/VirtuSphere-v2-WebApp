<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ansible_inventory.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/repo/status_events.php';
require_once __DIR__ . '/ssh.php';

final class DeployWorkerCancelled extends RuntimeException
{
}

function deploy_worker_options(array $argv): array
{
    $options = [
        'loop' => in_array('--loop', $argv, true),
        'once' => in_array('--once', $argv, true),
        'sleep' => VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS,
        'cleanup' => !in_array('--keep-local-artifacts', $argv, true),
    ];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--sleep=')) {
            $options['sleep'] = max(1, min(60, (int) substr($arg, 8)));
        }
    }

    if (!$options['loop']) {
        $options['once'] = true;
    }

    return $options;
}

function deploy_worker_main(array $argv): int
{
    $options = deploy_worker_options($argv);
    $workerId = deploy_worker_id();
    $db = deploy_worker_connect_db($options);

    do {
        try {
            $claimed = deploy_worker_run_once($db, $workerId, $options);
        } catch (mysqli_sql_exception $exception) {
            if ($options['once']) {
                throw $exception;
            }
            fwrite(STDERR, '[deploy-worker] Database error, reconnecting: ' . $exception->getMessage() . "\n");
            $db = deploy_worker_connect_db($options);
            continue;
        }
        if ($options['once']) {
            return $claimed ? 0 : 2;
        }
        if (!$claimed) {
            sleep((int) $options['sleep']);
        }
    } while (true);
}

function deploy_worker_connect_db(array $options): mysqli
{
    // In --loop mode the worker must survive MySQL restarts and slow stack
    // startups instead of exiting; --once keeps failing fast for tooling.
    $maxAttempts = $options['once'] ? 3 : 0;
    $attempt = 0;

    while (true) {
        $attempt++;
        try {
            return db(true);
        } catch (mysqli_sql_exception $exception) {
            if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
                throw $exception;
            }
            fwrite(STDERR, '[deploy-worker] Database not reachable (attempt ' . $attempt . '): ' . $exception->getMessage() . "\n");
            sleep(min(30, 2 * $attempt));
        }
    }
}

function deploy_worker_run_once(mysqli $db, string $workerId, array $options): bool
{
    deploy_worker_reap_stale_jobs($db);

    $job = repo_claim_next_deploy_job($db, $workerId);
    if ($job === null) {
        return false;
    }

    deploy_worker_process_job($db, $job, $workerId, $options);
    return true;
}

function deploy_worker_reap_stale_jobs(mysqli $db): void
{
    foreach (repo_reap_stale_deploy_jobs($db) as $job) {
        $payload = deploy_worker_payload($job);
        $vmIds = $payload['vm_ids'] ?? [];
        deploy_worker_mark_mission_vms($db, (int) $job['mission_id'], VIRTUSPHERE_LIFECYCLE_FAILED, 'deploy job ' . (int) $job['id'] . ' reaped after stale heartbeat', $vmIds);
    }
}

function deploy_worker_process_job(mysqli $db, array $job, string $workerId, array $options): void
{
    // System jobs (e.g. ESXi inventory) run a separate, mission-less path so the
    // deploy path below stays exactly as before (ADR-0023).
    if (deploy_worker_payload($job)['mode'] === VIRTUSPHERE_DEPLOY_MODE_INVENTORY) {
        deploy_worker_process_inventory_job($db, $job, $workerId, $options);
        return;
    }

    $jobId = (int) $job['id'];
    $localDir = null;

    $vmIds = deploy_worker_payload($job)['vm_ids'] ?? [];

    try {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing deploy artifacts.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        deploy_worker_mark_mission_vms($db, (int) $job['mission_id'], VIRTUSPHERE_LIFECYCLE_DEPLOYING, 'deploy job ' . $jobId . ' started', $vmIds);
        deploy_worker_assert_not_cancelled($db, $jobId);

        $esxiCredential = deploy_worker_credential($db, (int) $job['credential_esxi_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleCredential = deploy_worker_credential($db, (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($db, (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($db, (int) $ansibleCredential['id']);
        $apiBaseUrl = ansible_resolve_api_base_url($db);
        $payload = deploy_worker_payload($job);

        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible host preflight.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        $preflightBuffer = '';
        $preflightExitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command(), static function (string $chunk) use ($db, $jobId, $workerId, &$preflightBuffer): void {
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer, $chunk);
        }, 45);
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer);
        deploy_worker_assert_not_cancelled($db, $jobId);
        if ($preflightExitCode !== 0) {
            throw new RuntimeException('Ansible host preflight failed with exit code ' . $preflightExitCode . '.');
        }

        $artifacts = ansible_prepare_job_artifacts($db, $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy files prepared: ' . implode(', ', (array) $artifacts['files']));
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        deploy_worker_assert_not_cancelled($db, $jobId);

        // Autostart preflight (ADR-0025). Runs only when this job would write the
        // policy, and may drop the autostart step from a full pipeline without
        // failing the rest of it.
        $autostartEnabled = !empty($artifacts['autostart_enabled']);
        $writesAutostart = (string) $payload['mode'] === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART
            || ((string) $payload['mode'] === VIRTUSPHERE_DEPLOY_MODE_FULL && $autostartEnabled);
        if ($writesAutostart) {
            $autostartEnabled = deploy_worker_autostart_preflight($db, $jobId, (int) $esxiCredential['id'], (string) $payload['mode'], $autostartEnabled);
        }

        ssh_sftp_upload_directory($ansibleCredential, $ansibleSecret, $localDir, (string) $artifacts['remote_dir'], static function (string $line) use ($db, $jobId, $workerId): void {
            deploy_worker_heartbeat_tick($db, $jobId, $workerId);
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        });
        deploy_worker_assert_not_cancelled($db, $jobId);

        $command = ansible_remote_command((string) $artifacts['remote_dir'], $payload, $autostartEnabled);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible playbook sequence: ' . deploy_job_payload_summary((string) $job['payload_json']));
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        $buffer = '';
        $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $command, static function (string $chunk) use ($db, $jobId, $workerId, &$buffer): void {
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $chunk);
        });
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer);
        deploy_worker_assert_not_cancelled($db, $jobId);

        if ($exitCode !== 0) {
            throw new RuntimeException('Ansible command failed with exit code ' . $exitCode . '.');
        }

        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job succeeded.');
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        // A create/full deploy changed ESXi resource usage (new VMs, datastore
        // allocation): enqueue an inventory refresh for this credential (E3.4b).
        // Fail-soft and after the job is finalized, so it can never taint the
        // deploy result; the double-enqueue guard prevents pile-up.
        deploy_worker_refresh_inventory_after_deploy($db, $job);
    } catch (DeployWorkerCancelled $cancelled) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Worker stopped processing because the job was cancelled.');
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_STDERR, $message);
        deploy_worker_mark_mission_vms($db, (int) $job['mission_id'], VIRTUSPHERE_LIFECYCLE_FAILED, 'deploy job ' . $jobId . ' failed', $vmIds);
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
        deploy_worker_audit_outcome($db, $job, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
    } finally {
        repo_touch_deploy_job_heartbeat($db, $jobId, $workerId);
        if (!empty($options['cleanup'])) {
            ansible_cleanup_artifacts($localDir);
        }
    }
}

/**
 * Decides whether this job may write the ESXi autostart policy, from the cached
 * capability facts of the target credential (ADR-0025).
 *
 * The facts always land in the job log first, fresh or not, so the run is
 * explainable afterwards even when the verdict was "go ahead".
 *
 * Refusals need a FRESH fact, never a stale or absent one: the cache is a mirror
 * and must not block on an assumption (ADR-0023). Consequences differ by mode,
 * because the operator asked for different things:
 *  - `autostart` is a request to write the policy. If it provably cannot work,
 *    fail loudly rather than report a success that changed nothing.
 *  - `full` is a request to deploy. A host that ignores autostart should not cost
 *    the operator the VMs, so the step is dropped and the pipeline continues.
 *
 * @return bool whether the autostart playbook stays in the sequence
 */
function deploy_worker_autostart_preflight(mysqli $db, int $jobId, int $credentialId, string $mode, bool $autostartEnabled): bool
{
    $state = repo_esxi_inventory_state($db, $credentialId);
    $intervalHours = esxi_inventory_interval_hours($db);
    $preflight = esxi_autostart_preflight($state, $intervalHours);
    $fresh = esxi_capabilities_fresh($state, $intervalHours);
    repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line($preflight['facts'], $fresh));

    if ($preflight['verdict'] === 'block') {
        throw new RuntimeException('Autostart cannot be written: this ESXi host reports a free licence, whose API is read-only. Assign a licensed edition or turn the mission autostart off.');
    }

    if ($preflight['verdict'] === 'skip') {
        if ($mode === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART) {
            throw new RuntimeException('Autostart cannot be written: this ESXi host is part of a vSphere HA cluster, where ESXi disables autostart. Use the HA restart priority instead.');
        }
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Skipping the autostart step: the target host is in a vSphere HA cluster, where autostart has no effect. The rest of the pipeline runs unchanged.');

        return false;
    }

    if (!$fresh) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Autostart preflight ran on stale or missing capability facts. Proceeding: ESXi remains the authority and will reject the write if it cannot perform it.');
    }

    return $autostartEnabled || $mode === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART;
}

function deploy_worker_process_inventory_job(mysqli $db, array $job, string $workerId, array $options): void
{
    $jobId = (int) $job['id'];
    $credentialId = (int) $job['credential_esxi_id'];
    $localDir = null;
    $failCategory = null;
    $fullOutput = '';

    try {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing ESXi inventory fetch.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        deploy_worker_assert_not_cancelled($db, $jobId);

        $esxiCredential = deploy_worker_credential($db, $credentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleCredential = deploy_worker_credential($db, (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($db, (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($db, (int) $ansibleCredential['id']);
        $apiBaseUrl = ansible_resolve_api_base_url($db);

        $preflightBuffer = '';
        $preflightExit = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command(), static function (string $chunk) use ($db, $jobId, $workerId, &$preflightBuffer): void {
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer, $chunk);
        }, 45);
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer);
        deploy_worker_assert_not_cancelled($db, $jobId);
        if ($preflightExit !== 0) {
            $failCategory = VIRTUSPHERE_INVENTORY_ERROR_SSH;
            throw new RuntimeException('Ansible host preflight failed with exit code ' . $preflightExit . '.');
        }

        $artifacts = ansible_prepare_inventory_artifacts($db, $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        ssh_sftp_upload_directory($ansibleCredential, $ansibleSecret, $localDir, (string) $artifacts['remote_dir'], static function (string $line) use ($db, $jobId, $workerId): void {
            deploy_worker_heartbeat_tick($db, $jobId, $workerId);
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        });
        deploy_worker_assert_not_cancelled($db, $jobId);

        $command = ansible_inventory_remote_command((string) $artifacts['remote_dir'], !empty(deploy_worker_payload($job)['verbose']));
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running ESXi inventory playbook.');
        $buffer = '';
        $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $command, static function (string $chunk) use ($db, $jobId, $workerId, &$buffer, &$fullOutput): void {
            $fullOutput .= $chunk;
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $chunk);
        });
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer);
        deploy_worker_assert_not_cancelled($db, $jobId);

        if ($exitCode !== 0) {
            $failCategory = ansible_categorize_inventory_error($fullOutput, $exitCode);
            throw new RuntimeException('Inventory fetch failed (' . $failCategory . ').');
        }

        // Parse marker (may throw -> "parse") then apply the cache atomically.
        $parsed = ansible_parse_inventory_output($fullOutput);
        $summary = repo_esxi_inventory_apply($db, $credentialId, $parsed);
        repo_esxi_inventory_record_success($db, $credentialId, $parsed['capabilities'] ?? null);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line($parsed['capabilities'] ?? [], true));
        // VLAN catalog is ESXi-owned (E4b): resync from the union of cached
        // portgroups after every successful pull (retire not delete).
        $vlanSync = repo_esxi_vlan_sync($db);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'VLAN catalog sync: +' . $vlanSync['upserted'] . ' new, ' . $vlanSync['unretired'] . ' un-retired, ' . $vlanSync['retired'] . ' retired');

        $parts = [];
        foreach ($summary as $kind => $info) {
            $parts[] = $kind . ': ' . ($info['kept_empty'] ? 'kept (empty result)' : ($info['written'] . ' items'));
        }
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Inventory updated for credential ' . $credentialId . ' - ' . implode(', ', $parts));
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
    } catch (DeployWorkerCancelled $cancelled) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Inventory job cancelled.');
    } catch (Throwable $exception) {
        $category = $failCategory ?? VIRTUSPHERE_INVENTORY_ERROR_PARSE;
        try {
            // An auth failure pauses all future auto-pulls of this credential to
            // stop the ESXi account from locking out (ADR-0023). That pause blocks
            // deploys of the kind too, so it belongs in the audit trail, once per
            // onset: log only when this failure is what turned the pause on.
            $wasPaused = ($state = repo_esxi_inventory_state($db, $credentialId)) !== null
                && (int) $state['paused_until_credential_change'] === 1;
            repo_esxi_inventory_record_failure($db, $credentialId, $category);
            if ($category === VIRTUSPHERE_INVENTORY_ERROR_AUTH && !$wasPaused) {
                audit($db, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'esxi inventory auto-pull paused for credential id ' . $credentialId . ' after an authentication failure; save the credential to resume', null, 'cli');
            }
        } catch (Throwable $stateError) {
            error_log('[inventory] state update failed: ' . $stateError->getMessage());
        }
        $message = '[' . $category . '] ' . $exception->getMessage();
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_STDERR, $message);
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
    } finally {
        repo_touch_deploy_job_heartbeat($db, $jobId, $workerId);
        if (!empty($options['cleanup'])) {
            ansible_cleanup_artifacts($localDir);
        }
    }
}

function deploy_worker_finish_job(mysqli $db, int $jobId, string $workerId, string $status, ?string $lastError = null): void
{
    if (!repo_finish_deploy_job($db, $jobId, $workerId, $status, $lastError)) {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Terminal status ' . $status . ' skipped because the job is no longer locked by this worker.');
    }
}

/**
 * Records a finished MISSION deploy in the audit trail, so the logs page shows a
 * job ending, not just being queued. Attributed to the operator who queued it
 * (the job row carries user_id) even though the worker runs headless.
 *
 * Deliberately only for mission deploys, not the mission-less inventory system
 * jobs: those run on the interval and a "succeeded" line per credential every few
 * hours would bury real events. An inventory failure is already recorded in the
 * fetch-state row, and its one durable consequence, the auth pause, is audited
 * where it is set/cleared. Called after finish_job and never lets an audit hiccup
 * escape into the job's finally block.
 */
function deploy_worker_audit_outcome(mysqli $db, array $job, string $status, ?string $error = null): void
{
    if (($job['mission_id'] ?? null) === null) {
        return;
    }
    try {
        $userId = isset($job['user_id']) ? (int) $job['user_id'] : null;
        $mode = deploy_worker_payload($job)['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL;
        $message = 'deploy job id ' . (int) $job['id'] . ' (mission id ' . (int) $job['mission_id']
            . ', mode ' . audit_snippet($mode, 24) . ') ' . $status;
        if ($status === VIRTUSPHERE_DEPLOY_STATUS_FAILED && $error !== null && $error !== '') {
            $message .= ': ' . audit_snippet($error, 120);
        }
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, $message, $userId, 'cli');
    } catch (Throwable $exception) {
        error_log('[deploy-worker] outcome audit failed: ' . $exception->getMessage());
    }
}

/**
 * After a successful resource-changing deploy (create/full), enqueue an ESXi
 * inventory refresh for the job's credential so datastore usage etc. catch up
 * without waiting for the interval (ADR-0023, E3.4b). Fail-soft: a scheduling
 * hiccup must never taint the already-finished deploy job. The double-enqueue
 * guard in repo_create_system_job prevents pile-up with the interval automation.
 */
function deploy_worker_refresh_inventory_after_deploy(mysqli $db, array $job): void
{
    $mode = deploy_worker_payload($job)['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL;
    $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
    if ($credentialId <= 0 || !in_array($mode, VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES, true)) {
        return;
    }
    try {
        esxi_inventory_enqueue_for_credential($db, $credentialId);
    } catch (Throwable $exception) {
        error_log('[deploy-worker] post-deploy inventory refresh enqueue failed: ' . $exception->getMessage());
    }
}

function deploy_worker_credential(mysqli $db, int $credentialId, string $type): array
{
    return repo_deploy_assert_credential_type($db, $credentialId, $type);
}

function deploy_worker_payload(array $job): array
{
    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        return ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'verbose' => false];
    }

    return deploy_job_payload($payload);
}

function deploy_worker_assert_not_cancelled(mysqli $db, int $jobId): void
{
    $job = repo_deploy_job($db, $jobId);
    if ($job !== null && (string) $job['status'] === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED) {
        throw new DeployWorkerCancelled('Deploy job was cancelled.');
    }
}

/**
 * @param int[] $vmIds Empty means "all VMs of the mission".
 */
function deploy_worker_mark_mission_vms(mysqli $db, int $missionId, string $lifecycleState, string $note, array $vmIds = []): void
{
    virtusphere_assert_lifecycle_state($lifecycleState);
    if ($missionId <= 0) {
        return;
    }

    $vmIds = array_values(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0));
    if ($vmIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($vmIds), '?'));
        $stmt = $db->prepare('SELECT id, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? AND id IN (' . $placeholders . ') ORDER BY id');
        $stmt->bind_param('i' . str_repeat('i', count($vmIds)), $missionId, ...$vmIds);
    } else {
        $stmt = $db->prepare('SELECT id, mecm_sync_state, vm_status FROM deploy_vms WHERE mission_id = ? ORDER BY id');
        $stmt->bind_param('i', $missionId);
    }
    $stmt->execute();
    $vms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($vms as $vm) {
        $vmId = (int) $vm['id'];
        $mecmSyncState = (string) ($vm['mecm_sync_state'] ?? VIRTUSPHERE_MECM_NOT_READY);
        $legacyStatus = (string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED);
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $lifecycleState, $vmId);
        $stmt->execute();
        repo_record_vm_status_event($db, $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, $note);
    }
}

function deploy_worker_heartbeat_tick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds = VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS): void
{
    static $lastHeartbeat = [];

    $key = $jobId . ':' . $workerId;
    $now = time();
    if ($intervalSeconds > 0 && isset($lastHeartbeat[$key]) && ($now - $lastHeartbeat[$key]) < $intervalSeconds) {
        return;
    }

    if (repo_touch_deploy_job_heartbeat($db, $jobId, $workerId)) {
        $lastHeartbeat[$key] = $now;
    }
}

function deploy_worker_log_stream_chunk(mysqli $db, int $jobId, string $workerId, string $stream, string &$buffer, string $chunk): void
{
    deploy_worker_heartbeat_tick($db, $jobId, $workerId);
    $buffer .= str_replace("\r\n", "\n", str_replace("\r", "\n", $chunk));
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        repo_append_deploy_job_log($db, $jobId, $stream, $line);
    }
}

function deploy_worker_log_stream_flush(mysqli $db, int $jobId, string $workerId, string $stream, string &$buffer): void
{
    if ($buffer === '') {
        return;
    }

    repo_append_deploy_job_log($db, $jobId, $stream, $buffer);
    deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
    $buffer = '';
}

function deploy_worker_id(): string
{
    $host = gethostname() ?: 'worker';
    return $host . ':' . getmypid();
}

exit(deploy_worker_main($argv));
