<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_job_log_spool.php';
require_once __DIR__ . '/deploy_job_output.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
// The channel's own domain is the outage state machine. Its repository adapter
// and the worker's waiting policy are separate modules (ADR-0006); requiring
// them here keeps this file the single public require path both job processors
// already use.
require_once __DIR__ . '/deploy_worker_db_operations.php';

/**
 * The database side channel of a job that is currently executing on the Ansible
 * host.
 *
 * A job log line and a job heartbeat are not the job. The playbook runs on the
 * Ansible host and keeps creating VMs whether or not this process can reach
 * MySQL, so a database outage during a run must not end the SSH stream: the
 * remote exit code is the only thing that can still be learned about that work,
 * and closing the channel throws it away. Before this class, the first
 * mysqli_sql_exception from a stream logger or a heartbeat propagated out of the
 * SSH callback, aborted the transport, and the job was then classified as a
 * transport failure - so a database restart read as "the Ansible host answered
 * unexpectedly" while the playbook carried on unattended.
 *
 * Four rules, each of which was a defect on its own:
 *
 * 1. The channel OWNS the connection. Callbacks ask it for the live handle
 *    instead of capturing a mysqli in a closure, because after a reconnect a
 *    captured handle is a dead object that keeps throwing.
 * 2. An outage is announced exactly once, on STDERR, redacted. One line per
 *    failed write would bury the container log at Ansible's output rate.
 * 3. Finished, already redacted log lines are spooled in a bounded FIFO. An
 *    unbounded spool turns a database restart into an OOM kill of the process
 *    holding the job; silent dropping turns it into a job log that lies. So the
 *    oldest lines go first and the count that fell out is reported as its own
 *    SYSTEM line once the database is back.
 * 4. A tick attempts AT MOST one reconnect, and only when the backoff is due.
 *    A retry loop inside a stream callback stops reading the SSH channel, which
 *    is the very failure this class exists to prevent.
 *
 * After a successful reconnect the order is fixed and not negotiable: ownership
 * first, then the heartbeat, then the spool. Draining first would write a
 * foreign job's log if this job was reaped and re-claimed meanwhile, and beating
 * the heartbeat first would extend a lock this worker may no longer hold.
 */
final class DeployWorkerDbChannel
{
    private mysqli $db;

    /** @var callable(): mysqli */
    private $connector;

    /** @var callable(): int */
    private $clock;

    private bool $connected = true;

    private int $nextAttemptAt = 0;

    private int $backoffSeconds = VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS;

    private DeployJobLogSpool $spool;

    private DeployJobOutputGate $gate;

    private bool $ownershipLost = false;

    private ?string $ownershipReason = null;

    private ?int $outageSince = null;

    private int $outages = 0;

    private DeployWorkerDbOperations $ops;

    /**
     * @param callable(): mysqli $connector one bounded connect attempt; it may
     *        throw, and a throw is what keeps the backoff running
     * @param callable(): int|null $clock injectable for tests; nothing here ever
     *        sleeps, so a test needs no real time to pass
     */
    public function __construct(
        mysqli $db,
        callable $connector,
        private int $jobId,
        private string $workerId,
        ?callable $clock = null,
        ?DeployWorkerDbOperations $ops = null
    ) {
        $this->db = $db;
        $this->connector = $connector;
        $this->clock = $clock ?? static fn (): int => time();
        $this->ops = $ops ?? new DeployWorkerDbOperations();
        $this->spool = new DeployJobLogSpool();
        $this->gate = new DeployJobOutputGate();
    }

    /**
     * The live handle. Every caller has to ask each time: a local $db in a job
     * processor is stale the moment the channel reconnects.
     */
    public function connection(): mysqli
    {
        return $this->db;
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** How many separate outages this channel has seen; one state line each. */
    public function outageCount(): int
    {
        return $this->outages;
    }

    public function spooledLineCount(): int
    {
        return $this->spool->count();
    }

    public function droppedLineCount(): int
    {
        return $this->spool->droppedCount();
    }

    /**
     * True once a reconnect established that this job is no longer ours. The
     * caller stops the remote run WITHOUT writing a result: whoever owns the job
     * now (the reaper, another worker) has already published one, and
     * overwriting it would replace an established terminal state with a guess.
     */
    public function hasLostOwnership(): bool
    {
        return $this->ownershipLost;
    }

    public function ownershipReason(): ?string
    {
        return $this->ownershipReason;
    }

    /**
     * The secrets of this job, redacted out of every line before it is stored
     * (Etappe 8). Set once the processor has loaded them; before that there is
     * nothing to redact, because nothing remote has run yet.
     *
     * @param array<int, mixed> $secrets
     */
    public function withSecrets(array $secrets): void
    {
        $this->gate->withSecrets($secrets);
    }

    /**
     * Writes one finished job-log line, or spools it while the database is gone.
     *
     * Every line passes the output gate first (Etappe 8): normalisation, secret
     * redaction and both output limits decide here what may be stored, before
     * the line can reach the database OR the spool. At the call sites it would
     * be one rule per caller, and after the spool it would mean an outage can
     * park a secret that a later drain then persists.
     */
    public function log(string $stream, string $line): void
    {
        foreach ($this->gate->accept($stream, $line) as $row) {
            $this->write($row['stream'], $row['line']);
        }
    }

    /** The raw write: to the database, or to the spool while it is gone. */
    private function write(string $stream, string $line): void
    {
        if (!$this->connected) {
            $this->spool->push($stream, $line);

            return;
        }

        try {
            $this->ops->appendLog($this->db, $this->jobId, $stream, $line);
        } catch (mysqli_sql_exception $exception) {
            $this->enterOutage($exception);
            $this->spool->push($stream, $line);
        }
    }

    /**
     * One liveness tick from the transport: the file heartbeat always, the job
     * heartbeat when the database is there, and at most one reconnect attempt
     * when it is not.
     *
     * The file heartbeat comes first and unconditionally. It is what tells the
     * container the process is healthy, and a worker waiting out a database
     * outage is healthy - killing it would abandon the very playbook this class
     * is protecting.
     */
    public function tick(int $intervalSeconds = VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS): void
    {
        $this->ops->touchProcessHeartbeat();

        if (!$this->connected) {
            $this->attemptReconnect();

            return;
        }

        try {
            $this->ops->heartbeatTick($this->db, $this->jobId, $this->workerId, $intervalSeconds);
        } catch (mysqli_sql_exception $exception) {
            $this->enterOutage($exception);
            $this->attemptReconnect();
        }
    }

    /**
     * The step-boundary ownership check. While the database is unreachable there
     * is nothing to check against, so this reports "still ours" rather than
     * inventing a verdict; the reconnect path performs the real check and sets
     * hasLostOwnership().
     */
    public function assertJobIsOurs(): void
    {
        if (!$this->connected) {
            return;
        }

        try {
            $this->ops->assertJobIsOurs($this->db, $this->jobId, $this->workerId);
        } catch (mysqli_sql_exception $exception) {
            $this->enterOutage($exception);
        }
    }

    /**
     * Waits for the database after the remote command has ended, so the outcome
     * can still be persisted. Bounded by attempts, never by an open-ended loop:
     * `--once` must stay fail-fast, and even `--loop` has to give the caller the
     * chance to report a non-persistable outcome instead of hanging forever.
     *
     * @param callable(int): void|null $sleeper injected so tests do not wait
     */
    public function recover(int $maxAttempts, ?callable $sleeper = null): bool
    {
        if ($this->connected) {
            return true;
        }
        $sleeper ??= static function (int $seconds): void {
            sleep($seconds);
        };

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->attemptReconnect(true)) {
                return true;
            }
            $this->ops->touchProcessHeartbeat();
            $sleeper($this->backoffSeconds);
        }

        return false;
    }

    private function enterOutage(mysqli_sql_exception $exception): void
    {
        if (!$this->connected) {
            return;
        }
        $this->connected = false;
        $this->outages++;
        $this->outageSince = ($this->clock)();
        $this->backoffSeconds = VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS;
        $this->nextAttemptAt = $this->outageSince + $this->backoffSeconds;

        // Exactly one line per outage, and it says what is being protected, not
        // just what broke: the operator reading the container log needs to know
        // the remote run was NOT aborted.
        fwrite(STDERR, '[deploy-worker] database unreachable while deploy job ' . $this->jobId
            . ' is running; keeping the remote run and spooling its log lines: '
            . deploy_worker_redact_secrets($exception->getMessage(), []) . "\n");
    }

    /**
     * One attempt, and only when it is due. Returns whether the channel is up
     * afterwards.
     */
    private function attemptReconnect(bool $force = false): bool
    {
        $now = ($this->clock)();
        if (!$force && $now < $this->nextAttemptAt) {
            return false;
        }

        try {
            $this->db = ($this->connector)();
        } catch (Throwable) {
            $this->backoffSeconds = min(
                VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MAX_SECONDS,
                $this->backoffSeconds * 2
            );
            $this->nextAttemptAt = $now + $this->backoffSeconds;

            return false;
        }

        $this->connected = true;
        $this->backoffSeconds = VIRTUSPHERE_DEPLOY_DB_CHANNEL_BACKOFF_MIN_SECONDS;
        $this->nextAttemptAt = 0;
        $outageSeconds = $this->outageSince === null ? 0 : max(0, $now - $this->outageSince);
        $this->outageSince = null;

        return $this->resume($outageSeconds);
    }

    /**
     * Ownership, then heartbeat, then spool. Any of the three may hit a database
     * that is only briefly back; that re-enters the outage rather than throwing
     * into a stream callback.
     */
    private function resume(int $outageSeconds): bool
    {
        try {
            $this->ops->assertJobIsOurs($this->db, $this->jobId, $this->workerId);
        } catch (DeployWorkerCancelled $lost) {
            // Not our job any more. Drop the spool unwritten: those lines belong
            // to a run whose conclusion somebody else has already published.
            $this->ownershipLost = true;
            $this->ownershipReason = $lost->getMessage();
            $this->spool->clear();

            return true;
        } catch (mysqli_sql_exception $exception) {
            // Still inside the window attemptReconnect() opened, so the channel
            // is marked up and enterOutage() takes effect.
            $this->enterOutage($exception);

            return false;
        }

        try {
            $this->ops->touchJobHeartbeat($this->db, $this->jobId, $this->workerId);

            $buffered = $this->spool->take();
            $dropped = $buffered['dropped'];
            $spool = $buffered['lines'];

            $this->ops->appendLog(
                $this->db,
                $this->jobId,
                VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
                'Database was unreachable for ' . $outageSeconds . ' s; the remote run continued. '
                    . count($spool) . ' buffered output line(s) follow'
                    . ($dropped > 0 ? ', ' . $dropped . ' older line(s) were dropped by the buffer limit of '
                        . VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES : '')
                    . '.'
            );
            foreach ($spool as $entry) {
                $this->ops->appendLog($this->db, $this->jobId, $entry['stream'], $entry['line']);
            }
        } catch (mysqli_sql_exception $exception) {
            // Still inside the window attemptReconnect() opened, so the channel
            // is marked up and enterOutage() takes effect.
            $this->enterOutage($exception);

            return false;
        }

        return true;
    }
}

// Loaded last on purpose: the policy module type-hints the class above, and this
// keeps `require_once __DIR__ . '/deploy_worker_db_channel.php'` the one path a
// caller needs for the channel, its adapter and the open/settle policy.
require_once __DIR__ . '/deploy_worker_db_recovery.php';
