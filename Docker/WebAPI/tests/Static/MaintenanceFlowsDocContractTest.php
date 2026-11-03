<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';
require_once dirname(__DIR__, 2) . '/lib/maintenance_tasks.php';

/**
 * docs/operations/maintenance-flows.md names every job of the maintenance pass
 * with its schedule and every retention purge with its window. Nothing is listed
 * here: the jobs and their interval constants are read from the calls of
 * maintenance_worker_due() and maintenance_worker_job() in
 * lib/maintenance_tasks.php, the purges from its `$purges` map, the values from
 * the constants and the windows from the default constants of each purge
 * function. A job added to the pass without a row, or a row for a job that is
 * gone, turns this red.
 *
 * docs/ only exists outside the PHP container; the QA lane mounts the full repo.
 */
final class MaintenanceFlowsDocContractTest extends TestCase
{
    private const DOC = 'docs/operations/maintenance-flows.md';
    private const INDEX = 'docs/operations/flows.md';
    private const TASKS = 'lib/maintenance_tasks.php';

    public function testJobTableNamesEveryJobWithItsSchedule(): void
    {
        $schedule = self::schedule();
        $values = [];
        foreach ($schedule as $constant) {
            if ($constant !== null) {
                $values[$constant] = (int) constant($constant);
            }
        }

        self::assertSame([], self::jobTableProblems($schedule, $values, OperationsFlowDoc::section(self::doc(), 'Jobs und Takt')));
    }

    public function testPassDiagramNamesEveryJobAndFollowsTheLegend(): void
    {
        $flows = self::flows();
        $section = OperationsFlowDoc::section(self::doc(), 'Ein Durchlauf');

        self::assertSame([], array_merge(
            OperationsFlowDoc::sectionChartProblems('Pass diagram', $section, 1, (int) OperationsFlowDoc::nodeLimit($flows), OperationsFlowDoc::legend($flows)),
            OperationsFlowDoc::missingTokens('Pass diagram job', array_keys(self::schedule()), implode("\n", OperationsFlowDoc::mermaidBlocks($section)))
        ));
    }

    public function testRetentionTableNamesEveryPurgeWithItsWindow(): void
    {
        $purges = self::purges();
        $windows = [];
        foreach ($purges as $function) {
            $windows[$function] = self::defaultConstants($function);
        }

        self::assertSame([], self::retentionTableProblems($purges, $windows, OperationsFlowDoc::section(self::doc(), 'Aufbewahrung')));
    }

    public function testConvergenceChartFollowsTheLegend(): void
    {
        $flows = self::flows();

        self::assertSame([], OperationsFlowDoc::sectionChartProblems(
            'Convergence chart',
            OperationsFlowDoc::section(self::doc(), 'Hängengebliebene VMs'),
            1,
            (int) OperationsFlowDoc::nodeLimit($flows),
            OperationsFlowDoc::legend($flows)
        ));
    }

    public function testFlowsIndexLinksTheDocument(): void
    {
        self::assertStringContainsString('(maintenance-flows.md)', self::flows());
    }

    public function testTheDerivationFindsTheKnownShapeOfThePass(): void
    {
        $schedule = self::schedule();
        self::assertArrayHasKey('deploy-job-reap', $schedule, 'the reaper job must be derived from maintenance_worker_due()');
        self::assertArrayHasKey('audit-transitions', $schedule, 'a job without maintenance_worker_due() must be derived too');
        self::assertNull($schedule['audit-transitions'], 'a job without maintenance_worker_due() runs every pass');
        self::assertSame('audit_event', self::purges()['retention:audit'] ?? null, 'the summary row is the literal retention:audit job');
        self::assertArrayHasKey('retention:portal_logs', self::purges());
        self::assertSame('repo_purge_client_events', self::purges()['retention:client_events'] ?? null);
    }

    public function testJobTableRejectsMissingExtraDuplicateAndWrongRows(): void
    {
        $schedule = ['alpha' => 'X_ALPHA_SECONDS', 'beta' => null];
        $values = ['X_ALPHA_SECONDS' => 60];
        $ok = "| Job | Takt | Konstante | Arbeit |\n|---|---|---|---|\n| `alpha` | 60 s | `X_ALPHA_SECONDS` | a |\n| `beta` | jeder Durchlauf | | b |\n";
        self::assertSame([], self::jobTableProblems($schedule, $values, $ok));

        self::assertSame(['Job `beta` has no row.'], self::jobTableProblems($schedule, $values, str_replace("| `beta` | jeder Durchlauf | | b |\n", '', $ok)));
        self::assertSame(['Job row `gamma` is not a job of the pass.'], self::jobTableProblems($schedule, $values, $ok . "| `gamma` | 5 s | `X` | c |\n"));
        self::assertSame(['Job `alpha` has 2 rows.'], self::jobTableProblems($schedule, $values, $ok . "| `alpha` | 60 s | `X_ALPHA_SECONDS` | a |\n"));
        self::assertSame(['Job `alpha` reads `120 s` instead of `60 s`.'], self::jobTableProblems($schedule, $values, str_replace('| 60 s |', '| 120 s |', $ok)));
        self::assertSame(['Job `alpha` names `X_OTHER` instead of `X_ALPHA_SECONDS`.'], self::jobTableProblems($schedule, $values, str_replace('`X_ALPHA_SECONDS` | a', '`X_OTHER` | a', $ok)));
        self::assertSame(['Job `beta` runs every pass and must read `jeder Durchlauf` without a constant.'], self::jobTableProblems($schedule, $values, str_replace('| jeder Durchlauf | |', '| 5 s | `X` |', $ok)));
        self::assertSame(['The job table has no jobs derived from code (zero-match must not pass).'], self::jobTableProblems([], [], $ok));
    }

    public function testRetentionTableRejectsMissingWrongFunctionAndMissingWindow(): void
    {
        $purges = ['retention:a' => 'purge_a', 'retention:b' => 'purge_b'];
        $windows = ['purge_a' => ['X_A_DAYS'], 'purge_b' => []];
        $ok = "| Löschlauf | Funktion | Fenster |\n|---|---|---|\n| `retention:a` | `purge_a()` | `X_A_DAYS` |\n| `retention:b` | `purge_b()` | Marker |\n";
        self::assertSame([], self::retentionTableProblems($purges, $windows, $ok));

        self::assertSame(['Purge `retention:b` has no row.'], self::retentionTableProblems($purges, $windows, str_replace("| `retention:b` | `purge_b()` | Marker |\n", '', $ok)));
        self::assertSame(['Purge row `retention:c` is not a purge of the pass.'], self::retentionTableProblems($purges, $windows, $ok . "| `retention:c` | `purge_c()` | x |\n"));
        self::assertSame(['Purge `retention:a` names `purge_x()` instead of `purge_a()`.'], self::retentionTableProblems($purges, $windows, str_replace('`purge_a()`', '`purge_x()`', $ok)));
        self::assertSame(['Purge `retention:a` does not name its window `X_A_DAYS`.'], self::retentionTableProblems($purges, $windows, str_replace('| `X_A_DAYS` |', '| 30 Tage |', $ok)));
        self::assertSame(['The retention table has no purges derived from code (zero-match must not pass).'], self::retentionTableProblems([], [], $ok));
    }

    /**
     * @param array<string,?string> $schedule job => interval constant, null = every pass
     * @param array<string,int> $values constant => value
     * @return list<string>
     */
    public static function jobTableProblems(array $schedule, array $values, string $section): array
    {
        if ($schedule === []) {
            return ['The job table has no jobs derived from code (zero-match must not pass).'];
        }
        $problems = [];
        $seen = [];
        foreach (OperationsFlowDoc::tableRows($section) as $cells) {
            $job = OperationsFlowDoc::ticks($cells[0] ?? '')[0] ?? '';
            if ($job === '') {
                continue;
            }
            $seen[$job] = ($seen[$job] ?? 0) + 1;
            if (!array_key_exists($job, $schedule)) {
                $problems[] = sprintf('Job row `%s` is not a job of the pass.', $job);
                continue;
            }
            $constant = $schedule[$job];
            $named = OperationsFlowDoc::ticks($cells[2] ?? '');
            if ($constant === null) {
                if (($cells[1] ?? '') !== 'jeder Durchlauf' || $named !== []) {
                    $problems[] = sprintf('Job `%s` runs every pass and must read `jeder Durchlauf` without a constant.', $job);
                }
                continue;
            }
            $expected = ($values[$constant] ?? 0) . ' s';
            if (($cells[1] ?? '') !== $expected) {
                $problems[] = sprintf('Job `%s` reads `%s` instead of `%s`.', $job, $cells[1] ?? '', $expected);
            }
            if ($named !== [$constant]) {
                $problems[] = sprintf('Job `%s` names `%s` instead of `%s`.', $job, implode('`, `', $named), $constant);
            }
        }
        foreach (array_keys($schedule) as $job) {
            $count = $seen[$job] ?? 0;
            if ($count === 0) {
                $problems[] = sprintf('Job `%s` has no row.', $job);
            } elseif ($count > 1) {
                $problems[] = sprintf('Job `%s` has %d rows.', $job, $count);
            }
        }

        return $problems;
    }

    /**
     * @param array<string,string> $purges job key => purge function
     * @param array<string,list<string>> $windows function => its default constants
     * @return list<string>
     */
    public static function retentionTableProblems(array $purges, array $windows, string $section): array
    {
        if ($purges === []) {
            return ['The retention table has no purges derived from code (zero-match must not pass).'];
        }
        $problems = [];
        $seen = [];
        foreach (OperationsFlowDoc::tableRows($section) as $cells) {
            $key = OperationsFlowDoc::ticks($cells[0] ?? '')[0] ?? '';
            if (!str_starts_with($key, 'retention:')) {
                continue;
            }
            $seen[$key] = true;
            if (!isset($purges[$key])) {
                $problems[] = sprintf('Purge row `%s` is not a purge of the pass.', $key);
                continue;
            }
            $function = $purges[$key];
            $named = OperationsFlowDoc::ticks($cells[1] ?? '')[0] ?? '';
            if ($named !== $function . '()') {
                $problems[] = sprintf('Purge `%s` names `%s` instead of `%s()`.', $key, $named, $function);
            }
            foreach ($windows[$function] ?? [] as $constant) {
                if (!in_array($constant, OperationsFlowDoc::ticks($cells[2] ?? ''), true)) {
                    $problems[] = sprintf('Purge `%s` does not name its window `%s`.', $key, $constant);
                }
            }
        }
        foreach (array_keys($purges) as $key) {
            if (!isset($seen[$key])) {
                $problems[] = sprintf('Purge `%s` has no row.', $key);
            }
        }

        return $problems;
    }

    /** @return array<string,?string> job => interval constant (null: every pass) */
    private static function schedule(): array
    {
        $source = self::tasksSource();
        $schedule = [];
        preg_match_all("/maintenance_worker_due\\(\\\$state, '([a-z-]+)', (VIRTUSPHERE_[A-Z_]+)/", $source, $due, PREG_SET_ORDER);
        foreach ($due as $match) {
            $schedule[$match[1]] = $match[2];
        }
        preg_match_all("/maintenance_worker_job\\('([a-z-]+)'/", $source, $jobs);
        foreach ($jobs[1] as $job) {
            $schedule[$job] ??= null;
        }

        return $schedule;
    }

    /** @return array<string,string> `retention:<key>` => the function that purges it */
    private static function purges(): array
    {
        $source = self::tasksSource();
        self::assertStringContainsString("maintenance_worker_job('retention:' . \$name", $source, 'the purge loop must name each job retention:<key>');
        $purges = [];
        preg_match_all("/'([a-z_]+)' => static fn \\(\\): int => \\(int\\) ([a-z_]+)\\(\\\$db\\)/", $source, $map, PREG_SET_ORDER);
        foreach ($map as $match) {
            $purges['retention:' . $match[1]] = $match[2];
        }
        // The two literal retention jobs: the audit-log purge and the summary row.
        preg_match_all("/maintenance_worker_job\\('(retention:[a-z_]+)'[^\\n]*\\n\\s*(?:\\\$[a-z]+ = \\[\\];\\s*)?([A-Za-z_]+)\\(\\\$db/", $source, $literal, PREG_SET_ORDER);
        foreach ($literal as $match) {
            $purges[$match[1]] = $match[2];
        }
        preg_match_all("/maintenance_worker_job\\('(retention:[a-z_]+)'[^\\n]*\\n\\s*if \\(!([a-z_]+)\\(\\\$db/", $source, $audit, PREG_SET_ORDER);
        foreach ($audit as $match) {
            $purges[$match[1]] ??= $match[2];
        }

        return $purges;
    }

    /** @return list<string> the constants a function's parameters default to */
    private static function defaultConstants(string $function): array
    {
        $constants = [];
        foreach ((new ReflectionFunction($function))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() && $parameter->isDefaultValueConstant()) {
                $constants[] = (string) $parameter->getDefaultValueConstantName();
            }
        }

        return $constants;
    }

    private static function tasksSource(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::TASKS);
        self::assertNotSame('', $source);

        return $source;
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
        self::assertNotSame([], OperationsFlowDoc::legend($flows), 'flows.md has no classDef legend.');

        return $flows;
    }
}
