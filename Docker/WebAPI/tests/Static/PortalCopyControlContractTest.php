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

    public function testEveryPlannedSurfaceUsesTheSharedRenderer(): void
    {
        $list = $this->source('portal/vms.php');
        self::assertGreaterThanOrEqual(2, substr_count($list, 'portal_copy_value('));

        $names = $this->source('lib/vm_edit_names.php');
        self::assertGreaterThanOrEqual(2, substr_count($names, 'portal_copy_input_button('));
        self::assertStringContainsString('portal_copy_value((string) $vm[\'mecm_rollout_hostname\']', $names);

        $interfaces = $this->source('lib/vm_edit_rows.php');
        self::assertGreaterThanOrEqual(2, substr_count($interfaces, 'portal_copy_input_button('));
        self::assertStringContainsString("__t('vm_edit.copy_configured_ip'", $interfaces);
        self::assertStringContainsString("__t('vm_edit.copy_mac'", $interfaces);

        self::assertStringContainsString('portal_copy_value((string) $job[\'id\']', $this->source('portal/deploy_log.php'));
    }

    public function testBrowserReadsCurrentFieldsAndHasARealFailureBranch(): void
    {
        $core = $this->source('portal/assets/core.js');
        self::assertStringContainsString('document.getElementById(sourceId)', $core);
        self::assertStringContainsString("typeof source.value === 'string' ? source.value", $core);
        self::assertStringContainsString("button.hidden = currentValue(button) === ''", $core);
        self::assertStringContainsString('source.disabled', $core);
        self::assertStringContainsString("mode.getAttribute('data-mode-select')", $core);
        self::assertStringContainsString('navigator.clipboard.writeText(value).then(', $core);
        self::assertStringContainsString('announce(button, failed, false)', $core);
    }

    public function testOverviewHelpOwnsTheFallbackExplanation(): void
    {
        self::assertStringContainsString("'help-copying-values' => 'overview'", $this->source('lib/help_page.php'));
        self::assertStringContainsString('id="help-copying-values"', $this->source('lib/help/overview.php'));
    }
}
