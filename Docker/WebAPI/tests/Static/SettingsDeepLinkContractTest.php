<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/settings_page.php';

/**
 * settings.php spreads its forms over tabs and picks the open one from the URL
 * fragment. A link that omits it, or that names a panel the page does not
 * render, silently opens the FIRST tab: no error, no empty panel, just a page
 * that does not contain the field the message pointing here just named. That is
 * the same silent-wrong-view defect LogDeepLinkContractTest pins for logs.php,
 * and it had spread over eight hand-written call sites including the redirect
 * every POST on the page goes through.
 *
 * settings_url() (lib/settings_page.php) validates the anchor against the ids
 * the page renders; this pins it as the only builder and keeps the constants
 * and the markup honest in both directions.
 */
final class SettingsDeepLinkContractTest extends TestCase
{
    /** The one file allowed to spell the link out, because it is the builder. */
    private const BUILDER = 'lib/settings_page.php';
    private const PAGE = 'portal/settings.php';

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * Comments name the very pattern they forbid (this class does it too), so a
     * raw scan would report the warning as the offence.
     */
    private function stripComments(string $php): string
    {
        $stripped = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
                continue;
            }
            $stripped .= $token;
        }

        return $stripped;
    }

    /** @return array<string, string> relative path => source without PHP comments */
    private function sources(): array
    {
        $root = $this->root();
        $paths = array_merge(
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/repo/*.php') ?: [],
            glob($root . '/lib/help/*.php') ?: [],
        );
        self::assertNotSame([], $paths, 'no portal or lib sources found to scan');

        $sources = [];
        foreach ($paths as $path) {
            $relative = substr(str_replace('\\', '/', $path), strlen($root) + 1);
            if ($relative === self::BUILDER) {
                continue;
            }

            $sources[$relative] = $this->stripComments((string) file_get_contents($path));
        }

        return $sources;
    }

    /** @return list<string> panel ids settings.php renders, tabs and inner sections alike */
    private function renderedPanels(): array
    {
        $path = $this->root() . '/' . self::PAGE;
        self::assertFileExists($path, self::PAGE . ' must exist');
        preg_match_all('/id="panel-([a-z-]+)"/', (string) file_get_contents($path), $matches);
        self::assertNotSame([], $matches[1], 'no panel ids found; the markup or the regex changed');

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> tab ids that render as an actual tabpanel */
    private function renderedTabs(): array
    {
        $path = $this->root() . '/' . self::PAGE;
        preg_match_all('/id="panel-([a-z-]+)"[^>]*role="tabpanel"/', (string) file_get_contents($path), $matches);
        self::assertNotSame([], $matches[1], 'no tabpanels found; the markup or the regex changed');

        return array_values(array_unique($matches[1]));
    }

    public function testNoPageHandWritesASettingsDeepLink(): void
    {
        $offenders = [];
        foreach ($this->sources() as $relative => $source) {
            if (preg_match_all('/settings\.php#/i', $source, $matches) > 0) {
                $offenders[$relative] = count($matches[0]) . ' occurrence(s)';
            }
        }

        self::assertSame(
            [],
            $offenders,
            'build settings deep links with settings_url() (' . self::BUILDER . '); a hand-written'
            . " fragment is not checked against the panels the page renders"
        );
    }

    public function testTheBuilderIsUsed(): void
    {
        $callers = [];
        foreach ($this->sources() as $relative => $source) {
            if (str_contains($source, 'settings_url(')) {
                $callers[] = $relative;
            }
        }

        self::assertNotSame(
            [],
            $callers,
            'nothing calls settings_url() any more; either a page went back to a raw href or the links were dropped'
        );
    }

    /**
     * The constants are what settings_url() accepts. If the page renames a
     * panel and the constant stays, every link to it starts landing on the
     * first tab while the build stays green.
     */
    public function testEveryConstantAnchorIsRendered(): void
    {
        $unknown = array_diff(
            array_merge(VIRTUSPHERE_SETTINGS_TABS, VIRTUSPHERE_SETTINGS_SECTIONS),
            $this->renderedPanels()
        );
        sort($unknown);
        self::assertSame([], $unknown, 'a settings anchor constant names a panel settings.php does not render');
    }

    /**
     * And the other way: a tab added to the page without its constant cannot be
     * linked to at all, because settings_url() would reject it.
     */
    public function testEveryRenderedTabHasItsConstant(): void
    {
        $missing = array_diff($this->renderedTabs(), VIRTUSPHERE_SETTINGS_TABS);
        sort($missing);
        self::assertSame([], $missing, 'settings.php renders a tabpanel VIRTUSPHERE_SETTINGS_TABS does not list');
    }

    public function testTheBuilderRejectsAnUnknownAnchor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        settings_url('panel-that-does-not-exist');
    }

    /** The fragment carries the id, not the raw panel prefix twice. */
    public function testTheBuilderBuildsTheRenderedId(): void
    {
        foreach (array_merge(VIRTUSPHERE_SETTINGS_TABS, VIRTUSPHERE_SETTINGS_SECTIONS) as $anchor) {
            self::assertSame('settings.php#panel-' . $anchor, settings_url($anchor));
        }
    }
}
