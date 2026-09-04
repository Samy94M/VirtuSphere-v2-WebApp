<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/CssRules.php';
require_once __DIR__ . '/../Support/CssColorScanner.php';

/**
 * One file names the colours; every other stylesheet asks for them.
 *
 * `base.css` declares the palette, and the rest of the portal consumes it as
 * `var(--token)`. Without a guard that boundary erodes one convenience at a
 * time, and it already had: a hard black shadow sat on a light page, a login
 * button spelled `#ffffff` and then needed a dark-theme override to spell a
 * near-black, and a modal backdrop carried a colour that appears nowhere else.
 * None of that is visible in a diff review, because each looked local and
 * reasonable where it stood.
 *
 * The check parses rather than greps, because every interesting case hides:
 * inside a gradient stop, inside a shadow, inside a `var()` fallback, inside a
 * nested function, and inside a data URL that is only CSS after decoding.
 * Comments and ordinary strings are non-matches by construction.
 *
 * System colours are the one allowed exception, and they are allowed *because*
 * refusing them would be the accessibility defect: under `forced-colors: active`
 * the user agent owns the palette, so a page must be able to say `Canvas` there.
 * They stay inside that media query and must appear in matching pairs.
 */
final class CssColorTokenContractTest extends TestCase
{
    /** The one sheet allowed to name a colour. */
    private const PALETTE_SHEET = 'base.css';

    private function webApiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    public function testNoPortalStylesheetOutsideThePaletteNamesAColour(): void
    {
        $sheets = CssRules::stylesheets($this->webApiRoot());
        self::assertNotSame([], $sheets, 'no portal stylesheet found; the scan root moved');

        $offenders = [];
        $declarations = 0;
        $scanned = 0;

        foreach ($sheets as $path => $css) {
            if (basename($path) === self::PALETTE_SHEET) {
                continue;
            }
            $scanned++;
            $result = CssColorScanner::scan($css, basename($path));
            $declarations += $result['declarations'];
            foreach ($result['findings'] as $finding) {
                $offenders[] = sprintf(
                    '[%s] %s: %s { %s: %s }',
                    $finding['id'],
                    $finding['sheet'],
                    $finding['selector'],
                    $finding['property'],
                    $finding['value']
                );
            }
        }

        // Zero-match protection in both directions. A scan that visited no sheet
        // or no declaration reports "no raw colour" for the same reason a clean
        // repository does, which is the one failure this guard cannot survive.
        self::assertGreaterThan(1, $scanned, 'fewer than two stylesheets were scanned; the glob or the exclusion is wrong');
        self::assertGreaterThan(200, $declarations, 'the scan saw almost no declarations; it is not reading the sheets');

        self::assertSame(
            [],
            $offenders,
            "A colour is spelled out instead of named. Declare it in base.css and use var(--token).\n"
            . implode("\n", $offenders)
        );
    }

    public function testThePaletteSheetActuallyDeclaresTheTokensTheOthersUse(): void
    {
        $root = $this->webApiRoot();
        $base = (string) file_get_contents($root . '/portal/assets/css/' . self::PALETTE_SHEET);
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $base, $declared);
        $known = array_flip(array_map('strtolower', $declared[1]));
        self::assertNotSame([], $known, self::PALETTE_SHEET . ' declares no custom property at all');

        // Only a var() WITHOUT a fallback is a defect: that one resolves to
        // nothing and the browser drops the whole declaration silently. A
        // fallback is a deliberate "use this token if it ever exists", and
        // `var(--font-mono, monospace)` is exactly that, so it stays legal.
        $missing = [];
        foreach (CssRules::stylesheets($root) as $path => $css) {
            preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*\)/i', CssRules::stripComments($css), $used);
            foreach ($used[1] as $token) {
                if (!isset($known[strtolower($token)])) {
                    $missing[] = basename($path) . ' uses ' . $token;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($missing)),
            'a stylesheet reads a token no sheet declares and gives no fallback; the property resolves '
            . 'to nothing and the declaration is silently dropped'
        );
    }

    /**
     * Each forbidden shape, driven through the scanner directly.
     *
     * These are the mutation fixtures: every one of them is a form the guard
     * must catch, and each would pass a naive `#[0-9a-f]` grep or defeat it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenShapes(): array
    {
        return [
            'plain hex' => ['.a { color: #ff0000; }', CssColorScanner::ID_HEX],
            'hex in a gradient stop' => ['.a { background: linear-gradient(90deg, var(--bg), #123456); }', CssColorScanner::ID_HEX],
            'hex in a shadow' => ['.a { box-shadow: 0 1px 2px #0003; }', CssColorScanner::ID_HEX],
            'hex as a var fallback' => ['.a { color: var(--nope, #abc); }', CssColorScanner::ID_HEX],
            'hex inside color-mix' => ['.a { background: color-mix(in srgb, #02141a 70%, transparent); }', CssColorScanner::ID_HEX],
            'rgba' => ['.a { background: rgba(0, 0, 0, 0.4); }', CssColorScanner::ID_FUNCTION],
            'hsl' => ['.a { color: hsl(210 50% 40%); }', CssColorScanner::ID_FUNCTION],
            'oklch' => ['.a { color: oklch(0.7 0.1 250); }', CssColorScanner::ID_FUNCTION],
            'lab' => ['.a { color: lab(50% 40 30); }', CssColorScanner::ID_FUNCTION],
            'color()' => ['.a { color: color(display-p3 1 0 0); }', CssColorScanner::ID_FUNCTION],
            'nested function' => ['.a { box-shadow: 0 0 4px color-mix(in srgb, rgb(1 2 3) 50%, transparent); }', CssColorScanner::ID_FUNCTION],
            'named colour' => ['.a { color: white; }', CssColorScanner::ID_NAMED],
            'named colour in a gradient' => ['.a { background: linear-gradient(90deg, transparent, silver); }', CssColorScanner::ID_NAMED],
            'system colour outside forced colors' => ['.a { color: CanvasText; }', CssColorScanner::ID_SYSTEM_OUTSIDE],
            'hex in a decoded data url' => ['.a { background: url("data:image/svg+xml,%3Csvg%20fill%3D%22%23ff0000%22%3E"); }', CssColorScanner::ID_DATA_URI],
            'hex in a base64 data url' => ['.a { background: url(data:image/svg+xml;base64,' . 'PHN2ZyBmaWxsPSIjZmYwMDAwIj48L3N2Zz4=' . '); }', CssColorScanner::ID_DATA_URI],
        ];
    }

    #[DataProvider('forbiddenShapes')]
    public function testEveryForbiddenShapeIsCaught(string $css, string $expectedId): void
    {
        $result = CssColorScanner::scan($css, 'fixture.css');
        $ids = array_column($result['findings'], 'id');

        self::assertContains($expectedId, $ids, 'the scanner did not report ' . $expectedId . ' for: ' . $css);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedShapes(): array
    {
        return [
            'token' => ['.a { color: var(--text); }'],
            'token with a token fallback' => ['.a { color: var(--text, var(--muted)); }'],
            'transparent' => ['.a { border-color: transparent; }'],
            'currentColor' => ['.a { border-right-color: currentColor; }'],
            'css-wide keyword' => ['.a { color: inherit; }'],
            'token-only color-mix' => ['.a { background: color-mix(in srgb, var(--accent) 14%, transparent); }'],
            'token gradient' => ['.a { background: linear-gradient(92deg, var(--accent-strong), var(--accent)); }'],
            'token shadow' => ['.a { box-shadow: var(--shadow-lift); }'],
            'a token whose NAME reads like a colour' => ['.a { color: var(--danger); background: var(--surface-muted); }'],
            'a font stack' => ['.a { font-family: system-ui, "Segoe UI", Roboto, Arial, sans-serif; }'],
            'a string that quotes a colour' => ['.a { content: "#ffffff"; }'],
            'a length that looks like a hex' => ['.a { padding: 0 10px 12px 8px; }'],
            'system colours inside forced colors, paired' => [
                '@media (forced-colors: active) { .a { background: Canvas; color: CanvasText; } }',
            ],
        ];
    }

    #[DataProvider('allowedShapes')]
    public function testEveryAllowedShapePasses(string $css): void
    {
        $result = CssColorScanner::scan($css, 'fixture.css');

        self::assertSame(
            [],
            array_map(
                static fn (array $f): string => $f['id'] . ' on ' . $f['property'],
                $result['findings']
            ),
            'the scanner reported an allowed shape: ' . $css
        );
    }

    public function testACommentIsNeverAMatch(): void
    {
        $css = "/* the old value was #ffffff, replaced by rgba(0,0,0,.3) and white */\n.a { color: var(--text); }";
        $result = CssColorScanner::scan($css, 'fixture.css');

        self::assertSame([], $result['findings'], 'a comment explaining a removed colour must not read as that colour');
        self::assertGreaterThan(0, $result['declarations'], 'the fixture must still contain a declaration');
    }

    public function testASystemBackgroundWithoutItsPairedForegroundIsReported(): void
    {
        $css = '@media (forced-colors: active) { .a { background: Canvas; } }';
        $ids = array_column(CssColorScanner::scan($css, 'fixture.css')['findings'], 'id');

        self::assertContains(
            CssColorScanner::ID_SYSTEM_UNPAIRED,
            $ids,
            'a forced-colors rule that repaints the background must name the foreground the user agent pairs with it'
        );
    }

    public function testTheScannerReportsNothingOnAnEmptySheet(): void
    {
        $result = CssColorScanner::scan('', 'fixture.css');

        self::assertSame([], $result['findings']);
        self::assertSame(0, $result['declarations'], 'an empty sheet has no declarations, and the real run asserts it saw many');
    }
}
