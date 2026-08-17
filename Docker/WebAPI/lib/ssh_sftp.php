<?php

declare(strict_types=1);

use phpseclib3\Net\SFTP;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';

/** @param null|callable():float $now */
function ssh_transport_monotonic_now(?callable $now = null): float
{
    return $now !== null ? (float) $now() : hrtime(true) / 1_000_000_000;
}

function ssh_sftp_total_budget_message(string $activity): string
{
    return $activity . ' exceeded the total time budget of '
        . VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS . ' seconds.';
}

/**
 * Returns the exact timeout for the next remote operation. phpseclib accepts a
 * fractional timeout, so a positive sub-second remainder never rounds to zero
 * (where phpseclib would disable the timeout).
 *
 * @param null|callable():float $now
 * @return array{seconds:float,limit:'operation'|'total'}
 */
function ssh_sftp_next_operation_budget(float $startedAt, string $totalBudgetMessage, ?callable $now = null): array
{
    $elapsed = max(0.0, ssh_transport_monotonic_now($now) - $startedAt);
    $remaining = VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS - $elapsed;
    if ($remaining <= 0.0) {
        throw new SshTransportBudgetExceeded($totalBudgetMessage);
    }

    return [
        'seconds' => min((float) VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS, $remaining),
        'limit' => $remaining <= VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS ? 'total' : 'operation',
    ];
}

/** @param null|callable():float $now */
function ssh_sftp_assert_total_budget(float $startedAt, string $totalBudgetMessage, ?callable $now = null): void
{
    ssh_sftp_next_operation_budget($startedAt, $totalBudgetMessage, $now);
}

/**
 * @param array{seconds:float,limit:'operation'|'total'} $budget
 */
function ssh_sftp_timeout_failure(
    string $operation,
    array $budget,
    string $totalBudgetMessage,
    ?Throwable $previous = null
): SshTransportBudgetExceeded {
    $message = $budget['limit'] === 'total'
        ? $totalBudgetMessage
        : 'SFTP operation "' . $operation . '" exceeded the operation time budget of '
            . VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS . ' seconds.';

    return new SshTransportBudgetExceeded($message, 0, $previous);
}

/**
 * Runs one remote SFTP operation under both budgets. A false result is checked
 * against isTimeout() before any cleanup or further SFTP call can erase the
 * state. Only callers such as is_dir() may explicitly accept a non-timeout
 * false result.
 *
 * @template T
 * @param callable():T $operation
 * @param null|callable():float $now
 * @return T
 */
function ssh_sftp_run_operation(
    SFTP $sftp,
    string $operationName,
    string $failureMessage,
    callable $operation,
    float $startedAt,
    string $totalBudgetMessage,
    ?callable $now = null,
    bool $allowFalse = false
): mixed {
    $budget = ssh_sftp_next_operation_budget($startedAt, $totalBudgetMessage, $now);
    $sftp->setTimeout($budget['seconds']);

    try {
        $result = $operation();
    } catch (Throwable $exception) {
        if ($sftp->isTimeout()) {
            throw ssh_sftp_timeout_failure($operationName, $budget, $totalBudgetMessage, $exception);
        }
        throw new SftpTransportFailed($failureMessage, 0, $exception);
    }

    if ($result === false) {
        if ($sftp->isTimeout()) {
            throw ssh_sftp_timeout_failure($operationName, $budget, $totalBudgetMessage);
        }
        if (!$allowFalse) {
            throw new SftpTransportFailed($failureMessage);
        }
    }

    // A successful operation may still have crossed the total deadline. This
    // post-check prevents the final upload or probe delete from hiding it.
    ssh_sftp_assert_total_budget($startedAt, $totalBudgetMessage, $now);

    return $result;
}

function ssh_sftp_login(SFTP $sftp, string $username, string $secret, string $failureMessage): void
{
    try {
        $loggedIn = $sftp->login($username, $secret);
    } catch (Throwable $exception) {
        throw new SftpTransportFailed($failureMessage, 0, $exception);
    }
    if (!$loggedIn) {
        throw new SftpTransportFailed($failureMessage);
    }
}

function ssh_sftp_upload_directory(
    array $credential,
    string $secret,
    string $localDir,
    string $remoteDir,
    ?callable $logger = null,
    ?callable $now = null
): void {
    if (!class_exists(SFTP::class)) {
        throw new SshTransportConfigurationException('phpseclib SFTP is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        throw new SshTransportConfigurationException('Ansible SSH host and username are required.');
    }
    if (!is_dir($localDir)) {
        throw new SshTransportConfigurationException('Local deploy work directory does not exist.');
    }

    try {
        $sftp = new SFTP($host, $port, 15);
    } catch (Throwable $exception) {
        throw new SftpTransportFailed('SFTP connection could not be initialized.', 0, $exception);
    }

    try {
        $sftp->setTimeout(VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS);
        ssh_sftp_login($sftp, $username, $secret, 'SFTP login failed.');

        // The total budget starts only after login and uses a monotonic clock:
        // wall-clock adjustments cannot lengthen or shorten a transfer.
        $startedAt = ssh_transport_monotonic_now($now);
        $totalBudgetMessage = ssh_sftp_total_budget_message('SFTP upload');
        ssh_sftp_mkdir_recursive($sftp, $remoteDir, $startedAt, $totalBudgetMessage, $now);

        $files = scandir($localDir);
        if ($files === false) {
            throw new SshTransportConfigurationException('Cannot read local deploy work directory.');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $localPath = $localDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($localPath)) {
                continue;
            }

            $remotePath = rtrim($remoteDir, '/') . '/' . $file;
            ssh_sftp_run_operation(
                $sftp,
                'upload ' . $file,
                'SFTP upload failed for ' . $file . '.',
                static fn (): bool => $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE),
                $startedAt,
                $totalBudgetMessage,
                $now
            );

            // DB heartbeat/log callbacks are intentionally outside the SFTP
            // guard. A mysqli failure must reach the worker as a DB-side-channel
            // failure, never be relabelled as an SFTP transport problem.
            if ($logger !== null) {
                $logger('Uploaded ' . $file . ' to Ansible host.');
            }
        }

        // Covers an empty directory and the successful final return.
        ssh_sftp_assert_total_budget($startedAt, $totalBudgetMessage, $now);
    } finally {
        $sftp->disconnect();
    }
}

/**
 * Proves the SFTP path a deploy relies on: login, write a tiny probe in /tmp,
 * then delete it. The delete result is load-bearing: a green probe must not
 * leave files behind.
 */
function ssh_sftp_probe(array $credential, string $secret, ?callable $now = null): void
{
    if (!class_exists(SFTP::class)) {
        throw new SshTransportConfigurationException('phpseclib SFTP is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        throw new SshTransportConfigurationException('Ansible SSH host and username are required.');
    }

    try {
        $sftp = new SFTP($host, $port, 15);
    } catch (Throwable $exception) {
        throw new SftpTransportFailed('SFTP connection could not be initialized.', 0, $exception);
    }

    try {
        $sftp->setTimeout(VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS);
        ssh_sftp_login(
            $sftp,
            $username,
            $secret,
            'SFTP login failed (the SSH login worked, so the SFTP subsystem is likely disabled).'
        );
        $startedAt = ssh_transport_monotonic_now($now);
        $totalBudgetMessage = ssh_sftp_total_budget_message('SFTP probe');

        try {
            $probePath = '/tmp/.virtusphere-preflight-' . bin2hex(random_bytes(4));
        } catch (Throwable $exception) {
            throw new SshTransportConfigurationException('Cannot create the local SFTP probe name.', 0, $exception);
        }

        ssh_sftp_run_operation(
            $sftp,
            'write probe file',
            'Cannot write into /tmp over SFTP on the Ansible host.',
            static fn (): bool => $sftp->put($probePath, 'virtusphere preflight'),
            $startedAt,
            $totalBudgetMessage,
            $now
        );
        ssh_sftp_run_operation(
            $sftp,
            'delete probe file',
            'Cannot delete the SFTP probe file on the Ansible host.',
            static fn (): bool => $sftp->delete($probePath),
            $startedAt,
            $totalBudgetMessage,
            $now
        );
        ssh_sftp_assert_total_budget($startedAt, $totalBudgetMessage, $now);
    } finally {
        $sftp->disconnect();
    }
}

function ssh_sftp_mkdir_recursive(
    SFTP $sftp,
    string $dir,
    ?float $startedAt = null,
    ?string $totalBudgetMessage = null,
    ?callable $now = null
): void {
    $startedAt ??= ssh_transport_monotonic_now($now);
    $totalBudgetMessage ??= ssh_sftp_total_budget_message('SFTP upload');
    $dir = '/' . trim($dir, '/');
    $parts = array_values(array_filter(explode('/', $dir), static fn (string $part): bool => $part !== ''));
    $path = '';

    foreach ($parts as $part) {
        $path .= '/' . $part;
        $exists = ssh_sftp_run_operation(
            $sftp,
            'inspect remote directory ' . $path,
            'Cannot inspect remote directory: ' . $path,
            static fn (): bool => $sftp->is_dir($path),
            $startedAt,
            $totalBudgetMessage,
            $now,
            true
        );
        if ($exists === false) {
            ssh_sftp_run_operation(
                $sftp,
                'create remote directory ' . $path,
                'Cannot create remote directory: ' . $path,
                static fn (): bool => $sftp->mkdir($path),
                $startedAt,
                $totalBudgetMessage,
                $now
            );
        }
    }
}
