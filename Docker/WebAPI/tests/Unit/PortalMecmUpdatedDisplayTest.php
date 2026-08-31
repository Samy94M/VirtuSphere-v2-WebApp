<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_modules.php';

final class PortalMecmUpdatedDisplayTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testQueuedFlagHasLocalizedPortalTextAndEveryOtherValueIsADash(): void
    {
        foreach (['de' => 'Für MECM vorgemerkt', 'en' => 'Queued for MECM'] as $locale => $expected) {
            Lang::load($locale);
            self::assertSame($expected, mecm_updated_display(1));
            foreach ([0, 2, -1, null, ''] as $other) {
                self::assertSame('—', mecm_updated_display($other), $locale . ' other value');
            }
        }
    }

    public function testVmListAndEditorUseThePresenterInsteadOfRawFlagOutput(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $vms = (string) file_get_contents($root . '/portal/vms.php');
        $editor = implode("\n", array_map(
            static fn (string $module): string => (string) file_get_contents($root . '/' . $module),
            VIRTUSPHERE_VM_EDIT_MODULES
        ));
        self::assertStringContainsString('mecm_updated_display(', $vms);
        self::assertStringContainsString('mecm_updated_display(', $editor);
        self::assertStringNotContainsString("echo h((string) (\$vm['updated']", $vms . $editor);
    }
}
