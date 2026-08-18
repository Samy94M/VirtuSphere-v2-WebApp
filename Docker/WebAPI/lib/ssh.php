<?php

declare(strict_types=1);

use phpseclib3\Net\SSH2;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';
require_once __DIR__ . '/ssh_sftp.php';

/** Files that own the SSH/SFTP transport domain, including this facade. */
const VIRTUSPHERE_SSH_TRANSPORT_MODULES = [
    'ssh.php',
    'ssh_sftp.php',
    'ssh_transport_exceptions.php',
];

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
// SSH exec and the preflight passed, but the SFTP subsystem or /tmp write that
// every deploy relies on did not. A distinct code because it is not a login or
// a tooling problem: the account works, the file transport it needs does not.
const VIRTUSPHERE_CREDENTIAL_TEST_SFTP = 'sftp';
// Everything works, but the host's callback IP is missing from the machine-API
// allowlist: a deploy's MAC upload would get the legacy 403. Travels with
// ok=true, because the credential and the toolchain ARE fine; the portal turns
// it into a warning, not a failure.
const VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST = 'allowlist';

/**
 * @param array<string, string|int> $context Placeholders for the portal message.
 * @return array{ok: bool, code: string, detail: string, context: array<string, string|int>}
 */
function credential_test_result(bool $ok, string $code, string $detail = '', array $context = []): array
{
    return ['ok' => $ok, 'code' => $code, 'detail' => $detail, 'context' => $context];
}

function credential_test_connection(array $credential, string $secret, string $apiBaseUrl = ''): array
{
    $type = (string) ($credential['type'] ?? '');
    if ($type === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) {
        return credential_test_ansible($credential, $secret, $apiBaseUrl);
    }

    return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_CONFIG, 'Unsupported credential type for a synchronous test: ' . $type);
}

/**
 * Tests an Ansible credential end to end, in the order a deploy needs it: SSH
 * login, then the tooling/portal preflight over SSH exec, then a real SFTP
 * write into /tmp. Each layer only runs once the one below it passed, so the
 * failure that comes back is the first thing actually broken.
 */
function credential_test_ansible(array $credential, string $secret, string $apiBaseUrl = ''): array
{
    $login = credential_test_ssh($credential, $secret);
    if (!$login['ok']) {
        return $login;
    }

    try {
        $result = ssh_execute_capture($credential, $secret, ansible_preflight_command($apiBaseUrl, true), 25);
        $exitCode = (int) $result['exit_code'];
        if ($exitCode !== 0) {
            // The login worked, so this is not a credential problem: the remote
            // host is missing a tool, carries a too-old collection, or cannot
            // reach the portal. The last stage marker on stdout names the
            // component that broke the chain, so the operator sees "pyvmomi" or
            // "portal" instead of a bare exit code.
            $rawOutput = (string) $result['output'];
            $component = ansible_preflight_failed_component($rawOutput);
            return credential_test_result(
                false,
                VIRTUSPHERE_CREDENTIAL_TEST_PREFLIGHT,
                connection_error_detail(ansible_preflight_strip_markers($rawOutput), $secret),
                ['status' => $exitCode, 'component' => $component ?? '']
            );
        }
    } catch (Throwable $exception) {
        return credential_test_ssh_failure($exception, $secret, [
            'host' => (string) ($credential['host'] ?? ''),
            'port' => credential_ssh_port($credential['port'] ?? null),
        ]);
    }

    // Tooling is fine; now prove the file transport the deploy actually uses.
    // SSH exec working does not imply the SFTP subsystem is enabled or that /tmp
    // is writable, and both are load-bearing for every deploy.
    try {
        ssh_sftp_probe($credential, $secret);
    } catch (Throwable $exception) {
        return credential_test_sftp_failure($exception, $secret);
    }

    // The chain is green; the allowlist verdict decides between plain ok and
    // ok-with-warning. Reaching health.php proved the network path, but only
    // passing the db_importMAC.php IP gate proves the MAC upload would land.
    $allowlist = ansible_preflight_allowlist_verdict((string) $result['output']);
    if ($allowlist['status'] === 'denied') {
        return credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST, '', ['ip' => $allowlist['ip']]);
    }

    return credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK);
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
        return credential_test_result(false, VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH, 'SSH login rejected for user ' . $username . '.', $context);
    } catch (Throwable $exception) {
        return credential_test_ssh_failure($exception, $secret, $context);
    }
}

/**
 * Maps an SSH-side throwable using the shared Ansible-origin classifier
 * (Etappe 7): everything but the local-configuration case is qualified as an
 * Ansible-host finding, never a bare SSH/parse guess.
 *
 * @param array<string, string|int> $context
 */
function credential_test_ssh_failure(Throwable $exception, string $secret, array $context = []): array
{
    if ($exception instanceof SshTransportConfigurationException) {
        return credential_test_result(
            false,
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            connection_error_detail($exception->getMessage(), $secret),
            $context
        );
    }

    return credential_test_result(
        false,
        ansible_connection_error_category($exception),
        connection_error_detail($exception->getMessage(), $secret),
        $context
    );
}
function credential_test_sftp_failure(Throwable $exception, string $secret): array
{
    if ($exception instanceof SshTransportBudgetExceeded) {
        $code = VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT;
    } elseif ($exception instanceof SshTransportConfigurationException) {
        $code = VIRTUSPHERE_INVENTORY_ERROR_CONFIG;
    } else {
        $code = VIRTUSPHERE_CREDENTIAL_TEST_SFTP;
    }

    return credential_test_result(false, $code, connection_error_detail($exception->getMessage(), $secret));
}

function ssh_execute_capture(array $credential, string $secret, string $command, int $timeout = 20): array
{
    $output = '';
    $exitCode = ssh_execute_command($credential, $secret, $command, static function (string $chunk) use (&$output): void {
        $output .= $chunk;
    }, $timeout);

    return ['exit_code' => $exitCode, 'output' => $output];
}

/**
 * Runs a remote command over a BOUNDED transport (AP6). The old implementation
 * ran the playbook exec with an unlimited timeout, and when a caller did pass
 * one, phpseclib's timeout ended exec() without an exit status - which this
 * function then reported as exit 0: a hung remote command came back green.
 * Now every command has an idle and a total limit, and hitting either one
 * throws instead of returning a made-up success.
 *
 * @param callable(string):void $stdoutLogger Receives raw output chunks.
 * @param int $idleTimeout Seconds without any remote output before the command
 *        fails. Callers with a short, known-fast command (preflight, capture)
 *        pass their own budget; 0 or negative falls back to the SSoT default.
 * @param ?callable():void $onSilence Called roughly every
 *        VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS while the remote is silent. The
 *        deploy worker hangs its DB heartbeat here, so the heartbeat is
 *        time-based and a quiet clone task no longer looks like a dead worker.
 * @param int $totalTimeout Wall-clock cap regardless of output. 0 or negative
 *        falls back to the SSoT default; never below the idle timeout.
 */
function ssh_execute_command(array $credential, string $secret, string $command, callable $stdoutLogger, int $idleTimeout = 0, ?callable $onSilence = null, int $totalTimeout = 0): int
{
    if (!class_exists(SSH2::class)) {
        throw new SshTransportConfigurationException('phpseclib SSH is not available.');
    }

    $host = (string) ($credential['host'] ?? '');
    $port = credential_ssh_port($credential['port'] ?? null);
    $username = (string) ($credential['username'] ?? '');
    if ($host === '' || $username === '') {
        throw new SshTransportConfigurationException('Ansible SSH host and username are required.');
    }

    if ($idleTimeout <= 0) {
        $idleTimeout = VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS;
    }
    if ($totalTimeout <= 0) {
        $totalTimeout = VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS;
    }
    $totalTimeout = max($totalTimeout, $idleTimeout);

    $ssh = new SSH2($host, $port, 15);
    $ssh->setKeepAlive(VIRTUSPHERE_SSH_KEEPALIVE_INTERVAL_SECONDS);
    // The phpseclib timeout is only the read-slice length: each expiry returns
    // control so the loop below can tick the heartbeat and enforce the real
    // limits. It must never exceed the caller's idle budget.
    $ssh->setTimeout(min(VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS, $idleTimeout));
    if (!$ssh->login($username, $secret)) {
        throw new RuntimeException('SSH login failed.');
    }

    // exec() with callback === false only starts the command; the channel is
    // then drained manually, one slice per read, via the loop below. phpseclib
    // types the parameter as callable|null, but `false` is the documented way
    // to disable its internal callback loop, which is exactly what the bounded
    // AP6 read loop needs - null would make exec() block and drain internally.
    // @phpstan-ignore argument.type
    if ($ssh->exec($command, false) !== true) {
        $ssh->disconnect();
        throw new RuntimeException('SSH exec request failed.');
    }

    try {
        ssh_stream_command_output(static function () use ($ssh) {
            $slice = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
            if ($slice === false) {
                throw new RuntimeException('SSH transport failed while streaming command output.');
            }
            if ($slice === true) {
                // true means either "slice elapsed without data" or "channel
                // closed"; phpseclib's timeout flag tells them apart.
                return $ssh->isTimeout() ? true : false;
            }

            return (string) $slice;
        }, $stdoutLogger, $onSilence, $idleTimeout, $totalTimeout);
    } catch (Throwable $exception) {
        $ssh->disconnect();
        throw $exception;
    }

    $status = $ssh->getExitStatus();
    $ssh->disconnect();

    return $status === false || $status === null ? 0 : (int) $status;
}

/**
 * The bounded streaming loop, separated from the SSH plumbing so the timeout
 * and heartbeat semantics are provable with a scripted reader and a fake
 * clock (no SSH server in unit tests).
 *
 * @param callable():(string|bool) $readSlice Next output chunk; true for a
 *        silent slice (no data yet), false when the channel closed.
 * @param callable(string):void $stdoutLogger
 * @param ?callable():void $onSilence Called once per silent slice.
 * @param ?callable():int $clock Injectable time source for tests.
 */
function ssh_stream_command_output(callable $readSlice, callable $stdoutLogger, ?callable $onSilence, int $idleTimeout, int $totalTimeout, ?callable $clock = null): void
{
    $clock = $clock ?? static fn (): int => time();
    $startedAt = $clock();
    $lastDataAt = $startedAt;

    while (true) {
        $slice = $readSlice();
        $now = $clock();

        if ($slice === false) {
            return;
        }

        if ($slice === true) {
            if ($onSilence !== null) {
                $onSilence();
            }
            if (($now - $lastDataAt) >= $idleTimeout) {
                throw new SshTransportBudgetExceeded('Remote command produced no output for ' . $idleTimeout . ' seconds (idle timeout).');
            }
        } elseif ($slice !== '') {
            $stdoutLogger($slice);
            $lastDataAt = $now;
        }

        if (($now - $startedAt) >= $totalTimeout) {
            throw new SshTransportBudgetExceeded('Remote command exceeded the total time limit of ' . $totalTimeout . ' seconds.');
        }
    }
}
