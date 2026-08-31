<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormAccessibilityTest extends TestCase
{
    public function testIdsAreStableScopedAndTemplateSafe(): void
    {
        self::assertSame('form-row-7-12-user-name', form_element_id('Row 7', 'User Name', 12));
        self::assertSame('form-vm_edit-__INDEX__-disk_name', form_element_id('vm_edit', 'disk_name', '__INDEX__'));
        self::assertSame('form-field-field', form_element_id('***', '...'));
    }

    public function testValidControlWithStandardHintHasOnlyOwnedAttributes(): void
    {
        self::assertSame(
            ' id="form-settings-api_base_url" aria-describedby="form-settings-api_base_url-hint"',
            form_control_attrs('settings', 'api_base_url', null, true, '')
        );
    }

    public function testDirectErrorAndMultipleHintsShareOneDescriptionList(): void
    {
        $attrs = form_control_attrs(
            'vm_edit',
            'autostart_start_delay',
            null,
            ['first-hint', 'second-hint', 'first-hint', ''],
            'Too small'
        );

        self::assertSame(
            ' id="form-vm_edit-autostart_start_delay" class="is-invalid" aria-invalid="true"'
            . ' aria-describedby="first-hint second-hint form-vm_edit-autostart_start_delay-error"',
            $attrs
        );
        self::assertSame(
            '<span class="field-error" id="form-vm_edit-autostart_start_delay-error">Too small</span>',
            form_error_html('vm_edit', 'autostart_start_delay', null, 'Too small')
        );
    }

    public function testErrorTextIsEscapedAndEmptyErrorRendersNothing(): void
    {
        self::assertSame('', form_error_html('create', 'name', null, ''));
        self::assertSame(
            '<span class="field-error" id="form-create-name-error">&lt;b&gt;unsafe&lt;/b&gt;</span>',
            form_error_html('create', 'name', null, '<b>unsafe</b>')
        );
    }
}
