<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationPartialResultsContractTest extends TestCase
{
    public function testPartialResultMigrationMirrorsTheStatusSsot(): void
    {
        $constants = $this->webApiSource('lib/deploy_constants.php');
        $migration = $this->webApiSource('lib/migrate.php');
        $enum = "ENUM('queued','running','succeeded','failed','cancelled','partial')";

        self::assertStringContainsString("VIRTUSPHERE_DEPLOY_STATUS_PARTIAL = 'partial'", $constants);
        self::assertStringContainsString($enum, $migration);
        self::assertStringContainsString("'0019_deploy_partial_results'", $migration);
        self::assertStringContainsString("'result_json', 'JSON NULL AFTER payload_json'", $migration);
    }

    public function testDefaultInterfaceMigrationNeverGuessesOrDuplicates(): void
    {
        $migration = $this->webApiSource('lib/migrate.php');

        self::assertStringContainsString("'0020_materialize_default_interfaces'", $migration);
        self::assertStringContainsString('mission_name_is_template', $migration);
        self::assertStringContainsString("=== '' ? 'skipped' : 'materialize'", $migration);
        self::assertStringContainsString('WHERE NOT EXISTS (SELECT 1 FROM deploy_interfaces WHERE vm_id = ?)', $migration);
        self::assertStringContainsString('because wds_vlan is empty', $migration);
    }

    private function webApiSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
