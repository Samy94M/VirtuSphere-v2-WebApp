<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fresh schema and live migration must converge on the measured index. */
final class LogKeysetMigrationTest extends TestCase
{
    public function testMigrationAndFreshSchemaCarryTheSameCompositeIndex(): void
    {
        $webApi = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($webApi . '/lib/migrations/0054_log_keyset_pagination.php');
        $registry = (string) file_get_contents($webApi . '/lib/migrate.php');
        $schema = (string) file_get_contents(dirname($webApi) . '/mysql/mysql-init/struktur.sql');

        self::assertStringContainsString('deploy_logs_category_lookup (category, id)', $migration);
        self::assertStringContainsString('deploy_logs_category_lookup (category, id)', $schema);
        self::assertStringContainsString("require_once __DIR__ . '/migrations/0054_log_keyset_pagination.php'", $registry);
        self::assertStringContainsString("'0054_log_keyset_pagination' => migrate_0054_log_keyset_pagination(...)", $registry);
        self::assertStringContainsString("if (\$columns === ['category', 'id'])", $migration);
    }
}
