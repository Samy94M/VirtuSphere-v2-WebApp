<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_edit_modules.php';

/** Pins the VM editor's Etappe-14 request/render/helper split. */
final class VmEditModuleContractTest extends TestCase
{
    private const HELPER_SURFACE = [
        'vm_default_interfaces',
        'vm_default_disks',
        'vm_parse_interfaces',
        'vm_parse_disks',
        'vm_parse_packages',
        'vm_cidr_to_netmask',
        'vm_subnet_input_value',
        'vm_subnet_picker_value',
        'render_interface_row',
        'render_disk_row',
        'render_disk_type_hint',
        'render_interface_gateway_hint',
        'vm_field_error',
        'vm_guest_os_options_for_value',
        'vm_guest_os_option_label',
        'vm_edit_render_status_panel',
        'render_vm_status_history',
        'vm_edit_identity_fields',
    ];

    public function testRegistryMatchesFilesystemInBothDirections(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = ['portal/vm_edit.php'];
        $files = array_merge($files, array_map(
            static fn (string $path): string => 'lib/' . basename($path),
            glob($root . '/lib/vm_edit*.php') ?: []
        ));
        $files = array_values(array_diff($files, ['lib/vm_edit_modules.php']));

        self::assertSame($this->sorted($files), $this->sorted(VIRTUSPHERE_VM_EDIT_MODULES));
    }

    #[RunInSeparateProcess]
    public function testFormFacadeAloneDefinesCompleteHelperSurface(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/vm_edit_form.php';

        $missing = array_values(array_filter(
            self::HELPER_SURFACE,
            static fn (string $name): bool => !function_exists($name)
        ));
        self::assertSame([], $missing, 'The VM form facade does not define: ' . implode(', ', $missing));
    }

    public function testEveryHelperHasExactlyOneOwner(): void
    {
        $owners = [];
        foreach (VIRTUSPHERE_VM_EDIT_MODULES as $module) {
            if (in_array($module, ['portal/vm_edit.php', 'lib/vm_edit_form.php'], true)) {
                continue;
            }
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $module);
            preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);
            foreach ($matches[1] as $function) {
                $owners[$function][] = $module;
            }
        }

        self::assertSame([], array_filter($owners, static fn (array $files): bool => count($files) !== 1));
        self::assertSame($this->sorted(self::HELPER_SURFACE), $this->sorted(array_keys($owners)));
    }

    public function testPortalEntrypointIsOnlyRequestAndLayoutShell(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/vm_edit.php');

        self::assertStringContainsString("require_once __DIR__ . '/../lib/vm_edit_page.php'", $source);
        self::assertStringContainsString("require __DIR__ . '/../lib/vm_edit_panels.php'", $source);
        self::assertStringContainsString('layout_header(', $source);
        self::assertStringContainsString('layout_footer();', $source);
        self::assertStringNotContainsString("REQUEST_METHOD", $source);
        self::assertStringNotContainsString('<form', $source);
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
