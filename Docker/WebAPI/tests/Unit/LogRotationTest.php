<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/log_rotation.php';

/**
 * The file-log rotation contract (ADR-0026 amendment, campaign B10):
 * logs/error.log and the engine log grew without bound because the retention
 * job purges DB rows only. Pinned here: the size boundary, the generation
 * shift with a hard cap, the containment rule (only real children of the log
 * directory rotate; a symlink pointing elsewhere is an error, not a target),
 * the inter-process lock (held lock means idle, never a second rotation), and
 * that a missing file is idleness rather than a finding.
 */
final class LogRotationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs-rotate-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->dir . DIRECTORY_SEPARATOR . '.rotation.lock');
        @rmdir($this->dir);
    }

    private function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    private function logDir(): string
    {
        return (string) realpath($this->dir);
    }

    public function testAFileAtOrUnderTheCapDoesNotRotate(): void
    {
        file_put_contents($this->path('error.log'), str_repeat('x', 100));

        self::assertFalse(virtusphere_rotate_one_log($this->path('error.log'), $this->logDir(), 100, 3));
        self::assertFileExists($this->path('error.log'));
        self::assertFileDoesNotExist($this->path('error.log.1'));
    }

    public function testAMissingFileIsIdleNotAFinding(): void
    {
        self::assertFalse(virtusphere_rotate_one_log($this->path('error.log'), $this->logDir(), 100, 3));
    }

    public function testRotationShiftsGenerationsAndDropsTheOldest(): void
    {
        file_put_contents($this->path('error.log'), str_repeat('n', 101));
        file_put_contents($this->path('error.log.1'), 'gen1');
        file_put_contents($this->path('error.log.2'), 'gen2');
        file_put_contents($this->path('error.log.3'), 'gen3-faellt-weg');

        self::assertTrue(virtusphere_rotate_one_log($this->path('error.log'), $this->logDir(), 100, 3));

        self::assertFileDoesNotExist($this->path('error.log'));
        self::assertSame(str_repeat('n', 101), file_get_contents($this->path('error.log.1')));
        self::assertSame('gen1', file_get_contents($this->path('error.log.2')));
        self::assertSame('gen2', file_get_contents($this->path('error.log.3')));
        self::assertFileDoesNotExist($this->path('error.log.4'));
    }

    public function testASymlinkPointingOutsideTheLogDirectoryIsRefused(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'vs-outside-');
        self::assertIsString($outside);
        file_put_contents($outside, str_repeat('x', 200));
        $link = $this->path('error.log');
        if (!@symlink($outside, $link)) {
            @unlink($outside);
            self::markTestSkipped('symlink() unavailable on this host.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('outside the log directory');
            virtusphere_rotate_one_log($link, $this->logDir(), 100, 3);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    public function testNonsensicalBoundsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        virtusphere_rotate_one_log($this->path('error.log'), $this->logDir(), 0, 3);
    }

    public function testAHeldLockMeansIdleNeverASecondRotation(): void
    {
        file_put_contents($this->path('error.log'), str_repeat('x', 200));

        $holder = fopen($this->path('.rotation.lock'), 'c');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX));
        try {
            self::assertSame(0, virtusphere_rotate_logs(100, 3, $this->dir), 'a held lock must read as idle');
            self::assertFileExists($this->path('error.log'), 'the oversized file must stay untouched under a held lock');
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        self::assertSame(1, virtusphere_rotate_logs(100, 3, $this->dir), 'after the lock is released the rotation runs');
        self::assertFileExists($this->path('error.log.1'));
    }
}
