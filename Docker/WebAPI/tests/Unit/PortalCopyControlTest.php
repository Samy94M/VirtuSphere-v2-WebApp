<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/copy_control.php';

final class PortalCopyControlTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testStaticValueIsEscapedExactlyAndRemainsSelectable(): void
    {
        Lang::load('en');
        $value = 'Ä <&" 😀';
        $html = portal_copy_value($value, 'VM identity', true);

        self::assertStringContainsString('<code class="copy-value">' . h($value) . '</code>', $html);
        self::assertStringContainsString('data-copy-value="' . h($value) . '"', $html);
        self::assertStringContainsString('aria-label="Copy VM identity"', $html);
        self::assertStringContainsString('type="button"', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    public function testFormButtonNamesOnlyItsSourceAndNeverCachesTheValue(): void
    {
        Lang::load('en');
        $html = portal_copy_input_button('form-vm-edit-vm-name', 'current-value', 'VM name in ESXi');

        self::assertStringContainsString('data-copy-source="form-vm-edit-vm-name"', $html);
        self::assertStringNotContainsString('data-copy-value=', $html);
        self::assertStringNotContainsString('current-value', $html);
    }

    public function testEmptyStaticValueHasNoCopyAction(): void
    {
        self::assertStringNotContainsString('button', portal_copy_value('', 'anything'));
        self::assertStringContainsString('hidden', portal_copy_input_button('field-id', '', 'field'));
    }

    public function testLocalLinkKeepsANavigableFallbackAndNeverEmbedsARequestHost(): void
    {
        Lang::load('en');
        $html = portal_copy_local_link('package_run.php?mission_id=3&vm_id=7&run_id=abc', 'Link to this attempt');

        self::assertStringContainsString('href="package_run.php?mission_id=3&amp;vm_id=7&amp;run_id=abc"', $html);
        self::assertStringContainsString('data-copy-link="package_run.php?mission_id=3&amp;vm_id=7&amp;run_id=abc"', $html);
        self::assertStringNotContainsString('http://', $html);
        $this->expectException(InvalidArgumentException::class);
        portal_copy_local_link('https://outside.example/run', 'Outside');
    }
}
