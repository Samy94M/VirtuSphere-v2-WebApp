<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N1a F3: the FPM pool ends every request after a hard wall clock, and that
 * clock is the same number nginx waits for FastCGI output. A smaller pool
 * value would cut requests nginx still serves (the manual Ansible full test
 * budgets up to fastcgi_read_timeout, AnsibleTestWebLimitContractTest); a
 * missing one lets a request blocked on a socket hold its worker forever,
 * because max_execution_time counts CPU time on Linux.
 *
 * The slowlog writes to the master's stderr: a file in logs/ cannot be
 * created by the capability-less root master, and FPM does not start when
 * its slowlog cannot be opened.
 *
 * A source without its directive is a red test, never a skip.
 */
final class FpmRequestTimeoutContractTest extends TestCase
{
    private static function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4));
    }

    /** @return array<string,string> the pool directives, last one wins as in FPM */
    private static function poolDirectives(): array
    {
        $path = self::repoRoot() . '/Docker/php/fpm.d/zz-virtusphere.conf';
        self::assertFileExists($path);
        $directives = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^\s*([a-z_.\[\]]+)\s*=\s*(.*?)\s*$/', $line, $match) === 1) {
                $directives[$match[1]] = $match[2];
            }
        }

        return $directives;
    }

    private static function seconds(string $value): int
    {
        self::assertMatchesRegularExpression('/^[0-9]+s?$/', $value, 'FPM time value must be plain seconds: ' . $value);

        return (int) rtrim($value, 's');
    }

    /** @return list<int> every fastcgi_read_timeout of both nginx sources */
    private static function nginxReadTimeouts(): array
    {
        $timeouts = [];
        foreach (['Docker/nginx/default.conf', 'Docker/WebAPI/lib/https_config.php'] as $relative) {
            $path = self::repoRoot() . '/' . $relative;
            self::assertFileExists($path, 'nginx source is missing: ' . $relative);
            $found = preg_match_all('/fastcgi_read_timeout\s+(\d+)\s*;/', (string) file_get_contents($path), $matches);
            self::assertGreaterThan(0, $found, 'Zero match: no fastcgi_read_timeout in ' . $relative);
            foreach ($matches[1] as $timeout) {
                $timeouts[] = (int) $timeout;
            }
        }

        return $timeouts;
    }

    public function testTheHardWallClockEqualsTheNginxReadTimeout(): void
    {
        $directives = self::poolDirectives();
        self::assertArrayHasKey('request_terminate_timeout', $directives, 'the FPM pool has no hard wall clock (N1a F3)');
        $terminate = self::seconds($directives['request_terminate_timeout']);
        self::assertGreaterThan(0, $terminate, '0 switches the wall clock off');
        foreach (self::nginxReadTimeouts() as $readTimeout) {
            self::assertSame($readTimeout, $terminate, 'request_terminate_timeout must equal fastcgi_read_timeout');
        }
    }

    public function testSlowRequestsAreNamedOnStderrBeforeTheWallClockEnds(): void
    {
        $directives = self::poolDirectives();
        self::assertArrayHasKey('request_slowlog_timeout', $directives);
        self::assertArrayHasKey('slowlog', $directives);
        $slow = self::seconds($directives['request_slowlog_timeout']);
        self::assertGreaterThan(0, $slow);
        self::assertLessThan(self::seconds($directives['request_terminate_timeout']), $slow);
        self::assertSame('/proc/self/fd/2', $directives['slowlog'], 'the slowlog goes to the container log, never a file the root master would have to create');
    }
}
