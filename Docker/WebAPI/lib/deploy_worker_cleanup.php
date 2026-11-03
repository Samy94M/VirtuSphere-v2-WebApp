<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_worker_db_channel.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/deploy_remote_secret_sweep.php';

/**
 * Removes the ESXi access file accounts.yml at the end of a job, and removes the
 * job's whole remote work directory when this worker can prove that nothing of
 * the job is still running on the host (K21, VT-04).
 *
 * The material is not ordinary scratch: accounts.yml carries the ESXi password
 * until it is gone. The file is removed in every case. The directory is removed
 * only on an idle host: a step that never returned (a cancel accepted
 * mid-playbook, a broken transport) or an unresolved create unit keeps its
 * directory, because deleting it under a running playbook, or the async state of
 * an uncertain unit, would destroy the very evidence whose outcome is unknown.
 *
 * A removal the host did not confirm is remembered in deploy_remote_secret_files
 * and removed again at the next contact with the same Ansible credential; see
 * lib/deploy_remote_secret.php.
 *
 * @param array<string, mixed>|null $credential
 * @param (callable(array<string,mixed>, string, string, int): array{exit_code: int, output: string})|null $execute ssh_execute_capture() signature
 */
function deploy_worker_cleanup_remote_dir(
    DeployWorkerDbChannel $channel,
    ?array $credential,
    ?string $secret,
    ?string $remoteDir,
    bool $stepInFlight,
    ?callable $execute = null
): void {
    if ($remoteDir === null || $remoteDir === '' || $credential === null || $secret === null) {
        return;
    }
    try {
        // Durable evidence survives exceptions and reconnects; the playbook
        // loop's in-memory flag cannot describe a preceding async create.
        foreach (repo_deploy_create_results($channel->connection(), $channel->jobId()) as $unit) {
            if (in_array((string) $unit['status'], VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
                $stepInFlight = true;
                break;
            }
        }
    } catch (Throwable) {
        // An unavailable evidence store is not proof that the host is idle.
        $stepInFlight = true;
    }
    $idle = !$stepInFlight && !$channel->hasLostOwnership();

    // Never throws: a failed removal is diagnosed and remembered by the release.
    $removed = deploy_remote_secret_release(
        static fn (): mysqli => $channel->connection(),
        $credential,
        $secret,
        $channel->jobId(),
        $remoteDir,
        $idle,
        $execute
    );
    if (!$idle && $removed) {
        deploy_worker_cleanup_diagnose(
            $channel->jobId(),
            'Remote job directory left in place: this worker cannot prove the host is idle; its ESXi access file is gone.',
            $secret
        );
    }
}

/**
 * The side channels of the remote secret sweep (K21-A3), through this job's DB
 * channel: one job-log line per closed record and one heartbeat per candidate,
 * so a host that answers slowly cannot make a live job look dead to the reaper.
 *
 * @return array{log: callable(string): void, tick: callable(): void}
 */
function deploy_worker_remote_secret_seams(DeployWorkerDbChannel $channel): array
{
    return [
        'log' => static function (string $line) use ($channel): void {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $line);
        },
        'tick' => static function () use ($channel): void {
            $channel->tick();
        },
    ];
}
