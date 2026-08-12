<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/deploy_worker_db_channel.php';

/**
 * Streamed output: the line splitter both job processors hand to the SSH
 * transport, its flush, and the credential lookup they share.
 *
 * Every chunk also beats the job heartbeat, which is what keeps a playbook that
 * is busy without printing from looking like a dead worker.
 *
 * Both write through the DeployWorkerDbChannel rather than a captured mysqli.
 * This is the hot path of a database outage: Ansible keeps producing output at
 * full rate while MySQL is gone, so the failure has to be absorbed here (spool,
 * one state line, at most one reconnect per tick) instead of escaping into the
 * SSH transport, which would abort a playbook that is still creating VMs.
 */
function deploy_worker_credential(mysqli $db, int $credentialId, string $type): array
{
    return repo_deploy_assert_credential_type($db, $credentialId, $type);
}

function deploy_worker_log_stream_chunk(DeployWorkerDbChannel $channel, string $stream, string &$buffer, string $chunk, ?callable $onLine = null): void
{
    $channel->tick();
    $buffer .= str_replace("\r\n", "\n", str_replace("\r", "\n", $chunk));
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        $channel->log($stream, $line);
        if ($onLine !== null) {
            $onLine($line);
        }
    }
}

function deploy_worker_log_stream_flush(DeployWorkerDbChannel $channel, string $stream, string &$buffer, ?callable $onLine = null): void
{
    if ($buffer === '') {
        return;
    }

    $channel->log($stream, $buffer);
    if ($onLine !== null) {
        $onLine($buffer);
    }
    $channel->tick(0);
    $buffer = '';
}
