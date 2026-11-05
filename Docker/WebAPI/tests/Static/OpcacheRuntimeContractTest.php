<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the OPcache settings of PHP-FPM in Docker/php/conf.d/zz-virtusphere.ini (P2).
 *
 * The extension itself is loaded by the upstream base image: php:8.4-fpm ships
 * docker-php-ext-opcache.ini (`zend_extension=opcache`), and PHP's defaults
 * already switch the cache on for FPM and off for the CLI. Measured on
 * 05.11.2026 on the pinned digest through a real FPM request, not the CLI.
 * Nothing in this repository said so, which is how an analysis concluded the
 * cache was off. The project file therefore states the values the runtime
 * relies on, so a base-image or default change cannot drop them silently:
 *
 * - FPM caches, the CLI does not. Workers and CLI tools are long-lived or
 *   one-shot processes that gain nothing and would hold a second cache.
 * - Timestamp validation stays at PHP's defaults (on, every 2 s). The code is a
 *   bind mount, so a file replaced with a NEW mtime is picked up within 2 s
 *   even without a container swap. A replacement with an UNCHANGED mtime is
 *   not seen until the FPM master restarts; the update runbook always
 *   recreates the containers (docs/DEPLOYMENT.md).
 * - No preload and no JIT: each would be a decision with its own measurement.
 * - No second `zend_extension=opcache` line: PHP then prints "Cannot load
 *   Zend OPcache - it was already loaded" on every start, CLI included.
 *
 * The live state is read through an FPM request on the QA stack (register
 * entry P2); this test pins the source in the Fast lane.
 */
final class OpcacheRuntimeContractTest extends TestCase
{
    /** @return array<string, string> */
    private function settings(): array
    {
        // Docker/php is outside the container mount (only Docker/WebAPI is
        // /var/www/html), so this runs from a repo checkout and skips inside
        // the container, like SessionHardeningContractTest.
        $path = dirname(__DIR__, 3) . '/php/conf.d/zz-virtusphere.ini';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/php/conf.d/zz-virtusphere.ini is not visible from this runtime');
        }

        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'zz-virtusphere.ini is not parseable');

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pinnedValues(): iterable
    {
        yield 'FPM caches compiled scripts' => ['opcache.enable', '1'];
        yield 'the CLI and both workers do not' => ['opcache.enable_cli', '0'];
        yield 'shared memory for the compiled code' => ['opcache.memory_consumption', '128'];
        yield 'shared buffer for interned strings' => ['opcache.interned_strings_buffer', '16'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pinnedValues')]
    public function testPinnedValue(string $key, string $expected): void
    {
        $settings = $this->settings();

        self::assertArrayHasKey($key, $settings, $key . ' must be stated in zz-virtusphere.ini, not inherited.');
        self::assertSame($expected, $settings[$key]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function keysLeftToTheDefaults(): iterable
    {
        // Setting either would change when a replaced file becomes visible.
        yield 'timestamp validation' => ['opcache.validate_timestamps'];
        yield 'revalidation interval' => ['opcache.revalidate_freq'];
        // Not part of this runtime; enabling them is a decision of its own.
        yield 'preload script' => ['opcache.preload'];
        yield 'JIT mode' => ['opcache.jit'];
        yield 'JIT buffer' => ['opcache.jit_buffer_size'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keysLeftToTheDefaults')]
    public function testKeyIsNotSet(string $key): void
    {
        self::assertArrayNotHasKey($key, $this->settings());
    }

    public function testTheExtensionIsNotLoadedASecondTime(): void
    {
        $settings = $this->settings();

        self::assertArrayNotHasKey(
            'zend_extension',
            $settings,
            'The base image already loads OPcache (docker-php-ext-opcache.ini). A second '
            . 'zend_extension line makes PHP warn on every start, the workers and the CLI included.'
        );
    }
}
