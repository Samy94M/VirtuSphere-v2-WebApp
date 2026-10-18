<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_remote_secret_sweep.php';

/**
 * LG-25: a failing side channel of the sweep (job log, heartbeat) ends nothing, not
 * even on a deadlock, and it is written as one line that names its class. It is not
 * silent, and it is not a rethrow, because the sweep describes its removals through
 * these channels and must finish them.
 */
final class RemoteSecretSideChannelTest extends TestCase
{
    public function testAFailingSeamIsOneLineNamingItsClassAndEndsNothing(): void
    {
        $written = $this->capturedErrorLog(static function (): void {
            deploy_remote_secret_side_channel(static function (): void {
                throw new mysqli_sql_exception('Deadlock found when trying to get lock', 1213);
            });
        });

        self::assertStringContainsString('[deploy-remote-secret] side channel failed: mysqli_sql_exception', $written);
        self::assertSame(1, substr_count($written, "\n"), 'exactly one line');
    }

    public function testAWorkingSeamWritesNothing(): void
    {
        $seen = [];
        $written = $this->capturedErrorLog(static function () use (&$seen): void {
            deploy_remote_secret_side_channel(static function (string $line) use (&$seen): void {
                $seen[] = $line;
            }, 'Removed the ESXi access file.');
        });

        self::assertSame(['Removed the ESXi access file.'], $seen);
        self::assertSame('', $written);
    }

    public function testNoSeamIsNothingToDo(): void
    {
        $written = $this->capturedErrorLog(static function (): void {
            deploy_remote_secret_side_channel(null);
        });

        self::assertSame('', $written);
    }

    /** Runs $work with error_log() pointed at a temporary file and returns what it wrote. */
    private function capturedErrorLog(callable $work): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'vs-side-channel-');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $path);
        try {
            $work();

            return (string) file_get_contents($path);
        } finally {
            ini_set('error_log', $previous);
            unlink($path);
        }
    }
}
