<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory_modules.php';

/** Pins the public surface before the ADR-0006 inventory split. */
final class EsxiInventoryModuleContractTest extends TestCase
{
    private const REPO_SURFACE = [
        'esxi_inventory_name_key',
        'repo_esxi_inventory_dedupe',
        'repo_esxi_inventory_replace_kind',
        'repo_esxi_inventory_answered_kinds',
        'repo_esxi_inventory_apply',
        'repo_esxi_inventory_touch_kind_freshness',
        'repo_esxi_inventory_name_items',
        'repo_esxi_inventory_mixed_items',
        'repo_esxi_inventory_for_credential',
        'repo_esxi_inventory_datastore_rows',
        'repo_esxi_inventory_record_success',
        'repo_esxi_inventory_record_failure',
        'repo_esxi_inventory_clear_pause',
        'repo_esxi_inventory_state',
        'repo_esxi_inventory_states',
        'repo_esxi_inventory_counts',
        'repo_esxi_inventory_pending_jobs',
        'repo_esxi_vlan_sync',
        'repo_esxi_vlan_present_names',
        'repo_esxi_inventory_has_fresh_success',
        'repo_esxi_inventory_names_by_kind',
        'repo_esxi_inventory_names_by_credential',
        'repo_esxi_inventory_name_sets_by_credential',
        'repo_esxi_inventory_pulled_credential_ids',
        'repo_esxi_datacenters_for_credential',
        'repo_esxi_sole_datacenter',
        'repo_esxi_vlan_id_aggregate',
        'repo_esxi_vlan_presence_report',
    ];

    private const SERVICE_SURFACE = [
        'esxi_inventory_ansible_resolution',
        'esxi_inventory_ansible_resolve',
        'esxi_inventory_ansible_credential_id',
        'esxi_inventory_clear_ansible_selection_if_matches',
        'esxi_inventory_enqueue_for_credential',
        'esxi_inventory_refresh_all_targets',
        'esxi_inventory_name_set',
        'esxi_inventory_value_unknown',
        'esxi_inventory_missing_values',
        'esxi_inventory_mission_missing_by_credential',
        'esxi_inventory_mission_deviations',
        'esxi_inventory_add_vm_issue',
        'esxi_inventory_vm_deviations',
        'esxi_inventory_deviating_mission_ids',
        'repo_reassign_vlan',
        'esxi_inventory_interval_hours',
        'esxi_inventory_ampel',
        'esxi_inventory_summaries',
        'esxi_inventory_detail',
        'esxi_inventory_enqueue_due',
    ];

    public function testRegistriesMatchTheirFilesystemsInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $repoFiles = array_map(
            static fn (string $path): string => 'lib/repo/' . basename($path),
            glob($root . '/lib/repo/esxi_inventory*.php') ?: []
        );
        $serviceFiles = array_map(
            static fn (string $path): string => 'lib/' . basename($path),
            glob($root . '/lib/esxi_inventory*.php') ?: []
        );
        $serviceFiles = array_values(array_diff($serviceFiles, [
            'lib/esxi_inventory_modules.php',
            'lib/esxi_inventory_options.php',
        ]));

        self::assertSame(
            $this->sorted($repoFiles),
            $this->sorted(VIRTUSPHERE_ESXI_INVENTORY_REPO_MODULES),
            'The ESXi inventory repository registry and filesystem disagree.'
        );
        self::assertSame(
            $this->sorted($serviceFiles),
            $this->sorted(VIRTUSPHERE_ESXI_INVENTORY_SERVICE_MODULES),
            'The ESXi inventory service registry and filesystem disagree.'
        );
    }

    #[RunInSeparateProcess]
    public function testRepoFacadeAloneDefinesTheCompleteSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';
        $this->assertFunctionsExist(self::REPO_SURFACE);
    }

    #[RunInSeparateProcess]
    public function testServiceFacadeAloneDefinesBothCompleteSurfaces(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';
        $this->assertFunctionsExist(array_merge(self::REPO_SURFACE, self::SERVICE_SURFACE));
    }

    public function testEveryFunctionHasExactlyOneRegisteredOwner(): void
    {
        $this->assertOwnedSurface(VIRTUSPHERE_ESXI_INVENTORY_REPO_MODULES, self::REPO_SURFACE);
        $this->assertOwnedSurface(VIRTUSPHERE_ESXI_INVENTORY_SERVICE_MODULES, self::SERVICE_SURFACE);
    }

    /** @param array<int, string> $modules @param array<int, string> $expected */
    private function assertOwnedSurface(array $modules, array $expected): void
    {
        self::assertNotSame([], $modules, 'An inventory owner registry is empty.');
        $owners = [];
        foreach ($modules as $module) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $module;
            }
        }
        self::assertNotSame([], $owners, 'No function was found in the registered inventory modules.');

        $duplicates = array_filter($owners, static fn (array $files): bool => count($files) !== 1);
        self::assertSame([], $duplicates, 'An inventory function has more than one owner.');
        self::assertSame($this->sorted($expected), $this->sorted(array_keys($owners)));
    }

    /** @param array<int, string> $functions */
    private function assertFunctionsExist(array $functions): void
    {
        $missing = array_values(array_filter($functions, static fn (string $name): bool => !function_exists($name)));
        self::assertSame([], $missing, 'The facade does not define: ' . implode(', ', $missing));
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
