<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';

final class EditVersionTest extends TestCase
{
    #[DataProvider('expectations')]
    public function testOnlyCanonicalCurrentVersionsPass(string $expected, bool $required, bool $matches): void
    {
        self::assertSame($matches, repo_edit_version_matches($expected, '12', $required));
    }

    public static function expectations(): array
    {
        return [
            'current portal' => ['12', true, true],
            'stale portal' => ['11', true, false],
            'missing portal' => ['', true, false],
            'explicit legacy opt-out' => ['', false, true],
            'legacy current' => ['12', false, true],
            'legacy stale' => ['11', false, false],
            'leading zero' => ['012', true, false],
            'negative' => ['-12', true, false],
            'whitespace' => ['12 ', true, false],
            'line ending' => ["12\n", true, false],
            'retired timestamp' => ['2026-09-08 20:40:00', true, false],
        ];
    }

    public function testMigrationRegistryAndFreshSchemaConvergeWithoutTriggers(): void
    {
        $webApi = dirname(__DIR__, 2);
        $registry = (string) file_get_contents($webApi . '/lib/migrate.php');
        $migration = (string) file_get_contents($webApi . '/lib/migrations/0053_edit_versions.php');
        $schema = (string) file_get_contents(dirname(__DIR__, 4) . '/Docker/mysql/mysql-init/struktur.sql');

        self::assertStringContainsString("require_once __DIR__ . '/migrations/0053_edit_versions.php'", $registry);
        self::assertStringContainsString("'0053_edit_versions' => migrate_0053_edit_versions(...)", $registry);
        self::assertStringContainsString("foreach (['deploy_missions', 'deploy_vms'] as \$table)", $migration);
        foreach (['deploy_missions', 'deploy_vms'] as $table) {
            self::assertStringContainsString("migrator_add_column(\$db, \$table, 'edit_version', 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at')", $migration);
            self::assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\((.*?)\) ENGINE/s', $schema, $match));
            self::assertStringContainsString('edit_version BIGINT UNSIGNED NOT NULL DEFAULT 1', $match[1]);
        }
        self::assertSame(2, substr_count($schema, 'edit_version BIGINT UNSIGNED NOT NULL DEFAULT 1'));
        self::assertStringNotContainsString('TRIGGER', strtoupper($migration));
        self::assertStringNotContainsString('TRIGGER', strtoupper($schema));
    }

    #[DataProvider('versionedTables')]
    public function testGenericVersionedWritesRejectCallerOwnedCounters(string $table): void
    {
        $db = new mysqli();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('edit_version is repository-owned.');
        repo_update_from_values($db, $table, ['edit_version' => 40], 'id = ?', 'i', [1]);
    }

    public static function versionedTables(): array
    {
        return [
            'mission' => ['deploy_missions'],
            'VM' => ['deploy_vms'],
        ];
    }
}
