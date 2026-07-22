<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Active portal text uses the current MECM product name consistently. */
final class TerminologyContractTest extends TestCase
{
    private const RETIRED_TERMS = '/sccm|configmgr/i';

    public function testLanguageCatalogValuesUseMecmTerminology(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/lang/*/*.php') ?: [];
        self::assertNotSame([], $files, 'no language catalogs found; the terminology scan would prove nothing');

        $offenders = [];
        foreach ($files as $file) {
            /** @var array<string, mixed> $catalog */
            $catalog = require $file;
            array_walk_recursive(
                $catalog,
                static function (mixed $value, string|int $key) use ($file, &$offenders): void {
                    if (is_string($value) && preg_match(self::RETIRED_TERMS, $value) === 1) {
                        $offenders[] = $file . ':' . $key;
                    }
                }
            );
        }

        self::assertSame(
            [],
            $offenders,
            "SCCM/ConfigMgr are retired terms; active catalog text says MECM. "
            . 'Historical documentation is deliberately outside this portal contract.'
        );
    }

    public function testActivePortalSourceUsesMecmTerminology(): void
    {
        $root = dirname(__DIR__, 2);
        $patterns = [
            '/portal/*.php',
            '/portal/assets/*.js',
            '/lib/*.php',
            '/lib/repo/*.php',
            '/lib/help/*.php',
            '/tests/*.php',
            '/tests/*/*.php',
        ];
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root . $pattern) ?: [] as $file) {
                $files[$file] = true;
            }
        }
        self::assertNotSame([], $files, 'no active source files found; the terminology scan would prove nothing');

        $offenders = [];
        foreach (array_keys($files) as $file) {
            // This contract necessarily names the pattern it forbids.
            if (realpath($file) === __FILE__) {
                continue;
            }
            foreach (file($file) ?: [] as $lineNumber => $line) {
                if (preg_match(self::RETIRED_TERMS, $line) === 1) {
                    $offenders[] = $file . ':' . ($lineNumber + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "SCCM/ConfigMgr are retired terms; active portal and test source says MECM. "
            . 'Historical documentation is deliberately outside this source contract.'
        );
    }
}
