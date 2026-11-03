<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_input.php';

/**
 * docs/operations/deploy-flows.md draws the job states, the supervision of
 * orphaned jobs and the retry. Nothing is listed here: the states come from the
 * active and terminal sets (checked against every VIRTUSPHERE_DEPLOY_STATUS_*
 * constant), each node's text from `job_<status>` in lang/de/status.php, and the
 * terminal-reason rows with their status from VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES.
 * The vocabulary inside the worker and create charts stays with
 * DeployFlowsDocContractTest.
 */
final class DeployJobStatesDocContractTest extends TestCase
{
    private const DOC = 'docs/operations/deploy-flows.md';
    private const INDEX = 'docs/operations/flows.md';
    private const STATES_SECTION = 'Auftragszustände';
    private const SUPERVISION_SECTION = 'Aufsicht: verwaiste Aufträge';
    private const RETRY_SECTION = 'Wiederholen und Freigeben';

    public function testTheStateSetsCoverEveryStatusConstant(): void
    {
        $declared = [];
        foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
            if (str_starts_with((string) $name, 'VIRTUSPHERE_DEPLOY_STATUS_')) {
                $declared[] = (string) $value;
            }
        }
        sort($declared);
        $sets = self::statuses();
        sort($sets);

        self::assertNotSame([], $declared);
        self::assertSame($declared, $sets, 'every job status must be active or terminal, and only once');
    }

    public function testStateChartDrawsEveryStatusWithItsPortalLabel(): void
    {
        $flows = self::flows();
        $section = OperationsFlowDoc::section(self::doc(), self::STATES_SECTION);
        $blocks = OperationsFlowDoc::mermaidBlocks($section);
        self::assertCount(1, $blocks, 'the state section must contain exactly one mermaid block');

        self::assertSame([], array_merge(
            OperationsFlowDoc::flowchartProblems('State chart', $blocks[0], (int) OperationsFlowDoc::nodeLimit($flows), OperationsFlowDoc::legend($flows)),
            self::stateNodeProblems(self::statuses(), self::labels(self::statuses()), $blocks[0])
        ));
    }

    public function testTerminalReasonTableNamesEveryReasonWithItsStatus(): void
    {
        self::assertSame([], self::reasonTableProblems(
            VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES,
            OperationsFlowDoc::section(self::doc(), self::STATES_SECTION)
        ));
    }

    public function testSupervisionAndRetryChartsFollowTheLegend(): void
    {
        $flows = self::flows();
        $doc = self::doc();
        $limit = (int) OperationsFlowDoc::nodeLimit($flows);
        $legend = OperationsFlowDoc::legend($flows);

        self::assertSame([], array_merge(
            OperationsFlowDoc::sectionChartProblems('Supervision chart', OperationsFlowDoc::section($doc, self::SUPERVISION_SECTION), 2, $limit, $legend),
            OperationsFlowDoc::sectionChartProblems('Retry chart', OperationsFlowDoc::section($doc, self::RETRY_SECTION), 1, $limit, $legend)
        ));
    }

    public function testRetryChartNamesEveryRetryableStatus(): void
    {
        $retryable = array_values(array_filter(
            VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES,
            static fn (string $status): bool => deploy_job_is_retryable($status, 1)
        ));
        $chart = implode("\n", OperationsFlowDoc::mermaidBlocks(OperationsFlowDoc::section(self::doc(), self::RETRY_SECTION)));

        self::assertSame([], OperationsFlowDoc::missingTokens('Retryable status', $retryable, $chart));
    }

    public function testStateNodesRejectMissingExtraAndWrongNodes(): void
    {
        $values = ['queued', 'running'];
        $labels = ['queued' => 'In Warteschlange', 'running' => 'Läuft'];
        $ok = "flowchart TD\n    queued[\"In Warteschlange\"] --> running[\"Läuft\"]\n";
        self::assertSame([], self::stateNodeProblems($values, $labels, $ok));

        self::assertSame(['State `running` has no node.'], self::stateNodeProblems($values, $labels, "flowchart TD\n    queued[\"In Warteschlange\"]\n"));
        self::assertSame(['Node `ghost` is not a job status.'], self::stateNodeProblems($values, $labels, $ok . "    ghost[\"Geist\"]\n"));
        self::assertSame(['Node `running` reads `Laeuft` instead of `Läuft`.'], self::stateNodeProblems($values, $labels, str_replace('"Läuft"', '"Laeuft"', $ok)));
        self::assertSame(['State `running` has no German portal label.'], self::stateNodeProblems($values, ['queued' => 'In Warteschlange'], $ok));
        self::assertSame(['Node `queued` is declared twice.'], self::stateNodeProblems($values, $labels, $ok . "    queued[\"In Warteschlange\"]\n"));
        self::assertSame(['The state chart has no states derived from code (zero-match must not pass).'], self::stateNodeProblems([], [], $ok));
    }

    public function testReasonTableRejectsMissingExtraDuplicateAndWrongStatus(): void
    {
        $map = ['completed' => ['succeeded'], 'timeout' => ['failed']];
        $ok = "| Abschlussgrund | Status | Geschrieben von |\n|---|---|---|\n| `completed` | `succeeded` | a |\n| `timeout` | `failed` | b |\n";
        self::assertSame([], self::reasonTableProblems($map, $ok));

        self::assertSame(['Terminal reason `timeout` has no row.'], self::reasonTableProblems($map, str_replace("| `timeout` | `failed` | b |\n", '', $ok)));
        self::assertSame(['Terminal reason row `ghost` is not in the code vocabulary.'], self::reasonTableProblems($map, $ok . "| `ghost` | `failed` | c |\n"));
        self::assertSame(['Terminal reason `completed` has 2 rows.'], self::reasonTableProblems($map, $ok . "| `completed` | `succeeded` | a |\n"));
        self::assertSame(['Terminal reason `timeout` names status `cancelled` instead of `failed`.'], self::reasonTableProblems($map, str_replace('| `timeout` | `failed` |', '| `timeout` | `cancelled` |', $ok)));
        self::assertSame(['The terminal reason table has no reasons derived from code (zero-match must not pass).'], self::reasonTableProblems([], $ok));
    }

    /**
     * @param list<string> $values
     * @param array<string,string> $labels
     * @return list<string>
     */
    public static function stateNodeProblems(array $values, array $labels, string $chart): array
    {
        if ($values === []) {
            return ['The state chart has no states derived from code (zero-match must not pass).'];
        }
        $problems = [];
        $nodes = OperationsFlowDoc::nodes($chart);
        foreach ($nodes as $id => $texts) {
            if (!in_array((string) $id, $values, true)) {
                $problems[] = sprintf('Node `%s` is not a job status.', $id);
            }
            if (count($texts) > 1) {
                $problems[] = sprintf('Node `%s` is declared twice.', $id);
            }
        }
        foreach ($values as $value) {
            if (!isset($nodes[$value])) {
                $problems[] = sprintf('State `%s` has no node.', $value);
                continue;
            }
            if (!isset($labels[$value])) {
                $problems[] = sprintf('State `%s` has no German portal label.', $value);
                continue;
            }
            if ($nodes[$value][0] !== $labels[$value]) {
                $problems[] = sprintf('Node `%s` reads `%s` instead of `%s`.', $value, $nodes[$value][0], $labels[$value]);
            }
        }

        return $problems;
    }

    /**
     * @param array<string,list<string>> $map reason => the statuses it may end in
     * @return list<string>
     */
    public static function reasonTableProblems(array $map, string $section): array
    {
        if ($map === []) {
            return ['The terminal reason table has no reasons derived from code (zero-match must not pass).'];
        }
        $problems = [];
        $seen = [];
        foreach (OperationsFlowDoc::tableRows($section) as $cells) {
            $reason = OperationsFlowDoc::ticks($cells[0] ?? '')[0] ?? '';
            if ($reason === '') {
                continue;
            }
            $seen[$reason] = ($seen[$reason] ?? 0) + 1;
            if (!isset($map[$reason])) {
                $problems[] = sprintf('Terminal reason row `%s` is not in the code vocabulary.', $reason);
                continue;
            }
            $named = OperationsFlowDoc::ticks($cells[1] ?? '');
            if ($named !== $map[$reason]) {
                $problems[] = sprintf('Terminal reason `%s` names status `%s` instead of `%s`.', $reason, implode('`, `', $named), implode('`, `', $map[$reason]));
            }
        }
        foreach (array_keys($map) as $reason) {
            $count = $seen[$reason] ?? 0;
            if ($count === 0) {
                $problems[] = sprintf('Terminal reason `%s` has no row.', $reason);
            } elseif ($count > 1) {
                $problems[] = sprintf('Terminal reason `%s` has %d rows.', $reason, $count);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES);
    }

    /**
     * @param list<string> $statuses
     * @return array<string,string>
     */
    private static function labels(array $statuses): array
    {
        $de = require dirname(__DIR__, 2) . '/lang/de/status.php';
        $labels = [];
        foreach ($statuses as $status) {
            if (isset($de['job_' . $status])) {
                $labels[$status] = (string) $de['job_' . $status];
            }
        }

        return $labels;
    }

    private static function doc(): string
    {
        $doc = OperationsFlowDoc::load(self::DOC);
        if ($doc === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        self::assertNotSame('', $doc, self::DOC . ' is missing.');

        return $doc;
    }

    private static function flows(): string
    {
        $flows = OperationsFlowDoc::load(self::INDEX);
        if ($flows === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        self::assertNotNull(OperationsFlowDoc::nodeLimit($flows), 'flows.md has no flow-node-limit comment.');

        return $flows;
    }
}
