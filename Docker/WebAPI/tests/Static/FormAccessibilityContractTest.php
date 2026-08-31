<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormAccessibilityContractTest extends TestCase
{
    /** @return list<string> */
    private function migratedOwners(): array
    {
        $root = dirname(__DIR__, 2);

        return array_map(
            static fn (string $path): string => $root . '/' . $path,
            [
                'lib/credentials_panels.php',
                'lib/deploy_queue_panel.php',
                'lib/settings/catalog_panel.php',
                'lib/settings/deploy_panel.php',
                'lib/settings/https_panel.php',
                'lib/settings/machine_api_panel.php',
                'lib/settings/system_panel.php',
                'lib/system_status_esxi_panels.php',
                'lib/users_accounts_panels.php',
                'lib/users_directory_panels.php',
                'lib/vm_edit_panels.php',
                'lib/vm_edit_rows.php',
                'portal/account.php',
                'portal/mission_details.php',
                'portal/missions.php',
            ]
        );
    }

    public function testLegacyClassOnlyHelperIsGoneFromThePortal(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/settings/*.php') ?: [],
            glob($root . '/portal/*.php') ?: []
        );
        foreach ($files as $file) {
            self::assertStringNotContainsString('form_input_class(', (string) file_get_contents($file), $file);
        }
    }

    public function testMigratedOwnersUseTheCommonAttributeApi(): void
    {
        foreach ($this->migratedOwners() as $file) {
            self::assertFileExists($file);
            $source = (string) file_get_contents($file);
            self::assertStringContainsString('form_control_attrs(', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\saria-describedby\s*=\s*["\']/', $source, $file);
            self::assertDoesNotMatchRegularExpression('/class=["\']field-error["\'](?!\s+id=)/', $source, $file);
        }
    }

    public function testRepeatRowTemplateUsesOneMarkerForNamesAndGeneratedIds(): void
    {
        $root = dirname(__DIR__, 2);
        $rows = (string) file_get_contents($root . '/lib/vm_edit_rows.php');
        $js = (string) file_get_contents($root . '/portal/assets/forms.js');

        self::assertStringContainsString('$scope = $template ? \'__INDEX__\' : $index;', $rows);
        self::assertStringContainsString("replaceAll('__INDEX__', nextRepeatIndex())", $js);
        self::assertStringContainsString('Math.max(Date.now(), lastRepeatIndex + 1)', $js);
    }
}
