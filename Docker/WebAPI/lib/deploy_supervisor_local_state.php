<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_supervisor_policy.php';

final class DeploySupervisorLockException extends RuntimeException
{
}

final class DeploySupervisorStateException extends RuntimeException
{
}

/**
 * The supervisor's local, authoritative restart budget.
 *
 * The lock file is deliberately separate from the JSON file: state is
 * replaced atomically, while the lock inode and its LOCK_EX ownership stay
 * unchanged for this object's whole lifetime.
 */
final class DeploySupervisorLocalState
{
    private const STATE_FILE = 'state.json';
    private const LOCK_FILE = 'supervisor.lock';
    private const FORMAT_VERSION = 1;
    // The fixed schema currently encodes below one KiB. Keep generous room for
    // a future additive version without allowing a corrupt file to exhaust the
    // supervisor before it can fail closed.
    private const MAX_STATE_BYTES = 16384;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function acquire(): void
    {
        if ($this->lockHandle !== null) {
            return;
        }
        if (!is_dir($this->directory)
            && !@mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)) {
            throw new DeploySupervisorLockException('Supervisor state directory cannot be created.');
        }
        if (!@chmod($this->directory, 0700)) {
            throw new DeploySupervisorLockException('Supervisor state directory permissions cannot be restricted.');
        }

        $lockPath = $this->lockPath();
        if ((file_exists($lockPath) || is_link($lockPath))
            && (is_link($lockPath) || !is_file($lockPath))) {
            throw new DeploySupervisorLockException('Supervisor lifetime lock is not a regular file.');
        }
        // `e` sets close-on-exec on Linux. Without it proc_open would inherit
        // this otherwise unrelated descriptor into the worker child, and the
        // child could keep the lifetime lock after its supervisor died.
        $handle = @fopen($lockPath, 'c+be');
        if (!is_resource($handle)) {
            throw new DeploySupervisorLockException('Supervisor lifetime lock cannot be opened.');
        }
        if (!@chmod($lockPath, 0600)) {
            fclose($handle);
            throw new DeploySupervisorLockException('Supervisor lifetime lock permissions cannot be restricted.');
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new DeploySupervisorLockException('Another supervisor owns the lifetime lock.');
        }
        $this->lockHandle = $handle;
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        $this->assertLocked();
        $path = $this->statePath();
        if (!file_exists($path) && !is_link($path)) {
            return deploy_supervisor_initial_state();
        }
        if (is_link($path) || !is_file($path)) {
            throw new DeploySupervisorStateException('Supervisor state is not a regular file.');
        }
        if (!@chmod($path, 0600)) {
            throw new DeploySupervisorStateException('Supervisor state permissions cannot be restricted.');
        }
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_STATE_BYTES) {
            throw new DeploySupervisorStateException('Supervisor state has an invalid size.');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new DeploySupervisorStateException('Supervisor state is unreadable.');
        }
        try {
            $json = @stream_get_contents($handle, self::MAX_STATE_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($json) || $json === '') {
            throw new DeploySupervisorStateException('Supervisor state is unreadable.');
        }
        if (strlen($json) > self::MAX_STATE_BYTES) {
            throw new DeploySupervisorStateException('Supervisor state exceeds its size bound.');
        }
        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DeploySupervisorStateException('Supervisor state is corrupt.', 0, $exception);
        }
        if (!is_array($document)
            || ($document['version'] ?? null) !== self::FORMAT_VERSION
            || !isset($document['state'])
            || !is_array($document['state'])) {
            throw new DeploySupervisorStateException('Supervisor state has an unsupported format.');
        }

        return $this->validatedState($document['state']);
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        $this->assertLocked();
        $state = $this->validatedState($state);
        try {
            $json = json_encode(
                ['version' => self::FORMAT_VERSION, 'state' => $state],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ) . "\n";
        } catch (JsonException $exception) {
            throw new DeploySupervisorStateException('Supervisor state cannot be encoded.', 0, $exception);
        }

        $temporary = $this->directory . DIRECTORY_SEPARATOR . '.state-'
            . (getmypid() ?: 0) . '-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new DeploySupervisorStateException('Temporary supervisor state cannot be opened.');
        }

        $renamed = false;
        try {
            try {
                if (!@chmod($temporary, 0600)) {
                    throw new DeploySupervisorStateException('Supervisor state permissions cannot be restricted.');
                }
                $offset = 0;
                $length = strlen($json);
                while ($offset < $length) {
                    $written = @fwrite($handle, substr($json, $offset));
                    if (!is_int($written) || $written <= 0) {
                        throw new DeploySupervisorStateException('Supervisor state cannot be written.');
                    }
                    $offset += $written;
                }
                if (!@fflush($handle)) {
                    throw new DeploySupervisorStateException('Supervisor state cannot be flushed.');
                }
                if (function_exists('fsync') && !@fsync($handle)) {
                    throw new DeploySupervisorStateException('Supervisor state cannot be synchronized.');
                }
            } finally {
                fclose($handle);
            }

            if (!@rename($temporary, $this->statePath())) {
                throw new DeploySupervisorStateException('Supervisor state cannot be replaced atomically.');
            }
            $renamed = true;
        } finally {
            if (!$renamed) {
                @unlink($temporary);
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function validatedState(array $state): array
    {
        $expected = array_keys(deploy_supervisor_initial_state());
        $actual = array_keys($state);
        sort($expected);
        sort($actual);
        if ($actual !== $expected
            || !is_string($state['phase'])
            || !in_array($state['phase'], VIRTUSPHERE_SUPERVISOR_PHASES, true)
            || !is_int($state['stale_confirmations'])
            || $state['stale_confirmations'] < 0
            || !is_int($state['restart_count'])
            || $state['restart_count'] < 0) {
            throw new DeploySupervisorStateException('Supervisor state does not match its schema.');
        }
        foreach ([
            'term_sent_at', 'kill_sent_at', 'cooldown_until', 'restart_window_started_at',
            'next_retry_at', 'child_started_at',
        ] as $field) {
            if ($state[$field] !== null && (!is_int($state[$field]) || $state[$field] < 0)) {
                throw new DeploySupervisorStateException('Supervisor state timestamp is invalid.');
            }
        }

        return $state;
    }

    private function assertLocked(): void
    {
        if (!is_resource($this->lockHandle)) {
            throw new DeploySupervisorLockException('Supervisor state used without its lifetime lock.');
        }
    }

    private function statePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function lockPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }
}

/**
 * Reconcile a state that claimed a live child before this process started.
 *
 * A newly started supervisor has no local process handle and therefore no
 * waitpid evidence about the old child. It accounts the reserved run against
 * the durable budget, but latches `manual` regardless of its own PID. Stored
 * PIDs are intentionally absent; this code does not adopt a process by number.
 *
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function deploy_supervisor_restore_local_state(array $state, int $now): array
{
    if (!in_array($state['phase'], [
        VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING,
        VIRTUSPHERE_SUPERVISOR_PHASE_STOPPING,
    ], true)) {
        return $state;
    }
    // A new PHP PID 1 is not evidence of a new PID namespace: PID 1 may have
    // been replaced with exec while its child survived. Preserve all restart
    // accounting, charge the reserved run conservatively, and latch manual.
    // Only an operator who established that the old process is gone may clear
    // this state; no PID from disk is ever treated as ownership evidence.
    $state = deploy_supervisor_after_exit($state, $now)['state'];
    $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL;

    return $state;
}
