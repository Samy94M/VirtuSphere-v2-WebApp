<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pins the boundary between the manual full test and real job history. */
final class AnsibleActivityContractTest extends TestCase
{
    public function testMissionEvidenceReadsTheJobSsotWithoutWritingPreflightState(): void
    {
        $source = $this->webApiSource('lib/repo/ansible_activity.php');

        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES', $source);
        self::assertStringContainsString('FROM deploy_jobs j', $source);
        self::assertStringContainsString('j.mission_id IS NOT NULL', $source);
        self::assertStringNotContainsString('deploy_ansible_preflight_state', $source);
        self::assertStringNotContainsString('repo_ansible_preflight_record', $source);
    }

    public function testOnlyJobsAWorkerClaimedCountAsActivity(): void
    {
        $source = $this->webApiSource('lib/repo/ansible_activity.php');

        // attempts is incremented exactly at claim time, so this predicate is
        // the whole difference between "a worker ran this" and "somebody wished
        // for it": a queued -> cancelled job stays terminal at attempts = 0.
        self::assertStringContainsString('j.attempts > 0', $source);
        self::assertStringContainsString('attempts = attempts + 1', $this->webApiSource('lib/repo/deploy_job_worker.php'));
    }

    public function testTheReaderStaysOnTheIndexInsteadOfRankingTheWholeHistory(): void
    {
        $source = $this->webApiSource('lib/repo/ansible_activity.php');

        // Mission history is never purged. Measured against 201,545 rows the
        // window-function form scanned the table and sorted/materialized 193,333
        // rows; the per-credential read walks deploy_jobs_ansible_activity
        // backwards and stops at the first match. Keep both halves of that shape.
        self::assertStringNotContainsString('ROW_NUMBER', $source);
        self::assertStringContainsString('ORDER BY j.updated_at DESC, j.id DESC', $source);
        self::assertStringContainsString('LIMIT 1', $source);
    }

    public function testFreshAndMigratedSchemasCarryTheActivityIndex(): void
    {
        $schema = $this->repoSource('Docker/mysql/mysql-init/struktur.sql');
        $migration = $this->webApiSource('lib/migrate.php');
        $definition = 'deploy_jobs_ansible_activity (credential_ansible_id, updated_at, id)';

        self::assertStringContainsString($definition, $schema);
        self::assertStringContainsString("'0039_ansible_activity_index'", $migration);
        self::assertStringContainsString($definition, $migration);
    }

    /**
     * A fixture that leaves attempts at the schema default proves the display
     * with a job that never ran, which is the exact defect the reader closes.
     * Derived from the sources rather than from a line list, so a new seeded job
     * has to decide the same question.
     */
    public function testEveryFixtureSeedingActivityNamesItsAttempts(): void
    {
        $sources = [
            'tests/Integration/AnsibleActivityTest.php' => $this->webApiSource('tests/Integration/AnsibleActivityTest.php'),
            'tests/e2e/specs/system-status-actions.spec.js' => $this->repoSource('tests/e2e/specs/system-status-actions.spec.js'),
        ];

        $inserts = 0;
        foreach ($sources as $path => $source) {
            preg_match_all('/INSERT INTO deploy_jobs \(([^)]*)\)/i', $source, $matches);
            foreach ($matches[1] as $columns) {
                $inserts++;
                self::assertStringContainsString(
                    'attempts',
                    $columns,
                    $path . ' seeds a deploy job without saying whether a worker ever claimed it'
                );
            }
        }

        self::assertGreaterThan(0, $inserts, 'no deploy job fixture was found at all, so this contract proved nothing');
    }

    private function webApiSource(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($source);

        return $source;
    }

    private function repoSource(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relative;
        if (!is_file($path)) {
            self::markTestSkipped('Repository root is not visible from this test mount.');
        }
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }
}
