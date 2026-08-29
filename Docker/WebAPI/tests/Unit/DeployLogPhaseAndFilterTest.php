<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_phases.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_filter.php';

/**
 * The phase timeline and the log filter: two pure readers, no database.
 *
 * Both exist because a job log is long. The timeline says what the run DID, the
 * filter says what a reader asked for, and the interesting cases are the ones
 * where those two answers must not be confused with each other.
 */
final class DeployLogPhaseAndFilterTest extends TestCase
{
    /** @return list<array{seq:int,line:string}> */
    private function markers(array $pairs): array
    {
        $rows = [];
        $seq = 0;
        foreach ($pairs as [$event, $playbook]) {
            $rows[] = ['seq' => ++$seq, 'line' => ansible_step_marker_line($event, $playbook)];
        }

        return $rows;
    }

    public function testACompletedSequenceHasNoCurrentPhase(): void
    {
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'exportVMs-Informations-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'exportVMs-Informations-ESXi_playbook.yml'],
        ]));

        self::assertNull($timeline['current']);
        self::assertCount(2, $timeline['phases']);
        self::assertSame([1, 2], [$timeline['phases'][0]['begin_seq'], $timeline['phases'][0]['end_seq']]);
        self::assertTrue($timeline['phases'][0]['complete']);
        self::assertTrue($timeline['phases'][1]['complete']);
    }

    public function testAnOpenPhaseIsTheCurrentOneAndHasNoUpperBound(): void
    {
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'startVMs-ESXi_playbook.yml'],
        ]));

        self::assertSame('startVMs-ESXi_playbook.yml', $timeline['current']);
        self::assertFalse($timeline['phases'][1]['complete']);
        self::assertNull($timeline['phases'][1]['end_seq']);
        // An open phase filters from its start to the end of the log, which is
        // what a reader wants while it is still producing lines.
        self::assertSame([3, null], deploy_log_phase_range($timeline, 'startVMs-ESXi_playbook.yml'));
    }

    public function testAMissingEndMarkerLeavesThePhaseIncompleteRatherThanNesting(): void
    {
        // The failure this models is real: a playbook that dies writes its begin
        // marker and never its end. The next begin must not nest under it, and
        // the dead step must not be reported as finished.
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'startVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'startVMs-ESXi_playbook.yml'],
        ]));

        self::assertCount(2, $timeline['phases']);
        self::assertFalse($timeline['phases'][0]['complete']);
        self::assertNull($timeline['phases'][0]['end_seq']);
        self::assertTrue($timeline['phases'][1]['complete']);
        self::assertNull($timeline['current']);
    }

    public function testAnEndForAnotherPlaybookClosesNothing(): void
    {
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'somethingElse.yml'],
        ]));

        self::assertSame('createVMs-ESXi_playbook.yml', $timeline['current']);
        self::assertFalse($timeline['phases'][0]['complete']);
    }

    public function testOrdinaryOutputIsNotMistakenForAMarker(): void
    {
        $timeline = deploy_log_phase_timeline([
            ['seq' => 1, 'line' => 'TASK [Create VM] *******'],
            ['seq' => 2, 'line' => '::virtusphere-step:: begin'],
            ['seq' => 3, 'line' => '::virtusphere-step:: sideways createVMs.yml'],
            ['seq' => 4, 'line' => ''],
        ]);

        self::assertSame([], $timeline['phases']);
        self::assertNull($timeline['current']);
    }

    public function testPhaseNamesKeepRunOrderAndDropDuplicates(): void
    {
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'powercycleVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'powercycleVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'exportVMs-Informations-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'exportVMs-Informations-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'powercycleVMs-ESXi_playbook.yml'],
        ]));

        self::assertSame(
            ['powercycleVMs-ESXi_playbook.yml', 'exportVMs-Informations-ESXi_playbook.yml'],
            deploy_log_phase_names($timeline)
        );
    }

    public function testAHandEditedFilterNarrowsInsteadOfAnsweringSomethingElse(): void
    {
        $phases = ['createVMs-ESXi_playbook.yml'];

        $unknownSource = deploy_log_filter_from_query(['source' => 'stdout'], $phases);
        // `stdout` is a STORED value, not an offered one: it folds into the
        // Ansible option, so naming it in the URL selects nothing rather than
        // silently answering half the question.
        self::assertSame('', $unknownSource['source']);
        self::assertFalse($unknownSource['active']);

        $unknownPhase = deploy_log_filter_from_query(['phase' => 'neverRan.yml'], $phases);
        self::assertSame('', $unknownPhase['phase']);
        self::assertFalse($unknownPhase['active']);
    }

    public function testTheSearchTermIsBoundedAndTrimmed(): void
    {
        $long = str_repeat('a', VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH + 50);
        $filter = deploy_log_filter_from_query(['q' => '  ' . $long . '  '], []);

        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SEARCH_MAX_LENGTH, mb_strlen($filter['q'], 'UTF-8'));
        self::assertTrue($filter['active']);
        self::assertFalse(deploy_log_filter_from_query(['q' => '   '], [])['active']);
    }

    public function testTheAnsibleSourceCoversItsTwoLegacyValues(): void
    {
        // stdout/stderr were never two channels (the remote command redirects
        // with 2>&1), so the display source has to cover both stored values or
        // a filter on Ansible output would hide every older line.
        $sources = deploy_log_filter_sources();
        self::assertSame(
            [VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, VIRTUSPHERE_DEPLOY_LOG_STDOUT, VIRTUSPHERE_DEPLOY_LOG_STDERR],
            $sources[VIRTUSPHERE_DEPLOY_LOG_ANSIBLE]
        );
        self::assertArrayNotHasKey(VIRTUSPHERE_DEPLOY_LOG_STDOUT, $sources);
        self::assertArrayNotHasKey(VIRTUSPHERE_DEPLOY_LOG_STDERR, $sources);
    }

    public function testRepoArgumentsAreOneDerivationOfTheFilter(): void
    {
        $timeline = deploy_log_phase_timeline($this->markers([
            [VIRTUSPHERE_ANSIBLE_STEP_BEGIN, 'createVMs-ESXi_playbook.yml'],
            [VIRTUSPHERE_ANSIBLE_STEP_END, 'createVMs-ESXi_playbook.yml'],
        ]));
        $filter = deploy_log_filter_from_query(
            ['q' => 'fatal', 'source' => VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'phase' => 'createVMs-ESXi_playbook.yml'],
            deploy_log_phase_names($timeline)
        );

        self::assertSame(
            [
                'needle' => 'fatal',
                'streams' => [VIRTUSPHERE_DEPLOY_LOG_SYSTEM],
                'from_seq' => 1,
                'to_seq' => 2,
            ],
            deploy_log_filter_repo_args($filter, $timeline)
        );
    }

    public function testTheFilterUrlCarriesOnlyWhatWasAsked(): void
    {
        $empty = deploy_log_filter_from_query([], []);
        self::assertSame('deploy_log.php?id=42', deploy_log_filter_url(42, $empty));

        $filter = deploy_log_filter_from_query(['q' => 'no route to host'], []);
        self::assertSame('deploy_log.php?id=42&q=no+route+to+host', deploy_log_filter_url(42, $filter));
    }
}
