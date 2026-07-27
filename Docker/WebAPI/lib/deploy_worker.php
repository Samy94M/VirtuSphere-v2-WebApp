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
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/worker_heartbeat.php';

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
        worker_heartbeat_touch();
        try {
            deploy_worker_report_alive($db);
            $claimed = deploy_worker_run_once($db, $workerId, $options);
        } catch (mysqli_sql_exception $exception) {
            if ($options['once']) {
                throw $exception;
            }
            fwrite(STDERR, '[deploy-worker] Database error, reconnecting: ' . $exception->getMessage() . "\n");
            $db = deploy_worker_connect_db($options);
            // Sleep before retrying. `continue` used to skip it, so a PERMANENT
            // SQL error (a dropped grant, a full disk, a schema mismatch) turned
            // the loop into a hot spin: it reconnected and failed thousands of
            // times a second, filling the log and pinning a core, and nothing in
            // the portal said anything at all. The reconnect helper waits on its
            // own attempts, but a successful reconnect followed by a failing query
            // never reached it.
            sleep((int) $options['sleep']);
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
            // Waiting out a DB restart is a healthy worker state (AP8).
            worker_heartbeat_touch();
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

    // ADR-0032: every log line this job produces carries the job's stored
    // correlation id; a legacy job without one falls back to the worker's
    // process id. Dropped again in finally so the ids of two consecutive
    // jobs cannot bleed into each other.
    virtusphere_correlation_adopt(isset($job['correlation_id']) ? (string) $job['correlation_id'] : null);
    try {
        deploy_worker_process_job($db, $job, $workerId, $options);
    } finally {
        virtusphere_correlation_adopt(null);
    }
    return true;
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
    $esxiSecret = null;
    $ansibleSecret = null;

    $vmIds = deploy_worker_payload($job)['vm_ids'] ?? [];

    // Time-based heartbeat (AP6): the bounded SSH transport calls this on every
    // silent read slice, so a playbook that is busy without printing (a long
    // vmware_guest clone) keeps the job alive for the stale-heartbeat reaper.
    $heartbeatOnSilence = static function () use ($db, $jobId, $workerId): void {
        deploy_worker_heartbeat_tick($db, $jobId, $workerId);
    };

    try {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing deploy artifacts.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        $priorLifecycles = deploy_worker_mark_vms_deploying($db, (int) $job['mission_id'], 'deploy job ' . $jobId . ' started', $vmIds);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        $esxiCredential = deploy_worker_credential($db, (int) $job['credential_esxi_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleCredential = deploy_worker_credential($db, (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($db, (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($db, (int) $ansibleCredential['id']);
        $apiBaseUrl = ansible_resolve_api_base_url($db);
        $payload = deploy_worker_payload($job);

        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible host preflight.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        $preflightBuffer = '';
        // Accumulated separately from the stream buffer (which the chunk logger
        // consumes): on failure the last stage marker in here names the broken
        // component for the job's error message.
        $preflightOutput = '';
        // The portal/allowlist probes gate exactly the modes whose sequence
        // uploads MACs: those jobs strand at stage 2/5 when the host cannot
        // reach the portal, while a create-only job must not be failed for a
        // route it never uses (B6; same derivation as the missing-result rule).
        $preflightApiBaseUrl = ansible_mode_expects_mac_result((string) $payload['mode']) ? $apiBaseUrl : '';
        $preflightExitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command($preflightApiBaseUrl), static function (string $chunk) use ($db, $jobId, $workerId, &$preflightBuffer, &$preflightOutput): void {
            $preflightOutput .= $chunk;
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer, $chunk);
        }, 45, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);
        if ($preflightExitCode !== 0) {
            $failedComponent = ansible_preflight_failed_component($preflightOutput);
            throw new RuntimeException(
                'Ansible host preflight failed with exit code ' . $preflightExitCode . '.'
                . ($failedComponent !== null ? ' (failed at: ' . $failedComponent . ')' : '')
            );
        }

        $artifacts = ansible_prepare_job_artifacts($db, $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy files prepared: ' . implode(', ', (array) $artifacts['files']));
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

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
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        $command = ansible_remote_command((string) $artifacts['remote_dir'], $payload, $autostartEnabled);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible playbook sequence: ' . deploy_job_payload_summary((string) $job['payload_json']));
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        // Failed-phase naming (AP6): the remote command brackets every playbook
        // with begin/end markers; the last begin without its end is the step
        // that failed, and it is named in the error instead of leaving the
        // operator to guess which of up to five playbooks broke the chain.
        $currentStep = null;
        $stepTracker = static function (string $line) use (&$currentStep): void {
            $marker = ansible_step_marker_parse($line);
            if ($marker !== null) {
                $currentStep = $marker['event'] === VIRTUSPHERE_ANSIBLE_STEP_BEGIN ? $marker['playbook'] : null;
            }
        };
        $buffer = '';
        try {
            $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $command, static function (string $chunk) use ($db, $jobId, $workerId, &$buffer, $stepTracker): void {
                deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $chunk, $stepTracker);
            }, 0, $heartbeatOnSilence);
        } catch (RuntimeException $transportError) {
            deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $stepTracker);
            throw new RuntimeException($transportError->getMessage() . ansible_step_failure_suffix($currentStep), 0, $transportError);
        }
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $stepTracker);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        if ($exitCode !== 0) {
            throw new RuntimeException('Ansible command failed with exit code ' . $exitCode . ansible_step_failure_suffix($currentStep) . '.');
        }

        deploy_worker_conclude_sequence($db, $job, $workerId, $vmIds, $priorLifecycles);
    } catch (DeployWorkerCancelled $cancelled) {
        // The reason travels: "cancelled" and "the mission was deleted under me"
        // look identical in the log otherwise, and the second one is the finding.
        deploy_worker_handle_cancelled($db, $job, $vmIds, $cancelled->getMessage());
    } catch (Throwable $exception) {
        // Same redaction as the inventory path: a transport error can echo the
        // command line it ran, and accounts.yml values have no business in a
        // job error an operator copies into a ticket.
        deploy_worker_handle_failure($db, $job, $workerId, $vmIds, deploy_worker_redact_secrets($exception->getMessage(), [$esxiSecret, $ansibleSecret]));
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
    // Phase tracking (B6): a thrown failure is classified by WHERE it happened
    // first and by text evidence second (deploy_worker_classify_inventory_failure),
    // instead of every throw reading as "the host answered unexpectedly".
    $phase = VIRTUSPHERE_DEPLOY_PHASE_CONFIG;
    $esxiSecret = null;
    $ansibleSecret = null;

    $heartbeatOnSilence = static function () use ($db, $jobId, $workerId): void {
        deploy_worker_heartbeat_tick($db, $jobId, $workerId);
    };

    try {
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing ESXi inventory fetch.');
        deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        $esxiCredential = deploy_worker_credential($db, $credentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleCredential = deploy_worker_credential($db, (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($db, (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($db, (int) $ansibleCredential['id']);
        $apiBaseUrl = ansible_resolve_api_base_url($db);

        $phase = VIRTUSPHERE_DEPLOY_PHASE_SSH;
        $preflightBuffer = '';
        // Same accumulation as the deploy path: the last stage marker names the
        // broken component in the job error instead of a bare exit code.
        $preflightOutput = '';
        // Deliberately probe-less: the inventory pull has no MAC callback, so a
        // portal unreachable from the Ansible host must not fail it (B6 fixed
        // the deploy path; this path never needed the route).
        $preflightApiBaseUrl = '';
        $preflightExit = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command($preflightApiBaseUrl), static function (string $chunk) use ($db, $jobId, $workerId, &$preflightBuffer, &$preflightOutput): void {
            $preflightOutput .= $chunk;
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer, $chunk);
        }, 45, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);
        if ($preflightExit !== 0) {
            $failCategory = VIRTUSPHERE_INVENTORY_ERROR_SSH;
            $failedComponent = ansible_preflight_failed_component($preflightOutput);
            throw new RuntimeException(
                'Ansible host preflight failed with exit code ' . $preflightExit . '.'
                . ($failedComponent !== null ? ' (failed at: ' . $failedComponent . ')' : '')
            );
        }

        $phase = VIRTUSPHERE_DEPLOY_PHASE_CONFIG;
        $artifacts = ansible_prepare_inventory_artifacts($db, $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        $phase = VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT;
        ssh_sftp_upload_directory($ansibleCredential, $ansibleSecret, $localDir, (string) $artifacts['remote_dir'], static function (string $line) use ($db, $jobId, $workerId): void {
            deploy_worker_heartbeat_tick($db, $jobId, $workerId);
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        });
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        $command = ansible_inventory_remote_command((string) $artifacts['remote_dir'], !empty(deploy_worker_payload($job)['verbose']));
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running ESXi inventory playbook.');
        $buffer = '';
        $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $command, static function (string $chunk) use ($db, $jobId, $workerId, &$buffer, &$fullOutput): void {
            $fullOutput .= $chunk;
            deploy_worker_log_stream_chunk($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $chunk);
        }, 0, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer);
        deploy_worker_assert_job_is_ours($db, $jobId, $workerId);

        if ($exitCode !== 0) {
            $failCategory = ansible_categorize_inventory_error($fullOutput, $exitCode);
            throw new RuntimeException('Inventory fetch failed (' . $failCategory . ').');
        }

        // Parse marker (may throw -> "parse") then apply the cache atomically.
        $phase = VIRTUSPHERE_DEPLOY_PHASE_MARKER;
        $parsed = ansible_parse_inventory_output($fullOutput);
        $phase = VIRTUSPHERE_DEPLOY_PHASE_DB;
        $summary = repo_esxi_inventory_apply($db, $credentialId, $parsed);
        repo_esxi_inventory_record_success($db, $credentialId, $parsed['capabilities'], $jobId);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line($parsed['capabilities'], true));
        // VLAN catalog is ESXi-owned (E4b): resync from the union of cached
        // portgroups after every successful pull (retire not delete).
        $vlanSync = repo_esxi_vlan_sync($db);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'VLAN catalog sync: +' . $vlanSync['upserted'] . ' new, ' . $vlanSync['unretired'] . ' un-retired, ' . $vlanSync['retired'] . ' retired');

        $parts = [];
        foreach ($summary as $kind => $info) {
            if (!empty($info['cleared'])) {
                // Every query of the kind answered and the union is empty: the
                // host genuinely reports none, so the mirror says so too (B15).
                $parts[] = $kind . ': cleared (host reports none, ' . $info['removed'] . ' removed)';
            } elseif ($info['kept_empty']) {
                $parts[] = $kind . ': kept (empty result, not authoritative)';
            } else {
                $parts[] = $kind . ': ' . $info['written'] . ' items';
            }
        }
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Inventory updated for credential ' . $credentialId . ' - ' . implode(', ', $parts));
        // Directly after the counts, because that is where a 0 is read and
        // where its reason belongs. Absent for a pull from a playbook that
        // predates the per-query report.
        $queryLine = ansible_inventory_queries_log_line($parsed['queries']);
        if ($queryLine !== null) {
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $queryLine);
        }
        // Same reason and same place: a datastore health field path that stops
        // matching looks exactly like a fleet with nothing in maintenance, and
        // only a line that also speaks in the good case tells them apart.
        $healthLine = ansible_inventory_datastore_health_log_line($parsed['datastores']);
        if ($healthLine !== null) {
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $healthLine);
        }
        // Raw-vs-kept balance (B15): an entry whose shape stopped matching used
        // to vanish silently, indistinguishable from a host that has less.
        $normalizationLine = ansible_inventory_normalization_log_line($parsed['normalization'] ?? []);
        if ($normalizationLine !== null) {
            repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $normalizationLine);
        }
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
    } catch (DeployWorkerCancelled $cancelled) {
        // Tolerant of a vanished row for the same reason as the deploy path: one
        // of the ways we land here is that the job no longer exists.
        deploy_worker_log_if_job_exists($db, $jobId, 'Inventory job stopped. Reason: ' . $cancelled->getMessage());
    } catch (Throwable $exception) {
        $category = $failCategory ?? deploy_worker_classify_inventory_failure($phase, $exception->getMessage());
        try {
            // An auth failure pauses all future auto-pulls of this credential to
            // stop the ESXi account from locking out (ADR-0023). That pause blocks
            // deploys of the kind too, so it belongs in the audit trail, once per
            // onset: log only when this failure is what turned the pause on.
            $wasPaused = ($state = repo_esxi_inventory_state($db, $credentialId)) !== null
                && (int) $state['paused_until_credential_change'] === 1;
            repo_esxi_inventory_record_failure($db, $credentialId, $category, $jobId);
            if ($category === VIRTUSPHERE_INVENTORY_ERROR_AUTH && !$wasPaused) {
                audit($db, VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, 'esxi inventory auto-pull paused for credential id ' . $credentialId . ' after an authentication failure; save the credential to resume', null, 'cli');
            }
        } catch (Throwable $stateError) {
            error_log('[inventory] state update failed: ' . $stateError->getMessage());
        }
        $message = '[' . $category . '] ' . deploy_worker_redact_secrets($exception->getMessage(), [$esxiSecret, $ansibleSecret]);
        repo_append_deploy_job_log($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_STDERR, $message);
        deploy_worker_finish_job($db, $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_FAILED, $message);
    } finally {
        repo_touch_deploy_job_heartbeat($db, $jobId, $workerId);
        if (!empty($options['cleanup'])) {
            ansible_cleanup_artifacts($localDir);
        }
    }
}

function deploy_worker_credential(mysqli $db, int $credentialId, string $type): array
{
    return repo_deploy_assert_credential_type($db, $credentialId, $type);
}


function deploy_worker_log_stream_chunk(mysqli $db, int $jobId, string $workerId, string $stream, string &$buffer, string $chunk, ?callable $onLine = null): void
{
    deploy_worker_heartbeat_tick($db, $jobId, $workerId);
    $buffer .= str_replace("\r\n", "\n", str_replace("\r", "\n", $chunk));
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        repo_append_deploy_job_log($db, $jobId, $stream, $line);
        if ($onLine !== null) {
            $onLine($line);
        }
    }
}

function deploy_worker_log_stream_flush(mysqli $db, int $jobId, string $workerId, string $stream, string &$buffer, ?callable $onLine = null): void
{
    if ($buffer === '') {
        return;
    }

    repo_append_deploy_job_log($db, $jobId, $stream, $buffer);
    if ($onLine !== null) {
        $onLine($buffer);
    }
    deploy_worker_heartbeat_tick($db, $jobId, $workerId, 0);
    $buffer = '';
}

function deploy_worker_id(): string
{
    $host = gethostname() ?: 'worker';
    return $host . ':' . getmypid();
}

exit(deploy_worker_main($argv));
