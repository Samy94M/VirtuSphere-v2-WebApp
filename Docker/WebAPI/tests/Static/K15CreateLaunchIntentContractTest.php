<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class K15CreateLaunchIntentContractTest extends TestCase
{
    public function testResumeWithExistingIntentDiscoversOnlyAndNeverStartsAnotherLaunch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_create_launch.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/\$result\s*=\s*\$newIntent\s*===\s*false\s*\?\s*\[.*?discover only, never launch again.*?\]\s*:\s*deploy_worker_create_control_call\(/s',
            $source,
            'Only a newly persisted intent may call the launch playbook; resume follows the discovery branch.'
        );
        self::assertStringContainsString('deploy_worker_create_discover_jid($channel, $context, $unit)', $source);
    }

    public function testOnlyDefinitivePreLaunchRejectionsClearIntentUnderTheOriginalClaim(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_worker_create_launch.php');
        self::assertIsString($source);
        self::assertSame(2, preg_match_all(
            '/deploy_worker_vm_create_launch_intent\(\$channel->connection\(\),\s*\$context\[\'job_claim\'\],\s*\(int\)\s*\$unit\[\'vm_id\'\],\s*false\)/',
            $source
        ), 'Host identity rejection and the module REJECTED marker each close the pre-launch intent.');
        self::assertStringContainsString('if (($result[\'host_identity_error\'] ?? null) !== null)', $source);
        self::assertStringContainsString('if ($marker[\'event\'] === VIRTUSPHERE_CREATE_EVENT_REJECTED)', $source);
    }

    public function testPreStateSchemaStoresIntentWithoutBackfillingOldJobs(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/lib/migrations/0060_deploy_vm_prestate.php');
        self::assertIsString($migration);
        self::assertStringContainsString('create_launch_intent TINYINT(1) NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS deploy_job_vm_states', $migration);
        self::assertStringNotContainsString('INSERT INTO deploy_job_vm_states SELECT', $migration);
    }
}
