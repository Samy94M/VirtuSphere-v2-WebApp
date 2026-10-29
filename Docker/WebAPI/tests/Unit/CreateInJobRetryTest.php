<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use CreateInJobRetryProbe\Probe;

    require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

    /**
     * The in-job create retry (prod job 1527): one more attempt for a unit the
     * create module failed, after the last unit and after one pause.
     *
     * The pass and the unit driver run as unchanged product function bodies
     * with spies at the database and SSH boundary (the CreateHostIdentityRejection
     * pattern). What a green run cannot show is what this holds: the pass starts
     * nothing while a unit is pending or in flight, honours cancel and budget at
     * every boundary, and a second attempt that finds the VM its first attempt
     * left behind stops at the read-only Prepare instead of building another.
     */
    final class CreateInJobRetryTest extends TestCase
    {
        protected function setUp(): void
        {
            static $loaded = false;
            if (!$loaded) {
                self::load('CreateInJobRetryProbe', ['run_create_retry_pass']);
                self::load('CreateInJobRetrySafety', ['create_drive_unit', 'create_prepare_unit', 'create_control_call', 'create_terminate_unit']);
                $loaded = true;
            }
            Probe::reset();
        }

        /**
         * Two namespaces on purpose: the pass runs against a spy of the unit
         * driver, the safety case against the real driver and its Prepare.
         *
         * @param list<string> $suffixes
         */
        private static function load(string $namespace, array $suffixes): void
        {
            $code = 'namespace ' . $namespace . '; use \\SshHostIdentityRejected; use \\RuntimeException; use \\CreateMarkerProtocolException;';
            foreach ($suffixes as $suffix) {
                $reflection = new ReflectionFunction('deploy_worker_' . $suffix);
                $path = $reflection->getFileName();
                self::assertIsString($path);
                $lines = file($path);
                self::assertIsArray($lines);
                $code .= implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
            }
            eval($code);
        }

        public function testOnlyAModuleFailureOfAFirstAttemptIsACandidate(): void
        {
            $rows = [
                $this->row(1, 'succeeded'),
                $this->row(2, 'failed', ['error_code' => 'module_failed']),
                $this->row(3, 'failed', ['error_code' => 'transport_lost']),
                $this->row(4, 'failed', ['error_code' => 'module_failed', 'attempt' => 2]),
                $this->row(5, 'failed', ['error_code' => 'module_failed', 'action' => 'verify_skip']),
                $this->row(6, 'failed', ['error_code' => 'module_failed', 'async_jid' => null]),
                $this->row(7, 'failed', ['error_code' => 'operator_released']),
                $this->row(8, 'failed', ['error_code' => 'identity_conflict']),
            ];

            self::assertSame([2], array_column(deploy_worker_create_retry_candidates($rows), 'position'));
        }

        public function testNothingIsRetriedWhileAUnitIsPendingOrInFlight(): void
        {
            foreach (['pending', 'prepared', 'running', 'uncertain'] as $status) {
                $rows = [$this->row(1, 'failed', ['error_code' => 'module_failed']), $this->row(2, $status)];
                self::assertSame([], deploy_worker_create_retry_candidates($rows), $status . ' must hold the pass back');
            }
        }

        public function testAModuleFailureGetsOneMoreAttemptAfterThePause(): void
        {
            Probe::$rows = [$this->row(1, 'succeeded'), $this->row(2, 'failed', ['error_code' => 'module_failed'])];

            self::assertNull($this->pass());
            self::assertSame([VIRTUSPHERE_CREATE_RETRY_PAUSE_SECONDS], Probe::$sleeps);
            self::assertSame(['assert', 'sleep', 'assert', 'requeue:2', 'drive:2@2'], Probe::$events);
            self::assertStringContainsString('[2/2] RETRY create VM-2 module_failed; attempt 2 of 2 after 60 s', implode("\n", Probe::$logs));
        }

        public function testABudgetWithoutRoomForThePauseStartsNoRetry(): void
        {
            Probe::$rows = [$this->row(1, 'failed', ['error_code' => 'module_failed'])];
            Probe::$remaining = VIRTUSPHERE_CREATE_RETRY_PAUSE_SECONDS;

            self::assertNull($this->pass());
            self::assertSame([], Probe::$sleeps);
            self::assertSame([], Probe::$events);
            self::assertStringContainsString('Create retry not started', implode("\n", Probe::$logs));
        }

        public function testACancelAfterThePauseStartsNoFurtherAttempt(): void
        {
            Probe::$rows = [$this->row(1, 'failed', ['error_code' => 'module_failed'])];
            Probe::$cancelOnAssert = 2;

            try {
                $this->pass();
                self::fail('a confirmed cancel at the boundary must end the pass');
            } catch (DeployWorkerCancelled) {
            }
            self::assertSame(['assert', 'sleep', 'assert'], Probe::$events);
        }

        public function testAStopOfOneAttemptEndsThePass(): void
        {
            Probe::$rows = [
                $this->row(1, 'failed', ['error_code' => 'module_failed']),
                $this->row(2, 'failed', ['error_code' => 'module_failed']),
            ];
            Probe::$driveVerdict = ['stop' => true, 'reason' => 'protocol_error'];

            self::assertSame('protocol_error', $this->pass());
            self::assertNotContains('requeue:2', Probe::$events);
        }

        public function testARefusedRequeueStopsOnlyALostClaim(): void
        {
            Probe::$rows = [$this->row(1, 'failed', ['error_code' => 'module_failed'])];
            Probe::$requeueRefused = true;
            Probe::$ownership = 'lost';
            self::assertSame(VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST, $this->pass());

            Probe::reset();
            Probe::$rows = [$this->row(1, 'failed', ['error_code' => 'module_failed'])];
            Probe::$requeueRefused = true;
            Probe::$ownership = 'cancelling';
            self::assertNull($this->pass(), 'a cancel that won the lock is the conclusion\'s to confirm');
            self::assertNotContains('drive:1@2', Probe::$events);
        }

        public function testNoCandidateMeansNoPause(): void
        {
            Probe::$rows = [$this->row(1, 'succeeded'), $this->row(2, 'failed', ['error_code' => 'transport_lost'])];

            self::assertNull($this->pass());
            self::assertSame([], Probe::$sleeps);
            self::assertSame([], Probe::$events);
        }

        /**
         * The safety case of the brief. The first attempt left a VM of this
         * name on the host, and the portal stored no UUID for it. The second
         * attempt's read-only Prepare answers identity_conflict (the identity
         * matrix, proven against the playbook by the ansible-create-async gate,
         * cases retry-leftover-*); the unit ends failed, and no Launch is
         * called, so no second VM can come into existence.
         */
        public function testASecondAttemptThatFindsTheLeftoverVmStopsAtPrepare(): void
        {
            Probe::$marker = [
                'event' => VIRTUSPHERE_CREATE_EVENT_REJECTED,
                'portal_vm_id' => 102,
                'vm_name' => 'VM-2',
                'error_code' => VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT,
                'error' => 'an existing VM of this name does not carry the instance uuid stored by the portal',
            ];
            $unit = $this->row(2, 'pending', ['attempt' => 2, 'error_code' => null, 'async_jid' => null]);
            $drive = self::loaded('CreateInJobRetrySafety\\deploy_worker_create_drive_unit');
            $fence = ['worker_id' => 'fixture', 'lock_token' => str_repeat('a', 32), 'worker_epoch' => 1];

            $verdict = $drive(new \CreateInJobRetrySafety\DeployWorkerDbChannel(), ['id' => 7], $fence, $unit, $this->context());

            self::assertFalse($verdict['stop'], 'an identity finding is a per-VM failure');
            self::assertCount(1, Probe::$commands, 'only the read-only Prepare ran');
            self::assertStringContainsString("ansible-playbook '" . VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE . "'", Probe::$commands[0]);
            self::assertStringContainsString('/create.vm.2.attempt2/prepare.json', Probe::$commands[0]);
            self::assertSame([['from' => 'pending', 'to' => 'failed', 'code' => 'identity_conflict']], Probe::$transitions);
            self::assertSame([], Probe::$launches);
        }

        private function pass(): ?string
        {
            $pass = self::loaded('CreateInJobRetryProbe\\deploy_worker_run_create_retry_pass');
            $fence = ['worker_id' => 'fixture', 'lock_token' => str_repeat('a', 32), 'worker_epoch' => 1];

            return $pass(new \CreateInJobRetryProbe\DeployWorkerDbChannel(), ['id' => 7], $fence, $this->context());
        }

        private static function loaded(string $name): Closure
        {
            if (!is_callable($name)) {
                self::fail('The reflected product function is not loaded: ' . $name);
            }

            return Closure::fromCallable($name);
        }

        /** @return array<string, mixed> */
        private function context(): array
        {
            return ['credential' => [], 'secret' => 'fixture', 'secrets' => ['fixture'], 'remote_dir' => '/tmp/fixture-job',
                'create_started_at' => gmdate('Y-m-d H:i:s'), 'options' => [], 'job_claim' => ['id' => 7]];
        }

        /** @return array<string, mixed> */
        private function row(int $position, string $status, array $overrides = []): array
        {
            return array_merge([
                'id' => 1000 + $position, 'job_id' => 7, 'vm_id' => 100 + $position, 'vm_name' => 'VM-' . $position,
                'position' => $position, 'total' => 2, 'attempt' => 1, 'action' => 'create', 'status' => $status,
                'error_code' => null, 'async_jid' => '1234.' . $position, 'existed_before' => null,
            ], $overrides);
        }
    }
}

namespace CreateInJobRetryProbe {
    final class Probe
    {
        /** @var list<array<string,mixed>> */
        public static array $rows = [];
        public static int $remaining = 3600;
        /** @var list<int> */
        public static array $sleeps = [];
        /** @var list<string> */
        public static array $events = [];
        /** @var list<string> */
        public static array $logs = [];
        public static int $asserts = 0;
        public static int $cancelOnAssert = 0;
        /** @var array{stop:bool,reason:?string} */
        public static array $driveVerdict = ['stop' => false, 'reason' => null];
        public static bool $requeueRefused = false;
        public static string $ownership = 'ours';
        /** @var array<string,mixed> */
        public static array $marker = [];
        /** @var list<string> */
        public static array $commands = [];
        /** @var list<array{from:string,to:string,code:?string}> */
        public static array $transitions = [];
        /** @var list<int> */
        public static array $launches = [];

        public static function reset(): void
        {
            self::$rows = [];
            self::$remaining = 3600;
            self::$sleeps = self::$events = self::$logs = self::$commands = self::$transitions = self::$launches = [];
            self::$asserts = self::$cancelOnAssert = 0;
            self::$driveVerdict = ['stop' => false, 'reason' => null];
            self::$requeueRefused = false;
            self::$ownership = 'ours';
            self::$marker = [];
        }
    }

    final class DeployWorkerDbChannel
    {
        public function connection(): \stdClass { return new \stdClass(); }
        public function tick(): void { }
        public function log(string $type, string $message): void { Probe::$logs[] = $message; }
        public function hasLostOwnership(): bool { return false; }
        public function redact(string $text): string { return $text; }
    }

    function repo_deploy_create_results(...$args): array { return Probe::$rows; }
    function deploy_worker_create_remaining_seconds(...$args): int { return Probe::$remaining; }
    function deploy_worker_assert_job_is_ours(...$args): void
    {
        Probe::$events[] = 'assert';
        if (++Probe::$asserts === Probe::$cancelOnAssert) {
            throw new \DeployWorkerCancelled('fixture cancel');
        }
    }
    function deploy_worker_create_sleep($channel, array $context, int $seconds): void
    {
        Probe::$sleeps[] = $seconds;
        Probe::$events[] = 'sleep';
    }
    function deploy_worker_create_requeue_unit($db, array $claim, array $fence, int $position): ?array
    {
        Probe::$events[] = 'requeue:' . $position;
        if (Probe::$requeueRefused) {
            return null;
        }
        foreach (Probe::$rows as $row) {
            if ((int) $row['position'] === $position) {
                return array_merge($row, ['status' => 'pending', 'attempt' => 2, 'error_code' => null, 'async_jid' => null]);
            }
        }
        return null;
    }
    function deploy_worker_create_ownership(...$args): string { return Probe::$ownership; }
    function deploy_worker_create_drive_unit($channel, array $job, array $fence, array $unit, array $context): array
    {
        Probe::$events[] = 'drive:' . (int) $unit['position'] . '@' . (int) $unit['attempt'];
        return Probe::$driveVerdict;
    }
}

namespace CreateInJobRetrySafety {
    use CreateInJobRetryProbe\Probe;

    final class DeployWorkerDbChannel
    {
        public function connection(): \stdClass { return new \stdClass(); }
        public function tick(): void { }
        public function log(string $type, string $message): void { Probe::$logs[] = $message; }
        public function hasLostOwnership(): bool { return false; }
        public function redact(string $text): string { return $text; }
    }

    function deploy_worker_create_launch_unit($channel, array $fence, array $unit, array $context): array
    {
        Probe::$launches[] = (int) $unit['position'];
        return ['stop' => true, 'continue' => false, 'reason' => 'fixture', 'unit' => $unit];
    }
    function deploy_worker_create_poll_unit(...$args): array { return ['stop' => true, 'reason' => 'fixture']; }
    function deploy_worker_create_verify_skip(...$args): array { return ['stop' => true, 'reason' => 'fixture']; }
    function deploy_worker_create_conclude_unchanged(...$args): array { return ['stop' => true, 'continue' => false, 'reason' => 'fixture', 'unit' => []]; }
    function ssh_execute_command(array $credential, string $secret, string $command, callable $onChunk, ...$rest): int
    {
        Probe::$commands[] = $command;
        $json = json_encode(Probe::$marker, JSON_THROW_ON_ERROR);
        $onChunk(VIRTUSPHERE_CREATE_MARKER_PREFIX . ' v' . VIRTUSPHERE_CREATE_PROTOCOL_VERSION . ' '
            . rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . "\n");
        return 0;
    }
    function deploy_worker_log_stream_chunk($channel, string $stream, string &$buffer, string $chunk, ?callable $onLine = null): void
    {
        foreach (explode("\n", $chunk) as $line) {
            if ($line !== '' && $onLine !== null) {
                $onLine($line);
            }
        }
    }
    function deploy_worker_log_stream_flush(...$args): void { }
    function repo_deploy_create_transition($db, int $jobId, int $position, string $from, string $to, array $fields, array $fence): bool
    {
        Probe::$transitions[] = ['from' => $from, 'to' => $to, 'code' => $fields['error_code'] ?? null];
        return true;
    }
}
