<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use HostIdentityCreateProbe\Probe;

    require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

    /** Executes unchanged product function bodies with network/DB boundary spies. */
    final class CreateHostIdentityRejectionTest extends TestCase
    {
        protected function setUp(): void
        {
            static $loaded = false;
            if (!$loaded) {
                $code = 'namespace HostIdentityCreateProbe; use \\SshHostIdentityRejected; use \\RuntimeException; use \\CreateMarkerProtocolException;';
                foreach (['control_call', 'discover_jid', 'prepare_unit', 'launch_unit', 'verify_skip', 'poll_unit', 'terminate_unit', 'remaining_seconds', 'progress_line'] as $suffix) {
                    $reflection = new ReflectionFunction('deploy_worker_create_' . $suffix);
                    $path = $reflection->getFileName();
                    self::assertIsString($path);
                    $lines = file($path);
                    self::assertIsArray($lines);
                    $code .= implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
                }
                eval($code);
                $loaded = true;
            }
            Probe::$clock = time();
            Probe::$calls = Probe::$discoveries = Probe::$sleeps = 0;
            Probe::$loseLaunch = false;
            Probe::$writes = [];
        }

        public function testLaunchRejectedBeforeAuthenticationFailsAndStopsWithoutDiscovery(): void
        {
            $result = $this->runPhase('launch_unit', 'prepared');
            self::assertSame('failed', Probe::$writes[0]['status']);
            self::assertSame('host_identity_rejected', Probe::$writes[0]['fields']['error_code']);
            self::assertTrue($result['stop']);
            self::assertSame(0, Probe::$discoveries);
            self::assertSame(0, Probe::$sleeps);
            self::assertSame(1, Probe::$calls);
            self::assertNotContains(VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED, VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES);
        }

        public function testAReallyLostLaunchStaysUncertainWhenDiscoveryIsHostBlocked(): void
        {
            Probe::$loseLaunch = true;
            $result = $this->runPhase('launch_unit', 'prepared');
            self::assertSame('uncertain', Probe::$writes[0]['status']);
            self::assertSame('host_identity_rejected', Probe::$writes[0]['fields']['error_code']);
            self::assertTrue($result['stop']);
            self::assertSame(1, Probe::$calls);
            self::assertSame(1, Probe::$discoveries);
            self::assertSame(0, Probe::$sleeps);
        }

        public function testReadOnlyPrepareAndVerifySkipStopWithTheSameClosedCause(): void
        {
            foreach (['prepare_unit' => 'pending', 'verify_skip' => 'pending'] as $phase => $status) {
                Probe::resetWrites();
                $result = $this->runPhase($phase, $status);
                self::assertSame('failed', Probe::$writes[0]['status']);
                self::assertSame('host_identity_rejected', Probe::$writes[0]['fields']['error_code']);
                self::assertTrue($result['stop']);
            }
            self::assertSame(0, Probe::$sleeps);
        }

        public function testStartedUnitIsImmediatelyUncertainWithoutRetryingTheDeniedStatus(): void
        {
            $result = $this->runPhase('poll_unit', 'running');
            self::assertSame('uncertain', Probe::$writes[0]['status']);
            self::assertSame('host_identity_rejected', Probe::$writes[0]['fields']['error_code']);
            self::assertTrue($result['stop']);
            self::assertSame(1, Probe::$calls);
            self::assertSame(0, Probe::$sleeps);
        }

        public function testDiscoveryStopsImmediatelyWhenItsNewConnectionIsRejected(): void
        {
            $call = $this->phaseCallable('discover_jid');
            $result = $call(new \HostIdentityCreateProbe\DeployWorkerDbChannel(), $this->context(), $this->unit('prepared'));
            self::assertSame('host_identity_rejected', $result['error_code']);
            self::assertNull($result['jid']);
            self::assertSame(1, Probe::$discoveries);
            self::assertSame(0, Probe::$sleeps);
        }

        private function runPhase(string $phase, string $status): array
        {
            $call = $this->phaseCallable($phase);
            $channel = new \HostIdentityCreateProbe\DeployWorkerDbChannel();
            $fence = ['worker_id' => 'fixture', 'lock_token' => str_repeat('a', 32), 'worker_epoch' => 1];
            return $phase === 'poll_unit'
                ? $call($channel, [], $fence, $this->unit($status), $this->context())
                : $call($channel, $fence, $this->unit($status), $this->context());
        }

        private function phaseCallable(string $phase): Closure
        {
            $name = 'HostIdentityCreateProbe\\deploy_worker_create_' . $phase;
            if (!is_callable($name)) {
                self::fail('The reflected product phase is not loaded: ' . $phase);
            }
            return Closure::fromCallable($name);
        }

        private function context(): array
        {
            return ['credential' => [], 'secret' => 'fixture', 'secrets' => ['fixture'],
                'remote_dir' => '/tmp/fixture-job', 'create_started_at' => gmdate('Y-m-d H:i:s', Probe::$clock), 'options' => []];
        }

        private function unit(string $status): array
        {
            return ['job_id' => 1, 'vm_id' => 2, 'position' => 1, 'total' => 1, 'vm_name' => 'fixture-vm',
                'status' => $status, 'existed_before' => 0, 'resumed_from_result_id' => 1, 'async_jid' => '123.4'];
        }
    }
}

namespace HostIdentityCreateProbe {
    final class Probe
    {
        public static int $clock = 0;
        public static int $calls = 0;
        public static int $discoveries = 0;
        public static int $sleeps = 0;
        /** @var list<array{status:string,fields:array<string,mixed>}> */
        public static array $writes = [];
        public static bool $loseLaunch = false;
        public static function resetWrites(): void { self::$writes = []; }
    }

    final class DeployWorkerDbChannel
    {
        public function connection(): \stdClass { return new \stdClass(); }
        public function tick(): void { }
        public function log(string $type, string $message): void { }
        public function hasLostOwnership(): bool { return false; }
        public function redact(string $text): string { return $text; }
    }

    function time(): int { return Probe::$clock; }
    function ssh_execute_command(...$args): never
    {
        ++Probe::$calls;
        if (Probe::$loseLaunch) { throw new \RuntimeException('fixture transport lost'); }
        throw new \SshHostIdentityRejected('mismatch');
    }
    function ssh_execute_capture(...$args): never { ++Probe::$discoveries; throw new \SshHostIdentityRejected('mismatch'); }
    function deploy_worker_create_sleep(...$args): void { ++Probe::$sleeps; Probe::$clock += 100000; }
    function deploy_worker_create_ownership(...$args): string { return 'ours'; }
    function deploy_worker_settle_db_channel(...$args): void { }
    function deploy_worker_log_stream_flush(...$args): void { }
    function deploy_worker_log_stream_chunk(...$args): void { }
    function repo_deploy_create_skip_source(...$args): array { return ['vm_instance_uuid' => 'fixture-uuid']; }
    function repo_deploy_create_transition($db, int $jobId, int $position, string $from, string $status, array $fields, array $fence): bool
    {
        Probe::$writes[] = ['status' => $status, 'fields' => $fields];
        return true;
    }
}
