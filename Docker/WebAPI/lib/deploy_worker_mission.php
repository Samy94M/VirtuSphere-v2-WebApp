<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/deploy_worker_stream.php';
require_once __DIR__ . '/deploy_worker_inventory.php';

/**
 * The mission deploy processor: preflight, artifact preparation, the autostart
 * capability gate, the SFTP upload, the bracketed playbook sequence and the
 * hand-off to the outcome module.
 *
 * deploy_worker_process_job() is also the dispatcher: a mission-less inventory
 * job routes into the separate processor before any of this runs (ADR-0023), so
 * the deploy path never sees a NULL mission.
 */
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
    $remoteDir = null;
    $remoteStepInFlight = false;
    $esxiSecret = null;
    $ansibleSecret = null;
    $exitCode = null;

    $vmIds = deploy_worker_payload($job)['vm_ids'] ?? [];

    // Every database write of this job goes through the channel, and every read
    // asks it for the live handle. A local $db captured in a closure is a dead
    // object the moment the channel reconnects, and a database outage during a
    // playbook must not end the SSH stream: the remote work continues either way.
    $channel = deploy_worker_open_db_channel($db, $jobId, $workerId);

    // Time-based heartbeat (AP6): the bounded SSH transport calls this on every
    // silent read slice, so a playbook that is busy without printing (a long
    // vmware_guest clone) keeps the job alive for the stale-heartbeat reaper.
    // Through the channel it is also the outage's only retry opportunity.
    $heartbeatOnSilence = static function () use ($channel): void {
        $channel->tick();
    };

    try {
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing deploy artifacts.');
        $channel->tick(0);
        $priorLifecycles = deploy_worker_mark_vms_deploying($channel->connection(), (int) $job['mission_id'], 'deploy job ' . $jobId . ' started', $vmIds);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        $esxiCredential = deploy_worker_credential($channel->connection(), (int) $job['credential_esxi_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $ansibleCredential = deploy_worker_credential($channel->connection(), (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($channel->connection(), (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($channel->connection(), (int) $ansibleCredential['id']);
        // From here on every stored line is redacted against both secrets
        // (Etappe 8). `no_log` on the Ansible side is defence in depth, not a
        // guarantee: -vvv, a module without it or a failing task can echo a
        // value back, and the job log is read by everyone with deploy.run.
        $channel->withSecrets([$esxiSecret, $ansibleSecret]);
        $apiBaseUrl = ansible_resolve_api_base_url($channel->connection());
        $payload = deploy_worker_payload($job);

        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible host preflight.');
        $channel->tick(0);
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
        $preflightExitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command($preflightApiBaseUrl), static function (string $chunk) use ($channel, &$preflightBuffer, &$preflightOutput): void {
            $preflightOutput .= $chunk;
            deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $preflightBuffer, $chunk);
        }, 45, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $preflightBuffer);
        deploy_worker_settle_db_channel($channel, $options, null);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);
        if ($preflightExitCode !== 0) {
            $failedComponent = ansible_preflight_failed_component($preflightOutput);
            throw new RuntimeException(
                'Ansible host preflight failed with exit code ' . $preflightExitCode . '.'
                . ($failedComponent !== null ? ' (failed at: ' . $failedComponent . ')' : '')
            );
        }

        $artifacts = ansible_prepare_job_artifacts($channel->connection(), $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        // Known from here on, so a failed upload is cleaned up too: whatever
        // reached the host at that point includes accounts.yml.
        $remoteDir = (string) $artifacts['remote_dir'];
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy files prepared: ' . implode(', ', (array) $artifacts['files']));
        $channel->tick(0);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        // Autostart preflight (ADR-0025). Runs only when this job would write the
        // policy, and may drop the autostart step from a full pipeline without
        // failing the rest of it.
        $autostartEnabled = !empty($artifacts['autostart_enabled']);
        $writesAutostart = (string) $payload['mode'] === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART
            || ((string) $payload['mode'] === VIRTUSPHERE_DEPLOY_MODE_FULL && $autostartEnabled);
        if ($writesAutostart) {
            $autostartEnabled = deploy_worker_autostart_preflight($channel->connection(), $jobId, (int) $esxiCredential['id'], (string) $payload['mode'], $autostartEnabled);
        }

        ssh_sftp_upload_directory($ansibleCredential, $ansibleSecret, $localDir, (string) $artifacts['remote_dir'], static function (string $line) use ($channel): void {
            $channel->tick();
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        });
        deploy_worker_settle_db_channel($channel, $options, null);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        $steps = ansible_remote_steps($remoteDir, $payload, $autostartEnabled);
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible playbook sequence: ' . deploy_job_payload_summary((string) $job['payload_json']));
        $channel->tick(0);

        foreach ($steps as $step) {
            // THE step boundary (Etappe 8). Until the sequence became one
            // remote command per playbook, this decision existed only before
            // the first and after the last one, so an accepted cancel could not
            // stop anything the portal had already promised it would stop. All
            // four outcomes live in one helper: still ours and running, our own
            // cancel to confirm, ownership lost, or somebody else concluded it.
            deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

            // Named from the descriptor, not derived from the marker stream:
            // with one command per playbook the worker KNOWS which step it is
            // in, including before the first marker arrives, so a transport
            // failure at the very start of a step is named too.
            $currentStep = $step['playbook'];
            $buffer = '';
            // Only a step that RETURNS proves nothing is running on the host
            // any more. A cancel or a broken transport leaves that open, and
            // the remote signal trap is what removes the material then.
            $remoteStepInFlight = true;
            try {
                $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $step['command'], static function (string $chunk) use ($channel, &$buffer): void {
                    deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer, $chunk);
                }, 0, $heartbeatOnSilence);
                $remoteStepInFlight = false;
            } catch (DeployWorkerCancelled $cancelled) {
                deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer);
                throw $cancelled;
            } catch (RuntimeException $transportError) {
                deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer);
                throw deploy_worker_transport_failure_with_step($transportError, $currentStep);
            }
            deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $buffer);

            // The step is over and its exit code exists only in this process. If
            // the database went away during the run, waiting for it here is worth
            // more than failing fast: the alternative is a finished deploy nobody
            // can see. `--once` stays bounded and says so out loud instead.
            deploy_worker_settle_db_channel($channel, $options, $exitCode);
            if ($channel->hasLostOwnership()) {
                // Somebody else concluded this job while we could not write. Stop
                // without publishing a result: overwriting an established terminal
                // state with our own guess is the one thing worse than a gap.
                throw new DeployWorkerCancelled('Ownership was lost while the database was unreachable: ' . (string) $channel->ownershipReason());
            }

            if ($exitCode !== 0) {
                // Finalised through the same ownership recheck as a success:
                // the throw lands in the catch below, which finishes the job
                // with the compare-and-swap, exactly once.
                throw new RuntimeException('Ansible command failed with exit code ' . $exitCode . ansible_step_failure_suffix($currentStep) . '.');
            }
        }

        // The last boundary. A cancel committed while the final playbook ran is
        // honoured here rather than being overtaken by a success.
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        deploy_worker_conclude_sequence($channel->connection(), $job, $workerId, $vmIds, $priorLifecycles);
    } catch (DeployWorkerCancelled $cancelled) {
        // The reason travels: "cancelled" and "the mission was deleted under me"
        // look identical in the log otherwise, and the second one is the finding.
        deploy_worker_handle_cancelled($channel->connection(), $job, $vmIds, $cancelled->getMessage());
    } catch (Throwable $exception) {
        // Same redaction as the inventory path: a transport error can echo the
        // command line it ran, and accounts.yml values have no business in a
        // job error an operator copies into a ticket.
        deploy_worker_handle_failure($channel->connection(), $job, $workerId, $vmIds, deploy_worker_redact_secrets($exception->getMessage(), [$esxiSecret, $ansibleSecret]));
    } finally {
        // Through the channel: a final heartbeat is a side channel too, and a
        // database that is still gone must not turn a handled outcome into an
        // unhandled exception from the finally block.
        $channel->tick(0);
        deploy_worker_cleanup_remote_dir($channel, $ansibleCredential ?? null, $ansibleSecret, $remoteDir, $remoteStepInFlight);
        if (!empty($options['cleanup'])) {
            ansible_cleanup_artifacts($localDir);
        }
    }
}

/**
 * Removes the job's remote work directory, but only when this worker can prove
 * that nothing of the job is still running on the host.
 *
 * The material is not ordinary scratch: accounts.yml carries the ESXi password
 * until it is gone. With one chained remote command an EXIT trap removed it;
 * one command per playbook cannot use EXIT, so the steps carry HUP/INT/TERM
 * traps for the terminated cases and this runs for the normal one.
 *
 * A step that never returned (a cancel accepted mid-playbook, a broken
 * transport) is deliberately left alone: deleting the directory under a
 * running playbook would break the very work whose outcome is still unknown,
 * and the remote trap covers it when that shell ends. Material left behind by
 * a host this worker can no longer reach is reported, not resolved; it is the
 * remote-ownership stage that resolves it.
 *
 * @param array<string, mixed>|null $credential
 */
function deploy_worker_cleanup_remote_dir(
    DeployWorkerDbChannel $channel,
    ?array $credential,
    ?string $secret,
    ?string $remoteDir,
    bool $stepInFlight
): void {
    if ($remoteDir === null || $remoteDir === '' || $credential === null || $secret === null) {
        return;
    }
    if ($stepInFlight) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Remote job directory left in place: a remote step did not return, so this worker cannot prove the host is idle.'
        );

        return;
    }

    try {
        ssh_execute_command(
            $credential,
            $secret,
            ansible_remote_cleanup_command($remoteDir),
            static function (string $chunk): void {
            },
            VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS
        );
    } catch (Throwable $exception) {
        // Never the job's outcome: this runs in a finally block, after the
        // result is decided, and a host that cannot be reached for a cleanup
        // must not turn a finished deploy into an unhandled exception.
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Remote job directory could not be removed: ' . deploy_worker_redact_secrets($exception->getMessage(), [$secret])
        );
    }
}

/** Keeps the exact transport type while adding the failed Ansible step. */
function deploy_worker_transport_failure_with_step(RuntimeException $exception, ?string $currentStep): RuntimeException
{
    $message = $exception->getMessage() . ansible_step_failure_suffix($currentStep);
    if ($exception instanceof SshTransportBudgetExceeded) {
        return new SshTransportBudgetExceeded($message, 0, $exception);
    }
    if ($exception instanceof SftpTransportFailed) {
        return new SftpTransportFailed($message, 0, $exception);
    }
    if ($exception instanceof SshTransportConfigurationException) {
        return new SshTransportConfigurationException($message, 0, $exception);
    }

    return new RuntimeException($message, 0, $exception);
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
