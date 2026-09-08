<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AnsibleCollectionLockContractTest extends TestCase
{
    public function testExistingModuleGateVerifiesTheCompleteDependencyClosure(): void
    {
        $root = dirname(__DIR__, 4);
        $gate = (string) file_get_contents($root . '/Docker/qa-ansible/module-contract.sh');
        $verifier = $root . '/Docker/qa-ansible/verify-collection-lock.py';
        $contract = $root . '/Docker/qa-ansible/collection-lock-contract.py';

        self::assertFileExists($verifier);
        self::assertFileExists($contract);
        self::assertStringContainsString('verify-collection-lock.py', $gate);
        self::assertStringContainsString('collection-lock-contract.py', $gate);
        $source = (string) file_get_contents($verifier);
        self::assertStringContainsString('MANIFEST.json', $source);
        self::assertStringContainsString('dependencies', $source);
        self::assertStringContainsString('unpinned-dependency', $source);
        self::assertStringContainsString('version-mismatch', $source);
        self::assertMatchesRegularExpression('/\[\{position\}\/\{total\}\] RUN collection-lock-/', $source);
        self::assertMatchesRegularExpression('/\[\{position\}\/\{total\}\] PASS collection-lock-/', $source);
        $contractSource = (string) file_get_contents($contract);
        self::assertStringContainsString('missing-installed', $contractSource);
        self::assertStringContainsString('version-mismatch', $contractSource);
        self::assertStringContainsString('unpinned-dependency', $contractSource);
    }
}
