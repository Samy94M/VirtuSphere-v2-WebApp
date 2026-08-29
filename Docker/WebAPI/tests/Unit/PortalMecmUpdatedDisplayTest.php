<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

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
        $editor = (string) file_get_contents($root . '/lib/vm_edit_form.php');
        self::assertStringContainsString('mecm_updated_display(', $vms);
        self::assertStringContainsString('mecm_updated_display(', $editor);
        self::assertStringNotContainsString("echo h((string) (\$vm['updated']", $vms . $editor);
    }
}
