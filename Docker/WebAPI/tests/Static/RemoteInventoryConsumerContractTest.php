<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RemoteInventoryConsumerContractTest extends TestCase
{
    public function testConsumerIsImplementedButHasNoProductCallSite(): void
    {
        $root = dirname(__DIR__, 2);
        $repo = (string) file_get_contents($root . '/lib/repo/deploy_remote_execution.php');
        self::assertStringContainsString('function repo_prepare_remote_inventory_execution(', $repo);
        self::assertStringContainsString('function repo_observe_remote_inventory_execution(', $repo);
        self::assertStringContainsString('function repo_import_remote_inventory_output(', $repo);
        self::assertStringContainsString('function repo_mark_remote_inventory_reconciled(', $repo);
        self::assertStringContainsString('function repo_begin_remote_inventory_cleanup(', $repo);
        self::assertStringContainsString('function repo_record_remote_inventory_cleanup(', $repo);
        self::assertStringContainsString('Remote inventory activation is not site-approved.', $repo);
        self::assertStringContainsString('VIRTUSPHERE_REMOTE_ACTIVATION_PILOT', $repo);
        self::assertStringContainsString('VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED', $repo);

        $workerSource = '';
        foreach (glob($root . '/lib/deploy_worker*.php') ?: [] as $path) {
            $workerSource .= (string) file_get_contents($path);
        }
        self::assertNotSame('', $workerSource);
        self::assertStringNotContainsString('repo_prepare_remote_inventory_execution', $workerSource);
        self::assertStringNotContainsString('repo_observe_remote_inventory_execution', $workerSource);
        self::assertStringNotContainsString('remote_inventory_launch(', $workerSource);
        self::assertStringNotContainsString('remote_inventory_poll(', $workerSource);
    }

    public function testProtocolSchemaRemainsTheOnlyWireDefinition(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/remote_execution_protocol.php');
        self::assertStringContainsString("dirname(__DIR__, 3) . '/Ansible/runner/protocol-v1.json'", $source);
        self::assertStringContainsString('/Ansible/runner/protocol-v1.json', $source);
        self::assertStringContainsString('/var/www/ansible-src/runner/protocol-v1.json', $source);
        self::assertStringNotContainsString('virtusphere.remote.launch/v1', $source);
        self::assertStringNotContainsString('virtusphere.remote.result/v1', $source);
        $consumer = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/remote_inventory_consumer.php');
        self::assertStringContainsString('VIRTUSPHERE_REMOTE_LAUNCHER_PATH', $consumer);
        self::assertStringContainsString('VIRTUSPHERE_REMOTE_OBSERVER_PATH', $consumer);
        self::assertStringContainsString("remote_protocol_decode('observation'", $consumer);
    }

    public function testOffsetAndCleanupPathsFailClosed(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_remote_execution.php');
        self::assertStringContainsString('if ($offset < $stored)', $source);
        self::assertStringContainsString('if ($offset > $stored)', $source);
        self::assertStringContainsString("last_probe_category = 'log_gap'", $source);
        self::assertStringContainsString("cleanup_state'] !== 'eligible'", $source);
        self::assertStringContainsString("cleanup_state'] !== 'running'", $source);
        self::assertStringContainsString("['resolved_success', 'resolved_failure']", $source);
        self::assertStringNotContainsString('DELETE FROM deploy_remote_executions', $source);
    }
}
