<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/errors.php';

/**
 * PR-R1: the error page stands alone (no layout, no catalog, no portal
 * stylesheet), and still has to look like the portal and offer a way back.
 *
 * It repeats the theme tokens it needs. This test reads them from the rendered
 * page and compares each with base.css, light against :root and dark against
 * the dark block, so a palette change that misses the error page turns red
 * here instead of leaving a page in last year's colours.
 */
final class ErrorPageDesignTest extends TestCase
{
    private string|false $debugBefore = false;

    protected function setUp(): void
    {
        $this->debugBefore = getenv('VIRTUSPHERE_DEBUG');
        putenv('VIRTUSPHERE_DEBUG=0');
    }

    protected function tearDown(): void
    {
        putenv($this->debugBefore === false ? 'VIRTUSPHERE_DEBUG' : 'VIRTUSPHERE_DEBUG=' . $this->debugBefore);
    }

    public function testEveryThemeTokenOfTheErrorPageHasTheValueOfBaseCss(): void
    {
        $css = virtusphere_error_page_css();
        $darkStart = strpos($css, '@media (prefers-color-scheme:dark)');
        self::assertNotFalse($darkStart, 'the error page follows the system colour scheme');
        $darkEnd = strpos($css, '}}', $darkStart);
        self::assertNotFalse($darkEnd);

        $light = $this->tokens(substr($css, 0, $darkStart));
        $dark = $this->tokens(substr($css, $darkStart, $darkEnd - $darkStart));
        self::assertNotSame([], $light, 'no light tokens found in the error page style');
        self::assertSame(array_keys($light), array_keys($dark), 'both themes declare the same tokens');

        $base = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/assets/css/base.css');
        $baseLight = $this->tokens($this->block($base, ':root {'));
        $baseDark = $this->tokens($this->block($base, ':root[data-theme="dark"] {'));

        foreach ($light as $name => $value) {
            self::assertSame($baseLight[$name] ?? null, $value, 'light ' . $name . ' differs from base.css');
        }
        foreach ($dark as $name => $value) {
            // A token the dark block does not restate keeps its light value.
            self::assertSame($baseDark[$name] ?? $baseLight[$name] ?? null, $value, 'dark ' . $name . ' differs from base.css');
        }
    }

    public function testEveryColourOfTheErrorPageIsATokenValue(): void
    {
        $css = virtusphere_error_page_css();
        $withoutTokens = (string) preg_replace('/--[a-z-]+:[^;}]+/', '', $css);

        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $withoutTokens, 'a colour outside the token declarations');
    }

    public function testEveryRuleIsScopedToTheMarkedMain(): void
    {
        $css = virtusphere_error_page_css();
        $scope = 'main[' . VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE . ']';
        // Unwrap the two media blocks, then read selector by selector.
        $flat = (string) preg_replace('/@media[^{]+\{((?:[^{}]+\{[^{}]*\})+)\}/', '$1', $css);
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $flat, $rules);
        self::assertNotSame([], $rules[1]);

        foreach ($rules[1] as $selector) {
            if ($selector === 'body') {
                continue;
            }
            self::assertStringStartsWith($scope, $selector, 'a rule that would also restyle a portal page above the appended error page');
        }
    }

    public function testThePageNamesTheIdAsTheLogViewerDoesAndOffersAWayBack(): void
    {
        $html = $this->render();
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        self::assertSame('Korrelations-ID', $document->querySelector('dt')?->textContent);
        self::assertSame('0123456789abcdef', $document->querySelector('dd')?->textContent);
        self::assertSame('Fehler - VirtuSphere', $document->querySelector('title')?->textContent);

        $links = [];
        foreach ($document->querySelectorAll('main nav a') as $link) {
            $links[] = (string) $link->getAttribute('href');
        }
        self::assertSame(['', 'dashboard.php'], $links, 'reload by GET and the dashboard, both relative to the failed portal page');
        self::assertStringNotContainsString(' class="', $html, 'a class would need a rule in portal/assets/css (A30), which this page does not load');
    }

    private function render(): string
    {
        ob_start();
        try {
            virtusphere_render_error_page(new RuntimeException('synthetic failure'), '0123456789abcdef');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return array<string,string> token name => value, whitespace removed */
    private function tokens(string $css): array
    {
        preg_match_all('/(--[a-z-]+)\s*:\s*([^;}]+)/', $css, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = (string) preg_replace('/\s+/', '', $match[2]);
        }

        return $tokens;
    }

    private function block(string $css, string $opening): string
    {
        $start = strpos($css, $opening);
        self::assertNotFalse($start, $opening . ' not found in base.css');
        $end = strpos($css, "\n}", $start);
        self::assertNotFalse($end);

        return substr($css, $start, $end - $start);
    }
}
