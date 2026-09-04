<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/CssRules.php';

/**
 * One registry decides which stylesheets the portal loads and in which order.
 *
 * Two things used to be able to drift apart silently. The portal shell and the
 * login page each linked their sheets by hand, and login.php was already one
 * behind: it never linked status.css, so a rule that page shares with the rest
 * of the portal would have rendered differently there with nothing to notice.
 * And a stylesheet added under assets/css but linked nowhere is dead weight
 * that still passes every other guard, because every CSS contract in this repo
 * reads the files it finds rather than the files the page loads.
 *
 * `layout_app_styles()` in lib/layout.php is that registry. The order in it is
 * load-bearing: equally specific rules are resolved by position, so the file
 * order IS the position (see the function's own comment and
 * StatusSpacingContractTest).
 */
final class PortalStyleRegistryContractTest extends TestCase
{
    private function webApiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents($this->webApiRoot() . '/' . $relative);
    }

    /** @return list<string> basenames of every stylesheet that exists */
    private function existingSheets(): array
    {
        $paths = glob($this->webApiRoot() . '/portal/assets/css/*.css') ?: [];
        $names = array_map(static fn (string $p): string => basename($p), $paths);
        sort($names);

        return $names;
    }

    /**
     * The comparison both real assertions below run, isolated so its negative
     * and zero-match cases can be driven without writing files into the asset
     * directory. A guard that can only be exercised by the state it happens to
     * find is a guard nobody can prove.
     *
     * @param list<string> $existing
     * @param list<string> $registered
     * @return array{unregistered: list<string>, missing: list<string>}
     */
    public static function compareRegistry(array $existing, array $registered): array
    {
        return [
            'unregistered' => array_values(array_diff($existing, $registered)),
            'missing' => array_values(array_diff($registered, $existing)),
        ];
    }

    public function testEveryStylesheetOnDiskIsRegisteredAndEveryRegisteredOneExists(): void
    {
        $existing = $this->existingSheets();
        $registered = CssRules::linkOrder($this->webApiRoot());

        self::assertNotSame([], $existing, 'no stylesheet found under portal/assets/css: the scan root moved');
        self::assertNotSame([], $registered, 'layout_app_styles() names no stylesheet');

        $result = self::compareRegistry($existing, $registered);

        self::assertSame(
            [],
            $result['unregistered'],
            'a stylesheet exists that layout_app_styles() does not load. Add it to the order (and re-run the '
            . 'computed-style comparison, because the position decides ties) or delete it'
        );
        self::assertSame(
            [],
            $result['missing'],
            'layout_app_styles() loads a stylesheet that does not exist; the portal would fetch a 404'
        );
    }

    public function testTheRegistryComparisonCatchesAnUnregisteredSheetAndAMissingOne(): void
    {
        self::assertSame(
            ['unregistered' => ['new.css'], 'missing' => []],
            self::compareRegistry(['base.css', 'new.css'], ['base.css']),
            'a new stylesheet nobody registered must be reported'
        );
        self::assertSame(
            ['unregistered' => [], 'missing' => ['gone.css']],
            self::compareRegistry(['base.css'], ['base.css', 'gone.css']),
            'a registered stylesheet that no longer exists must be reported'
        );
        self::assertSame(
            ['unregistered' => [], 'missing' => []],
            self::compareRegistry(['base.css'], ['base.css'])
        );
    }

    public function testBothHeadsUseTheRegistryAndLinkNoStylesheetByHand(): void
    {
        $layout = $this->source('lib/layout.php');
        $registry = $this->registryBody($layout);

        self::assertSame(
            1,
            preg_match_all('/<link[^>]*rel="stylesheet"/', $registry),
            'layout_app_styles() must emit exactly one stylesheet link template, once per entry of its list'
        );

        // Everything OUTSIDE the emitter, plus the login head, must be free of
        // hand-written links. Scanning the whole file would match the emitter
        // itself, which is the one place the tag belongs.
        foreach ([
            'lib/layout.php' => str_replace($registry, '', $layout),
            'portal/login.php' => $this->source('portal/login.php'),
        ] as $relative => $source) {
            self::assertStringContainsString(
                'layout_app_styles()',
                $source,
                $relative . ' must load its stylesheets through the shared registry'
            );
            self::assertDoesNotMatchRegularExpression(
                '/<link[^>]*rel="stylesheet"/',
                $source,
                $relative . ' links a stylesheet beside the registry; that is how the two heads drifted apart, '
                . 'and login.php was already missing status.css'
            );
        }
    }

    /** The source of layout_app_styles(), from its signature to its closing brace. */
    private function registryBody(string $layout): string
    {
        $start = strpos($layout, 'function layout_app_styles(');
        self::assertNotFalse($start, 'lib/layout.php must define layout_app_styles()');

        $open = strpos($layout, '{', $start);
        self::assertNotFalse($open, 'layout_app_styles() must have a body');

        $depth = 0;
        for ($i = $open; $i < strlen($layout); $i++) {
            if ($layout[$i] === '{') {
                $depth++;
            } elseif ($layout[$i] === '}' && --$depth === 0) {
                return substr($layout, $start, $i - $start + 1);
            }
        }

        self::fail('layout_app_styles() has an unbalanced body');
    }

    public function testTheFourDomainSheetsExistAndTheMonolithIsGone(): void
    {
        $existing = $this->existingSheets();

        foreach (['panels.css', 'tables.css', 'controls.css', 'feedback.css'] as $sheet) {
            self::assertContains($sheet, $existing, $sheet . ' is one of the four domain sheets of Etappe 16');
        }
        self::assertNotContains(
            'components.css',
            $existing,
            'components.css was split into the four domain sheets; a returning monolith re-bundles the families'
        );
    }

    public function testNoStylesheetImportsAnother(): void
    {
        foreach (CssRules::stylesheets($this->webApiRoot()) as $path => $css) {
            self::assertStringNotContainsString(
                '@import',
                CssRules::stripComments($css),
                basename($path) . ' must not @import: an imported sheet bypasses the cache-busting version query '
                . 'and the registry above, and the air-gapped portal ships every byte itself'
            );
        }
    }
}
