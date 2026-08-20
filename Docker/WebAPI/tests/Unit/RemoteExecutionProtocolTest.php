<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/remote_execution.php';
require_once dirname(__DIR__, 2) . '/lib/remote_inventory_consumer.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_remote_execution.php';

final class RemoteExecutionProtocolTest extends TestCase
{
    private string $schemaPath;

    protected function setUp(): void
    {
        $this->schemaPath = remote_protocol_schema_path();
    }

    public function testProtocolUsesTheRunnerSchemaAndRejectsUnknownOrForeignDocuments(): void
    {
        $launch = $this->launch('launched');
        $decoded = remote_protocol_decode('launch', json_encode($launch, JSON_THROW_ON_ERROR), [
            'run_token' => str_repeat('3', 32),
            'unit_name' => 'virtusphere-j17-a2-inventory-333333333333.service',
        ], $this->schemaPath);
        self::assertSame('launched', $decoded['decision']);

        $launch['surprise'] = true;
        $this->assertRejected(fn (): array => remote_protocol_decode('launch', json_encode($launch, JSON_THROW_ON_ERROR), [], $this->schemaPath));
        unset($launch['surprise']);
        $this->assertRejected(fn (): array => remote_protocol_decode('launch', json_encode($launch, JSON_THROW_ON_ERROR), ['run_token' => str_repeat('4', 32)], $this->schemaPath));
        $this->assertRejected(fn (): array => remote_protocol_decode('launch', str_repeat('x', VIRTUSPHERE_REMOTE_PROTOCOL_DOCUMENT_MAX_BYTES + 1), [], $this->schemaPath));
    }

    public function testReattachNeverTurnsAnUnknownOutcomeIntoSuccessOrRelaunch(): void
    {
        $base = $this->state('prepared');
        $active = remote_inventory_observation($base, $this->launch('launched'), null, null, false);
        self::assertSame('active', $active['controller_state']);
        self::assertSame('active_or_possible', $active['effect_state']);

        $lost = remote_inventory_observation($active, null, null, null, true);
        self::assertSame('lost_after_start', $lost['controller_state']);
        self::assertSame('pending', $lost['reconciliation_state']);

        $result = $this->resultDocument(0);
        $finished = remote_inventory_observation($lost, null, null, $result, false);
        self::assertSame('exited_0', $finished['controller_state']);
        self::assertSame('pending', $finished['reconciliation_state']);
        self::assertNotSame('goal_verified', $finished['effect_state']);

        $afterDbLoss = remote_inventory_observation($base, null, null, $result, false);
        self::assertSame('exited_0', $afterDbLoss['controller_state']);
        self::assertSame('pending', $afterDbLoss['reconciliation_state']);
    }

    public function testIdentifiersAreCanonicalAndTokenStable(): void
    {
        $identity = remote_inventory_identifiers(17, 2, str_repeat('1', 32), str_repeat('2', 32), '/home/ansible/.local/state/virtusphere', str_repeat('3', 32));
        self::assertSame('virtusphere-j17-a2-inventory-333333333333.service', $identity['unit_name']);
        self::assertStringEndsWith('/17/2/inventory/' . str_repeat('3', 32), $identity['remote_dir']);
        $this->assertRejected(static fn (): array => remote_inventory_identifiers(17, 2, str_repeat('1', 32), str_repeat('2', 32), '/tmp/../escape', str_repeat('3', 32)));
    }

    public function testCleanupAndReconciliationTransitionsAreClosed(): void
    {
        remote_execution_assert_transition('reconciliation', 'pending', 'running');
        remote_execution_assert_transition('reconciliation', 'running', 'resolved_success');
        remote_execution_assert_transition('cleanup', 'pending', 'eligible');
        remote_execution_assert_transition('cleanup', 'eligible', 'running');
        remote_execution_assert_transition('cleanup', 'running', 'cleaned');
        self::assertContains('cleaned', VIRTUSPHERE_REMOTE_CLEANUP_STATES);
        $this->assertRejected(static function (): array {
            remote_execution_assert_transition('cleanup', 'pending', 'cleaned');
            return [];
        });
    }

    public function testObserverEnvelopeAndCommandsKeepIdentityAndOffsetClosed(): void
    {
        $execution = [
            'id' => 9,
            'instance_id' => str_repeat('1', 32),
            'generation_id' => str_repeat('2', 32),
            'run_token' => str_repeat('3', 32),
            'unit_name' => 'virtusphere-j17-a2-inventory-333333333333.service',
            'remote_dir' => "/home/ansible/state with quote'/" . str_repeat('3', 32),
            'log_offset' => 7,
        ];
        $launchCommand = remote_inventory_launch_command($execution);
        $observerCommand = remote_inventory_observer_command($execution);
        self::assertStringStartsWith(VIRTUSPHERE_REMOTE_LAUNCHER_PATH . ' ', $launchCommand);
        self::assertStringStartsWith(VIRTUSPHERE_REMOTE_OBSERVER_PATH . ' ', $observerCommand);
        self::assertStringEndsWith(' 7', $observerCommand);
        self::assertStringContainsString('\\', $launchCommand);
        self::assertStringNotContainsString("quote'/333", $launchCommand);

        $observation = [
            'schema' => 'virtusphere.remote.observation/v1',
            'protocol' => 1,
            'instance_id' => $execution['instance_id'],
            'generation_id' => $execution['generation_id'],
            'run_token' => $execution['run_token'],
            'unit_name' => $execution['unit_name'],
            'unit_state' => 'active',
            'offset' => 7,
            'next_offset' => 13,
            'output_b64' => base64_encode("after\n"),
        ];
        $decoded = remote_protocol_decode('observation', json_encode($observation, JSON_THROW_ON_ERROR), [
            'generation_id' => $execution['generation_id'],
            'run_token' => $execution['run_token'],
        ], $this->schemaPath);
        self::assertSame(13, $decoded['next_offset']);
    }

    /** @return array<string, mixed> */
    private function launch(string $decision): array
    {
        return ['schema' => 'virtusphere.remote.launch/v1', 'run_token' => str_repeat('3', 32), 'unit_name' => 'virtusphere-j17-a2-inventory-333333333333.service', 'decision' => $decision, 'written_at' => '2026-08-20T12:00:00.000Z'];
    }

    /** @return array<string, mixed> */
    private function resultDocument(int $exitCode): array
    {
        return ['schema' => 'virtusphere.remote.result/v1', 'run_token' => str_repeat('3', 32), 'unit_name' => 'virtusphere-j17-a2-inventory-333333333333.service', 'outcome' => $exitCode === 0 ? 'completed' : 'failed', 'exit_code' => $exitCode, 'output_truncated' => false, 'started_at' => '2026-08-20T12:00:00.000Z', 'finished_at' => '2026-08-20T12:01:00.000Z'];
    }

    /** @return array<string, mixed> */
    private function state(string $controller): array
    {
        return ['controller_state' => $controller, 'effect_state' => 'not_started', 'reconciliation_state' => 'not_required', 'cleanup_state' => 'pending'];
    }

    /** @param callable():array<mixed> $callable */
    private function assertRejected(callable $callable): void
    {
        try {
            $callable();
        } catch (Throwable) {
            return;
        }
        self::fail('invalid remote execution input unexpectedly passed');
    }
}
