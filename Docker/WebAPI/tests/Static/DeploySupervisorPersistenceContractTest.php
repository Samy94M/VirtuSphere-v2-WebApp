<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeploySupervisorPersistenceContractTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/' . $relative);
    }

    public function testDurableStateUsesTheBindMountedWebApiVarTreeAndASeparateCloseOnExecLock(): void
    {
        $constants = $this->source('Docker/WebAPI/lib/deploy_supervisor_constants.php');
        self::assertStringContainsString("dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var'", $constants);
        self::assertStringNotContainsString("'/tmp/virtusphere-supervisor-state", $constants);

        $store = $this->source('Docker/WebAPI/lib/deploy_supervisor_local_state.php');
        self::assertStringContainsString("private const STATE_FILE = 'state.json'", $store);
        self::assertStringContainsString("private const LOCK_FILE = 'supervisor.lock'", $store);
        self::assertStringContainsString('fopen($lockPath, \'c+be\')', $store);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $store);
        self::assertStringContainsString('rename($temporary, $this->statePath())', $store);
    }

    public function testTheStartReservationIsSavedBeforeTheOnlyProcessStartCall(): void
    {
        $loop = $this->source('Docker/WebAPI/lib/deploy_supervisor_loop.php');
        $save = strpos($loop, '$store->save($candidate)');
        $apply = strpos($loop, 'deploy_supervisor_apply($process, $decision, $options)');
        $start = strpos($loop, '$process->start(');

        self::assertIsInt($save);
        self::assertIsInt($apply);
        self::assertIsInt($start);
        self::assertLessThan($apply, $save);
        self::assertLessThan($start, $apply);
        self::assertStringContainsString(
            '$decision[\'action\'] === VIRTUSPHERE_SUPERVISOR_ACTION_START && !$persisted',
            $loop
        );
    }

    public function testBothWorkerCallersHandleTheNullableConnectionAndUseInterruptibleWaits(): void
    {
        foreach ([
            'Docker/WebAPI/lib/deploy_worker_loop.php' => 'deploy_worker_connect_db',
            'Docker/WebAPI/lib/maintenance_worker.php' => 'maintenance_worker_connect_db',
        ] as $relative => $connector) {
            $source = $this->source($relative);
            self::assertSame(2, substr_count($source, '$db = ' . $connector . '($options);'));
            self::assertSame(2, substr_count($source, 'if ($db === null)'));
            self::assertStringContainsString('worker_idle_wait(', $source);
            self::assertSame(0, preg_match('/(?<!_)\bsleep\s*\(/', $source), $relative);
        }

        $shared = $this->source('Docker/WebAPI/lib/worker_database_connect.php');
        self::assertStringContainsString('$maxAttempts = $once ? 3 : 0', $shared);
        self::assertStringContainsString('worker_stop_requested()', $shared);
        self::assertStringContainsString('worker_idle_wait(', $shared);
    }
}
