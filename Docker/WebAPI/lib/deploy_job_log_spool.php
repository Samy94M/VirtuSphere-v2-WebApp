<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';

/**
 * The bounded buffer a running job's log lines wait in while the database is
 * gone.
 *
 * Its own mechanism, separate from the channel's outage state machine: an
 * unbounded spool turns a database restart into an OOM kill of the very process
 * holding the job, and silent dropping turns it into a job log that lies. So
 * the OLDEST lines go first - the tail is what explains how the run ended - and
 * how many fell out is carried alongside, because a gap the reader cannot see
 * is worse than a gap that is named.
 *
 * Lines arrive here already normalised and redacted (DeployJobOutputGate). That
 * order is load-bearing: an outage must never park a secret that a later drain
 * then persists.
 */
final class DeployJobLogSpool
{
    /** @var list<array{stream: string, line: string}> */
    private array $lines = [];

    private int $dropped = 0;

    public function push(string $stream, string $line): void
    {
        $this->lines[] = ['stream' => $stream, 'line' => $line];
        while (count($this->lines) > VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES) {
            array_shift($this->lines);
            $this->dropped++;
        }
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /**
     * Snapshot only. Entries stay buffered until their write is acknowledged.
     *
     * @return array{lines: list<array{stream: string, line: string}>, dropped: int}
     */
    public function snapshot(): array
    {
        return ['lines' => $this->lines, 'dropped' => $this->dropped];
    }

    public function acknowledgeLine(): void
    {
        array_shift($this->lines);
    }

    public function acknowledgeDropped(): void
    {
        $this->dropped = 0;
    }

    /** Throws the buffer away unwritten, for lines that are no longer ours. */
    public function clear(): void
    {
        $this->lines = [];
        $this->dropped = 0;
    }
}
