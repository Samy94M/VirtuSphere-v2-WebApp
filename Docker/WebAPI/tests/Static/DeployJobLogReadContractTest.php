<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Guards the cursor/endpoint/client contract even when integration is absent. */
final class DeployJobLogReadContractTest extends TestCase
{
    public function testRealSourcesCarryTheCompleteReadContract(): void
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
        $files = [
            'lib/deploy_constants.php',
            'lib/deploy_log_constants.php',
            'lib/audit_events.php',
            'lib/repo/deploy_job_queries.php',
            'lib/repo/deploy_job_worker.php',
            'portal/deploy_log.php',
            'portal/assets/deploy_log.js',
            'lib/layout.php',
        ];
        $sources = [];
        foreach ($files as $file) {
            $sources[$file] = (string) file_get_contents($root . '/' . $file);
        }

        return $sources;
    }

    /** @return array<string,array<int,string>> */
    private function needles(): array
    {
        return [
            // The read windows moved out of lib/deploy_constants.php in Etappe
            // 13, when that file reached the ADR-0006 budget: "what may be
            // stored" and "how much may be read at once" are different domains.
            'lib/deploy_log_constants.php' => [
                'VIRTUSPHERE_DEPLOY_LOG_INITIAL_TAIL_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_OLDER_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW',
                'VIRTUSPHERE_DEPLOY_LOG_RAW_BATCH_SIZE',
                // The bottom tolerance is not a comfort margin: scrollTop is
                // fractional while scrollHeight and clientHeight are rounded,
                // so without it follow mode pauses itself at the actual bottom.
                'VIRTUSPHERE_DEPLOY_LOG_BOTTOM_TOLERANCE_PX',
                'VIRTUSPHERE_DEPLOY_LOG_STATUS_THROTTLE_MS',
            ],
            'lib/repo/deploy_job_queries.php' => [
                'function repo_deploy_job_log_initial_tail(',
                'ORDER BY seq DESC LIMIT ?) AS recent ORDER BY seq ASC',
                'function repo_deploy_job_log_forward(',
                'function repo_deploy_job_log_older(',
                'function repo_deploy_job_log_raw_batches(',
                'MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT',
                'if (!$closed)',
                '$limit + 1',
                "'caught_up' =>",
            ],
            'lib/repo/deploy_job_worker.php' => [
                "SELECT status FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE",
                "throw new RuntimeException('Cannot append to a terminal deploy job.')",
                'repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $terminalLine);',
            ],
            'portal/deploy_log.php' => [
                'can(\'deploy.run\', $user)',
                'portal_forbid($connection, $user, \'deploy.run\', $format === \'json\')',
                "'oldest_seq' =>",
                "'newest_seq' =>",
                "'has_older' =>",
                "'has_more' =>",
                "'caught_up' =>",
                "X-VirtuSphere-Log-Retention-Days:",
                'repo_deploy_job_log_raw_batches(',
                'Content-Type: application/x-ndjson; charset=utf-8',
                'Content-Disposition: attachment; filename=',
                'virtusphere-deploy-job-',
                'data-deploy-log-older',
                // The poll is a read, and it must not hold the session lock
                // while it runs: at a two-second cadence with an unpaused drain
                // the rest of the portal would queue behind one open job log.
                // Identity, permission and locale are resolved before this.
                "if (\$format === 'json' && session_status() === PHP_SESSION_ACTIVE) {",
                'session_write_close();',
                // Additive only. `status` and `badge` are the existing wire
                // fields and stay; `label` is what the browser prints.
                "'status' => (string) \$job['status'],",
                "'badge' => deploy_job_status_badge_class((string) \$job['status']),",
                "'label' => deploy_job_status_label((string) \$job['status']),",
                // The log region is announced as a log but does not speak per
                // line; the throttled status region does the speaking instead.
                'role="log" aria-live="off"',
                'data-deploy-log-scroller',
                // Both numbers come from lib/deploy_log_constants.php through an
                // attribute, so the browser cannot carry a second copy.
                'data-bottom-tolerance="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_BOTTOM_TOLERANCE_PX); ?>"',
                'data-status-throttle="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_STATUS_THROTTLE_MS); ?>"',
                // Both switches carry a visible label, not a tooltip.
                "data-deploy-log-follow checked> <?php echo h(__t('deploy.follow_label'))",
                "data-deploy-log-wrap checked> <?php echo h(__t('deploy.wrap_label'))",
                'data-deploy-log-jump',
                'data-deploy-log-retry',
                'data-deploy-log-connection role="status" aria-atomic="true"',
            ],
            'lib/audit_events.php' => [
                'if ($json)',
                "header('Content-Type: application/json; charset=utf-8')",
                "json_encode(['ok' => false, 'message' => __t('portal.forbidden')]",
            ],
            'portal/assets/deploy_log.js' => [
                'if (busy || stopped || historyMode)',
                'payload.job.terminal && payload.caught_up',
                'payload.has_more ? 0 : 2000',
                'response.status === 401',
                'response.status === 403',
                'Math.min(5000, retryDelay * 2)',
                'data-log-seq',
                'trimOldest();',
                'trimNewest();',
                "setFeedback(i18n.history_mode || '')",
                // The visible status text comes from `label` alone. A fallback
                // to `status` would put the raw token back in front of a person
                // on exactly the day the server sends something the catalog
                // does not know.
                'status.textContent = payload.job.label',
                // Follow is a state the reader owns: it pauses itself when they
                // scroll up and only a deliberate return clears the counter.
                // The initial position belongs to the same rule and is checked
                // structurally below, because a following reader who is left at
                // the TOP of the newest window never reaches the bottom test at
                // all: every batch counts as unseen and nothing ever moves.
                'if (followEnabled) {',
                'function atBottom()',
                'bottomTolerance',
                'scroller.addEventListener(\'scroll\'',
                'scrollPaused = true;',
                'unseenLines',
                'virtusphere.deploy_log.follow',
                // A background tab stops asking and comes back with exactly one
                // catch-up, not with the polls it missed.
                "document.addEventListener('visibilitychange'",
                'if (document.hidden) { return; }',
                // One DOM mutation per batch, so nothing observes the
                // intermediate states of a five-hundred-line drain.
                'document.createDocumentFragment()',
                // A login page answering 200 is not this endpoint.
                "indexOf('application/json') === -1",
            ],
            'lib/layout.php' => [
                "'assets/deploy_log.js'",
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
        $deployJs = $sources['portal/assets/deploy_log.js'] ?? '';
        if (substr_count($deployJs, 'function poll()') !== 1) {
            $errors[] = 'the log client must own exactly one poll loop';
        }
        // The last thing the module does on a live view is put a following
        // reader at the end. Text presence alone cannot say this: the file can
        // carry every follow-mode line and still open at scrollTop 0, where
        // atBottom() is false, the first batch is counted as unseen and the
        // switch labelled "live" moves nothing. Only a browser sees that, so it
        // is pinned here as a shape and in tests/e2e/specs/deploy-log.spec.js as
        // geometry.
        if (preg_match('#renderConnection\(\);\s*(?://[^\n]*\n\s*)*if \(followEnabled\) \{\s*scrollToEnd\(\);#', $deployJs) !== 1) {
            $errors[] = 'the initial render must place a following reader at the end';
        }

        return $errors;
    }
}
