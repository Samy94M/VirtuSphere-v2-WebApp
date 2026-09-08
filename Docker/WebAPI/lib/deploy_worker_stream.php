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
    $chunk = str_replace(["\r\n", "\r"], "\n", $chunk);
    $offset = 0;
    do {
        $end = strpos($chunk, "\n", $offset);
        $length = ($end === false ? strlen($chunk) : $end) - $offset;
        if (strlen($buffer) + $length > VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES) {
            // Keep only a bounded discard sentinel until the real line ends.
            // Never emit a prefix: it could end halfway through a secret or
            // turn a truncated control marker into false execution evidence.
            $buffer = str_repeat(' ', VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES + 1);
        } else {
            $buffer .= substr($chunk, $offset, $length);
        }
        if ($end !== false) {
            deploy_worker_log_stream_flush($channel, $stream, $buffer, $onLine, false);
            $offset = $end + 1;
        }
    } while ($end !== false && $offset < strlen($chunk));
}

function deploy_worker_log_stream_flush(DeployWorkerDbChannel $channel, string $stream, string &$buffer, ?callable $onLine = null, bool $tick = true): void
{
    if ($buffer === '') {
        return;
    }

    $discarded = strlen($buffer) > VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES;
    $channel->log($stream, $discarded ? '[oversized remote output line discarded]' : $buffer);
    if (!$discarded && $onLine !== null) {
        $onLine($buffer);
    }
    if ($tick) {
        $channel->tick(0);
    }
    $buffer = '';
}
