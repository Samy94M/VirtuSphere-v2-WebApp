<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/audit_event_definitions.php';
require_once __DIR__ . '/audit_registry.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_job_output.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/ssh.php';

/**
 * The one owner of the removal of the ESXi access file accounts.yml from an
 * Ansible host (K21, VT-04).
 *
 * accounts.yml carries the ESXi password. It is removed at the end of every job
 * that uploaded it, including a job whose create unit ended uncertain. When the
 * removal cannot be confirmed, deploy_remote_secret_files keeps a row, and the
 * next job that contacts the same Ansible credential removes the file first.
 * Every removal here is idempotent (rm -f), so a repeated one is harmless.
 */
const VIRTUSPHERE_REMOTE_SECRET_SWEEP_LIMIT = 20;

/**
 * Write-ahead record: called before every SFTP upload that carries accounts.yml,
 * so no upload happens without its row. Idempotent. Throws on failure, which
 * keeps the upload from starting.
 */
function deploy_remote_secret_remember(mysqli $db, int $jobId, ?int $ansibleCredentialId, string $remoteDir): void
{
    ansible_create_assert_remote_dir($remoteDir);
    $stored = repo_execute(
        $db,
        'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
        'iis',
        [$jobId, $ansibleCredentialId, $remoteDir]
    );
    if (!$stored) {
        throw new RuntimeException('The ESXi access file of job ' . $jobId . ' could not be recorded before its upload.');
    }
}

/** Forgets the row of a confirmed removal. */
function deploy_remote_secret_forget(mysqli $db, int $jobId, string $remoteDir): void
{
    repo_execute($db, 'DELETE FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]);
}

/**
 * Removes accounts.yml (or, with $removeWholeDir, the whole job directory) over
 * SSH and forgets the row when the host confirmed it. Never throws: it runs in a
 * finally block, and a side channel must not end the job it describes.
 *
 * Returns true only when the host confirmed the removal (exit 0). A failed
 * removal keeps the row and says so in the error log, so the next contact with
 * the same Ansible credential removes the file.
 *
 * @param callable(): mysqli $connection the worker passes fn () => $channel->connection()
 * @param array<string, mixed> $credential the Ansible credential
 * @param (callable(array<string,mixed>, string, string, int): array{exit_code: int, output: string})|null $execute ssh_execute_capture() signature
 */
function deploy_remote_secret_release(callable $connection, array $credential, string $secret, int $jobId, string $remoteDir, bool $removeWholeDir, ?callable $execute = null): bool
{
    $run = $execute ?? ssh_execute_capture(...);
    $confirmed = false;
    try {
        $command = $removeWholeDir
            ? ansible_remote_cleanup_command($remoteDir)
            : ansible_remote_secret_remove_command($remoteDir);
        $result = $run($credential, $secret, $command, VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS);
        $exitCode = (int) ($result['exit_code'] ?? -1);
        if ($exitCode !== 0) {
            throw new RuntimeException('the host returned exit code ' . $exitCode . '.');
        }
        $confirmed = true;
    } catch (Throwable $exception) {
        $what = $removeWholeDir
            ? 'Remote job directory ' . $remoteDir
            : 'ESXi access file of job ' . $jobId . ' in ' . $remoteDir;
        deploy_worker_cleanup_diagnose(
            $jobId,
            $what . ' could not be removed: ' . $exception->getMessage() . ' It will be removed at the next contact with this Ansible host.',
            $secret
        );

        return false;
    }

    try {
        deploy_remote_secret_forget($connection(), $jobId, $remoteDir);
    } catch (Throwable $exception) {
        // The file is gone, the row is not: the next contact removes it again, which costs one idempotent rm.
        deploy_worker_cleanup_diagnose(
            $jobId,
            'The ESXi access file in ' . $remoteDir . ' was removed, but its row could not be forgotten: ' . $exception->getMessage(),
            $secret
        );
    }

    return $confirmed;
}

/**
 * The rows of one Ansible credential whose job is not active any more, oldest
 * first. A row whose job row is gone (its mission was deleted) has nothing left
 * to protect it and is included.
 *
 * @return list<array{id: int|string, job_id: int|string, remote_dir: string}>
 */
function deploy_remote_secret_sweep_candidates(mysqli $db, int $credentialId, int $currentJobId): array
{
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(',', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT s.id, s.job_id, s.remote_dir FROM deploy_remote_secret_files s'
        . ' LEFT JOIN deploy_jobs j ON j.id = s.job_id'
        . ' WHERE s.credential_ansible_id = ? AND s.job_id <> ?'
        . ' AND (j.id IS NULL OR j.status NOT IN (' . $placeholders . '))'
        . ' ORDER BY s.id LIMIT ' . VIRTUSPHERE_REMOTE_SECRET_SWEEP_LIMIT
    );
    $stmt->bind_param('ii' . str_repeat('s', count($active)), $credentialId, $currentJobId, ...$active);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * The "next contact" of K21: removes accounts.yml left behind by OTHER jobs of
 * the same Ansible credential. Only the file is removed, never a directory,
 * because the directory of an uncertain create unit holds the async evidence.
 *
 * A confirmed removal deletes the row and writes one audit row, in one
 * transaction. A failed removal keeps the row and writes a diagnostic. Never
 * throws, so a side channel cannot end the current job.
 *
 * @param array<string, mixed> $ansibleCredential
 * @param (callable(array<string,mixed>, string, string, int): array{exit_code: int, output: string})|null $execute ssh_execute_capture() signature
 * @param (callable(string): void)|null $log receives one line per removed file
 * @return int the number of rows removed
 */
function deploy_remote_secret_sweep(mysqli $db, array $ansibleCredential, string $ansibleSecret, int $currentJobId, ?callable $execute = null, ?callable $log = null): int
{
    $run = $execute ?? ssh_execute_capture(...);
    $credentialId = (int) ($ansibleCredential['id'] ?? 0);
    try {
        $candidates = deploy_remote_secret_sweep_candidates($db, $credentialId, $currentJobId);
    } catch (Throwable $exception) {
        deploy_worker_cleanup_diagnose($currentJobId, 'Leftover ESXi access files could not be listed: ' . $exception->getMessage(), $ansibleSecret);

        return 0;
    }

    $removed = 0;
    foreach ($candidates as $candidate) {
        $rowId = (int) $candidate['id'];
        $jobId = (int) $candidate['job_id'];
        $remoteDir = (string) $candidate['remote_dir'];
        try {
            $result = $run($ansibleCredential, $ansibleSecret, ansible_remote_secret_remove_command($remoteDir), VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS);
            $exitCode = (int) ($result['exit_code'] ?? -1);
            if ($exitCode !== 0) {
                throw new RuntimeException('the host returned exit code ' . $exitCode . '.');
            }
            repo_transaction($db, static function () use ($db, $rowId, $jobId, $credentialId, $currentJobId): void {
                repo_execute($db, 'DELETE FROM deploy_remote_secret_files WHERE id = ?', 'i', [$rowId]);
                audit_event_required(
                    $db,
                    VIRTUSPHERE_AUDIT_EVENT_DEPLOY_REMOTE_SECRET_REMOVED,
                    'deploy_job',
                    $jobId,
                    VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                    ['credential_id' => $credentialId, 'job_id' => $currentJobId]
                );
            });
        } catch (Throwable $exception) {
            deploy_worker_cleanup_diagnose(
                $currentJobId,
                'ESXi access file of job ' . $jobId . ' in ' . $remoteDir . ' could not be removed at this contact: ' . $exception->getMessage()
                    . ' It will be removed at the next contact with this Ansible host.',
                $ansibleSecret
            );

            continue;
        }

        $removed++;
        if ($log !== null) {
            try {
                $log('Removed the ESXi access file that job ' . $jobId . ' left on the Ansible host.');
            } catch (Throwable) {
                // The job log is a side channel; a line that cannot be written changes nothing about the removal.
            }
        }
    }

    return $removed;
}

/**
 * Writes one diagnostic line to the worker's error log, redacted against the
 * job's secret. Never throws: a failing diagnostic cannot replace the decided
 * outcome of the job it describes.
 */
function deploy_worker_cleanup_diagnose(int $jobId, string $message, string $secret): void
{
    try {
        error_log('[deploy-worker] job ' . $jobId . ' cleanup: '
            . deploy_worker_redact_secrets(deploy_job_output_normalize_line($message), [$secret]));
    } catch (Throwable) {
        // A secondary diagnostic failure cannot replace the decided outcome.
    }
}
