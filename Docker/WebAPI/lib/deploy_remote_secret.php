<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_constants.php';
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
 * removal cannot be confirmed, deploy_remote_secret_files keeps a row with the
 * target the file went to (host, port, pinned host key), and the next job that
 * contacts the same Ansible credential AND the same target removes it first. A
 * removal is confirmed only by exit 0 plus the state line of
 * ansible_remote_secret_remove_command(): "removed" or "absent" (the file was
 * already gone). `rm -f` alone answers 0 for a path that never existed, so an
 * exit code cannot tell a removed file from a host that never had it.
 */
const VIRTUSPHERE_REMOTE_SECRET_SWEEP_LIMIT = 20;

/** lib/ssh.php and lib/ssh_sftp.php open every connection with this timeout. */
const VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS = 15;

/**
 * The lease of a status query outside the job (K21-A4): while it runs, a sweep of
 * another job of the same credential leaves the row alone. Twice the worst case
 * of the query: connect, login and upload, connect and the status command up to
 * its idle limit, connect and the removal.
 */
const VIRTUSPHERE_REMOTE_SECRET_STATUS_LEASE_SECONDS = 2 * (
    VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS + VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS + VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS
    + VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS + VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS
    + VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS + VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS
);

/**
 * Write-ahead record: called before every SFTP upload that carries accounts.yml,
 * so no upload happens without its row. The target is read from the credential
 * row at this moment, after the host preflight pinned its key. Idempotent for the
 * same target; a second upload of the same job directory to another target is
 * refused, because it would move the row away from the file of the first one.
 * Throws on failure, which keeps the upload from starting.
 *
 * $leaseSeconds > 0 marks a caller outside the worker (the status query): a sweep
 * leaves the row alone until the lease ends.
 */
function deploy_remote_secret_remember(mysqli $db, int $jobId, int $ansibleCredentialId, string $remoteDir, int $leaseSeconds = 0): void
{
    ansible_create_assert_remote_dir($remoteDir);
    // Lock sequence of the K21-F fence: the credential, then its row. A concurrent
    // target change waits for this write, and the row describes the target that
    // was locked when it was read.
    $stored = repo_transaction($db, static function () use ($db, $jobId, $ansibleCredentialId, $remoteDir, $leaseSeconds): bool {
        $target = deploy_remote_secret_target($db, $ansibleCredentialId, true);
        if ($target === null) {
            throw new RuntimeException('The ESXi access file of job ' . $jobId . ' could not be recorded before its upload: the Ansible credential is gone.');
        }
        $existing = repo_fetch_one($db, 'SELECT target_host, target_port, target_fingerprint FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]);
        if ($existing !== null && !deploy_remote_secret_same_target($existing, $target)) {
            throw new RuntimeException('The ESXi access file of job ' . $jobId . ' is still recorded on the Ansible host '
                . deploy_remote_secret_target_label($existing) . '; it is not uploaded to ' . deploy_remote_secret_target_label($target) . ' as well.');
        }

        return repo_execute(
            $db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint, lease_until)'
                . ' VALUES (?, ?, ?, ?, ?, ?, IF(? > 0, NOW(6) + INTERVAL ? SECOND, NULL))'
                . ' ON DUPLICATE KEY UPDATE lease_until = IF(? > 0, NOW(6) + INTERVAL ? SECOND, NULL)',
            'iissisiiii',
            [$jobId, $ansibleCredentialId, $remoteDir, $target['target_host'], $target['target_port'], $target['target_fingerprint'], $leaseSeconds, $leaseSeconds, $leaseSeconds, $leaseSeconds]
        );
    });
    if (!$stored) {
        throw new RuntimeException('The ESXi access file of job ' . $jobId . ' could not be recorded before its upload.');
    }
}

/**
 * Where an Ansible credential points now: host, effective SSH port and pinned
 * host key, in the column names of deploy_remote_secret_files. Not config_revision:
 * that also moves on a password change, which does not move the file. $lock takes
 * the credential row FOR UPDATE; the write-ahead row reads it that way.
 *
 * @return array{target_host: string, target_port: int, target_fingerprint: ?string}|null
 */
function deploy_remote_secret_target(mysqli $db, int $ansibleCredentialId, bool $lock = false): ?array
{
    $row = repo_fetch_one($db, 'SELECT host, port, ansible_host_fingerprint FROM deploy_credentials WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), 'i', [$ansibleCredentialId]);
    if ($row === null) {
        return null;
    }

    return [
        'target_host' => (string) $row['host'],
        'target_port' => credential_ssh_port($row['port']),
        'target_fingerprint' => $row['ansible_host_fingerprint'] === null ? null : (string) $row['ansible_host_fingerprint'],
    ];
}

/** @param array<string, mixed> $a @param array<string, mixed> $b */
function deploy_remote_secret_same_target(array $a, array $b): bool
{
    return strcasecmp((string) $a['target_host'], (string) $b['target_host']) === 0
        && (int) $a['target_port'] === (int) $b['target_port']
        && ($a['target_fingerprint'] === null ? null : (string) $a['target_fingerprint'])
            === ($b['target_fingerprint'] === null ? null : (string) $b['target_fingerprint']);
}

/** @param array<string, mixed> $target */
function deploy_remote_secret_target_label(array $target): string
{
    return (string) $target['target_host'] . ':' . (int) $target['target_port']
        . ' (host key ' . ($target['target_fingerprint'] === null ? 'not pinned' : (string) $target['target_fingerprint']) . ')';
}

/** Forgets the row of a confirmed removal. */
function deploy_remote_secret_forget(mysqli $db, int $jobId, string $remoteDir): void
{
    repo_execute($db, 'DELETE FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]);
}

/**
 * What a removal confirmed: VIRTUSPHERE_REMOTE_SECRET_REMOVED,
 * VIRTUSPHERE_REMOTE_SECRET_ABSENT, or null for no confirmation (a non-zero or
 * missing exit code, or exit 0 without a state line of its own).
 *
 * @param array<string, mixed> $result ssh_execute_capture() result
 */
function deploy_remote_secret_removal_state(array $result): ?string
{
    if (!array_key_exists('exit_code', $result) || (int) $result['exit_code'] !== 0) {
        return null;
    }
    $state = null;
    foreach (preg_split('/\r\n|\r|\n/', (string) ($result['output'] ?? '')) ?: [] as $line) {
        $words = explode(' ', trim($line));
        if (count($words) === 2 && $words[0] === VIRTUSPHERE_REMOTE_SECRET_STATE_PREFIX
            && in_array($words[1], [VIRTUSPHERE_REMOTE_SECRET_REMOVED, VIRTUSPHERE_REMOTE_SECRET_ABSENT], true)) {
            $state = $words[1];
        }
    }

    return $state;
}

/** Why a removal is not confirmed, for a diagnostic. @param array<string, mixed> $result */
function deploy_remote_secret_unconfirmed_reason(array $result): string
{
    $exitCode = (int) ($result['exit_code'] ?? -1);

    return $exitCode !== 0
        ? 'the host returned exit code ' . $exitCode . '.'
        : 'the host answered without confirming the removal.';
}

/**
 * Removes accounts.yml (or, with $removeWholeDir, the whole job directory) over
 * SSH and forgets the row when the host confirmed it. Never throws: it runs in a
 * finally block, and a side channel must not end the job it describes.
 *
 * Returns true only when the host confirmed that the file is gone, removed now or
 * already absent. A failed removal keeps the row, records the attempt, ends a
 * lease and says so in the error log, so the next contact with the same target
 * removes the file. The next contact removes only the file, never the directory,
 * and the diagnostic says so.
 *
 * @param callable(): mysqli $connection the worker passes fn () => $channel->connection()
 * @param array<string, mixed> $credential the Ansible credential
 * @param (callable(array<string,mixed>, string, string, int): array{exit_code: int, output: string})|null $execute ssh_execute_capture() signature
 */
function deploy_remote_secret_release(callable $connection, array $credential, string $secret, int $jobId, string $remoteDir, bool $removeWholeDir, ?callable $execute = null): bool
{
    // A ternary, not "??": the portal closure contract resolves a callable through a
    // ternary of a declared callable and a first-class callable only.
    $run = $execute !== null ? $execute : ssh_execute_capture(...);
    try {
        $result = $run($credential, $secret, ansible_remote_secret_remove_command($remoteDir, $removeWholeDir), VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS);
        $state = deploy_remote_secret_removal_state($result);
        if ($state === null) {
            throw new RuntimeException(deploy_remote_secret_unconfirmed_reason($result));
        }
    } catch (Throwable $exception) {
        deploy_remote_secret_record_attempt($connection, 'job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]);
        deploy_worker_cleanup_diagnose(
            $jobId,
            ($removeWholeDir
                ? 'Remote job directory ' . $remoteDir . ' could not be removed: ' . $exception->getMessage()
                    . ' Its ESXi access file will be removed at the next contact; the rest of the directory stays.'
                : 'ESXi access file of job ' . $jobId . ' in ' . $remoteDir . ' could not be removed: ' . $exception->getMessage()
                    . ' It will be removed at the next contact with this Ansible host.'),
            $secret
        );

        return false;
    }

    if ($state === VIRTUSPHERE_REMOTE_SECRET_ABSENT) {
        deploy_worker_cleanup_diagnose($jobId, 'The ESXi access file of job ' . $jobId . ' in ' . $remoteDir . ' was not on the Ansible host any more; its record is closed.', $secret);
    }
    try {
        deploy_remote_secret_forget($connection(), $jobId, $remoteDir);
    } catch (Throwable $exception) {
        // The file is gone, the row is not: the next contact removes it again, which costs one idempotent rm.
        deploy_worker_cleanup_diagnose(
            $jobId,
            'The ESXi access file in ' . $remoteDir . ' is gone, but its record could not be closed: ' . $exception->getMessage(),
            $secret
        );
    }

    return true;
}

/**
 * Records a failed removal: the attempt time orders the sweep (rows never tried
 * first), and a running lease ends, because its query is over.
 *
 * @param list<int|string> $params
 */
function deploy_remote_secret_record_attempt(callable $connection, string $where, string $types, array $params): void
{
    try {
        repo_execute($connection(), 'UPDATE deploy_remote_secret_files SET last_attempt_at = NOW(6), lease_until = NULL WHERE ' . $where, $types, $params);
    } catch (Throwable $exception) {
        // Only the order of the next sweep depends on it; the row itself stays either way.
        // The UPDATE is a standalone statement (no enclosing transaction), so a deadlock
        // rolls back only this statement and the release must still finish its diagnosis.
        error_log('[deploy-remote-secret] attempt time not recorded: ' . $exception::class);
    }
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
