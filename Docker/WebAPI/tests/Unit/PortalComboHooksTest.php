<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The compound-field contract between markup and the portal scripts is a pure
 * attribute agreement, so php -l, node --check and the unit suite all pass while
 * it is broken. It was: the JS was generalized from data-ram-* to data-combo-*
 * while the RAM markup in vm_edit.php kept the old names, which silently killed
 * the RAM preset picker. This test pins the contract as text.
 *
 * app.js was split into core.js/forms.js/deploy.js; the client side is the three
 * concatenated (assetsJs), the markup side is the PHP pages.
 */
final class PortalComboHooksTest extends TestCase
{
    /** @return array<string, string> relative path => contents, PHP markup only */
    private function markup(): array
    {
        $root = dirname(__DIR__, 2);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        $files = [];
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'tests/')) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files[$relative] = (string) file_get_contents($path);
        }

        self::assertNotSame([], $files, 'no PHP markup was scanned');

        return $files;
    }

    /** The portal client scripts, concatenated. */
    private function assetsJs(): string
    {
        $files = glob(dirname(__DIR__, 2) . '/portal/assets/*.js') ?: [];
        self::assertNotSame([], $files, 'no portal assets/*.js scripts were found');

        $source = '';
        foreach ($files as $path) {
            $source .= (string) file_get_contents($path) . "\n";
        }

        return $source;
    }

    public function testNoOrphanedRamHooksRemain(): void
    {
        $offenders = [];
        foreach ($this->markup() as $relative => $contents) {
            if (str_contains($contents, 'data-ram-')) {
                $offenders[] = $relative;
            }
        }
        if (str_contains($this->assetsJs(), 'data-ram-')) {
            $offenders[] = 'portal/assets/*.js';
        }

        self::assertSame([], $offenders, 'data-ram-* was replaced by data-combo-*; the scripts no longer handle it');
    }

    public function testEveryComboPickerHasAnInputInTheSameFile(): void
    {
        $offenders = [];
        foreach ($this->markup() as $relative => $contents) {
            if (str_contains($contents, 'data-combo-picker') && !str_contains($contents, 'data-combo-input')) {
                $offenders[] = $relative;
            }
        }

        // A picker without its input is a dropdown that fills nothing.
        self::assertSame([], $offenders);
    }

    public function testAppJsHandlesEveryDeployHostWarningAttributeTheMarkupUses(): void
    {
        // Same contract idea for the deploy per-host warning: the PHP island and
        // the alert target are only useful if the scripts actually read them.
        $appJs = $this->assetsJs();

        $used = [];
        foreach ($this->markup() as $contents) {
            foreach (['data-deploy-esxi', 'data-deploy-host-warnings', 'data-deploy-host-warning'] as $attribute) {
                if (str_contains($contents, $attribute)) {
                    $used[$attribute] = true;
                }
            }
        }

        self::assertNotSame([], $used, 'the deploy host-warning markup disappeared entirely');
        foreach (array_keys($used) as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }

    public function testAppJsHandlesEveryDeployMissionNavigationAttributeTheMarkupUses(): void
    {
        // Both controls that write mission_id navigate, and both must carry the
        // queue form's values along (lib/deploy_form_state.php reads them back).
        // A renamed hook turns the navigation back into the reset it used to be:
        // the page still works, the operator just fills the form in again.
        $appJs = $this->assetsJs();

        $used = [];
        foreach ($this->markup() as $contents) {
            foreach (['data-deploy-mission', 'data-deploy-filter'] as $attribute) {
                if (str_contains($contents, $attribute)) {
                    $used[$attribute] = true;
                }
            }
        }

        self::assertNotSame([], $used, 'the deploy mission-navigation markup disappeared entirely');
        foreach (array_keys($used) as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }

    public function testAppJsHandlesEveryDeployStorageAttributeTheMarkupUses(): void
    {
        // The deploy storage table is rendered by PHP and then kept live by JS on
        // every credential/VM change. A renamed hook would silently freeze the
        // free-space and verdict columns at their placeholder dash.
        $appJs = $this->assetsJs();

        $used = [];
        foreach ($this->markup() as $contents) {
            foreach ([
                'data-storage-live',
                'data-deploy-storage',
                'data-storage-row',
                'data-storage-vms',
                'data-storage-required',
                'data-storage-free-text',
                'data-storage-bar',
                'data-storage-verdict',
                'data-storage-total',
            ] as $attribute) {
                if (str_contains($contents, $attribute)) {
                    $used[$attribute] = true;
                }
            }
        }

        self::assertNotSame([], $used, 'the deploy storage markup disappeared entirely');
        foreach (array_keys($used) as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }

    public function testAppJsHandlesEveryDeployScheduleLockAttributeTheMarkupUses(): void
    {
        // The two mode-driven locks on the deploy form (staggering, and the
        // power-cycle wait time) are attribute agreements just like the storage
        // island: a renamed hook leaves a field that never disables and a lock
        // hint that never shows, with every automated check still green.
        $appJs = $this->assetsJs();

        $used = [];
        foreach ($this->markup() as $contents) {
            foreach ([
                'data-stagger-modes',
                'data-stagger-input',
                'data-stagger-lock',
                'data-powercycle-modes',
                'data-powercycle-input',
                'data-powercycle-lock',
            ] as $attribute) {
                if (str_contains($contents, $attribute)) {
                    $used[$attribute] = true;
                }
            }
        }

        self::assertNotSame([], $used, 'the deploy schedule-lock markup disappeared entirely');
        foreach (array_keys($used) as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }

    public function testAppJsHandlesEveryComboAttributeTheMarkupUses(): void
    {
        $appJs = $this->assetsJs();

        $used = [];
        foreach ($this->markup() as $contents) {
            foreach (['data-combo-input', 'data-combo-picker', 'data-combo-clear'] as $attribute) {
                if (str_contains($contents, $attribute)) {
                    $used[$attribute] = true;
                }
            }
        }

        self::assertNotSame([], $used, 'the compound-field markup disappeared entirely');
        foreach (array_keys($used) as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }
}
