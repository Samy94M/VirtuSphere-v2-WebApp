<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The wiring of the create progress card (Etappe 14B, Teiletappe G).
 *
 * A view model nobody calls is the state this stage started in: the module was
 * written, complete and correct, and no page required it, so nothing rendered
 * and nothing failed. That is invisible to every test that exercises the module
 * itself, which is why the wiring is pinned here rather than assumed.
 *
 * The no-JavaScript path is pinned for the same reason one level down. The
 * "load older" control was a `<button type="button">` for three stages: the
 * endpoint accepted `before_seq` as a GET cursor the whole time, so the
 * capability existed and simply was not offered, on the one page a person opens
 * when something has already gone wrong.
 */
final class DeployCreateProgressContractTest extends TestCase
{
    public function testRealSourcesCarryTheCompleteContract(): void
    {
        self::assertSame([], $this->validate($this->sources()));
    }

    public function testEveryBoundaryAndAZeroMatchAreEffective(): void
    {
        $sources = $this->sources();
        self::assertNotSame([], $this->validate(array_fill_keys(array_keys($sources), '')));
        foreach ($this->needles() as $file => $needles) {
            foreach ($needles as $needle) {
                $mutated = $sources;
                $mutated[$file] = str_replace($needle, '', $mutated[$file]);
                self::assertNotSame($sources[$file], $mutated[$file], $file . ' fixture did not remove ' . $needle);
                self::assertNotSame([], $this->validate($mutated), $file . ' passed without ' . $needle);
            }
        }
    }

    /** @return array<string,string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (array_keys($this->needles()) as $file) {
            $sources[$file] = (string) file_get_contents($root . '/' . $file);
        }

        return $sources;
    }

    /** @return array<string,array<int,string>> */
    private function needles(): array
    {
        return [
            'portal/deploy_log.php' => [
                // Required AND called. Either alone is the defect this pins.
                "require_once __DIR__ . '/../lib/deploy_create_progress.php';",
                'deploy_log_render_create_progress($connection, $job, $user);',
                "'create_progress' => deploy_create_progress_payload(\$connection, \$job),",
                // A link, not a button: the cursor already worked without
                // JavaScript, it was simply not reachable.
                'deploy_log_older_url((int) $job[\'id\'], $oldestSeq)',
                // A page reached by that link is history: following it would
                // append the newest incoming lines below a window of old ones.
                "\$isHistoryPage = deploy_job_log_cursor('before_seq', true, \$_GET) !== null;",
                "\$logFilter['active'] || \$isHistoryPage",
            ],
            'lib/deploy_log_filter.php' => [
                'function deploy_log_older_url(',
                "'before_seq' => (string) \$beforeSeq",
            ],
            'lib/deploy_log_view.php' => [
                // The half that makes the anchor more than decoration. The link
                // existed first and the HTML renderer still answered every
                // request with the newest tail, so a click without JavaScript
                // reloaded the same page and looked like a dead control. Only a
                // browser saw that; no source-text check could.
                "\$beforeSeq = deploy_job_log_cursor('before_seq', true, \$query);",
                'repo_deploy_job_log_older($db, $jobId, $beforeSeq)',
            ],
            'lib/deploy_create_progress.php' => [
                // The card and its live update read ONE function, so they
                // cannot become two opinions about one job.
                'function deploy_create_progress_from_rows(',
                'function deploy_create_progress_payload_from_view(',
                'deploy_create_progress_from_rows(repo_deploy_create_results($db, $jobId))',
                // The counters the card walks, and the class this project
                // actually styles. A card with its own class name renders as a
                // naked definition list and no source review notices.
                'VIRTUSPHERE_CREATE_PROGRESS_COUNTERS',
                'class="status-facts"',
                // The current line exists even when nothing is in flight, and
                // its two facts are separate nodes: a paragraph that only
                // exists while a unit runs can never be filled when the next
                // one starts, and one text node would drop the "since".
                'data-create-current-label',
                'data-create-since-text',
                'data-create-findings',
                'data-create-finding-list',
                "help_url('deploy', 'help-create-progress')",
            ],
            'lib/help_page.php' => [
                "'help-create-progress' => 'deploy',",
            ],
            'lib/help/deploy.php' => [
                'id="help-create-progress"',
            ],
            'portal/assets/deploy_log.js' => [
                'function renderCreateProgress(progress)',
                'renderCreateProgress(payload.create_progress || null);',
                'data-create-current-label',
                'data-create-since-text',
                'data-create-findings',
                'data-create-finding-list',
                // The control is a real link now, so its default navigation
                // must be suppressed here rather than relied upon.
                'event.preventDefault();',
            ],
        ];
    }

    /** @param array<string,string> $sources @return array<int,string> */
    private function validate(array $sources): array
    {
        $errors = [];
        foreach ($this->needles() as $file => $needles) {
            foreach ($needles as $needle) {
                if (!str_contains($sources[$file] ?? '', $needle)) {
                    $errors[] = $file . ' is missing ' . $needle;
                }
            }
        }
        // The counter list and the two catalogs must agree in BOTH directions:
        // a counter without a label renders its own key, and a label without a
        // counter is a promise the card never keeps.
        $module = $sources['lib/deploy_create_progress.php'] ?? '';
        if ($module !== '' && !str_contains($module, "__t('deploy.create_progress_count_' . \$counter)")) {
            $errors[] = 'the card must build its counter labels from the constant';
        }

        return $errors;
    }
}
