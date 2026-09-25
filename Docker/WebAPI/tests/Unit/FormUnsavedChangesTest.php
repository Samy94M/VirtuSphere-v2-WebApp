<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormUnsavedChangesTest extends TestCase
{
    public function testAttributesCarryOnlyTheClosedLocalizedContract(): void
    {
        $attrs = form_unsaved_attrs('vm editor');

        self::assertStringContainsString(' data-unsaved-form', $attrs);
        self::assertStringContainsString(' data-unsaved-key="vm-editor"', $attrs);
        self::assertStringContainsString(
            ' data-unsaved-clean-label="' . h(__t('common.unsaved_clean')) . '"',
            $attrs
        );
        self::assertStringContainsString(
            ' data-unsaved-dirty-label="' . h(__t('common.unsaved_dirty')) . '"',
            $attrs
        );
        self::assertStringNotContainsString('data-unsaved-restored', $attrs);
    }

    public function testRestoredPostIsMarkedUnresolved(): void
    {
        self::assertStringContainsString(
            ' data-unsaved-restored',
            form_unsaved_attrs('mission-settings', true)
        );
    }

    public function testStatusStartsHiddenWithoutJavascript(): void
    {
        self::assertSame(
            '<p class="muted" data-unsaved-status role="status" aria-live="polite" hidden></p>',
            form_unsaved_status_html()
        );
    }
}
