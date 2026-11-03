<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';

/**
 * The shared checks of the FC4 flow guards prove their own negatives here, so a
 * guard that relies on them cannot pass by matching nothing.
 */
final class OperationsFlowDocTest extends TestCase
{
    private const LEGEND = [
        'classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;',
        'classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;',
    ];

    public function testSectionReturnsTheBodyOfTheExactHeading(): void
    {
        $doc = "# T\n\n## A\none\n### A.1\ntwo\n## AB\nthree\n";
        self::assertSame("one\n### A.1\ntwo\n", OperationsFlowDoc::section($doc, 'A'));
        self::assertSame("three\n", OperationsFlowDoc::section($doc, 'AB'));
        self::assertSame('', OperationsFlowDoc::section($doc, 'B'));
    }

    public function testTableRowsSkipHeaderAndSeparatorOfEveryTable(): void
    {
        $text = "| H1 | H2 |\n|---|---|\n| a | `b` |\n\ntext\n| X |\n|:-:|\n| c |\n";
        self::assertSame([['a', '`b`'], ['c']], OperationsFlowDoc::tableRows($text));
        self::assertSame([], OperationsFlowDoc::tableRows('no table'));
        self::assertSame(['b', 'c'], OperationsFlowDoc::ticks('`b` and `c`'));
    }

    public function testNodesReadEveryDeclarationForm(): void
    {
        self::assertSame(
            ['a' => ['A'], 'b' => ['B b'], 'c' => ['C?'], 'd' => ['D']],
            OperationsFlowDoc::nodes("flowchart TD\n    a[\"A\"] --> b[B b]\n    b --> c{\"C?\"}\n    c -->|ja| d{D}\n")
        );
    }

    public function testLegendAndLimitComeFromTheIndex(): void
    {
        $flows = "<!-- flow-node-limit: 12 -->\n```mermaid\nflowchart TD\n" . implode("\n", self::LEGEND) . "\n```\n";
        self::assertSame(12, OperationsFlowDoc::nodeLimit($flows));
        self::assertNull(OperationsFlowDoc::nodeLimit('none'));
        self::assertSame(self::LEGEND, OperationsFlowDoc::legend($flows));
    }

    public function testFlowchartProblemsAcceptALegalChart(): void
    {
        $chart = "flowchart TD\n    a[\"A\"] -->|ja| b{\"B\"}\n    b -.-> c[\"C\"]\n    class a,c step;\n    class b blocked;\n";
        self::assertSame([], OperationsFlowDoc::flowchartProblems('C', $chart, 3, self::LEGEND));
    }

    public function testFlowchartProblemsRejectEveryBrokenRule(): void
    {
        self::assertSame(['C is not a flowchart.'], OperationsFlowDoc::flowchartProblems('C', "graph TD\n    a[\"A\"]\n", 3, self::LEGEND));
        self::assertSame(['C declares no node (zero-match must not pass).'], OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n", 3, self::LEGEND));
        self::assertSame(['C has 3 nodes, the limit in flows.md is 2.'], OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n    a[\"A\"] --> b[\"B\"] --> c[\"C\"]\n", 2, self::LEGEND));
        self::assertSame(
            ['C defines a classDef outside the flows.md legend: classDef own fill:#000;'],
            OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n    a[\"A\"]\n    classDef own fill:#000;\n", 3, self::LEGEND)
        );
        self::assertSame(['C uses class `own` outside the flows.md legend.'], OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n    a[\"A\"]\n    class a own;\n", 3, self::LEGEND));
        self::assertSame(['C uses undeclared node `ghost`.'], OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n    a[\"A\"]\n    class a,ghost step;\n", 3, self::LEGEND));
        self::assertSame(['C uses undeclared node `ghost`.'], OperationsFlowDoc::flowchartProblems('C', "flowchart TD\n    a[\"A\"] -->|text in label| ghost\n", 3, self::LEGEND));
    }

    public function testSectionChartProblemsCountTheCharts(): void
    {
        $chart = "```mermaid\nflowchart TD\n    a[\"A\"]\n```\n";
        self::assertSame([], OperationsFlowDoc::sectionChartProblems('S', $chart, 1, 3, self::LEGEND));
        self::assertSame(['S must contain 1 mermaid block(s), found 0.'], OperationsFlowDoc::sectionChartProblems('S', 'prose only', 1, 3, self::LEGEND));
        self::assertSame(['S must contain 1 mermaid block(s), found 2.'], OperationsFlowDoc::sectionChartProblems('S', $chart . $chart, 1, 3, self::LEGEND));
    }

    public function testMissingTokensMatchWholeTokensOnly(): void
    {
        self::assertSame([], OperationsFlowDoc::missingTokens('V', ['failed'], 'ends failed.'));
        self::assertSame(['V `fail` is missing.'], OperationsFlowDoc::missingTokens('V', ['fail'], 'ends failed and fail_over and pre-fail.'));
        self::assertSame(['V has no values derived from code (zero-match must not pass).'], OperationsFlowDoc::missingTokens('V', [], 'x'));
    }
}
