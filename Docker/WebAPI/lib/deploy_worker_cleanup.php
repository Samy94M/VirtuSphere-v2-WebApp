<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_worker_db_channel.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/ansible_command.php';

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
    if ($stepInFlight || $channel->hasLostOwnership()) {
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Remote job directory left in place: a remote step did not return, so this worker cannot prove the host is idle.'
        );

        return;
    }

    try {
        $exit = ssh_execute_command(
            $credential,
            $secret,
            ansible_remote_cleanup_command($remoteDir),
            static function (string $chunk): void {
            },
            VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS
        );
        if ($exit !== 0) {
            throw new RuntimeException('Remote cleanup returned exit code ' . $exit . '.');
        }
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
