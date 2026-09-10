<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once dirname(__DIR__, 2) . '/lib/worker_database_connect.php';

/** @group process */
final class WorkerDatabaseStopTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['virtusphere_worker_stop_requested'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['virtusphere_worker_stop_requested'] = false;
    }

    #[RunInSeparateProcess]
    public function testBothWorkersReturnNullBeforeAStartupConnectionWhenStopWasRequested(): void
    {
        $GLOBALS['virtusphere_worker_stop_requested'] = true;
        foreach (['deploy-worker', 'maintenance-worker'] as $component) {
            $calls = 0;
            $result = worker_database_connect(
                ['once' => false],
                $component,
                static function (): void {
                    self::fail('the connected callback ran after stop');
                },
                static function () use (&$calls): mysqli {
                    $calls++;
                    if ($calls > 2) {
                        throw new RuntimeException('startup stop regression exceeded its bounded attempts');
                    }
                    throw new mysqli_sql_exception('connector should not have run');
                }
            );
            self::assertNull($result, $component);
            self::assertSame(0, $calls, $component);
        }
    }

    public function testSigquitInterruptsTheDatabaseRetryWaitForBothWorkers(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the signal fault case');
        }

        $probe = $this->signalProbe('retry');
        self::assertSame(['deploy-worker', 'maintenance-worker'], array_keys($probe['result']));
        $expectedStderr = '';
        foreach ($probe['result'] as $component => $result) {
            self::assertTrue($result['returned_null'], $component);
            self::assertTrue($result['stop_requested'], $component);
            self::assertSame(1, $result['attempts'], $component);
            self::assertTrue($result['sender_exited'], $component);
            self::assertGreaterThanOrEqual(0, $result['stop_ms'], $component);
            self::assertLessThan(1000, $result['stop_ms'], $component . ' retry wait was not promptly interrupted');
            $expectedStderr .= "[$component] Database not reachable (attempt 1): synthetic unavailable database\n"
                . "[$component] signal 3 received, stopping after the current unit of work\n";
        }
        $expectedLines = explode("\n", trim($expectedStderr));
        $actualLines = explode("\n", trim($probe['stderr']));
        sort($expectedLines);
        sort($actualLines);
        self::assertSame($expectedLines, $actualLines);
    }

    public function testAStopRequestDoesNotInterruptTheCurrentBusyUnit(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_async_signals')) {
            self::markTestSkipped('pcntl and posix are required for the signal control case');
        }
        $probe = $this->signalProbe('busy');
        self::assertSame(['started', 'finished'], $probe['result']['events']);
        self::assertTrue($probe['result']['stop_requested'], 'the next unit boundary must see the request');
        self::assertSame("[busy-control] signal 3 received, stopping after the current unit of work\n", $probe['stderr']);
    }

    /** Capture and assert the product's expected stderr outside PHPUnit's own isolation protocol. */
    private function signalProbe(string $scenario): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the signal probe');
        }
        $stdout = $stderr = $heartbeat = false;
        $process = null;
        try {
            $stdout = tempnam(sys_get_temp_dir(), 'worker-stop-out-');
            $stderr = tempnam(sys_get_temp_dir(), 'worker-stop-err-');
            $heartbeat = tempnam(sys_get_temp_dir(), 'worker-stop-heartbeat-');
            self::assertNotFalse($stdout);
            self::assertNotFalse($stderr);
            self::assertNotFalse($heartbeat);
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-database-stop-probe.php', $scenario, $heartbeat],
                [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $deadline = microtime(true) + 10;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'signal probe exceeded its bounded wait');
            self::assertSame(0, $status['exitcode'], (string) file_get_contents($stderr));
            return ['result' => json_decode((string) file_get_contents($stdout), true, 512, JSON_THROW_ON_ERROR),
                'stderr' => (string) file_get_contents($stderr)];
        } finally {
            if (is_resource($process)) {
                $state = proc_get_status($process);
                if ($state['running']) { proc_terminate($process, 9); }
                proc_close($process);
            }
            foreach ([$stdout, $stderr, $heartbeat] as $temporary) {
                if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
            }
        }
    }
}
