<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Active portal text uses the current MECM product name consistently. */
final class TerminologyContractTest extends TestCase
{
    private const RETIRED_TERMS = '/sccm|configmgr/i';

    /**
     * The operator-facing name of this application is "Portal", in both
     * locales. "WebApp" / "web app" is the internal name of the deployment and
     * meant nothing to the rotating admin staff who read these screens: help
     * and settings used both words for the same thing, sometimes in one
     * sentence, so a reader had to guess whether they were two systems.
     *
     * The machine it runs on is a separate idea and keeps a name of its own
     * ("Portal-Server" / "portal server"), because "restart the portal" and
     * "log into the machine the portal runs on" are different instructions.
     */
    private const INTERNAL_APP_NAME = '/\bweb\s?app\b/i';

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

    public function testCatalogTextCallsThisApplicationThePortal(): void
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
                    if (is_string($value) && preg_match(self::INTERNAL_APP_NAME, $value) === 1) {
                        $offenders[] = basename(dirname($file)) . '/' . basename($file) . ':' . $key;
                    }
                }
            );
        }

        self::assertSame(
            [],
            $offenders,
            'user-facing text must say "Portal", not the internal name "WebApp"/"web app". '
            . 'For the machine it runs on, say "Portal-Server" / "portal server".'
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
