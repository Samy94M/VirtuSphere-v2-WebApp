<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The correlation id as a diagnostic path, not as three independent features.
 *
 * Three places render the id and they must render it identically, because the
 * point of the id is that a reader carries it from one to the next: the audit
 * table, the CSV export and a deploy job's header. A second rendering with a
 * slightly different shape (linked here, bare there, copyable only in the
 * third) is how an identity turns back into something people retype by hand.
 *
 * The permission boundary is the other rule with teeth. `users.manage` decides
 * that this reader may see the audit rows and therefore the job ids beside
 * them; `deploy.run` decides that they may open a job LOG. Hiding the row from
 * a user-administrator would make a trace look incomplete, and handing them the
 * log would widen what `users.manage` grants, so the two gates are separate and
 * the row is not behind the second one.
 */
final class LogCorrelationContractTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $relative;
        self::assertFileExists($path, $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    public function testEveryReaderRendersTheIdThroughOneHelper(): void
    {
        foreach (['portal/logs.php', 'portal/deploy_log.php'] as $page) {
            self::assertStringContainsString(
                'portal_correlation_id(',
                $this->source($page),
                $page . ' must render the correlation id through the shared presenter'
            );
        }

        $helper = $this->source('lib/correlation_display.php');
        self::assertStringContainsString('data-copy-value=', $helper, 'the id must be copyable');
        self::assertStringContainsString('role="status"', $helper, 'the copy outcome must be announced, not only coloured');
        self::assertStringContainsString('data-copy-failed=', $helper, 'a failed clipboard write is a real branch, not a silent catch');
        self::assertStringContainsString('<code>', $helper, 'the id stays selectable text; the button is an accelerator');
    }

    /**
     * The export carries the column too. The file is what leaves the portal, and
     * an incident report built from it has to point back at the trace it came
     * from; a header without its value (or the other way round) would shift
     * every column of the file by one.
     */
    public function testTheExportCarriesTheIdInBothHeaderAndRow(): void
    {
        $export = $this->source('lib/logs_export.php');

        self::assertStringContainsString("__t('logs.th_correlation')", $export, 'the CSV header names the column');
        self::assertStringContainsString("\$row['correlation_id']", $export, 'the CSV rows carry the value');

        // The projection has to select it, or every row would export an empty
        // column while the table above showed a value.
        self::assertStringContainsString(
            'l.correlation_id',
            $this->source('lib/repo/log.php'),
            'the row projection must select the correlation id'
        );
    }

    public function testTheJobPanelSeparatesTheTwoPermissions(): void
    {
        $panel = $this->source('lib/logs_correlation_panel.php');

        self::assertStringContainsString("\$mayOpenLog = can('deploy.run', \$user)", $panel, 'the job log link is gated on deploy.run');
        self::assertStringContainsString('deploy_job_log_url(', $panel, 'the link is built, never hand-written');

        // The row is rendered before the gate is ever consulted. Position, not
        // wording: a later edit that wraps the loop in the same condition would
        // move the gate above these cells and fail here, instead of quietly
        // hiding a trace's jobs from a user-administrator.
        $loop = strpos($panel, 'foreach ($jobs as $job)');
        $idCell = strpos($panel, '(string) $jobId');
        $statusCell = strpos($panel, 'deploy_job_status_badge(');
        $firstGateInBody = strpos($panel, 'if ($mayOpenLog)', (int) $loop);

        self::assertNotFalse($loop, 'the panel must render one row per job');
        self::assertNotFalse($firstGateInBody, 'the link must be gated inside the row');
        self::assertGreaterThan($loop, $idCell, 'the job id belongs to the row, not to the gate');
        self::assertLessThan($firstGateInBody, $idCell, 'the job id must be rendered without deploy.run');
        self::assertLessThan($firstGateInBody, $statusCell, 'the job status must be rendered without deploy.run');
    }

    /**
     * The panel is bounded and says when it cut. One portal request can enqueue
     * a staggered batch, and a silent prefix of it reads as the whole answer.
     */
    public function testTheJobListIsBoundedDeterministicallyAndSaysSo(): void
    {
        $panel = $this->source('lib/logs_correlation_panel.php');
        $repo = $this->source('lib/repo/deploy_job_queries.php');

        self::assertStringContainsString('logs.jobs_truncated', $panel, 'a cut list is named as cut');
        self::assertStringContainsString('VIRTUSPHERE_LOG_CORRELATION_JOB_LIMIT', $panel);
        self::assertStringContainsString('logs.jobs_retention_note', $panel, 'the retention asymmetry is stated');

        $start = strpos($repo, 'function repo_deploy_jobs_by_correlation(');
        self::assertNotFalse($start);
        $body = substr($repo, $start, 1400);
        self::assertStringContainsString('ORDER BY j.id DESC', $body, 'ordering by id alone keeps a batch stable');
        self::assertStringNotContainsString('ORDER BY j.created_at', $body, 'created_at has second resolution; a batch ties on it');
        self::assertStringContainsString('LEFT JOIN deploy_missions', $body, 'a system job has no mission and must not be dropped');
    }

    /**
     * The trace link drops every other filter. A trace is read to see the WHOLE
     * request; carrying the category or the date range that happened to be set
     * would show a slice of it while looking like the complete answer.
     */
    public function testTheTraceLinkIsBuiltByItsOwnBuilder(): void
    {
        $filter = $this->source('lib/log_filter.php');
        $start = strpos($filter, 'function log_filter_correlation_url(');
        self::assertNotFalse($start, 'the trace link needs its own builder');

        $body = substr($filter, $start, 400);
        foreach (['category', 'from', 'to', 'event', 'object_id', 'result'] as $dropped) {
            self::assertStringNotContainsString(
                "'" . $dropped . "'",
                $body,
                'the trace link must not carry the ' . $dropped . ' filter'
            );
        }
    }
}
