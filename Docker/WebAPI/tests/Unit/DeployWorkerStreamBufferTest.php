<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_stream.php';

final class DeployWorkerStreamBufferTest extends TestCase
{
    public function testOversizedLineCannotLeakAPartialSecretOrBecomeAMarker(): void
    {
        $ops = new class extends DeployWorkerDbOperations {
            public array $lines = [];
            public function appendLog(mysqli $db, int $jobId, string $stream, string $line): void { $this->lines[] = $line; }
            public function heartbeatTick(mysqli $db, int $jobId, string $workerId, int $intervalSeconds): void {}
            public function touchProcessHeartbeat(): void {}
        };
        $channel = new DeployWorkerDbChannel(new mysqli(), static fn (): mysqli => new mysqli(), 1, 'fixture', null, $ops);
        $channel->withSecrets(['sensitive-password']);
        $buffer = '';
        $markers = [];
        $observe = static function (string $line) use (&$markers): void { $markers[] = $line; };
        deploy_worker_log_stream_chunk($channel, 'stdout', $buffer, str_repeat('x', VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES - 4) . 'sensi', $observe);
        for ($i = 0; $i < 20; $i++) {
            deploy_worker_log_stream_chunk($channel, 'stdout', $buffer, str_repeat('y', 10000), $observe);
            self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_OUTPUT_LINE_MAX_BYTES + 1, strlen($buffer));
        }
        deploy_worker_log_stream_chunk($channel, 'stdout', $buffer, "tive-password\nvalid ä", $observe);
        deploy_worker_log_stream_flush($channel, 'stdout', $buffer, $observe, false);
        self::assertSame(['valid ä'], $markers);
        self::assertSame(['[oversized remote output line discarded]', 'valid ä'], $ops->lines);
        self::assertSame('', $buffer);
    }
}
