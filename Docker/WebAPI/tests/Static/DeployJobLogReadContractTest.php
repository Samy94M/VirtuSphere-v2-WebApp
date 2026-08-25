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
            'lib/deploy_constants.php' => [
                'VIRTUSPHERE_DEPLOY_LOG_INITIAL_TAIL_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_OLDER_LIMIT',
                'VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW',
                'VIRTUSPHERE_DEPLOY_LOG_RAW_BATCH_SIZE',
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

        return $errors;
    }
}
