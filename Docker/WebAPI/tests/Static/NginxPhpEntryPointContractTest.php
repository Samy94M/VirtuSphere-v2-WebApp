<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/https_config.php';

/**
 * SEC3 (C-09, and the derived half of C-03): nginx hands only real entry points
 * to PHP-FPM and serves only the asset tree. Derived, not listed:
 *  - the entry points are the PHP files directly in the webroot or directly in
 *    portal/ that no other webroot file requires; a required one is an include
 *    file and must answer 404;
 *  - every other file in the webroot (deeper PHP, translations, tool configs,
 *    dot files) must be refused, and only portal/assets/ is served as a file.
 * The test replays nginx's location choice (exact, longest prefix, `^~`, then
 * the regex locations in order) for every file, in Docker/nginx/default.conf
 * and in the generated HTTPS block. A new include file or config file in the
 * webroot fails here until nginx refuses it.
 */
final class NginxPhpEntryPointContractTest extends TestCase
{
    private static function webRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return array<string,string> label => config text */
    private static function configs(): array
    {
        $path = dirname(__DIR__, 3) . '/nginx/default.conf';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/nginx/default.conf is not visible from this runtime');
        }

        return ['Docker/nginx/default.conf' => (string) file_get_contents($path), 'generated HTTPS block' => https_render_nginx_conf()];
    }

    /** @return list<array{modifier:string,pattern:string,outcome:string}> */
    private static function locations(string $config): array
    {
        $server = (string) substr($config, (int) strpos($config, "\nserver {"));
        preg_match_all('/^\s*location\s+(?:(=|\^~|~\*|~)\s+)?(\S+)\s*\{(.*?)\}/ms', $server, $matches, PREG_SET_ORDER);
        $locations = [];
        foreach ($matches as $match) {
            $body = $match[3];
            $outcome = match (true) {
                str_contains($body, 'fastcgi_pass') => 'fpm',
                str_contains($body, 'deny all') => 'deny',
                str_contains($body, 'return 404') => '404',
                default => 'file',
            };
            $locations[] = ['modifier' => $match[1], 'pattern' => $match[2], 'outcome' => $outcome];
        }
        self::assertGreaterThanOrEqual(8, count($locations), 'the location parser found too few blocks to mean anything');

        return $locations;
    }

    /** @param list<array{modifier:string,pattern:string,outcome:string}> $locations */
    private static function outcome(array $locations, string $uri): string
    {
        $prefix = null;
        foreach ($locations as $location) {
            if ($location['modifier'] === '=' && $location['pattern'] === $uri) {
                return $location['outcome'];
            }
            if (in_array($location['modifier'], ['', '^~'], true) && str_starts_with($uri, $location['pattern'])
                && ($prefix === null || strlen($location['pattern']) > strlen($prefix['pattern']))) {
                $prefix = $location;
            }
        }
        if ($prefix !== null && $prefix['modifier'] === '^~') {
            return $prefix['outcome'];
        }
        foreach ($locations as $location) {
            if (in_array($location['modifier'], ['~', '~*'], true)) {
                $regex = '#' . str_replace('#', '\#', $location['pattern']) . '#' . ($location['modifier'] === '~*' ? 'i' : '');
                if (preg_match($regex, $uri) === 1) {
                    return $location['outcome'];
                }
            }
        }

        return $prefix['outcome'] ?? 'none';
    }

    /** @return list<string> webroot-relative paths, runtime and vendor trees left out */
    private static function webrootFiles(): array
    {
        $root = self::webRoot();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !in_array(substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1), ['vendor', 'logs', 'var'], true)
        ));
        foreach ($iterator as $file) {
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        }
        sort($files);

        return $files;
    }

    /** @return array<string,true> webroot-relative PHP files some other webroot file requires */
    private static function requiredFiles(array $files): array
    {
        $root = self::webRoot();
        $required = [];
        foreach ($files as $relative) {
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, 'tests/')) {
                continue;
            }
            $dir = dirname($root . '/' . $relative);
            $source = (string) file_get_contents($root . '/' . $relative);
            preg_match_all("/(?:require|include)(?:_once)?\s*\(?\s*(?:__DIR__|dirname\(__DIR__(?:\s*,\s*(\d+))?\))\s*\.\s*'([^']+\.php)'/", $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $base = str_contains($match[0], 'dirname(') ? dirname($dir, max(1, (int) ($match[1] ?: 1))) : $dir;
                $target = realpath($base . '/' . $match[2]);
                if ($target !== false) {
                    $required[substr(str_replace('\\', '/', $target), strlen($root) + 1)] = true;
                }
            }
        }

        return $required;
    }

    public function testOnlyEntryPointsReachFpmAndOnlyAssetsAreServed(): void
    {
        $files = self::webrootFiles();
        $required = self::requiredFiles($files);
        self::assertArrayHasKey('lib/db.php', $required, 'the require scan found nothing; the derivation is broken');

        $expected = [];
        foreach ($files as $relative) {
            $entryCandidate = preg_match('#^(portal/)?[^/]+\.php$#', $relative) === 1;
            $expected[$relative] = match (true) {
                $entryCandidate && !isset($required[$relative]) => ['fpm'],
                str_starts_with($relative, 'portal/assets/') && !str_ends_with($relative, '.php') => ['file'],
                default => ['deny', '404'],
            };
        }
        $includeOnly = array_values(array_filter($files, static fn (string $f): bool => preg_match('#^[^/]+\.php$#', $f) === 1 && isset($required[$f])));
        self::assertNotSame([], $includeOnly, 'no top-level include file found; the derivation is broken');

        foreach (self::configs() as $label => $config) {
            $locations = self::locations($config);
            $wrong = [];
            foreach ($expected as $relative => $allowed) {
                $got = self::outcome($locations, '/' . $relative);
                if (!in_array($got, $allowed, true)) {
                    $wrong[] = '/' . $relative . ' -> ' . $got . ' (expected ' . implode('|', $allowed) . ')';
                }
            }
            self::assertSame([], $wrong, $label . ': nginx disagrees with the entry points derived from the source');
        }
    }

    /** Every machine endpoint must stay an FPM entry point; their callers sit outside the portal. */
    public function testMachineEndpointsAndRuntimeTreesKeepTheirAnswers(): void
    {
        $probes = [
            '/index.php' => 'fpm',
            '/portal/login.php' => 'fpm',
            '/portal/health.php' => 'fpm',
            '/vendor/autoload.php' => 'deny',
            '/var/deploy-supervisor/state.php' => 'deny',
            '/logs/deploy.log' => 'deny',
            '/lang/de/login.php' => 'deny',
            '/.env' => 'deny',
            '/.git/config' => 'deny',
            '/somewhere/deeper.php' => '404',
            '/portal/' => 'file',
        ];
        foreach (VIRTUSPHERE_MACHINE_API_ENDPOINTS as $endpoint) {
            if ($endpoint !== VIRTUSPHERE_MACHINE_API_ENDPOINT_UNKNOWN) {
                $probes['/' . $endpoint] = 'fpm';
            }
        }
        foreach (self::configs() as $label => $config) {
            $locations = self::locations($config);
            foreach ($probes as $uri => $answer) {
                self::assertSame($answer, self::outcome($locations, $uri), $label . ': ' . $uri);
            }
        }
    }
}
