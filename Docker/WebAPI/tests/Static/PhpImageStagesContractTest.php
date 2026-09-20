<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhpImageStagesContractTest extends TestCase
{
    public function testRuntimeAndToolingStaySeparatedAndVersioned(): void
    {
        $root = dirname(__DIR__, 4);
        $dockerfile = str_replace("\r\n", "\n", (string) file_get_contents($root . '/Docker/php/Dockerfile'));
        $compose = str_replace("\r\n", "\n", (string) file_get_contents($root . '/docker-compose.yml'));
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        $toolLock = json_decode((string) file_get_contents($root . '/scripts/tool-lock.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, preg_match('/^FROM php:[^\n]+ AS extension-builder$/m', $dockerfile));
        self::assertSame(1, preg_match('/^FROM php:[^\n]+ AS runtime$/m', $dockerfile));
        self::assertSame(1, preg_match('/^FROM runtime AS tooling$/m', $dockerfile));

        [$beforeTooling, $tooling] = explode("FROM runtime AS tooling\n", $dockerfile, 2);
        foreach (['composer', "\n    git ", "\n    zip ", "\n    unzip ", 'linux-libc-dev', 'libpng-dev', 'libldap2-dev', 'libzip-dev'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->runtimeStage($beforeTooling), $forbidden);
        }
        foreach (['git', 'zip', 'unzip', 'composer'] as $tool) {
            self::assertStringContainsString($tool, $tooling, $tool);
        }

        self::assertStringContainsString("x-php-runtime: &php-runtime\n  image: virtusphere-php:8.4-runtime", $compose);
        self::assertSame(3, substr_count($compose, '<<: *php-runtime'));
        self::assertSame(1, substr_count($compose, 'target: runtime'));
        self::assertSame(1, preg_match_all('/^\s+context: \.\/Docker\/php$/m', $compose));
        self::assertSame('virtusphere-php:8.4-tooling', $toolLock['dockerImages']['php']['ref'] ?? null);
        self::assertSame('tooling', $toolLock['dockerImages']['php']['target'] ?? null);
        self::assertSame(2, substr_count($workflow, 'docker build --target tooling -t virtusphere-php:8.4-tooling Docker/php'));
        self::assertStringNotContainsString('virtusphere-v2-webapp-php composer', $workflow);
    }

    private function runtimeStage(string $beforeTooling): string
    {
        $parts = explode(' AS runtime', $beforeTooling, 2);
        self::assertCount(2, $parts, 'runtime stage missing');
        return $parts[1];
    }
}
