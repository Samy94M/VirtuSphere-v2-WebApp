<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * K4b F1: deploy_jobs.failed_step names the remote step a failed job stopped
 * at, and the retry resumes there. The values live three times: the PHP list
 * the worker writes through, the CHECK in the fresh schema and the CHECK the
 * upgrade adds. A value one of them lacks would be refused by the database at
 * the terminal write, the one moment the job's evidence must land.
 */
final class DeployJobFailedStepContractTest extends TestCase
{
    /** @return list<string> */
    private static function checkValues(string $sql): array
    {
        self::assertSame(1, preg_match("/failed_step IS NULL OR failed_step IN \(([^)]*)\)/", $sql, $m), 'failed_step CHECK not found');
        preg_match_all("/_ascii'([a-z]+)'/", $m[1], $values);

        return $values[1];
    }

    public function testTheFreshSchemaAllowsExactlyTheFailedSteps(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/mysql/mysql-init/struktur.sql');

        self::assertSame(VIRTUSPHERE_DEPLOY_FAILED_STEPS, self::checkValues($schema));
    }

    public function testTheMigrationAllowsExactlyTheFailedSteps(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrations/0068_deploy_job_failed_step.php');

        self::assertSame(VIRTUSPHERE_DEPLOY_FAILED_STEPS, self::checkValues($migration));
    }

    public function testTheFailedStepsAreTheRemoteStepsExceptCreate(): void
    {
        self::assertSame(
            array_values(array_diff(array_keys(VIRTUSPHERE_PLAYBOOKS), ['create'])),
            VIRTUSPHERE_DEPLOY_FAILED_STEPS,
        );
    }

    public function testOnlyTheTerminalWriteStoresTheFailedStep(): void
    {
        $writers = [];
        foreach (glob(dirname(__DIR__, 2) . '/lib/{,*/}*.php', GLOB_BRACE) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'failed_step = ?')) {
                $writers[] = basename($file);
            }
        }

        self::assertSame(['deploy_job_worker.php'], $writers);
    }
}
