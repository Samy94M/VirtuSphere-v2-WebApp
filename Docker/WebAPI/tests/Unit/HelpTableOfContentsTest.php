<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/help_page.php';

/**
 * PR-R6: every help tab opens with a table of contents that help.php builds
 * from the rendered panel (help_panel_with_toc()). Nothing lists the entries by
 * hand, so this test derives them too: from the partial sources and the
 * section registry. A new H2 section without an anchor, an anchor registered
 * to another panel, or a section that does not start with its H2 fails here.
 */
final class HelpTableOfContentsTest extends TestCase
{
    /** @return array<string,string> panel => partial source */
    private function partials(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $sources = [];
        foreach (VIRTUSPHERE_HELP_PANELS as $panel => $definition) {
            $sources[$panel] = (string) file_get_contents($root . '/lib/help/' . $definition['partial']);
        }
        self::assertNotSame([], $sources, 'no help partials found (zero-match)');

        return $sources;
    }

    public function testEverySectionStartsWithAnAnchoredHeadingOfItsOwnPanel(): void
    {
        $tocEntries = 0;
        foreach ($this->partials() as $panel => $source) {
            $sectionCount = preg_match_all('~<section\b[^>]*\bclass="panel\b~', $source);
            $sections = help_panel_sections($source);
            self::assertSame(
                $sectionCount,
                preg_match_all(VIRTUSPHERE_HELP_TOC_SECTION_PATTERN, $source),
                $panel . ': every panel section must open with its H2'
            );
            self::assertCount($sectionCount, $sections, $panel . ': every H2 section needs a help-… anchor on the section or its heading');
            foreach ($sections as $section) {
                self::assertSame(
                    $panel,
                    VIRTUSPHERE_HELP_SECTIONS[$section['id']] ?? null,
                    $panel . ': ' . $section['id'] . ' must be registered to the panel that renders it'
                );
                $tocEntries++;
            }
        }
        self::assertGreaterThan(40, $tocEntries, 'too few table-of-contents entries found; the pattern no longer matches the partials');
    }

    public function testEveryAnchorOfAPartialIsRegisteredToThatPartialsPanel(): void
    {
        foreach ($this->partials() as $panel => $source) {
            preg_match_all('/id="(help-[a-z-]+)"/', $source, $matches);
            $rendered = array_values(array_unique($matches[1]));
            sort($rendered);
            $registered = array_keys(array_filter(
                VIRTUSPHERE_HELP_SECTIONS,
                static fn (string $owner): bool => $owner === $panel
            ));
            sort($registered);
            self::assertSame($registered, $rendered, $panel . ': registry and partial anchors differ');
        }
    }

    public function testTheTableListsExactlyTheH2SectionsInOrder(): void
    {
        // Two real sections of one panel, one anchored on the section, one on
        // its heading, plus an anchored H3 that must stay out of the table.
        $stack = array_keys(array_filter(VIRTUSPHERE_HELP_SECTIONS, static fn (string $owner): bool => $owner === 'stack'));
        self::assertContains('help-stack-faq', $stack);
        self::assertContains('help-app-key', $stack);
        self::assertContains('help-backup', $stack);
        $html = '<div class="stack" id="panel-stack" role="tabpanel" data-tab-panel>'
            . '<section class="panel faq" id="help-stack-faq"><h2>Fragen &amp; Antworten</h2>'
            . '<h3 id="help-backup" tabindex="-1">Backup</h3></section>'
            . '<section class="panel"><h2 id="help-app-key" tabindex="-1">APP_KEY</h2><p>x</p></section>'
            . '</div>';

        $out = help_panel_with_toc($html, 'stack');

        self::assertSame(1, substr_count($out, '<nav class="panel help-toc" aria-labelledby="toc-stack">'));
        self::assertSame(1, substr_count($out, '<h2 id="toc-stack">'));
        self::assertMatchesRegularExpression('~^<div[^>]*id="panel-stack"[^>]*>\s*<nav~', $out, 'the table opens the panel');
        preg_match('~<ol>(.*?)</ol>~s', $out, $list);
        preg_match_all('~<li><a href="([^"]+)">(.*?)</a></li>~', $list[1] ?? '', $items, PREG_SET_ORDER);
        self::assertSame(
            [
                [h(help_url('stack', 'help-stack-faq')), 'Fragen &amp; Antworten'],
                [h(help_url('stack', 'help-app-key')), 'APP_KEY'],
            ],
            array_map(static fn (array $item): array => [$item[1], $item[2]], $items),
            'the table lists every H2 in order, keeps the escaped label and leaves the H3 out'
        );
        self::assertStringEndsWith(substr($html, strpos($html, '<section')), $out, 'the panel content follows unchanged');
    }

    public function testAPanelWithOneSectionGetsNoTable(): void
    {
        $html = '<div class="stack" id="panel-stack" data-tab-panel>'
            . '<section class="panel" id="help-stack-faq"><h2>Eins</h2></section></div>';

        self::assertSame($html, help_panel_with_toc($html, 'stack'));
    }

    public function testAnUnregisteredSectionAnchorIsRefused(): void
    {
        $html = '<div class="stack" id="panel-stack" data-tab-panel>'
            . '<section class="panel" id="help-stack-faq"><h2>Eins</h2></section>'
            . '<section class="panel" id="help-not-registered"><h2>Zwei</h2></section></div>';

        $this->expectException(InvalidArgumentException::class);
        help_panel_with_toc($html, 'stack');
    }
}
