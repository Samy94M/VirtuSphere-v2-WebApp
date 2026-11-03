<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/system_status_page.php';

final class SystemStatusActionInputTest extends TestCase
{
    /** @return iterable<string,array{mixed}> */
    public static function malformedTargets(): iterable
    {
        yield 'array' => [[1]];
        yield 'boolean' => [true];
        yield 'float' => [1.0];
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'suffix' => ['12abc'];
        yield 'overflow' => [str_repeat('9', 80)];
    }

    #[DataProvider('malformedTargets')]
    public function testMalformedSingleTargetCanNeverBecomeBulk(mixed $value): void
    {
        $target = system_status_target_id(['credential_id' => $value], 'credential_id');
        self::assertTrue($target['present']);
        self::assertFalse($target['valid']);
        self::assertSame(0, $target['id']);
    }

    public function testOmittedTargetIsTheOnlyBulkSelector(): void
    {
        self::assertSame(
            ['present' => false, 'valid' => true, 'id' => 0],
            system_status_target_id([], 'credential_id')
        );
        self::assertSame(
            ['present' => true, 'valid' => true, 'id' => 12],
            system_status_target_id(['credential_id' => '12'], 'credential_id')
        );
    }

    public function testVlanValidationPreservesExactRawIdentity(): void
    {
        self::assertSame([], system_status_validate_vlan_reassign(' VLAN 700 ', 'Target', ['Target']));
        self::assertSame([], system_status_validate_vlan_reassign('VLAN 700', 'Target', ['Target']));
        self::assertNotSame(
            system_status_validate_vlan_reassign(' VLAN 700 ', 'Target', ['Target']),
            system_status_validate_vlan_reassign(' VLAN 700 ', ' Target ', ['Target'])
        );
    }
}
