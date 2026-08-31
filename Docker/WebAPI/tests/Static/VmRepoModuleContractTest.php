<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/vms_modules.php';

/** Pins the public VM-repository surface across the Etappe-14 split. */
final class VmRepoModuleContractTest extends TestCase
{
    private const SURFACE = [
        'repo_vm_flag_value',
        'repo_vm_autostart_flag',
        'repo_vm_delay_value',
        'repo_validate_interfaces',
        'repo_validate_disks',
        'repo_validate_vm_payload',
        'getVMs',
        'repo_adopt_vm_identity',
        'repo_fetch_related',
        'repo_source_to_array',
        'deleteVM',
        'vmListToCreate',
        'vmListToUpdate',
        'vmListToDelete',
        'repo_bulk_delete_vms',
        'repo_bulk_reset_mecm_ids',
        'repo_delete_vm_by_id',
        'repo_vm_has_imported_mac',
        'repo_reset_vm_mecm_id',
        'repo_restart_vm_progress_watch',
        'repo_vm_progress_attention_count',
        'repo_vm_progress_attention_counts_by_mission',
        'repo_mark_vm_for_mecm_resync',
        'repo_replace_interfaces',
        'repo_interface_mac_value',
        'repo_replace_packages',
        'repo_replace_disks',
        'repo_get_vm_bundle',
        'repo_vm_name_conflict_global',
        'repo_vm_name_exists',
        'repo_save_vm',
    ];

    public function testRegistryMatchesFilesystemInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_map(
            static fn (string $path): string => 'lib/repo/' . basename($path),
            glob($root . '/lib/repo/vms*.php') ?: []
        );
        $files = array_values(array_diff($files, ['lib/repo/vms_modules.php']));

        self::assertNotSame([], $files, 'The VM-repository glob matched nothing.');
        self::assertSame($this->sorted($files), $this->sorted(VIRTUSPHERE_VM_REPO_MODULES));
    }

    #[RunInSeparateProcess]
    public function testFacadeAloneDefinesCompleteSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

        $missing = array_values(array_filter(
            self::SURFACE,
            static fn (string $name): bool => !function_exists($name)
        ));
        self::assertSame([], $missing, 'The VM facade does not define: ' . implode(', ', $missing));
    }

    public function testEveryFunctionHasExactlyOneRegisteredOwner(): void
    {
        $owners = [];
        foreach (VIRTUSPHERE_VM_REPO_MODULES as $module) {
            if ($module === 'lib/repo/vms.php') {
                continue;
            }
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $module;
            }
        }

        self::assertNotSame([], $owners, 'No VM-repository function was found.');
        self::assertSame([], array_filter($owners, static fn (array $files): bool => count($files) !== 1));
        self::assertSame($this->sorted(self::SURFACE), $this->sorted(array_keys($owners)));
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
