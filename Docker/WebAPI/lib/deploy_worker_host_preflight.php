<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/deploy_host_preflight_result.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/deploy_worker_network_preflight.php';
require_once __DIR__ . '/deploy_worker_stream.php';
require_once __DIR__ . '/ssh.php';

/**
 * The Ansible host preflight of a mission deploy (K2 / DF-L8, MR-02).
 *
 * It runs BEFORE deploy_worker_mark_vms_deploying(): it only reads the host,
 * so a job that ends here changed nothing on ESXi and must not change a VM
 * either. The failure path is told so through $vmsMarked in the caller.
 *
 * The portal and allowlist probes run exactly for the modes whose sequence
 * ends in a MAC import (same derivation as the missing-result rule): a
 * create-only job must not be failed for a route it never uses. For those
 * modes the allowlist verdict is evaluated, not only logged: `denied` means the
 * portal will answer the host's MAC upload with the legacy 403, so the job
 * could create, power-cycle and export every VM and still end without a
 * result. It ends as `configuration_blocked` instead, before any upload.
 * `unknown` does not block: the portal probe right before it passed, and a
 * transport hiccup on the second request is no proof of a closed gate.
 *
 * @param array<string,mixed> $options worker options; `host_preflight_runner`
 *        (callable(string $command, callable $onChunk): int) replaces the SSH
 *        call in integration tests, which have no Ansible host.
 */
function deploy_worker_run_host_preflight(
    DeployWorkerDbChannel $channel,
    array $job,
    string $workerId,
    array $ansibleCredential,
    string $ansibleSecret,
    string $apiBaseUrl,
    callable $heartbeatOnSilence,
    array $options
): void {
    $jobId = (int) $job['id'];
    $mode = (string) deploy_worker_payload($job)['mode'];
    $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Running Ansible host preflight.');
    $channel->tick(0);
    $preflightBuffer = '';
    // Accumulated separately from the stream buffer (which the chunk logger
    // consumes): on failure the last stage marker in here names the broken
    // component for the job's error message, and the last allowlist line is
    // the verdict.
    $preflightOutput = '';
    $preflightObserver = static function (string $line) use (&$preflightOutput): void {
        if (ansible_preflight_failed_component($line) !== null
            || str_starts_with(trim($line), VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' ')
        ) {
            $preflightOutput .= $line . "\n";
        }
    };
    $expectsMacResult = ansible_mode_expects_mac_result($mode);
    $preflightApiBaseUrl = $expectsMacResult ? $apiBaseUrl : '';
    $preflightCommand = ansible_preflight_command($preflightApiBaseUrl);
    $onChunk = static function (string $chunk) use ($channel, &$preflightBuffer, $preflightObserver): void {
        deploy_worker_log_stream_chunk($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $preflightBuffer, $chunk, $preflightObserver);
    };
    $runner = $options['host_preflight_runner'] ?? null;
    $preflightExitCode = is_callable($runner)
        ? (int) $runner($preflightCommand, $onChunk)
        : ssh_execute_command($ansibleCredential, $ansibleSecret, $preflightCommand, $onChunk, VIRTUSPHERE_ANSIBLE_PREFLIGHT_IDLE_SECONDS, $heartbeatOnSilence);
    deploy_worker_log_stream_flush($channel, VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $preflightBuffer, $preflightObserver);
    deploy_worker_settle_db_channel($channel, $options, null);
    deploy_worker_assert_job_is_ours($channel->connection(), $jobId, $workerId, true, $job);

    deploy_worker_host_preflight_verdict($mode, $preflightExitCode, $preflightOutput);
}

/**
 * Turns the preflight's exit code and marker lines into the job's next step:
 * return to continue, RuntimeException for a broken host component (an
 * execution failure as before), DeployWorkerConfigurationBlocked for a portal
 * that refuses the host's MAC upload.
 */
function deploy_worker_host_preflight_verdict(string $mode, int $exitCode, string $output): void
{
    if ($exitCode !== 0) {
        $failedComponent = ansible_preflight_failed_component($output);
        throw new RuntimeException(
            'Ansible host preflight failed with exit code ' . $exitCode . '.'
            . ($failedComponent !== null ? ' (failed at: ' . $failedComponent . ')' : '')
        );
    }
    if (!ansible_mode_expects_mac_result($mode)) {
        return;
    }
    $allowlist = ansible_preflight_allowlist_verdict($output);
    if ($allowlist['status'] !== 'denied') {
        return;
    }
    throw new DeployWorkerConfigurationBlocked(
        'The portal refuses MAC uploads from the Ansible host'
        . ($allowlist['ip'] !== '' ? ' (' . $allowlist['ip'] . ')' : '')
        . ': its IP is not on the machine API allowlist (Settings > Machine API). '
        . 'This mode ends with a MAC import, so the job stopped before any VM was created or changed.',
        deploy_host_preflight_allowlist_result($allowlist['ip'])
    );
}
