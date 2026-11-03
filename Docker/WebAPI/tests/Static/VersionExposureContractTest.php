<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the version-exposure hardening (AP7 security, plan v2 / TESTPLAN C5).
 *
 * Three independent places decide whether an unauthenticated observer learns
 * exact component versions, and each of them regresses silently: the portal
 * keeps working with expose_php back on, with server_tokens back to default,
 * or with health.php reporting the full PHP_VERSION again. Nothing functional
 * notices, only the attack surface grows. The one machine consumer of
 * health.php (install-VirtuSphere-MECM.ps1) reads only `.status`, so the
 * coarse `php` field is contractually safe.
 *
 * The live-response side (no X-Powered-By header, Server header without a
 * version, coarse `php` in the health JSON) is asserted by the
 * `health-contract` gate of the Integration lane against the QA stack; this
 * test pins the configuration sources so a regression fails in the Fast lane
 * already.
 */
final class VersionExposureContractTest extends TestCase
{
    private function repoFile(string $relative): string
    {
        // Docker/php and Docker/nginx are outside the container mount (only
        // Docker/WebAPI is /var/www/html), so this runs from a repo checkout
        // and skips inside the container, like SessionHardeningContractTest.
        $path = dirname(__DIR__, 3) . '/' . $relative;
        if (!is_file($path)) {
            self::markTestSkipped($relative . ' is not visible from this runtime');
        }

        return $path;
    }

    public function testPhpDoesNotAnnounceItsExactVersion(): void
    {
        $parsed = parse_ini_file($this->repoFile('php/conf.d/zz-virtusphere.ini'), false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'zz-virtusphere.ini is not parseable');

        self::assertSame(
            'Off',
            $parsed['expose_php'] ?? '(missing)',
            'expose_php must be Off: with it on, every response carries X-Powered-By with the exact PHP version.'
        );
    }

    public function testNginxDoesNotAnnounceItsExactVersion(): void
    {
        $conf = (string) file_get_contents($this->repoFile('nginx/default.conf'));

        self::assertMatchesRegularExpression(
            '/^\s*server_tokens\s+off\s*;/m',
            $conf,
            'server_tokens off is missing from Docker/nginx/default.conf: the Server header would name the exact nginx version on every response, including the generated HTTPS block.'
        );
    }

    public function testHealthEndpointReportsOnlyTheCoarsePhpVersion(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/health.php');

        self::assertDoesNotMatchRegularExpression(
            '/(?<![A-Z_])PHP_VERSION\b/',
            $source,
            'health.php must not emit the full PHP_VERSION: the endpoint is unauthenticated and the patch level maps straight to CVEs. Use the coarse HEALTH_PHP_VERSION (major.minor).'
        );
        self::assertStringContainsString(
            "PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION",
            $source,
            'health.php should build its php field from PHP_MAJOR_VERSION.PHP_MINOR_VERSION so monitoring still sees the runtime line it runs on.'
        );
    }
}
