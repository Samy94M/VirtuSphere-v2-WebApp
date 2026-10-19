<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_mecm_save.php';

/**
 * AV-R12: the decision of one registered VM's save, without a database. Each row
 * is one combination of the four facts the save decides from.
 */
final class VmMecmSaveDecisionTest extends TestCase
{
    /** @return iterable<string, array{bool, string, bool, bool}> pending, mode, deviates, queued */
    public static function decisions(): iterable
    {
        yield 'nothing pending sends nothing, even on the transfer button' => [false, VIRTUSPHERE_VM_MECM_SAVE_TRANSFER, false, false];
        yield 'a pending plan is sent by a plain save' => [true, '', false, true];
        yield 'an OS deviation holds back a plain save' => [true, '', true, false];
        yield 'an OS deviation holds back the portal-only choice' => [true, VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY, true, false];
        yield 'an OS deviation sends with the transfer button' => [true, VIRTUSPHERE_VM_MECM_SAVE_TRANSFER, true, true];
        yield 'the transfer button without a deviation is the plain save' => [true, VIRTUSPHERE_VM_MECM_SAVE_TRANSFER, false, true];
        yield 'portal-only without a deviation is the plain save' => [true, VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY, false, true];
    }

    #[DataProvider('decisions')]
    public function testTheDecisionTable(bool $pending, string $mode, bool $deviates, bool $queued): void
    {
        self::assertSame($queued, vm_mecm_save_queues($pending, $mode, $deviates));
    }

    public function testAnUnknownMecmOsDeviatesAndAnEqualOneDoesNot(): void
    {
        self::assertTrue(vm_mecm_os_deviates('Win11', ''), 'no OS rule is not a known match');
        self::assertFalse(vm_mecm_os_deviates(' Win11 ', 'Win11'));
        self::assertTrue(vm_mecm_os_deviates('Win11', 'Win10'));
    }

    public function testTheOwnOsIsTheOsRuleOnly(): void
    {
        $rules = [
            ['collection_type' => VIRTUSPHERE_MECM_RULE_TYPE_PACKAGE, 'collection_name' => 'Firefox-115.0'],
            ['collection_type' => VIRTUSPHERE_MECM_RULE_TYPE_OS, 'collection_name' => 'Win10 Pro'],
        ];

        self::assertSame('Win10 Pro', vm_mecm_own_os($rules));
        self::assertSame('', vm_mecm_own_os([]));
    }

    public function testTheSaveModeIsAClosedSetAndEverythingElseIsThePlainSave(): void
    {
        self::assertSame(VIRTUSPHERE_VM_MECM_SAVE_TRANSFER, vm_mecm_save_mode(['mecm_save' => 'transfer']));
        self::assertSame(VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY, vm_mecm_save_mode(['mecm_save' => 'portal_only']));
        self::assertSame('', vm_mecm_save_mode(['mecm_save' => 'queue_everything']));
        self::assertSame('', vm_mecm_save_mode([]));
    }
}
