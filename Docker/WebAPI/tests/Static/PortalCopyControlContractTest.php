<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalCopyControlContractTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $relative;
        self::assertFileExists($path, $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    public function testOneSharedRendererOwnsAccessibleCopyActions(): void
    {
        $helper = $this->source('lib/copy_control.php');
        self::assertStringContainsString('type="button"', $helper);
        self::assertStringContainsString('role="status"', $helper);
        self::assertStringContainsString("__t('common.copy_failed')", $helper);
        self::assertStringContainsString('data-copy-source=', $helper);
        self::assertStringContainsString('data-copy-value=', $helper);

        self::assertStringContainsString('portal_copy_button(', $this->source('lib/correlation_display.php'));
    }

    public function testRemainingSurfacesUseTheSharedRenderer(): void
    {
        self::assertStringContainsString('portal_copy_value((string) $job[\'id\']', $this->source('portal/deploy_log.php'));

        $report = $this->source('lib/package_report_page.php');
        self::assertStringContainsString('portal_copy_local_link(', $report);
        self::assertStringContainsString("portal_copy_input_button('package-report-support-text'", $report);
    }

    public function testVmListAndEditorOfferNoCopyAction(): void
    {
        // Operator decision 2026-10-17: names and network values in the VM
        // list and editor are plain text and inputs. Derived from the file
        // names so a new VM renderer is covered without editing a list here.
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_merge(glob($root . '/lib/vm*.php') ?: [], [$root . '/portal/vms.php', $root . '/portal/vm_edit.php']);
        self::assertGreaterThan(5, count($files), 'the VM renderers must be found');
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('portal_copy_', $source, basename($file));
            self::assertStringNotContainsString('copy_control.php', $source, basename($file));
        }
    }

    public function testBrowserReadsCurrentFieldsAndHasARealFailureBranch(): void
    {
        $core = $this->source('portal/assets/core.js');
        self::assertStringContainsString('document.getElementById(sourceId)', $core);
        self::assertStringContainsString("typeof source.value === 'string' ? source.value", $core);
        self::assertStringContainsString("button.hidden = currentValue(button) === ''", $core);
        self::assertStringContainsString('navigator.clipboard.writeText(value).then(', $core);
        self::assertStringContainsString('announce(button, failed, false)', $core);
    }

    public function testOverviewHelpOwnsTheFallbackExplanation(): void
    {
        self::assertStringContainsString("'help-copying-values' => 'overview'", $this->source('lib/help_page.php'));
        self::assertStringContainsString('id="help-copying-values"', $this->source('lib/help/overview.php'));
    }
}
