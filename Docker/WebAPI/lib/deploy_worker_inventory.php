<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/ansible_inventory.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/deploy_worker_stream.php';

/**
 * The ESXi inventory processor: the mission-less system job (ADR-0023).
 *
 * Separate from the mission path on purpose. It tracks a phase across its
 * steps, so a thrown failure is classified by WHERE it happened before any text
 * evidence is read, and it is deliberately probe-less: the pull has no MAC
 * callback, so a portal the Ansible host cannot reach must not fail it.
 */
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

    // Same channel principle as the mission path, deliberately not a second
    // reconnect policy: an inventory pull loses its database exactly like a
    // deploy does, and two behaviours would mean two failure modes to reason
    // about (Masterplan Etappe 2, requirement 7).
    $channel = deploy_worker_open_db_channel($db, $jobId, $workerId);

    $heartbeatOnSilence = static function () use ($channel): void {
        $channel->tick();
    };

    try {
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Preparing ESXi inventory fetch.');
        $channel->tick(0);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        $esxiCredential = deploy_worker_credential($channel->connection(), $credentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        $inventoryPayload = deploy_worker_payload($job);
        $strictTrustProbe = !empty($inventoryPayload['strict_trust_probe']);
        if ($strictTrustProbe) {
            // Test the candidate trust material without changing the durable
            // mode. Only a successful run records the evidence that enables
            // the separate activation action.
            $esxiCredential['esxi_trust_mode'] = VIRTUSPHERE_ESXI_TRUST_STRICT;
        }
        $ansibleCredential = deploy_worker_credential($channel->connection(), (int) $job['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
        $esxiSecret = repo_credential_secret($channel->connection(), (int) $esxiCredential['id']);
        $ansibleSecret = repo_credential_secret($channel->connection(), (int) $ansibleCredential['id']);
        $apiBaseUrl = ansible_resolve_api_base_url($channel->connection());

        $phase = VIRTUSPHERE_DEPLOY_PHASE_SSH;
        $preflightBuffer = '';
        // Same accumulation as the deploy path: the last stage marker names the
        // broken component in the job error instead of a bare exit code.
        $preflightOutput = '';
        // Deliberately probe-less: the inventory pull has no MAC callback, so a
        // portal unreachable from the Ansible host must not fail it (B6 fixed
        // the deploy path; this path never needed the route).
        $preflightApiBaseUrl = '';
        $preflightExit = ssh_execute_command($ansibleCredential, $ansibleSecret, ansible_preflight_command($preflightApiBaseUrl), static function (string $chunk) use ($channel, &$preflightBuffer, &$preflightOutput): void {
            $preflightOutput .= $chunk;
            deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer, $chunk);
        }, 45, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $preflightBuffer);
        deploy_worker_settle_db_channel($channel, $options, null);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);
        if ($preflightExit !== 0) {
            $failCategory = VIRTUSPHERE_INVENTORY_ERROR_SSH;
            $failedComponent = ansible_preflight_failed_component($preflightOutput);
            throw new RuntimeException(
                'Ansible host preflight failed with exit code ' . $preflightExit . '.'
                . ($failedComponent !== null ? ' (failed at: ' . $failedComponent . ')' : '')
            );
        }

        $phase = VIRTUSPHERE_DEPLOY_PHASE_CONFIG;
        $artifacts = ansible_prepare_inventory_artifacts($channel->connection(), $job, $esxiCredential, $esxiSecret, $ansibleCredential, $apiBaseUrl);
        $localDir = (string) $artifacts['local_dir'];
        $phase = VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT;
        ssh_sftp_upload_directory($ansibleCredential, $ansibleSecret, $localDir, (string) $artifacts['remote_dir'], static function (string $line) use ($channel): void {
            $channel->tick();
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        });
        deploy_worker_settle_db_channel($channel, $options, null);
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        $command = ansible_inventory_remote_command((string) $artifacts['remote_dir'], !empty($inventoryPayload['verbose']));
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running ESXi inventory playbook.');
        $buffer = '';
        $exitCode = ssh_execute_command($ansibleCredential, $ansibleSecret, $command, static function (string $chunk) use ($channel, &$buffer, &$fullOutput): void {
            $fullOutput .= $chunk;
            deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer, $chunk);
        }, 0, $heartbeatOnSilence);
        deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_STDOUT, $buffer);

        // The remote pull is over; its exit code lives only here. Same rule as
        // the mission path: wait for the database rather than lose the outcome.
        deploy_worker_settle_db_channel($channel, $options, $exitCode);
        if ($channel->hasLostOwnership()) {
            throw new DeployWorkerCancelled('Ownership was lost while the database was unreachable: ' . (string) $channel->ownershipReason());
        }
        deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId);

        if ($exitCode !== 0) {
            $failCategory = ansible_categorize_inventory_error($fullOutput, $exitCode);
            throw new RuntimeException('Inventory fetch failed (' . $failCategory . ').');
        }

        // Parse marker (may throw -> "parse") then apply the cache atomically.
        $phase = VIRTUSPHERE_DEPLOY_PHASE_MARKER;
        $parsed = ansible_parse_inventory_output($fullOutput);
        $phase = VIRTUSPHERE_DEPLOY_PHASE_DB;
        $summary = repo_esxi_inventory_apply($channel->connection(), $credentialId, $parsed);
        repo_esxi_inventory_record_success($channel->connection(), $credentialId, $parsed['capabilities'], $jobId);
        if (credential_esxi_trust_mode($esxiCredential) === VIRTUSPHERE_ESXI_TRUST_STRICT) {
            repo_record_esxi_strict_test_success($channel->connection(), $credentialId);
        }
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, esxi_capabilities_log_line($parsed['capabilities'], true));
        // VLAN catalog is ESXi-owned (E4b): resync from the union of cached
        // portgroups after every successful pull (retire not delete).
        $vlanSync = repo_esxi_vlan_sync($channel->connection());
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'VLAN catalog sync: +' . $vlanSync['upserted'] . ' new, ' . $vlanSync['unretired'] . ' un-retired, ' . $vlanSync['retired'] . ' retired');

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
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Inventory updated for credential ' . $credentialId . ' - ' . implode(', ', $parts));
        // Directly after the counts, because that is where a 0 is read and
        // where its reason belongs. Absent for a pull from a playbook that
        // predates the per-query report.
        $queryLine = ansible_inventory_queries_log_line($parsed['queries']);
        if ($queryLine !== null) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $queryLine);
        }
        // Same reason and same place: a datastore health field path that stops
        // matching looks exactly like a fleet with nothing in maintenance, and
        // only a line that also speaks in the good case tells them apart.
        $healthLine = ansible_inventory_datastore_health_log_line($parsed['datastores']);
        if ($healthLine !== null) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $healthLine);
        }
        // Raw-vs-kept balance (B15): an entry whose shape stopped matching used
        // to vanish silently, indistinguishable from a host that has less.
        $normalizationLine = ansible_inventory_normalization_log_line($parsed['normalization']);
        if ($normalizationLine !== null) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $normalizationLine);
        }
        deploy_worker_finish_job($channel->connection(), $jobId, $workerId, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
    } catch (DeployWorkerCancelled $cancelled) {
        // Tolerant of a vanished row for the same reason as the deploy path: one
        // of the ways we land here is that the job no longer exists.
        deploy_worker_log_if_job_exists($channel->connection(), $jobId, 'Inventory job stopped. Reason: ' . $cancelled->getMessage());
    } catch (Throwable $exception) {
        $category = $failCategory ?? deploy_worker_classify_inventory_failure($phase, $exception->getMessage());
        $db = $channel->connection();
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
        // Through the channel: a final heartbeat is a side channel too, and a
        // database that is still gone must not turn a handled outcome into an
        // unhandled exception from the finally block.
        $channel->tick(0);
        if (!empty($options['cleanup'])) {
            ansible_cleanup_artifacts($localDir);
        }
    }
}
