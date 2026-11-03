<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_db_operations.php';

/**
 * A DeployWorkerDbOperations that keeps stream AND line of every write.
 *
 * The channel's own test double records only the line text, which is enough
 * for the outage state machine but not for the output gate: whether a
 * truncation notice went to SYSTEM rather than into the middle of the playbook
 * output is exactly the thing under test there.
 */
final class RecordingDbOperations extends DeployWorkerDbOperations
{
    /** @var list<array{stream: string, line: string}> */
    public array $logs = [];

    public function appendLog(mysqli $db, int $jobId, string $stream, string $line): void
    {
        $this->logs[] = ['stream' => $stream, 'line' => $line];
    }

    public function touchJobHeartbeat(mysqli $db, int $jobId, string $workerId): void
    {
    }

    public function heartbeatTick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds): void
    {
    }

    public function assertJobIsOurs(mysqli $db, int $jobId, string $workerId): void
    {
    }

    public function touchProcessHeartbeat(): void
    {
    }
}
