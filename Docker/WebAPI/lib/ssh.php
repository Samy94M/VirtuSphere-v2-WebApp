<?php

declare(strict_types=1);

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/connection_errors.php';

/**
 * Connection tests return a code plus an operator detail, never a ready-made
 * sentence: the portal localizes the code and renders the detail separately
 * (portal.md: no raw getMessage() in user-facing output).
 *
 * 'code' is a VIRTUSPHERE_INVENTORY_ERROR_* category, or one of the two
 * test-only outcomes below.
 *
 * Only the Ansible credential is tested synchronously here. ESXi is never spoken
 * to from PHP: the portal reaches it through the Ansible host, and testing a
 * transport production does not use produced two documented false negatives (a
 * `tls` verdict for a self-signed certificate the playbooks accept, and an HTTP
 * 404 from a vCenter-only REST path). The credentials page enqueues a real
 * inventory pull instead (ADR-0023 amendment 3).
 */
const VIRTUSPHERE_CREDENTIAL_TEST_OK = 'ok';
const VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT = 'preflight';

/**
 * @param array<string, string|int> $context Placeholders for the portal message.
 * @return array{ok: bool, code: string, detail: string, context: array<string, string|int>}
 */
function credential_test_result(bool $ok, string $code, string $detail = '', array $context = []): array
{
    return ['ok' => $ok, 'code' => $code, 'detail' => $detail, 'context' => $context];
}

function credential_test_connection(array $credential, string $secret): array
{
    $type = (string) ($credential['type'] ?? '');
    if ($type === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) {
        return credential_test_ansible($credential, $secret);
    }

    return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG, 'Unsupported credential type for a synchronous test: ' . $type);
}

function credential_test_ansible(array $credential, string $secret): array
{
    $login = credential_test_ssh($credential, $secret);
    if (!$login['ok']) {
        return $login;
    }

    try {
        $result = ssh_execute_capture($credential, $secret, ansible_preflight_command(), 25);
        $exitCode = (int) $result['exit_code'];
        if ($exitCode === 0) {
            return credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK);
        }

        // The login worked, so this is not a credential problem: the remote host
        // is missing Ansible or the preflight command itself failed.
        return credential_test_result(
            false,
            VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT,
            connection_error_detail((string) $result['output'], $secret),
            ['status' => $exitCode]
        );
    } catch (Throwable $exception) {
        return credential_test_ssh_failure($exception, $secret, [
            'host' => (string) ($credential['host'] ?? ''),
            'port' => credential_ssh_port($credential['port'] ?? null),
        ]);
    }
}

function credential_test_ssh(array $credential, string $secret): array
{
    if (!class_exists(SSH2::class)) {
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG, 'phpseclib is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG, 'Host and username are required.');
    }

    $context = ['host' => $host, 'port' => $port];

    try {
        $ssh = new SSH2($host, $port, 8);
        if ($ssh->login($username, $secret)) {
            $ssh->disconnect();

            return credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK);
        }

        // phpseclib reports a rejected login as false, not as an exception.
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_AUTH, 'SSH login rejected for user ' . $username . '.', $context);
    } catch (Throwable $exception) {
        return credential_test_ssh_failure($exception, $secret, $context);
    }
}

/**
 * Maps an SSH-side throwable. Anything the shared classifier cannot place is an
 * SSH transport problem rather than an unparsable response.
 *
 * @param array<string, string|int> $context
 */
function credential_test_ssh_failure(Throwable $exception, string $secret, array $context = []): array
{
    $category = connection_error_category($exception->getMessage());
    if ($category === VIRTUSPHERE_INVENTORY_ERROR_PARSE) {
        $category = VIRTUSPHERE_INVENTORY_ERROR_SSH;
    }

    return credential_test_result(false, $category, connection_error_detail($exception->getMessage(), $secret), $context);
}

function ssh_sftp_upload_directory(array $credential, string $secret, string $localDir, string $remoteDir, ?callable $logger = null): void
{
    if (!class_exists(SFTP::class)) {
        throw new RuntimeException('phpseclib SFTP is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        throw new RuntimeException('Ansible SSH host and username are required.');
    }
    if (!is_dir($localDir)) {
        throw new RuntimeException('Local deploy work directory does not exist.');
    }

    $sftp = new SFTP($host, $port, 15);
    if (!$sftp->login($username, $secret)) {
        throw new RuntimeException('SFTP login failed.');
    }

    ssh_sftp_mkdir_recursive($sftp, $remoteDir);
    $files = scandir($localDir);
    if ($files === false) {
        throw new RuntimeException('Cannot read local deploy work directory.');
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
        if (!$sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('SFTP upload failed for ' . $file . '.');
        }

        if ($logger !== null) {
            $logger('Uploaded ' . $file . ' to Ansible host.');
        }
    }

    $sftp->disconnect();
}

function ssh_execute_capture(array $credential, string $secret, string $command, int $timeout = 20): array
{
    $output = '';
    $exitCode = ssh_execute_command($credential, $secret, $command, static function (string $chunk) use (&$output): void {
        $output .= $chunk;
    }, $timeout);

    return ['exit_code' => $exitCode, 'output' => $output];
}

function ssh_execute_command(array $credential, string $secret, string $command, callable $stdoutLogger, int $timeout = 0): int
{
    if (!class_exists(SSH2::class)) {
        throw new RuntimeException('phpseclib SSH is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        throw new RuntimeException('Ansible SSH host and username are required.');
    }

    $ssh = new SSH2($host, $port, 15);
    $ssh->setTimeout($timeout);
    if (!$ssh->login($username, $secret)) {
        throw new RuntimeException('SSH login failed.');
    }

    $ssh->exec($command, static function (string $chunk) use ($stdoutLogger): void {
        $stdoutLogger($chunk);
    });

    $status = $ssh->getExitStatus();
    $ssh->disconnect();

    return $status === false || $status === null ? 0 : (int) $status;
}

function ssh_sftp_mkdir_recursive(SFTP $sftp, string $dir): void
{
    $dir = '/' . trim($dir, '/');
    $parts = array_values(array_filter(explode('/', $dir), static fn (string $part): bool => $part !== ''));
    $path = '';

    foreach ($parts as $part) {
        $path .= '/' . $part;
        if (!$sftp->is_dir($path) && !$sftp->mkdir($path)) {
            throw new RuntimeException('Cannot create remote directory: ' . $path);
        }
    }
}
