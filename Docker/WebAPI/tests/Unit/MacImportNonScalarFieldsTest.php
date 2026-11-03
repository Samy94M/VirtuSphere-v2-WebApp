<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mac_import.php';

/**
 * MA-01: a non-scalar value where a text field of a result entry belongs is read
 * exactly like the absent field. Before, the cast of an array was a warning, which
 * the error handler promoted to an exception, so the callback answered 500 and the
 * Ansible sender retried it. Each reader below is pure, so this runs without a database.
 */
final class MacImportNonScalarFieldsTest extends TestCase
{
    public function testAnArrayOrObjectFieldIsAbsentAndAScalarKeepsItsLegacyCast(): void
    {
        self::assertNull(mac_import_text_field(['a' => ['x']], 'a'));
        self::assertNull(mac_import_text_field(['a' => null], 'a'));
        self::assertNull(mac_import_text_field([], 'a'));
        self::assertSame('12', mac_import_text_field(['a' => 12], 'a'), 'a numeric VM name that is valid today still matches');
        self::assertSame('', mac_import_text_field(['a' => false], 'a'));
        self::assertSame('WDS', mac_import_text_field(['a' => 'WDS'], 'a'));
    }

    public function testAnArrayNameFallsThroughToTheItemNameAsIfAbsent(): void
    {
        $item = ['vm_name' => ['not', 'a', 'name'], 'name' => 'ITEM-NAME'];
        $resolved = mac_import_text_field($item, 'vm_name') ?? mac_import_text_field($item, 'name') ?? '';

        self::assertSame('ITEM-NAME', $resolved);
    }

    public function testAnArrayMacOrSummaryIsTheSameNicAsAnAbsentOne(): void
    {
        $absent = mac_import_instance_nics(['hw_eth0' => []]);
        $arrays = mac_import_instance_nics(['hw_eth0' => ['macaddress' => ['00:50:56:AA:BB:CC'], 'summary' => ['WDS']]]);

        self::assertSame($absent, $arrays);
        self::assertSame([['vlan' => '', 'mac' => '', 'normalized_mac' => null]], $arrays);
    }

    public function testAnArrayIdentityFieldIsTheSameAsAnAbsentOne(): void
    {
        self::assertSame(
            mac_import_extract_identity([]),
            mac_import_extract_identity(['moid' => ['vm-1'], 'instance_uuid' => ['1234']])
        );
        self::assertSame(
            ['moid' => '', 'instance_uuid' => '5f1c1d2e-0000-4000-8000-000000000001'],
            mac_import_extract_identity(['moid' => ['vm-1'], 'instance_uuid' => ' 5f1c1d2e-0000-4000-8000-000000000001 '])
        );
    }

    public function testTheSemanticFingerprintOfAnArrayResultIsTheFingerprintOfTheAbsentOne(): void
    {
        $absent = [['instance' => ['hw_name' => 'VM-1', 'hw_eth0' => []]]];
        $arrays = [[
            'instance' => [
                'hw_name' => 'VM-1',
                'moid' => ['vm-1'],
                'instance_uuid' => ['x'],
                'hw_eth0' => ['macaddress' => ['00:50:56:AA:BB:CC'], 'summary' => ['WDS']],
            ],
        ]];

        self::assertSame(
            mac_import_callback_fingerprint(1, 2, $absent, [7]),
            mac_import_callback_fingerprint(1, 2, $arrays, [7]),
            'a replay must compare equal to the first delivery, or a retry is refused as a conflict'
        );
    }
}
