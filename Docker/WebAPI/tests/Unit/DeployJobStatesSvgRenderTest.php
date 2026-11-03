<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';

/**
 * The job-state figure of the help page (lib/help_deploy_job_states.php), the
 * portal twin of the Mermaid chart in docs/operations/deploy-flows.md
 * („Auftragszustände“, the subject source). Nothing is listed here: the nodes
 * are the active and terminal status sets, each worded by
 * deploy_job_status_label() and toned by deploy_job_status_badge_class(), the
 * cancel hint marks exactly the cancellable set, and the drawn transitions are
 * compared with the edges of the documented chart in both directions.
 *
 * The partial is required inside the helper, after its existence is asserted:
 * a missing file must fail these tests by name, not abort the unit run.
 */
final class DeployJobStatesSvgRenderTest extends TestCase
{
    private const PARTIAL = 'lib/help_deploy_job_states.php';
    private const DOC = 'docs/operations/deploy-flows.md';
    private const STATES_SECTION = 'Auftragszustände';
    private const NODE_PATTERN = '/<g class="help-fig-node help-fig-node-(active|terminal) help-fig-tone-([a-z]+)">(.*?)<\/g>/s';
    private const EDGE_PATTERN = '/^\s*([a-z_]+)(?:\[[^\]\n]*\])?\s*-->\s*(?:\|[^|\n]*\|\s*)?([a-z_]+)/m';

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /** @return list<array{0:string}> */
    public static function locales(): array
    {
        return [['de'], ['en']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureAndSvgCarryTheAccessibleStructure(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringContainsString('<figure class="help-fig">', $html);
        self::assertStringContainsString('<svg class="help-fig-svg"', $html);
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-labelledby="job-states-title job-states-desc"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertSame(1, preg_match('/<title id="job-states-title">[^<]+<\/title>/', $html), 'the svg needs a non-empty title');
        self::assertSame(1, preg_match('/<desc id="job-states-desc">[^<]+<\/desc>/', $html), 'the svg needs a non-empty desc');
        self::assertStringContainsString('<figcaption', $html);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryStatusIsOneNodeInItsGroupWithLabelToneAndCancelHint(string $locale): void
    {
        $html = self::figure($locale);
        $hint = h(__t('help_deploy_figures.state_cancellable'));
        self::assertNotSame(h('help_deploy_figures.state_cancellable'), $hint, 'the cancel hint is missing from the help catalog');

        preg_match_all(self::NODE_PATTERN, $html, $nodes, PREG_SET_ORDER);
        $expected = array_merge(
            array_map(static fn (string $status): array => [$status, 'active'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES),
            array_map(static fn (string $status): array => [$status, 'terminal'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES)
        );
        self::assertNotSame([], $expected, 'no job status (zero-match must not pass)');
        self::assertCount(count($expected), $nodes, 'one node per job status');

        foreach ($expected as $index => [$status, $group]) {
            [, $drawnGroup, $tone, $body] = $nodes[$index];
            self::assertSame($group, $drawnGroup, $status . ' is drawn in the wrong group');
            self::assertSame(deploy_job_status_badge_class($status), $tone, $status . ' is drawn in another tone than its badge');
            self::assertStringContainsString('>' . h(deploy_job_status_label($status)) . '</text>', $body, $status . ' node is missing its label or is out of status order');
            self::assertSame(
                in_array($status, VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true),
                str_contains($body, '>' . $hint . '</text>'),
                $status . ' must carry the cancel hint exactly when it is cancellable'
            );
        }
    }

    public function testDrawnTransitionsAreTheEdgesOfTheDocumentedStateChart(): void
    {
        $doc = OperationsFlowDoc::load(self::DOC);
        if ($doc === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        $blocks = OperationsFlowDoc::mermaidBlocks(OperationsFlowDoc::section($doc, self::STATES_SECTION));
        self::assertCount(1, $blocks, 'the state section must contain exactly one mermaid block');
        preg_match_all(self::EDGE_PATTERN, $blocks[0], $edges, PREG_SET_ORDER);
        $documented = array_map(static fn (array $edge): string => $edge[1] . '>' . $edge[2], $edges);
        self::assertNotSame([], $documented, 'the documented chart has no edges (zero-match must not pass)');

        self::figure('de');
        $drawn = array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['to'], help_deploy_job_state_transitions());
        sort($documented);
        sort($drawn);
        self::assertSame($documented, $drawn, 'the help figure and docs/operations/deploy-flows.md must draw the same transitions');
    }

    public function testTransitionsJoinKnownStatusesAndNeverLeaveATerminalState(): void
    {
        self::figure('de');
        $statuses = array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES);
        $reached = [];
        foreach (help_deploy_job_state_transitions() as ['from' => $from, 'to' => $to]) {
            self::assertContains($from, $statuses, $from . ' is not a job status');
            self::assertContains($to, $statuses, $to . ' is not a job status');
            self::assertNotContains($from, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, 'a terminal state has no way out: ' . $from . ' to ' . $to);
            $reached[$to] = true;
        }
        foreach ($statuses as $status) {
            if ($status === VIRTUSPHERE_DEPLOY_STATUS_QUEUED) {
                continue;
            }
            self::assertArrayHasKey($status, $reached, $status . ' is never reached');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryTransitionIsOneDrawnEdgeAndOneSentenceOfTheDescription(string $locale): void
    {
        $html = self::figure($locale);
        $transitions = help_deploy_job_state_transitions();

        self::assertSame(count($transitions), substr_count($html, 'class="help-fig-edge"'), 'one drawn edge per transition');
        self::assertSame(1, preg_match('/<desc id="job-states-desc">(.*?)<\/desc>/s', $html, $match), 'the svg has no desc');
        foreach ($transitions as ['from' => $from, 'to' => $to]) {
            $sentence = h(__t('help_deploy_figures.job_states_transition', [
                'from' => deploy_job_status_label($from),
                'to' => deploy_job_status_label($to),
            ]));
            self::assertStringContainsString($sentence, $match[1], $from . ' to ' . $to . ' is missing from the desc');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureHasNoScriptStyleHandlerLinkDashOrRawToken(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString("\u{2014}", $html);
        self::assertStringNotContainsString("\u{2013}", $html);
        foreach (array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES) as $status) {
            self::assertStringNotContainsString('>' . $status . '<', $html, 'the raw status token ' . $status . ' is shown');
        }
    }

    public function testGermanAndEnglishFiguresDiffer(): void
    {
        $de = self::figure('de');
        $deLabel = deploy_job_status_label(VIRTUSPHERE_DEPLOY_STATUS_QUEUED);
        $en = self::figure('en');
        $enLabel = deploy_job_status_label(VIRTUSPHERE_DEPLOY_STATUS_QUEUED);

        self::assertNotSame($deLabel, $enLabel, 'the queued label is not localised');
        self::assertNotSame($de, $en, 'the German and English figures render the same');
    }

    private static function figure(string $locale): string
    {
        $partial = dirname(__DIR__, 2) . '/' . self::PARTIAL;
        self::assertFileExists($partial, self::PARTIAL . ' (the job-state figure) does not exist yet.');
        require_once $partial;
        Lang::load($locale);

        return help_deploy_job_states_figure();
    }
}
