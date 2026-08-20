<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/remote_step_policy.php';

final class RemoteStepPolicyContractTest extends TestCase
{
    public function testPolicyHasNoActivationWriterOrProductionCallSite(): void
    {
        $root = dirname(__DIR__, 2);
        $policy = (string) file_get_contents($root . '/lib/remote_step_policy.php');
        self::assertStringNotContainsString('UPDATE deploy_remote_mode_activations', $policy);
        self::assertStringNotContainsString('INSERT INTO deploy_remote_mode_activations', $policy);
        self::assertStringNotContainsString('repo_execute(', $policy);

        $callers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib'));
        foreach ($files as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $file = $fileInfo->getPathname();
            if (basename($file) === 'remote_step_policy.php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file), 'remote_step_policy_registry(')
                || str_contains((string) file_get_contents($file), 'remote_mode_activation_verdict(')) {
                $callers[] = basename($file);
            }
        }
        self::assertSame([], $callers, 'O5 policy must remain unreachable from production workers.');
    }

    public function testMaterializationStillCreatesOnlyDisabledRows(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_remote_mode_activation.php');
        $materialize = substr($source, (int) strpos($source, 'function repo_materialize_disabled_remote_activations'));
        self::assertStringContainsString("VALUES (?, ?, 'disabled', NULL)", $materialize);
        self::assertStringNotContainsString("SET state = 'pilot_remote'", $source);
        self::assertStringNotContainsString("SET state = 'remote_enabled'", $source);
    }

    public function testCreateAndFullHaveNoOfflinePolicy(): void
    {
        self::assertArrayNotHasKey('create', remote_step_policy_registry());
        self::assertArrayNotHasKey(VIRTUSPHERE_DEPLOY_MODE_FULL, remote_step_policy_registry());
    }
}
