<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/vm_disk_layout.php';

/**
 * DN1: the disk rows, the SCSI unit per position and the Windows volume label
 * have one owner (lib/vm_disk_layout.php). The serverlist and getDeviceInfos
 * read the same rows, so a disk the client names is the disk the playbook
 * created at that position. Pure, no database.
 */
final class VmDiskLayoutTest extends TestCase
{
    public function testUnitsFollowThePositionAndSkipTheControllerUnit(): void
    {
        $units = array_map('vm_disk_scsi_unit', range(0, 15));

        self::assertSame([0, 1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13, 14, 15, null], $units);
        self::assertNull(vm_disk_scsi_unit(-1));
    }

    public function testLabelKeepsAnOrdinaryNameUnchanged(): void
    {
        self::assertSame('Daten', vm_disk_volume_label('Daten'));
        self::assertSame('Größe Übung', vm_disk_volume_label('Größe Übung'));
        self::assertSame(vm_disk_default_name(2), vm_disk_volume_label(vm_disk_default_name(2)));
    }

    public function testLabelReplacesForbiddenAndControlCharactersAndCollapsesSpace(): void
    {
        self::assertSame('Daten Logs', vm_disk_volume_label('Daten/Logs'));
        self::assertSame('a b c d e f g h i j', vm_disk_volume_label('a\\b:c*d?e"f<g>h|i/j'));
        self::assertSame('Log Archiv', vm_disk_volume_label("  Log\t\tArchiv \n"));
        self::assertSame('A B', vm_disk_volume_label("A\u{200B}B"));
        self::assertSame('Daten', vm_disk_volume_label("\u{202E}Daten"));
        self::assertSame('x y', vm_disk_volume_label("x\u{00A0}y"));
    }

    public function testLabelWithNothingUsableLeftIsNull(): void
    {
        self::assertNull(vm_disk_volume_label(''));
        self::assertNull(vm_disk_volume_label('   '));
        self::assertNull(vm_disk_volume_label('***???'));
        self::assertNull(vm_disk_volume_label("\xC3\x28"));
    }

    public function testLabelIsCutToThirtyTwoUtf16Units(): void
    {
        $max = VIRTUSPHERE_DISK_VOLUME_LABEL_MAX;
        self::assertSame(32, $max);

        self::assertSame(str_repeat('a', $max), vm_disk_volume_label(str_repeat('a', $max)));
        self::assertSame(str_repeat('a', $max), vm_disk_volume_label(str_repeat('a', $max + 1)));
        self::assertSame(str_repeat('ä', $max), vm_disk_volume_label(str_repeat('ä', $max + 5)));
        // A character outside the BMP is two UTF-16 units and is never split.
        self::assertSame(str_repeat('a', $max - 1), vm_disk_volume_label(str_repeat('a', $max - 1) . "\u{1F4BE}"));
        self::assertSame(str_repeat('a', $max - 2) . "\u{1F4BE}", vm_disk_volume_label(str_repeat('a', $max - 2) . "\u{1F4BE}x"));
        // The cut never leaves a trailing space.
        self::assertSame(str_repeat('a', $max - 1), vm_disk_volume_label(str_repeat('a', $max - 1) . ' bbb'));
    }

    public function testRowsDefaultLikeTheServerlistAndKeepTheirOrder(): void
    {
        self::assertSame([[
            'name' => vm_disk_default_name(1),
            'size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
            'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ]], vm_disk_rows([]));

        // DN2: the legacy default 'System' on the system disk reads as no name.
        $vm = ['disks' => [
            ['disk_name' => 'System', 'disk_size' => '80', 'disk_type' => 'THIN'],
            ['disk_name' => ' ', 'disk_size' => '0', 'disk_type' => ''],
        ]];
        self::assertSame([
            ['name' => '', 'size_gb' => 80, 'type' => 'thin'],
            ['name' => vm_disk_default_name(2), 'size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'], 'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type']],
        ], vm_disk_rows($vm));

        // The values the serverlist produced before DN1 for untidy rows.
        self::assertSame([
            ['size_gb' => 12, 'type' => 'thin'],
            ['size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'], 'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type']],
            ['size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'], 'type' => 'thick'],
        ], ansible_vm_disks(['disks' => [
            ['disk_size' => ' 12 ', 'disk_type' => ' THIN '],
            ['disk_size' => '', 'disk_type' => ''],
            ['disk_size' => 'abc', 'disk_type' => 'Thick'],
        ]]));

        // The playbook gets exactly size and type of the same rows.
        self::assertSame(
            array_map(static fn (array $row): array => ['size_gb' => $row['size_gb'], 'type' => $row['type']], vm_disk_rows($vm)),
            ansible_vm_disks($vm)
        );
    }

    public function testClientLayoutCarriesPositionUnitSizeAndLabel(): void
    {
        $disks = [];
        for ($i = 1; $i <= 16; $i++) {
            $disks[] = ['disk_name' => 'Disk ' . $i, 'disk_size' => $i, 'disk_type' => 'thin'];
        }
        $layout = vm_disk_client_layout(['disks' => $disks]);

        self::assertCount(16, $layout);
        // 'Disk 1' on the system disk is the auto pattern: no label, nothing to rename.
        self::assertSame(['position' => 0, 'scsi_unit' => 0, 'size_gb' => 1, 'volume_label' => null, 'label_custom' => false], $layout[0]);
        self::assertSame(['position' => 7, 'scsi_unit' => 8, 'size_gb' => 8, 'volume_label' => 'Disk 8', 'label_custom' => false], $layout[7]);
        self::assertSame(['position' => 15, 'scsi_unit' => null, 'size_gb' => 16, 'volume_label' => 'Disk 16', 'label_custom' => false], $layout[15]);
    }

    public function testClientLayoutMarksOnlyAnOwnNameAsCustom(): void
    {
        // DN2, F1: the client renames the Windows drive only on label_custom.
        $layout = static fn (string $system, string $data = 'Daten'): array => vm_disk_client_layout(['disks' => [
            ['disk_name' => $system, 'disk_size' => 60, 'disk_type' => 'thin'],
            ['disk_name' => $data, 'disk_size' => 20, 'disk_type' => 'thin'],
        ]]);

        foreach (['', '   ', 'System', 'Disk 2', 'Disk 9'] as $default) {
            $row = $layout($default)[0];
            self::assertFalse($row['label_custom'], $default);
            self::assertNull($row['volume_label'], $default);
        }
        self::assertSame(['position' => 0, 'scsi_unit' => 0, 'size_gb' => 60, 'volume_label' => 'Ölstand Prüfung', 'label_custom' => true], $layout('Ölstand Prüfung')[0]);
        self::assertSame(['volume_label' => 'system', 'label_custom' => true], array_intersect_key($layout('system')[0], ['volume_label' => 1, 'label_custom' => 1]));
        // An own name with nothing usable: custom, but no label to set.
        self::assertSame(['volume_label' => null, 'label_custom' => true], array_intersect_key($layout('???')[0], ['volume_label' => 1, 'label_custom' => 1]));

        // Data disks keep the DN1 behaviour; the flag says whether the name is the default.
        self::assertSame(['volume_label' => 'Daten', 'label_custom' => true], array_intersect_key($layout('', 'Daten')[1], ['volume_label' => 1, 'label_custom' => 1]));
        self::assertSame(['volume_label' => 'Disk 2', 'label_custom' => false], array_intersect_key($layout('', '')[1], ['volume_label' => 1, 'label_custom' => 1]));
        self::assertSame(['volume_label' => 'System', 'label_custom' => true], array_intersect_key($layout('', 'System')[1], ['volume_label' => 1, 'label_custom' => 1]));

        // volume_label stays a key on every entry (the client refuses a list without it).
        foreach ($layout('') as $row) {
            self::assertSame(['label_custom', 'position', 'scsi_unit', 'size_gb', 'volume_label'], self::sortedKeys($row));
        }
    }

    /** @return list<string> */
    private static function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    public function testStoredNameIsTheOneNormalisation(): void
    {
        // DN2: system disk default names are stored empty, data disks unchanged.
        self::assertSame('', vm_disk_stored_name('', 1));
        self::assertSame('', vm_disk_stored_name('  ', 1));
        self::assertSame('', vm_disk_stored_name('System', 1));
        self::assertSame('', vm_disk_stored_name(' Disk 2 ', 1));
        self::assertSame('Systemplatte', vm_disk_stored_name(' Systemplatte ', 1));
        self::assertSame('system', vm_disk_stored_name('system', 1));
        self::assertSame("\u{00A0}", vm_disk_stored_name("\u{00A0}", 1));
        self::assertSame('Disk 2', vm_disk_stored_name('', 2));
        self::assertSame('Disk 3', vm_disk_stored_name('   ', 3));
        self::assertSame('System', vm_disk_stored_name('System', 2));
        self::assertSame('Disk 5', vm_disk_stored_name('Disk 5', 2));
        self::assertSame('Daten', vm_disk_stored_name(' Daten ', 4));
        self::assertSame('', vm_disk_default_name(1));
        self::assertSame('Disk 2', vm_disk_default_name(2));
        self::assertSame('System', VIRTUSPHERE_VM_DEFAULTS['disk_name_legacy_default']);
    }

    public function testFormParserNeverDropsTheSystemDiskRow(): void
    {
        // DN2 (audit L6): an empty first row is the normal case now; dropping
        // it would move the data disk and its name up to the system disk.
        require_once dirname(__DIR__, 2) . '/lib/vm_edit_values.php';
        $parsed = vm_parse_disks([
            ['disk_name' => '', 'disk_size' => '', 'disk_type' => 'thin'],
            ['disk_name' => 'Daten', 'disk_size' => '20', 'disk_type' => 'thin'],
            ['disk_name' => '', 'disk_size' => '', 'disk_type' => 'thin'],
        ]);
        self::assertSame([
            ['disk_name' => '', 'disk_size' => 1, 'disk_type' => 'thin'],
            ['disk_name' => 'Daten', 'disk_size' => 20, 'disk_type' => 'thin'],
        ], $parsed);

        self::assertSame('', vm_parse_disks([['disk_name' => 'System', 'disk_size' => '60', 'disk_type' => 'thin']])[0]['disk_name']);
        self::assertSame('', vm_parse_disks([['disk_name' => 'Disk 2', 'disk_size' => '60', 'disk_type' => 'thin']])[0]['disk_name']);
        self::assertSame('Disk 2', vm_parse_disks([
            ['disk_name' => 'C', 'disk_size' => '60', 'disk_type' => 'thin'],
            ['disk_name' => '', 'disk_size' => '20', 'disk_type' => 'thin'],
        ])[1]['disk_name']);
        self::assertSame('', vm_default_disks()[0]['disk_name']);
    }

    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/disk-label-vectors.json');
        $vectors = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);

        return $vectors;
    }

    public function testSharedVectorsFollowThePhpConstants(): void
    {
        // NZ1: the JavaScript mirror reads the same file; its limit and default
        // names must be the ones the portal renders.
        $vectors = self::vectors();
        self::assertSame(VIRTUSPHERE_DISK_VOLUME_LABEL_MAX, $vectors['max']);
        self::assertSame(vm_disk_default_name(1), $vectors['defaults']['first']);
        self::assertSame(vm_disk_default_name(2), $vectors['defaults']['prefix'] . ' 2');
        self::assertSame(VIRTUSPHERE_VM_DEFAULTS['disk_name_legacy_default'], $vectors['defaults']['legacy_first']);
        self::assertGreaterThan(20, count($vectors['labels']));
        self::assertGreaterThan(8, count($vectors['notices']));
        self::assertGreaterThan(10, count($vectors['defaults_by_position']));
    }

    public function testDefaultNameRuleMatchesTheSharedVectors(): void
    {
        foreach (self::vectors()['defaults_by_position'] as $vector) {
            self::assertSame($vector['is_default'], vm_disk_name_is_default($vector['name'], $vector['position']), $vector['why']);
        }
    }

    public function testLabelRuleMatchesTheSharedVectors(): void
    {
        foreach (self::vectors()['labels'] as $vector) {
            self::assertSame($vector['label'], vm_disk_volume_label($vector['name']), $vector['why']);
        }
    }

    public function testEditorNoticeMatchesTheSharedVectors(): void
    {
        foreach (self::vectors()['notices'] as $vector) {
            self::assertSame(
                ['kind' => $vector['kind'], 'label' => $vector['label']],
                vm_disk_label_notice($vector['name'], $vector['position']),
                $vector['why']
            );
        }
    }
}
