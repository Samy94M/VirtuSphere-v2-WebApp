<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * docs/operations/vm-lifecycle.md draws the phase path (the derived VM phase),
 * the lifecycle axis, the MECM sync axis, and a table of the x/5 stage values.
 * Every closed list is derived from code and never listed here: the phases from
 * VIRTUSPHERE_VM_PHASES, the lifecycle and MECM states from their constants, the
 * stage values from VIRTUSPHERE_VM_STATUS_COMPAT, and each node's German portal
 * label from lang/de/status.php (`phase_<token>`, `lifecycle_<token>`, `mecm_<token>`).
 *
 * Each direction is checked: a value without its node or row leaves the page
 * incomplete, a node or row without a code counterpart is a diagram of nothing.
 * A zero match never passes. The checks are pure functions over strings so the
 * negative cases below prove each guard turns red without file IO.
 *
 * docs/ only exists outside the PHP container; the QA lane mounts the full repo
 * (same pattern as DeployFlowsDocContractTest).
 */
final class VmLifecycleDocContractTest extends TestCase
{
    private const DOC = 'docs/operations/vm-lifecycle.md';
    private const INDEX = 'docs/operations/flows.md';
    private const PATH_SECTION = 'Der Weg einer VM';
    private const LIFECYCLE_SECTION = 'Lebenszyklus';
    private const MECM_SECTION = 'MECM-Abgleich';
    private const STAGE_SECTION = 'Stufenwert x/5';
    private const NODE_PATTERN = '/(?<![A-Za-z0-9_])([a-z][a-z0-9_]*)\[([^\]\n]*)\]/';
    private const FIXTURE_LEGEND = ['classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;'];
    private const FIXTURE_VALUES = ['created', 'in_progress'];
    private const FIXTURE_LABELS = ['created' => 'Angelegt', 'in_progress' => 'In Arbeit'];
    private const FIXTURE_NODE_LIMIT = 12;

    public function testPhasePathDrawsEveryPhaseWithItsPortalLabel(): void
    {
        $doc = self::loadDoc(self::DOC);
        $flows = self::loadDoc(self::INDEX);

        self::assertSame([], self::diagramProblems(
            'Phase path',
            VIRTUSPHERE_VM_PHASES,
            self::portalLabels(VIRTUSPHERE_VM_PHASES, 'phase_'),
            self::section($doc, self::PATH_SECTION),
            self::nodeLimit($flows),
            self::legend($flows)
        ));
    }

    public function testLifecycleDiagramDrawsEveryStoredLifecycleState(): void
    {
        $doc = self::loadDoc(self::DOC);
        $flows = self::loadDoc(self::INDEX);

        self::assertSame([], self::diagramProblems(
            'Lifecycle diagram',
            VIRTUSPHERE_LIFECYCLE_STATES,
            self::portalLabels(VIRTUSPHERE_LIFECYCLE_STATES, 'lifecycle_'),
            self::section($doc, self::LIFECYCLE_SECTION),
            self::nodeLimit($flows),
            self::legend($flows)
        ));
    }

    public function testMecmDiagramDrawsEveryMecmSyncState(): void
    {
        $doc = self::loadDoc(self::DOC);
        $flows = self::loadDoc(self::INDEX);

        self::assertSame([], self::diagramProblems(
            'MECM diagram',
            VIRTUSPHERE_MECM_SYNC_STATES,
            self::portalLabels(VIRTUSPHERE_MECM_SYNC_STATES, 'mecm_'),
            self::section($doc, self::MECM_SECTION),
            self::nodeLimit($flows),
            self::legend($flows)
        ));
    }

    public function testStageTableNamesEveryStageValue(): void
    {
        $doc = self::loadDoc(self::DOC);

        self::assertSame([], self::statusTableProblems(VIRTUSPHERE_VM_STATUS_COMPAT, self::section($doc, self::STAGE_SECTION)));
    }

    public function testFlowsIndexLinksTheDocument(): void
    {
        self::assertStringContainsString('(vm-lifecycle.md)', self::loadDoc(self::INDEX), 'flows.md must link vm-lifecycle.md');
    }

    public function testTemplateIsNotAPhaseNode(): void
    {
        $doc = self::loadDoc(self::DOC);

        $blocks = self::mermaidBlocks(self::section($doc, self::PATH_SECTION));
        self::assertCount(1, $blocks, 'the phase path must contain exactly one mermaid block');
        self::assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])template\[/', $blocks[0]);
    }

    public function testGuardAcceptsAFixtureThatMatchesEveryDirection(): void
    {
        $section = self::fixtureBlock(
            "created[Angelegt]\nin_progress[In Arbeit]\ncreated --> in_progress\nclass created step;\nclassDef step fill:#eef2ff,stroke:#475569,color:#0f172a;"
        );

        self::assertSame([], self::fixtureProblems($section));
    }

    public function testGuardRejectsAMissingValue(): void
    {
        self::assertSame(
            ['Fixture `in_progress` is missing from its diagram.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]"))
        );
    }

    public function testGuardRejectsAnExtraNode(): void
    {
        self::assertSame(
            ['Fixture node `ghost` is not in the derived closed list.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]\nghost[Geist]"))
        );
    }

    public function testGuardRejectsAWrongLabel(): void
    {
        self::assertSame(
            ['Fixture node `in_progress` reads `Arbeit` instead of the portal label `In Arbeit`.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[Arbeit]"))
        );
    }

    public function testGuardRejectsADuplicateDeclaration(): void
    {
        self::assertSame(
            ['Fixture node `created` is declared twice.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\ncreated[Angelegt]\nin_progress[In Arbeit]"))
        );
    }

    public function testGuardRejectsAnUndeclaredEdgeNode(): void
    {
        self::assertSame(
            ['Fixture edge uses undeclared node `ghost`.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]\ncreated -->|weiter| ghost"))
        );
    }

    public function testGuardRejectsAForeignClassDef(): void
    {
        self::assertSame(
            ['Fixture defines a classDef outside the flows.md legend: classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]\nclassDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;"))
        );
    }

    public function testGuardRejectsAForeignClassName(): void
    {
        self::assertSame(
            ['Fixture uses class `blocked` outside the flows.md legend.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]\nclass created blocked;"))
        );
    }

    public function testGuardRejectsAClassLineThatNamesAnUndeclaredNode(): void
    {
        self::assertSame(
            ['Fixture class line uses undeclared node `ghost`.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]\nclass ghost step;"))
        );
    }

    public function testGuardRejectsADiagramOverTheNodeLimit(): void
    {
        $values = [];
        $labels = [];
        $lines = [];
        for ($i = 1; $i <= 13; $i++) {
            $values[] = 'node' . $i;
            $labels['node' . $i] = 'Knoten ' . $i;
            $lines[] = 'node' . $i . '[Knoten ' . $i . ']';
        }

        self::assertSame(
            ['Fixture has 13 nodes, the limit in flows.md is 12.'],
            self::fixtureProblems(self::fixtureBlock(implode("\n", $lines)), $values, $labels)
        );
    }

    public function testGuardRejectsZeroValues(): void
    {
        self::assertSame(
            ['Fixture has no values derived from code (zero-match must not pass).'],
            self::fixtureProblems(self::fixtureBlock(''), [], [])
        );
    }

    public function testGuardRejectsASectionWithoutMermaidBlock(): void
    {
        self::assertSame(
            ['Fixture must contain exactly one mermaid block.'],
            self::fixtureProblems("Nur Text, kein Diagramm.\n")
        );
    }

    public function testGuardRejectsTwoMermaidBlocks(): void
    {
        self::assertSame(
            ['Fixture must contain exactly one mermaid block.'],
            self::fixtureProblems(self::fixtureBlock("created[Angelegt]\nin_progress[In Arbeit]") . self::fixtureBlock("created[Angelegt]"))
        );
    }

    public function testStageTableRejectsAMissingRow(): void
    {
        self::assertSame(
            ['Stage table has no row for `2/5 Registered`.'],
            self::statusTableProblems(['1/5 Initializing', '2/5 Registered'], "| `1/5 Initializing` | Vorbereitung |\n")
        );
    }

    public function testStageTableRejectsAnExtraRow(): void
    {
        self::assertSame(
            ['Stage table row `9/5 Ghost` is not in the derived stage list.'],
            self::statusTableProblems(['1/5 Initializing'], "| `1/5 Initializing` | Vorbereitung |\n| `9/5 Ghost` | x |\n")
        );
    }

    public function testStageTableRejectsADuplicateRow(): void
    {
        self::assertSame(
            ['Stage table has 2 rows for `1/5 Initializing`, expected exactly one.'],
            self::statusTableProblems(['1/5 Initializing'], "| `1/5 Initializing` | a |\n| `1/5 Initializing` | b |\n")
        );
    }

    public function testStageTableZeroMatchesDoNotPass(): void
    {
        self::assertSame(
            ['Stage table has no values derived from code (zero-match must not pass).'],
            self::statusTableProblems([], '')
        );
        self::assertSame(
            ['Stage table has no row for `1/5 Initializing`.'],
            self::statusTableProblems(['1/5 Initializing'], "Keine Tabelle.\n")
        );
    }

    /**
     * @param list<string> $values the closed list derived from code
     * @param array<string,string> $labels token => German portal label (may miss entries)
     * @param list<string> $legendClassDefs the classDef lines of the flows.md legend
     * @return list<string>
     */
    public static function diagramProblems(string $label, array $values, array $labels, string $section, int $nodeLimit, array $legendClassDefs): array
    {
        $problems = [];
        if ($values === []) {
            $problems[] = $label . ' has no values derived from code (zero-match must not pass).';
        }
        $blocks = self::mermaidBlocks($section);
        if (count($blocks) !== 1) {
            $problems[] = $label . ' must contain exactly one mermaid block.';

            return $problems;
        }
        $body = $blocks[0];

        // token => every label written for it, in source order
        $declared = [];
        preg_match_all(self::NODE_PATTERN, $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $declared[$match[1]][] = trim($match[2]);
        }
        foreach ($declared as $token => $written) {
            if (count($written) > 1) {
                $problems[] = sprintf('%s node `%s` is declared twice.', $label, $token);
            }
            if (!in_array((string) $token, $values, true)) {
                $problems[] = sprintf('%s node `%s` is not in the derived closed list.', $label, $token);
            }
        }
        foreach ($values as $value) {
            if (!isset($declared[$value])) {
                $problems[] = sprintf('%s `%s` is missing from its diagram.', $label, $value);
            }
            if (!isset($labels[$value])) {
                $problems[] = sprintf('%s `%s` has no German portal label.', $label, $value);
                continue;
            }
            foreach ($declared[$value] ?? [] as $written) {
                if ($written !== $labels[$value]) {
                    $problems[] = sprintf('%s node `%s` reads `%s` instead of the portal label `%s`.', $label, $value, $written, $labels[$value]);
                }
            }
        }

        $distinct = count($declared);
        if ($distinct > $nodeLimit) {
            $problems[] = sprintf('%s has %d nodes, the limit in flows.md is %d.', $label, $distinct, $nodeLimit);
        }

        $lines = preg_split('/\R/', $body) ?: [];
        $undeclaredEdge = [];
        foreach ($lines as $line) {
            if (preg_match('/-->|-\.->|==>/', $line) !== 1) {
                continue;
            }
            $bare = (string) preg_replace(['/\|[^|\n]*\|/', '/\[[^\]\n]*\]/'], ' ', $line);
            preg_match_all('/[a-z][a-z0-9_]*/', $bare, $ids);
            foreach ($ids[0] as $id) {
                if (!isset($declared[$id])) {
                    $undeclaredEdge[$id] = true;
                }
            }
        }
        foreach (array_keys($undeclaredEdge) as $id) {
            $problems[] = sprintf('%s edge uses undeclared node `%s`.', $label, $id);
        }

        $legend = array_map('trim', $legendClassDefs);
        $legendClasses = [];
        foreach ($legend as $definition) {
            if (preg_match('/^classDef\s+([A-Za-z0-9_]+)/', $definition, $name) === 1) {
                $legendClasses[] = $name[1];
            }
        }
        $undeclaredClassNode = [];
        $foreignClass = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'classDef ') && !in_array($trimmed, $legend, true)) {
                $problems[] = sprintf('%s defines a classDef outside the flows.md legend: %s', $label, $trimmed);
            }
            if (preg_match('/^\s*class\s+([a-z0-9_,]+)\s+([A-Za-z0-9_]+);?\s*$/', $line, $match) !== 1) {
                continue;
            }
            foreach (explode(',', $match[1]) as $id) {
                if ($id !== '' && !isset($declared[$id])) {
                    $undeclaredClassNode[$id] = true;
                }
            }
            if (!in_array($match[2], $legendClasses, true)) {
                $foreignClass[$match[2]] = true;
            }
        }
        foreach (array_keys($undeclaredClassNode) as $id) {
            $problems[] = sprintf('%s class line uses undeclared node `%s`.', $label, $id);
        }
        foreach (array_keys($foreignClass) as $class) {
            $problems[] = sprintf('%s uses class `%s` outside the flows.md legend.', $label, $class);
        }

        return $problems;
    }

    /**
     * The x/5 table: one row per stage value, no other stage row. Only rows whose
     * first backticked cell starts with "n/5 " count as stage rows.
     *
     * @param list<string> $values VIRTUSPHERE_VM_STATUS_COMPAT
     * @return list<string>
     */
    public static function statusTableProblems(array $values, string $section): array
    {
        $problems = [];
        if ($values === []) {
            $problems[] = 'Stage table has no values derived from code (zero-match must not pass).';
        }
        preg_match_all('/^\| `([^`]+)` \|/m', $section, $matches);
        $rows = array_values(array_filter($matches[1], static fn (string $cell): bool => preg_match('/^\d\/5 /', $cell) === 1));

        foreach ($values as $value) {
            $count = count(array_keys($rows, $value, true));
            if ($count === 0) {
                $problems[] = sprintf('Stage table has no row for `%s`.', $value);
            } elseif ($count > 1) {
                $problems[] = sprintf('Stage table has %d rows for `%s`, expected exactly one.', $count, $value);
            }
        }
        foreach (array_unique($rows) as $row) {
            if (!in_array($row, $values, true)) {
                $problems[] = sprintf('Stage table row `%s` is not in the derived stage list.', $row);
            }
        }

        return $problems;
    }

    private static function loadDoc(string $relative): string
    {
        $root = dirname(__DIR__, 4);
        if (!is_dir($root . '/docs')) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        $path = $root . '/' . $relative;
        self::assertFileExists($path, $relative . ' is missing from the repository.');

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    private static function section(string $doc, string $title): string
    {
        $body = self::sectionBody($doc, $title);
        self::assertNotSame('', trim($body), 'vm-lifecycle.md has no "## ' . $title . '" section with content.');

        return $body;
    }

    private static function sectionBody(string $doc, string $heading): string
    {
        $parts = preg_split('/^## /m', $doc) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            $newline = strpos($part, "\n");
            $title = trim($newline === false ? $part : substr($part, 0, $newline));
            if ($title === $heading) {
                return $newline === false ? '' : substr($part, $newline + 1);
            }
        }

        return '';
    }

    /** @return list<string> the body of every mermaid fence of the text */
    private static function mermaidBlocks(string $text): array
    {
        preg_match_all('/```mermaid\s*\R(.*?)```/s', $text, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $values
     * @return array<string,string> token => German label of `<prefix><token>` in lang/de/status.php
     */
    private static function portalLabels(array $values, string $prefix): array
    {
        $de = require dirname(__DIR__, 2) . '/lang/de/status.php';
        $labels = [];
        foreach ($values as $value) {
            if (isset($de[$prefix . $value])) {
                $labels[$value] = (string) $de[$prefix . $value];
            }
        }

        return $labels;
    }

    private static function nodeLimit(string $flows): int
    {
        self::assertSame(1, preg_match('/flow-node-limit:\s*(\d+)/', $flows, $match), 'flows.md has no flow-node-limit comment.');

        return (int) $match[1];
    }

    /** @return list<string> */
    private static function legend(string $flows): array
    {
        preg_match_all('/^classDef [^\r\n]+/m', $flows, $matches);
        self::assertNotSame([], $matches[0], 'flows.md has no classDef legend.');

        return $matches[0];
    }

    private static function fixtureBlock(string $body): string
    {
        return "```mermaid\nflowchart TD\n" . $body . "\n```\n";
    }

    /**
     * @param list<string> $values
     * @param array<string,string> $labels
     * @return list<string>
     */
    private static function fixtureProblems(
        string $section,
        array $values = self::FIXTURE_VALUES,
        array $labels = self::FIXTURE_LABELS,
        int $limit = self::FIXTURE_NODE_LIMIT
    ): array {
        return self::diagramProblems('Fixture', $values, $labels, $section, $limit, self::FIXTURE_LEGEND);
    }
}
