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

        $vm = ['disks' => [
            ['disk_name' => 'System', 'disk_size' => '80', 'disk_type' => 'THIN'],
            ['disk_name' => ' ', 'disk_size' => '0', 'disk_type' => ''],
        ]];
        self::assertSame([
            ['name' => 'System', 'size_gb' => 80, 'type' => 'thin'],
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
        self::assertSame(['position' => 7, 'scsi_unit' => 8, 'size_gb' => 8, 'volume_label' => 'Disk 8'], $layout[7]);
        self::assertSame(['position' => 15, 'scsi_unit' => null, 'size_gb' => 16, 'volume_label' => 'Disk 16'], $layout[15]);
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
        self::assertGreaterThan(20, count($vectors['labels']));
        self::assertGreaterThan(8, count($vectors['notices']));
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
