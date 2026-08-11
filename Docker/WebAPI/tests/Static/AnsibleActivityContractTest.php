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

    public function testFreshAndMigratedSchemasCarryTheActivityIndex(): void
    {
        $schema = $this->repoSource('Docker/mysql/mysql-init/struktur.sql');
        $migration = $this->webApiSource('lib/migrate.php');
        $definition = 'deploy_jobs_ansible_activity (credential_ansible_id, updated_at, id)';

        self::assertStringContainsString($definition, $schema);
        self::assertStringContainsString("'0039_ansible_activity_index'", $migration);
        self::assertStringContainsString($definition, $migration);
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
