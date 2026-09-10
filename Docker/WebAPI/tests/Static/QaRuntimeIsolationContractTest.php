<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QaRuntimeIsolationContractTest extends TestCase
{
    /** @return list<string> */
    private function violations(string $compose, string $fast, string $integration, string $runtime): array
    {
        $errors = [];
        foreach (['webserver', 'php', 'deploy-worker', 'maintenance-worker'] as $service) {
            preg_match('/^  ' . preg_quote($service, '/') . ':\R(.*?)(?=^  [a-z][a-z-]*:|\z)/ms', $compose, $match);
            $body = $match[1] ?? '';
            foreach (['qa-runtime', 'qa-logs'] as $mount) {
                if (!str_contains($body, '- *' . $mount)) {
                    $errors[] = $service . ':' . $mount;
                }
            }
            if ($service !== 'webserver'
                && (!str_contains($body, "env_file: !override\n      - Docker/qa/qa.env")
                    || !str_contains($body, '- *qa-dotenv'))) {
                $errors[] = $service . ':environment';
            }
        }
        foreach (['runtime' => 'var', 'logs' => 'logs'] as $anchor => $target) {
            $expected = "x-qa-$anchor: &qa-$anchor\n  type: volume\n  source: qa-app-$anchor\n"
                . "  target: /var/www/html/$target\n  volume:\n    nocopy: true";
            if (!str_contains($compose, $expected)) {
                $errors[] = 'nocopy:' . $anchor;
            }
        }
        if (!str_contains($compose, "source: qa-backup-status\n        target: /var/backups/virtusphere-status\n"
            . "        read_only: true\n        volume:\n          nocopy: true")) {
            $errors[] = 'backup-status';
        }
        foreach (['qa-app-runtime', 'qa-app-logs', 'qa-backup-status'] as $volume) {
            if (!preg_match('/^  ' . $volume . ':\s*$(?!\R    )/m', $compose)) {
                $errors[] = 'volume:' . $volume;
            }
        }
        if (!str_contains($compose, "x-qa-dotenv: &qa-dotenv\n  type: bind\n  source: ./Docker/qa/qa.env\n"
            . "  target: /var/www/html/.env\n  read_only: true")) {
            $errors[] = 'dotenv-source';
        }
        foreach (['unit' => $fast, 'full' => $integration, 'composer' => $runtime] as $gate => $source) {
            foreach (['/repo/Docker/WebAPI/var:mode=1777', '/repo/Docker/WebAPI/logs:mode=1777',
                "\$qaEnvFile + ':/repo/.env:ro'", "\$qaEnvFile + ':/repo/Docker/WebAPI/.env:ro'"] as $mask) {
                if (!str_contains($source, $mask)) {
                    $errors[] = $gate . ':' . $mask;
                }
            }
        }
        if (str_contains($runtime, "'exec', '-w', '/var/www/html', \$phpContainer, 'composer'")) {
            $errors[] = 'dev-composer';
        }
        if (!str_contains($integration, 'chown 33:0 /var/www/html/logs && chmod 0770 /var/www/html/logs')
            || !str_contains($integration, "\$logInit = Invoke-QaCompose @('run', '--rm', '--no-deps', '--build'")
            || strpos($integration, '$logInit =') >= strpos($integration, '$up = Invoke-QaCompose')) {
            $errors[] = 'log-initialization';
        }
        return $errors;
    }

    public function testRuntimeFilesAreIsolatedAndMissingBoundariesAreDetected(): void
    {
        $root = dirname(__DIR__, 4);
        $sources = [];
        foreach (['Docker/qa/docker-compose.qa.yml', 'scripts/lib/check/gates-fast.ps1',
            'scripts/lib/check/gates-integration.ps1', 'scripts/lib/check/runtime.ps1'] as $path) {
            $sources[] = str_replace("\r\n", "\n", (string) file_get_contents($root . '/' . $path));
        }
        self::assertSame([], $this->violations(...$sources));
        foreach (['- *qa-runtime', '- *qa-logs', 'nocopy: true', 'source: qa-backup-status',
            'env_file: !override', '- *qa-dotenv', 'source: ./Docker/qa/qa.env'] as $boundary) {
            $mutated = $sources;
            $mutated[0] = str_replace($boundary, '# removed isolation', $mutated[0]);
            self::assertNotEmpty($this->violations(...$mutated), $boundary);
        }
        foreach ([1, 2, 3] as $index) {
            $mutated = $sources;
            $mutated[$index] = str_replace('/repo/Docker/WebAPI/logs:mode=1777', '/unprotected', $mutated[$index]);
            self::assertNotEmpty($this->violations(...$mutated), 'test container ' . $index);
        }
        self::assertNotEmpty($this->violations('', '', '', ''));
    }
}
