<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/help_page.php';

final class HelpAnchorContractTest extends TestCase
{
    /** @var array<string,string> */
    private const WITHOUT_PAGE_HELP = [
        'help.php' => 'the page already is the complete help destination',
    ];

    public function testEveryLayoutHeaderHelpAnchorBelongsToTheRegistry(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $anchors = [];
        $withoutAnchor = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $page) {
            $source = (string) file_get_contents($page);
            if (!str_contains($source, 'layout_header(')) {
                continue;
            }
            if (array_key_exists(basename($page), self::WITHOUT_PAGE_HELP)) {
                $withoutAnchor[] = basename($page);
                continue;
            }
            preg_match_all("/layout_header\\([^;]+,\\s*'([a-z-]+)'\\s*\\);/", $source, $matches);
            if ($matches[1] === []) {
                $withoutAnchor[] = basename($page);
            }
            foreach ($matches[1] as $anchor) {
                $anchors[] = $anchor;
                self::assertArrayHasKey($anchor, VIRTUSPHERE_HELP_PANELS, basename($page));
            }
        }
        self::assertNotSame([], $anchors);
        sort($withoutAnchor);
        $declared = array_keys(self::WITHOUT_PAGE_HELP);
        sort($declared);
        self::assertSame($declared, $withoutAnchor, 'every layout page needs a registered help target or a reasoned exception');
        foreach (self::WITHOUT_PAGE_HELP as $page => $reason) {
            self::assertNotSame('', trim($reason), $page . ' help exception needs a reason');
        }
    }

    public function testOnlyTheBuilderSpellsHelpFragments(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $offenders = [];
        foreach (array_merge(glob($root . '/portal/*.php') ?: [], glob($root . '/lib/*.php') ?: []) as $path) {
            if (basename($path) === 'help_page.php') {
                continue;
            }
            if (str_contains((string) file_get_contents($path), 'help.php#')) {
                $offenders[] = basename($path);
            }
        }
        self::assertSame([], $offenders);
    }

    public function testNestedSectionBuilderValidatesItsOwnerPanel(): void
    {
        self::assertSame('help.php#panel-deploy', help_url('deploy'));
        self::assertSame('help.php#help-backup', help_url('stack', 'help-backup'));
        $this->expectException(InvalidArgumentException::class);
        help_url('settings', 'help-backup');
    }

    public function testPanelRegistryAndHelpPartialsMatchInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_map('basename', glob($root . '/lib/help/*.php') ?: []);
        sort($files);
        self::assertNotSame([], $files, 'no help partials found (zero-match)');

        $registered = array_column(VIRTUSPHERE_HELP_PANELS, 'partial');
        self::assertSame(count($registered), count(array_unique($registered)), 'a help partial is registered twice');
        sort($registered);
        self::assertSame($files, $registered, 'help panel registry and partial directory must match in both directions');
    }

    public function testRegisteredPanelAndSectionIdsAreExactlyWhatPartialsRender(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $source = '';
        foreach (VIRTUSPHERE_HELP_PANELS as $panel => $definition) {
            $partial = (string) file_get_contents($root . '/lib/help/' . $definition['partial']);
            self::assertSame(1, substr_count($partial, 'id="panel-' . $panel . '"'), $panel . ' panel id');
            $source .= "\n" . $partial;
        }

        preg_match_all('/id="panel-([a-z-]+)"/', $source, $panels);
        $renderedPanels = array_values(array_unique($panels[1]));
        sort($renderedPanels);
        $registeredPanels = array_keys(VIRTUSPHERE_HELP_PANELS);
        sort($registeredPanels);
        self::assertSame($registeredPanels, $renderedPanels);

        preg_match_all('/id="(help-[a-z-]+)"/', $source, $sections);
        $renderedSections = array_values(array_unique($sections[1]));
        sort($renderedSections);
        $registeredSections = array_keys(VIRTUSPHERE_HELP_SECTIONS);
        sort($registeredSections);
        self::assertSame($registeredSections, $renderedSections);
    }
}
