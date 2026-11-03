<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * docs/operations/system-status-checks.md has an overview table with one row
 * per area of the System status page and one "## " section (with a Mermaid
 * flowchart) per area. The doc names every area exactly as the page's
 * overview card does, so the operator finds the card they are looking at.
 *
 * Both lists are derived, never listed here: the areas are the
 * `<section class="panel status-section" id="<anchor>">` blocks rendered by
 * lib/system_status*.php, cross-checked against the overview strip in
 * lib/system_status_panels.php; the strip maps each anchor to its
 * `system_status.overview_*` key, whose German text in
 * lang/de/system_status.php is the name the doc must use. A new area, a renamed
 * card or a section without its card turns this red; a zero match never passes.
 *
 * Runs on the host and in the QA lane's full-repo mount; docs/ is not visible
 * inside the PHP container.
 */
final class SystemStatusChecksDocContractTest extends TestCase
{
    private const NAME_COLUMN_HEADER = 'Bereich';

    public function testDocNamesEveryAreaTheCodeRendersWithItsCardLabel(): void
    {
        $docPath = dirname(__DIR__, 4) . '/docs/operations/system-status-checks.md';
        if (!is_file($docPath)) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        $root = dirname(__DIR__, 2);
        $rendered = self::renderedAnchors($root . '/lib');
        $cards = self::overviewCards($root . '/lib/system_status_panels.php');

        self::assertNotSame([], $rendered, 'No rendered status sections found (zero-match must not pass).');
        $cardAnchors = array_keys($cards);
        sort($cardAnchors);
        self::assertSame($rendered, $cardAnchors, 'Every rendered area needs its overview card and vice versa.');

        $de = require $root . '/lang/de/system_status.php';
        $names = [];
        foreach ($cards as $anchor => $key) {
            self::assertArrayHasKey($key, $de, sprintf('Overview card %s has no German label system_status.%s.', $anchor, $key));
            $names[] = (string) $de[$key];
        }

        $doc = str_replace("\r\n", "\n", (string) file_get_contents($docPath));
        self::assertSame([], self::areaProblems($names, $doc));
    }

    public function testGuardRejectsMissingSection(): void
    {
        self::assertSame(
            ['Area "B" has no "## " section.'],
            self::areaProblems(['A', 'B'], self::fixture(['A', 'B'], ['A' => true]))
        );
    }

    public function testGuardRejectsSectionWithoutCodeCounterpart(): void
    {
        self::assertSame(
            ['Section "Ghost" names no area of the page (diagram of nothing).'],
            self::areaProblems(['A'], self::fixture(['A'], ['A' => true, 'Ghost' => true]))
        );
    }

    public function testGuardRejectsMissingTableRow(): void
    {
        self::assertSame(
            ['Area "B" has no overview row.'],
            self::areaProblems(['A', 'B'], self::fixture(['A'], ['A' => true, 'B' => true]))
        );
    }

    public function testGuardRejectsNameThatDiffersFromTheCardLabel(): void
    {
        self::assertSame(
            [
                'Area "Interne Dienste" has no overview row.',
                'Area "Interne Dienste" has no "## " section.',
                'Overview row "Intern" names no area of the page (diagram of nothing).',
                'Section "Intern" names no area of the page (diagram of nothing).',
            ],
            self::areaProblems(['Interne Dienste'], self::fixture(['Intern'], ['Intern' => true]))
        );
    }

    public function testGuardRejectsDuplicateRow(): void
    {
        self::assertSame(
            ['Overview row "A" appears 2 times, expected once.'],
            self::areaProblems(['A'], self::fixture(['A', 'A'], ['A' => true]))
        );
    }

    public function testGuardRejectsSectionWithoutFlowchart(): void
    {
        self::assertSame(
            ['Section "B" has no Mermaid flowchart.'],
            self::areaProblems(['A', 'B'], self::fixture(['A', 'B'], ['A' => true, 'B' => false]))
        );
    }

    public function testGuardRejectsZeroMatches(): void
    {
        self::assertNotSame([], self::areaProblems([], self::fixture(['A'], ['A' => true])));
        self::assertNotSame([], self::areaProblems(['A'], "# Doc\n\nNo table, no sections.\n"));
    }

    public function testGuardAcceptsAConsistentDocument(): void
    {
        self::assertSame([], self::areaProblems(['A', 'B'], self::fixture(['A', 'B'], ['A' => true, 'B' => true])));
    }

    /**
     * @param list<string> $names area names as the page's overview cards show them
     * @return list<string>
     */
    public static function areaProblems(array $names, string $doc): array
    {
        $problems = [];
        if ($names === []) {
            $problems[] = 'No areas derived from code (zero-match must not pass).';
        }
        $rows = self::overviewRows($doc);
        $sections = self::sections($doc);
        if ($rows === []) {
            $problems[] = 'The overview table has no rows (zero-match must not pass).';
        }
        if ($sections === []) {
            $problems[] = 'The document has no "## " sections (zero-match must not pass).';
        }

        foreach ($names as $name) {
            if (!in_array($name, $rows, true)) {
                $problems[] = sprintf('Area "%s" has no overview row.', $name);
            }
            if (!array_key_exists($name, $sections)) {
                $problems[] = sprintf('Area "%s" has no "## " section.', $name);
            }
        }
        foreach (array_count_values($rows) as $row => $count) {
            if (!in_array((string) $row, $names, true)) {
                $problems[] = sprintf('Overview row "%s" names no area of the page (diagram of nothing).', $row);
            } elseif ($count > 1) {
                $problems[] = sprintf('Overview row "%s" appears %d times, expected once.', $row, $count);
            }
        }
        foreach ($sections as $heading => $body) {
            if (!in_array((string) $heading, $names, true)) {
                $problems[] = sprintf('Section "%s" names no area of the page (diagram of nothing).', $heading);
            } elseif (preg_match('/```mermaid\nflowchart /', $body) !== 1) {
                $problems[] = sprintf('Section "%s" has no Mermaid flowchart.', $heading);
            }
        }

        return $problems;
    }

    /**
     * First column of the table headed "Bereich".
     *
     * @return list<string>
     */
    public static function overviewRows(string $doc): array
    {
        $rows = [];
        $inTable = false;
        foreach (explode("\n", $doc) as $line) {
            if (!$inTable) {
                $inTable = str_starts_with($line, '| ' . self::NAME_COLUMN_HEADER . ' |');
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            if (str_starts_with($line, '|---')) {
                continue;
            }
            $rows[] = trim(explode('|', $line)[1]);
        }

        return $rows;
    }

    /**
     * @return array<string, string> "## " heading => section body
     */
    public static function sections(string $text): array
    {
        $parts = preg_split('/^## /m', $text) ?: [];
        array_shift($parts);
        $sections = [];
        foreach ($parts as $part) {
            $newline = strpos($part, "\n");
            $heading = trim($newline === false ? $part : substr($part, 0, $newline));
            $sections[$heading] = $newline === false ? '' : substr($part, $newline + 1);
        }

        return $sections;
    }

    /**
     * Anchors of every `<section class="panel status-section" id="...">`
     * rendered by lib/system_status*.php.
     *
     * @return list<string>
     */
    private static function renderedAnchors(string $lib): array
    {
        $anchors = [];
        foreach (glob($lib . '/system_status*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/class="panel status-section" id="<\?php echo h\((VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_[A-Z_]+)\)/', $source, $matches) > 0) {
                foreach ($matches[1] as $constant) {
                    $anchors[$constant] = true;
                }
            }
        }
        $names = array_keys($anchors);
        sort($names);

        return $names;
    }

    /**
     * Overview strip entries in page order: anchor constant => label key.
     *
     * @return array<string, string>
     */
    private static function overviewCards(string $panels): array
    {
        $source = (string) file_get_contents($panels);
        preg_match_all('/\[(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_[A-Z_]+), __t\(\'system_status\.(overview_[a-z_]+)\'\)/', $source, $matches, PREG_SET_ORDER);
        $cards = [];
        foreach ($matches as $match) {
            $cards[$match[1]] = $match[2];
        }

        return $cards;
    }

    /**
     * @param string[] $rows
     * @param array<string, bool> $sections heading => has flowchart
     */
    private static function fixture(array $rows, array $sections): string
    {
        $doc = "# Doc\n\n| Bereich | Quelle |\n|---|---|\n";
        foreach ($rows as $row) {
            $doc .= '| ' . $row . " | x |\n";
        }
        foreach ($sections as $heading => $hasFlowchart) {
            $doc .= "\n## " . $heading . "\n\n" . ($hasFlowchart ? "```mermaid\nflowchart TD\n  A --> B\n```\n" : "Text only.\n");
        }

        return $doc;
    }
}
