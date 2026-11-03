<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_inventory_parse.php';

final class InventoryCaptureBoundTest extends TestCase
{
    public function testOverflowCannotBecomeAPartialInventoryObservation(): void
    {
        $buffer = str_repeat('x', VIRTUSPHERE_INVENTORY_OUTPUT_MAX_BYTES - 2);
        self::assertTrue(ansible_inventory_capture_chunk($buffer, 'ab'));
        self::assertSame(VIRTUSPHERE_INVENTORY_OUTPUT_MAX_BYTES, strlen($buffer));
        self::assertFalse(ansible_inventory_capture_chunk($buffer, 'c'));
        self::assertSame('ab', substr($buffer, -2));
    }
}
