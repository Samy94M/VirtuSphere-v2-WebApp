<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalUnsavedChangesContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testOnlyTheIntendedEditorsOptIn(): void
    {
        $owners = [];
        foreach ([$this->root . '/lib', $this->root . '/portal'] as $sourceRoot) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains((string) file_get_contents($file->getPathname()), 'form_unsaved_attrs(')
                    && !str_ends_with($path, '/lib/forms.php')) {
                    $owners[] = substr($path, strlen(str_replace('\\', '/', $this->root)) + 1);
                }
            }
        }

        sort($owners);
        self::assertSame(['lib/vm_edit_panels.php', 'portal/mission_details.php'], $owners);
    }

    public function testModuleKeepsTheBaselineEphemeralAndFailSafe(): void
    {
        $source = (string) file_get_contents($this->root . '/portal/assets/unsaved_changes.js');

        foreach (['beforeunload', 'data-unsaved-restored', "type === 'password'", "type === 'file'",
            'MutationObserver', 'virtusphere:confirm-request', 'virtusphere:confirm-result'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        foreach (['localStorage', 'sessionStorage', 'window.confirm', '<dialog'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString("['button', 'submit', 'reset', 'image', 'hidden']", $source);
        self::assertStringContainsString('state.unresolved || snapshot(form) !== state.baseline', $source);
    }

    public function testCoreOwnsTheOnlyCustomDialogHandshake(): void
    {
        $core = (string) file_get_contents($this->root . '/portal/assets/core.js');
        $layout = (string) file_get_contents($this->root . '/lib/layout_modals.php');

        self::assertStringContainsString("document.addEventListener('virtusphere:confirm-request'", $core);
        self::assertStringContainsString("new CustomEvent('virtusphere:confirm-result'", $core);
        self::assertSame(1, substr_count($layout, 'data-confirm-dialog'));
    }

    public function testBothEditorsDeclareRestoredServerRenders(): void
    {
        $mission = (string) file_get_contents($this->root . '/portal/mission_details.php');
        $vm = (string) file_get_contents($this->root . '/lib/vm_edit_panels.php');

        self::assertStringContainsString("form_unsaved_attrs('mission-settings', form_has_state('update'))", $mission);
        self::assertGreaterThanOrEqual(2, substr_count($mission, "form_remember('update', \$_POST"));
        self::assertStringContainsString("form_unsaved_attrs('vm-editor', \$error !== '')", $vm);
        self::assertStringContainsString('form_unsaved_status_html()', $mission);
        self::assertStringContainsString('form_unsaved_status_html()', $vm);
    }
}
