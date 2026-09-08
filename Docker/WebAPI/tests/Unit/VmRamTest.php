<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_edit_ram.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';

final class VmRamTest extends TestCase
{
    public function testExactBoundsRoundingAndLegacyPosts(): void
    {
        foreach ([['128', 'mb', 128], ['1048576', 'mb', 1048576], ['0.125', 'gb', 128], ['1024', 'gb', 1048576], ['1,3', 'gb', 1331], ['0.12548828125', 'gb', null], ['1.0004882812', 'gb', 1024], ['1.0004882813', 'gb', 1025], ['6', 'gb', 6144]] as [$raw, $unit, $expected]) {
            $parsed = vm_ram_parse_input($raw, $unit);
            self::assertSame($expected, $parsed['mb'] ?? null, $raw);
        }
        self::assertSame(6144, vm_edit_ram_from_post(['vm_ram' => '6144']));
        self::assertSame(6144, vm_edit_ram_from_post(['vm_ram' => '6', 'vm_ram_unit' => 'gb']));
        foreach (['', ' ', '.5', '1.', '+1', '-1', '1e3', 'NaN', 'INF', '1 000', '0.1249', '1024.0001', '9999999999999999', '0.12345678901'] as $raw) {
            self::assertFalse(vm_ram_parse_input($raw, 'gb')['ok'], $raw);
        }
        self::assertFalse(vm_ram_parse_input('128.0', 'mb')['ok']);
        self::assertFalse(vm_ram_parse_input([], 'mb')['ok']);
        self::assertFalse(vm_ram_parse_input('4096', [])['ok']);
        self::assertFalse(vm_ram_parse_input('4096', 'tb')['ok']);
    }

    public function testDisplayNeverInventsAUnitForInvalidLegacyValues(): void
    {
        foreach (['4096' => '4 GB', '1536' => '1.5 GB', '1331' => '1331 MB', '128' => '128 MB', 'bad' => 'bad', '0' => '0'] as $stored => $display) {
            self::assertSame($display, vm_ram_format((string) $stored));
        }
    }

    public function testPresentInvalidUnitDoesNotBecomeLegacyMb(): void
    {
        $this->expectException(ValidationException::class);
        vm_edit_ram_from_post(['vm_ram' => '4096', 'vm_ram_unit' => ['gb']]);
    }

    public function testOtherFieldErrorsKeepTheExactPostedRamAndUnit(): void
    {
        $post = $_POST;
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['vm_ram' => ' 1,3 ', 'vm_ram_unit' => 'gb'];
            ob_start();
            render_vm_ram_field(['vm_ram' => '4096'], true, ['vm_name' => 'invalid']);
            $html = (string) ob_get_clean();
            self::assertStringContainsString('value=" 1,3 "', $html);
            self::assertStringContainsString('value="gb" selected', $html);
            self::assertStringNotContainsString('value="4"', $html);
        } finally {
            $_POST = $post;
            if ($method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $method; }
        }
    }
}
