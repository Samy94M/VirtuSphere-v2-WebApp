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
                'lib/logs_filter_form.php',
                'lib/missions_import_panel.php',
                'lib/settings/catalog_panel.php',
                'lib/settings/deploy_panel.php',
                'lib/settings/https_panel.php',
                'lib/settings/machine_api_panel.php',
                'lib/settings/system_panel.php',
                'lib/system_status_esxi_panels.php',
                'lib/users_accounts_panels.php',
                'lib/users_directory_panels.php',
                'lib/vm_edit_names.php',
                'lib/vm_edit_panels.php',
                'lib/vm_edit_rows.php',
                'portal/account.php',
                'portal/mission_details.php',
                'portal/missions.php',
            ]
        );
    }

    /**
     * Every PHP file that can render portal markup, derived and never listed.
     *
     * The named owner list above is the positive net: those renderers must keep
     * using the API. It cannot be the negative one, because a file written after
     * Etappe 14 is by definition not on it. That is not hypothetical: the mission
     * import preview kept two hand-written `.field-error` spans on a control with
     * no id, no `aria-invalid` and no `aria-describedby` for five weeks, and the
     * only guard that would have seen it walked fifteen paths.
     *
     * @return list<string>
     */
    private function portalRenderers(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: [],
            glob($root . '/portal/*.php') ?: []
        );
        sort($files);

        return $files;
    }

    public function testLegacyClassOnlyHelperIsGoneFromThePortal(): void
    {
        $files = $this->portalRenderers();
        self::assertNotSame([], $files, 'The portal renderer glob matched nothing.');

        foreach ($files as $file) {
            self::assertStringNotContainsString('form_input_class(', (string) file_get_contents($file), $file);
        }
    }

    public function testMigratedOwnersUseTheCommonAttributeApi(): void
    {
        $derived = $this->portalRenderers();

        foreach ($this->migratedOwners() as $file) {
            self::assertFileExists($file);
            // A renamed or moved owner would otherwise leave the positive net
            // silently green while nothing scans the file any more.
            self::assertContains(str_replace('\\', '/', $file), array_map(
                static fn (string $path): string => str_replace('\\', '/', $path),
                $derived
            ), $file);
            self::assertStringContainsString('form_control_attrs(', (string) file_get_contents($file), $file);
        }
    }

    public function testNoRendererWritesFieldSemanticsByHand(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        // `forms.php` writes both attributes because it IS the owner; scanning it
        // would report the rule as a violation of itself. The shared confirm
        // dialog points `aria-describedby` at its own static message element,
        // which is not a form control and carries no field error.
        $attributeOwner = $root . '/lib/forms.php';
        $describedByOwners = [$attributeOwner, $root . '/lib/layout_modals.php'];

        $files = $this->portalRenderers();
        self::assertNotSame([], $files, 'The portal renderer glob matched nothing.');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $path = str_replace('\\', '/', $file);

            if (!in_array($path, $describedByOwners, true)) {
                self::assertDoesNotMatchRegularExpression('/\saria-describedby\s*=\s*["\']/', $source, $path);
            }
            if ($path !== $attributeOwner) {
                self::assertDoesNotMatchRegularExpression('/\saria-invalid\s*=\s*["\']/', $source, $path);
            }
            self::assertDoesNotMatchRegularExpression('/class=["\']field-error["\'](?!\s+id=)/', $source, $path);
        }
    }

    public function testEveryFieldErrorRendererIsARegisteredOwner(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $registered = array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            $this->migratedOwners()
        );

        $unregistered = [];
        foreach ($this->portalRenderers() as $file) {
            $path = str_replace('\\', '/', $file);
            if ($path === $root . '/lib/forms.php') {
                // The API itself, not a consumer of it.
                continue;
            }
            $source = (string) file_get_contents($file);
            $rendersFieldError = str_contains($source, 'form_error_html(')
                || str_contains($source, 'form_error_id(');
            if ($rendersFieldError && !in_array($path, $registered, true)) {
                $unregistered[] = substr($path, strlen($root) + 1);
            }
        }

        // Membership is derived from what a file actually does, so a new form
        // owner has to be decided about instead of quietly joining the portal
        // outside every positive assertion.
        self::assertSame([], $unregistered, 'Renderers with a field error outside the owner list: ' . implode(', ', $unregistered));
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
