<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_local_state.php';

/** @group process */
final class DeploySupervisorPersistenceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/vs-supervisor-state-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @chmod($this->directory, 0700);
        @rmdir($this->directory);
    }

    public function testCooldownWindowAndFutureRetrySurviveAStoreRestart(): void
    {
        $state = deploy_supervisor_initial_state();
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_WAIT_RETRY;
        $state['restart_window_started_at'] = 10_000;
        $state['restart_count'] = VIRTUSPHERE_SUPERVISOR_RESTART_WINDOW_MAX + 2;
        $state['cooldown_until'] = 10_100;
        $state['next_retry_at'] = 10_900;

        $first = new DeploySupervisorLocalState($this->directory);
        $first->acquire();
        $first->save($state);
        unset($first);

        $second = new DeploySupervisorLocalState($this->directory);
        $second->acquire();
        self::assertSame($state, $second->load());
    }

    public function testReservedRunningStateIsChargedAndLatchedManualWithoutReapEvidence(): void
    {
        $state = deploy_supervisor_initial_state();
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING;
        $state['child_started_at'] = 20_000;
        $state['restart_window_started_at'] = 19_700;
        $state['restart_count'] = 2;

        $restored = deploy_supervisor_restore_local_state($state, 20_100);

        self::assertSame(VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL, $restored['phase']);
        self::assertSame(3, $restored['restart_count'], 'the pre-start reservation is charged conservatively');
        self::assertNull($restored['child_started_at']);

        $shutdown = deploy_supervisor_decide($restored, [
            'now' => 20_101,
            'child_running' => false,
            'child_heartbeat_age' => null,
            'shutdown_requested' => true,
        ]);
        self::assertSame(VIRTUSPHERE_SUPERVISOR_ACTION_SHUTDOWN, $shutdown['action']);
        self::assertSame(
            VIRTUSPHERE_SUPERVISOR_PHASE_MANUAL,
            $shutdown['state']['phase'],
            'stopping the supervisor must not clear unresolved old-child ownership'
        );
    }

    public function testCorruptAndOversizedStateFailClosed(): void
    {
        $store = new DeploySupervisorLocalState($this->directory);
        $store->acquire();
        file_put_contents($this->directory . '/state.json', '{broken');
        try {
            $store->load();
            self::fail('corrupt state was accepted');
        } catch (DeploySupervisorStateException) {
            self::assertSame('{broken', file_get_contents($this->directory . '/state.json'));
        }

        file_put_contents($this->directory . '/state.json', str_repeat('x', 16385));
        $this->expectException(DeploySupervisorStateException::class);
        $store->load();
    }

    public function testANonRegularStatePathIsNotTreatedAsAFirstStart(): void
    {
        $store = new DeploySupervisorLocalState($this->directory);
        $store->acquire();
        mkdir($this->directory . '/state.json', 0700);

        $this->expectException(DeploySupervisorStateException::class);
        $store->load();
    }

    public function testASecondSupervisorCannotAcquireTheLifetimeLock(): void
    {
        $first = new DeploySupervisorLocalState($this->directory);
        $first->acquire();
        $second = new DeploySupervisorLocalState($this->directory);

        $this->expectException(DeploySupervisorLockException::class);
        $second->acquire();
    }

    public function testAReplaceFailureLeavesNoTemporaryStateFiles(): void
    {
        $store = new DeploySupervisorLocalState($this->directory);
        $store->acquire();
        mkdir($this->directory . '/state.json', 0700);

        try {
            $store->save(deploy_supervisor_initial_state());
            self::fail('a directory at the state path was replaced');
        } catch (DeploySupervisorStateException) {
            self::assertSame([], glob($this->directory . '/.state-*') ?: []);
        }
    }

    public function testTheLifetimeLockIsNotInheritedByTheExecedWorker(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open')) {
            self::markTestSkipped('the production close-on-exec contract is Linux-specific');
        }
        $first = new DeploySupervisorLocalState($this->directory);
        $first->acquire();
        $resultFile = $this->directory . '/child-fds';
        $lockPath = $this->directory . '/supervisor.lock';
        $code = '$wanted = ' . var_export($lockPath, true) . ';'
            . '$found = false; foreach (glob("/proc/self/fd/*") ?: [] as $fd) {'
            . ' if (@readlink($fd) === $wanted) { $found = true; break; }}'
            . 'file_put_contents(' . var_export($resultFile, true) . ', $found ? "inherited" : "closed");';
        $pipes = [];
        $child = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($child);

        try {
            for ($attempt = 0; $attempt < 100 && !is_file($resultFile); $attempt++) {
                usleep(20_000);
            }
            self::assertSame('closed', @file_get_contents($resultFile));
        } finally {
            $status = proc_get_status($child);
            if ($status['running']) {
                proc_terminate($child, 9);
            }
            proc_close($child);
        }
    }
}
