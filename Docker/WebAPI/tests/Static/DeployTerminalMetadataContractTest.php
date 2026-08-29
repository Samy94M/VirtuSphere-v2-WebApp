<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeployTerminalMetadataContractTest extends TestCase
{
    public function testMigrationAndFreshSchemaShareTheBoundedColumnsAndCheck(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrations/0043_deploy_terminal_metadata.php');
        $registry = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrate.php');
        $schemaPath = dirname(__DIR__, 4) . '/Docker/mysql/mysql-init/struktur.sql';
        if (!is_file($schemaPath)) {
            self::markTestSkipped('Fresh schema is outside the WebAPI-only mount.');
        }
        $schema = (string) file_get_contents($schemaPath);

        self::assertStringContainsString("'0043_deploy_terminal_metadata' => migrate_0043_deploy_terminal_metadata(...)", $registry);
        foreach (['terminal_reason_code VARCHAR(32) NULL', 'terminal_reason_detail VARCHAR(1024) NULL', 'deploy_jobs_terminal_reason_check'] as $needle) {
            self::assertStringContainsString($needle, $migration . "\n" . $schema);
        }
        self::assertStringNotContainsString('UPDATE deploy_jobs SET terminal_reason', $migration, 'historical terminal reasons must not be guessed');
    }

    public function testPollResponseAndCancelFormUseTheCentralPresenterAndClosedOriginToken(): void
    {
        $portal = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/deploy_log.php');
        $handler = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_actions.php');
        $urls = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_urls.php');

        self::assertStringContainsString("'terminal_html' => deploy_terminal_blocks_html(\$job)", $portal);
        self::assertStringContainsString("'can_cancel'", $portal);
        self::assertStringContainsString('name="origin"', $portal);
        self::assertStringContainsString('deploy_job_cancel_redirect_url', $handler);
        self::assertStringNotContainsString('return $originToken', $urls);
    }
}
