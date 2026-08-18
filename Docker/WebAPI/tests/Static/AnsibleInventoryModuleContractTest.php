<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_inventory_modules.php';

/**
 * Pins the public surface of the Ansible inventory transport/parsing layer
 * across its Etappe 7 (ADR-0006) split. Same reason as
 * EsxiInventoryModuleContractTest: a static contract that reads exactly one
 * source file stops guarding the moment that file is split into domain
 * modules, silently and while still green.
 */
final class AnsibleInventoryModuleContractTest extends TestCase
{
    private const SURFACE = [
        'ansible_prepare_inventory_artifacts',
        'ansible_inventory_remote_command',
        'ansible_inventory_network_item',
        'ansible_inventory_vm_item',
        'ansible_parse_inventory_output',
        'ansible_inventory_normalization_log_line',
        'ansible_categorize_inventory_error',
        'ansible_inventory_datastore_health',
        'ansible_inventory_datastore_health_log_line',
        'ansible_parse_inventory_queries',
        'ansible_inventory_queries_log_line',
        'ansible_parse_inventory_capabilities',
        'ansible_capability_string',
        'ansible_capability_resolve',
        'ansible_capability_bool',
        'ansible_capability_ha_state',
        'ansible_parse_inventory_hosts',
    ];

    public function testRegistryMatchesTheFilesystemInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_map(
            static fn (string $path): string => 'lib/' . basename($path),
            glob($root . '/lib/ansible_inventory*.php') ?: []
        );
        $files = array_values(array_diff($files, ['lib/ansible_inventory_modules.php']));

        self::assertSame(
            $this->sorted($files),
            $this->sorted(VIRTUSPHERE_ANSIBLE_INVENTORY_MODULES),
            'The Ansible inventory owner registry and filesystem disagree.'
        );
    }

    #[RunInSeparateProcess]
    public function testFacadeAloneDefinesTheCompleteSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';

        $missing = array_values(array_filter(self::SURFACE, static fn (string $name): bool => !function_exists($name)));
        self::assertSame([], $missing, 'The facade does not define: ' . implode(', ', $missing));
    }

    public function testEveryFunctionHasExactlyOneRegisteredOwner(): void
    {
        $root = dirname(__DIR__, 2) . '/';
        $owners = [];
        foreach (VIRTUSPHERE_ANSIBLE_INVENTORY_MODULES as $module) {
            if ($module === 'lib/ansible_inventory.php') {
                // The facade itself defines no function of its own; it only
                // requires the domain modules and shared dependencies.
                continue;
            }
            $source = (string) file_get_contents($root . $module);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $module;
            }
        }
        self::assertNotSame([], $owners, 'No function was found in the registered Ansible inventory modules.');

        $duplicates = array_filter($owners, static fn (array $files): bool => count($files) !== 1);
        self::assertSame([], $duplicates, 'An Ansible inventory function has more than one owner.');
        self::assertSame($this->sorted(self::SURFACE), $this->sorted(array_keys($owners)));
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
