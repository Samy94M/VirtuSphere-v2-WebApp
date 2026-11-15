<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Migrations 0019/0020 (ADR-0030).
 *
 * What they DO is proven elsewhere since QL4 (B2): the status ENUM in every
 * mirror, the constant included, by scripts/check-enum-sync.sh (gate enum-sync);
 * result_json as JSON, the default interface 0020 materializes, no guess for an
 * empty WDS VLAN or a template, no change to an existing interface, its
 * idempotent re-run and its report line by scripts/check-schema-convergence.sh
 * which runs both migrations against real MySQL. A renamed 0020 now also fails
 * there, because its re-run must remove exactly one tracking row.
 *
 * What stays are the two things no gate notices (mutation probe in
 * docs/register/2026-11-15-QL4.md). The tracking name of 0019: a rename changes
 * no schema and no row, so every check stays green, and the next upgrade would
 * run 0019 again on every installation under its new name. And the NOT EXISTS
 * guard of the 0020 INSERT: the plan already leaves out a VM that has an
 * interface, so the convergence run stays green without it; the guard is what
 * keeps a VM that gains an interface between plan and insert from getting a
 * second one.
 */
final class MigrationPartialResultsContractTest extends TestCase
{
    public function testTheTrackingNameOfMigration0019StaysAsShipped(): void
    {
        self::assertStringContainsString("'0019_deploy_partial_results' => function", $this->migration());
    }

    public function testTheDefaultInterfaceInsertKeepsItsOwnDuplicateGuard(): void
    {
        self::assertStringContainsString('WHERE NOT EXISTS (SELECT 1 FROM deploy_interfaces WHERE vm_id = ?)', $this->migration());
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 2) . '/lib/migrate.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
