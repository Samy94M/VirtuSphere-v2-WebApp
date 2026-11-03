<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * The canonical reading of an uploaded transfer document.
 *
 * Every case here was silent before: a cast never fails, so `interfaces: "oops"`
 * counted as one interface and produced no finding, `disks: "oops"` counted as
 * one disk and wrote none, a package reference without a name vanished, and an
 * extra `interfaces[*].mac` blocked a preview over a value the import never
 * carries. Shape is decided once, here, with no database in reach - which is why
 * these are unit tests and why every edge is cheap enough to pin.
 *
 * The error handler is armed per test with the project's own semantics (any
 * reported diagnostic becomes an ErrorException) rather than through
 * virtusphere_install_error_handlers(), which installs process-wide and cannot
 * be restored: an "Array to string conversion" anywhere in the analysis must
 * fail THIS test, not leak into the rest of the suite.
 */
final class MissionTransferDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION,
            'exported_at' => '2026-08-20T10:00:00+02:00',
            'mission' => [
                'mission_name' => 'unit_doc',
                'mission_status' => '',
                'mission_notes' => 'note',
                'wds_vlan' => 'VLAN10',
                'hypervisor_datastorage' => 'ds1',
                'hypervisor_datacenter' => 'DC1',
                'domain' => 'dc.example.com',
            ],
            'vms' => [$this->vm()],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function vm(array $overrides = []): array
    {
        return array_merge([
            'vm_name' => 'UNITDOC1',
            'vm_hostname' => 'UNITDOC1',
            'vm_os' => 'Windows Server 2019',
            'vm_domain' => 'dc.example.com',
            'interfaces' => [['ip' => '', 'subnet' => '', 'gateway' => '', 'vlan' => 'VLAN10', 'mode' => 'dhcp', 'type' => 'vmxnet3']],
            'disks' => [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            'packages' => [['name' => 'pkg', 'version' => '1.0']],
        ], $overrides);
    }

    public function testAWellFormedDocumentKeepsItsCountsAndValues(): void
    {
        $analysis = mission_transfer_document_analyze($this->document());

        self::assertSame('', $analysis['document_error']);
        self::assertSame(['vms' => 1, 'interfaces' => 1, 'disks' => 1, 'packages' => 1], $analysis['counts']);
        self::assertSame([], $analysis['mission_shape_errors']);
        self::assertSame([], $analysis['vm_shape_errors']);
        self::assertSame('unit_doc', $analysis['suggested_name']);
        self::assertSame('2026-08-20T10:00:00+02:00', $analysis['exported_at']);
        self::assertSame('VLAN10', $analysis['mission']['wds_vlan']);
        self::assertSame('UNITDOC1', $analysis['vms'][0]['vm_name']);
        self::assertSame('VLAN10', $analysis['vms'][0]['interfaces'][0]['vlan']);
        self::assertSame('40', $analysis['vms'][0]['disks'][0]['disk_size']);
        self::assertSame([['name' => 'pkg', 'version' => '1.0']], $analysis['vms'][0]['packages']);
    }

    /**
     * mission_status rides along in every export and the import always replaces
     * it with the default, so its value in the file is neither validated nor
     * written - and it must not be able to block a preview.
     */
    public function testMissionStatusFromTheFileIsNeitherProjectedNorReported(): void
    {
        $document = $this->document();
        $document['mission']['mission_status'] = ['whatever'];

        $analysis = mission_transfer_document_analyze($document);

        self::assertSame([], $analysis['mission_shape_errors']);
        self::assertArrayNotHasKey('mission_status', $analysis['mission']);
    }

    /**
     * The transfer format has no MAC, so a MAC in the file is an unknown field:
     * it can neither be written nor produce a finding. An invalid one used to
     * block the preview through repo_validate_interfaces(), over a value the
     * write deliberately drops.
     *
     * @return array<string, array{0: string}>
     */
    public static function macValues(): array
    {
        return ['valid' => ['00:11:22:33:44:55'], 'invalid' => ['not-a-mac']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('macValues')]
    public function testAMacInTheFileIsIgnoredEntirely(string $mac): void
    {
        $vm = $this->vm();
        $vm['interfaces'][0]['mac'] = $mac;
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertSame([], $analysis['vm_shape_errors']);
        self::assertArrayNotHasKey('mac', $analysis['vms'][0]['interfaces'][0]);
        self::assertSame(1, $analysis['counts']['interfaces']);
    }

    /**
     * A scalar where a list belongs counted as ONE entry through `(array)`. It
     * now counts as none and says where it sits.
     *
     * @return array<string, array{0: string}>
     */
    public static function listContainers(): array
    {
        return ['interfaces' => ['interfaces'], 'disks' => ['disks'], 'packages' => ['packages']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('listContainers')]
    public function testAScalarInsteadOfAListCountsAsNothingAndIsReported(string $container): void
    {
        $vm = $this->vm([$container => 'oops']);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertSame(0, $analysis['counts'][$container]);
        self::assertSame([], $analysis['vms'][0][$container]);
        self::assertCount(1, $analysis['vm_shape_errors']);
        self::assertStringContainsString($container, $analysis['vm_shape_errors'][0]);
        self::assertStringStartsWith('UNITDOC1: ', $analysis['vm_shape_errors'][0]);
    }

    /** A JSON object is not a list, and must not be read as one. */
    #[\PHPUnit\Framework\Attributes\DataProvider('listContainers')]
    public function testAnObjectIsNotAcceptedAsAList(string $container): void
    {
        $vm = $this->vm([$container => ['first' => ['name' => 'pkg']]]);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertSame(0, $analysis['counts'][$container]);
        self::assertNotSame([], $analysis['vm_shape_errors']);
    }

    public function testANonArrayEntryInAListIsReportedWithItsPosition(): void
    {
        $vm = $this->vm(['interfaces' => [
            ['vlan' => 'VLAN10', 'mode' => 'dhcp'],
            'oops',
        ]]);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertSame(1, $analysis['counts']['interfaces']);
        self::assertCount(1, $analysis['vm_shape_errors']);
        self::assertStringContainsString('2', $analysis['vm_shape_errors'][0]);
    }

    /** A package reference with no name used to be dropped without a word. */
    public function testAPackageReferenceWithoutANameBlocksInsteadOfVanishing(): void
    {
        $vm = $this->vm(['packages' => [['name' => '', 'version' => '1.0']]]);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertSame(0, $analysis['counts']['packages']);
        self::assertSame([], $analysis['vms'][0]['packages']);
        self::assertCount(1, $analysis['vm_shape_errors']);
    }

    public function testANonScalarPackageNameIsReportedOnceNotTwice(): void
    {
        $vm = $this->vm(['packages' => [['name' => ['pkg'], 'version' => '1.0']]]);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));

        self::assertCount(1, $analysis['vm_shape_errors']);
        self::assertSame(0, $analysis['counts']['packages']);
    }

    /**
     * A VM entry that is not an object at all: reported by position, skipped,
     * and the rest of the file still analysed. Nothing throws, and nothing
     * produces an "Array to string conversion".
     */
    public function testAScalarVmEntryIsReportedByPositionAndSkipped(): void
    {
        $analysis = mission_transfer_document_analyze($this->document(['vms' => ['oops', $this->vm()]]));

        self::assertSame(1, $analysis['counts']['vms']);
        self::assertCount(1, $analysis['vm_shape_errors']);
        self::assertStringContainsString('1', $analysis['vm_shape_errors'][0]);
        self::assertSame('UNITDOC1', $analysis['vms'][0]['vm_name']);
    }

    /**
     * A known field holding an array: reported, and never cast into the literal
     * string "Array" that a later validator would then happily accept.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function knownFields(): array
    {
        return [
            'mission field' => ['mission', 'domain'],
            'vm field' => ['vm', 'vm_os'],
            'interface field' => ['interface', 'vlan'],
            'disk field' => ['disk', 'disk_name'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownFields')]
    public function testANonScalarKnownFieldIsReportedAndNeverCast(string $where, string $field): void
    {
        $document = $this->document();
        $vm = $this->vm();
        if ($where === 'mission') {
            $document['mission'][$field] = ['x'];
        } elseif ($where === 'vm') {
            $vm[$field] = ['x'];
        } elseif ($where === 'interface') {
            $vm['interfaces'][0][$field] = ['x'];
        } else {
            $vm['disks'][0][$field] = ['x'];
        }
        $document['vms'] = [$vm];

        $analysis = mission_transfer_document_analyze($document);

        $errors = $where === 'mission' ? $analysis['mission_shape_errors'] : $analysis['vm_shape_errors'];
        self::assertCount(1, $errors);
        self::assertStringContainsString($field, $errors[0]);
        self::assertStringNotContainsString('Array', $errors[0]);

        $projected = $where === 'mission'
            ? ($analysis['mission'][$field] ?? '')
            : ($where === 'vm'
                ? ($analysis['vms'][0]['fields'][$field] ?? '')
                : ($analysis['vms'][0][$where === 'interface' ? 'interfaces' : 'disks'][0][$field] ?? ''));
        self::assertSame('', $projected);
    }

    /** Forward compatibility: a field this version does not know does nothing. */
    public function testUnknownFieldsHaveNoEffect(): void
    {
        $vm = $this->vm(['id' => 42, 'mecm_id' => 'X', 'vm_status' => '5/5 OS Installed', 'future_field' => ['a']]);
        $vm['interfaces'][0]['id'] = 7;
        $document = $this->document(['vms' => [$vm]]);
        $document['mission']['id'] = 9;
        $document['unknown_top_level'] = ['x'];

        $analysis = mission_transfer_document_analyze($document);

        self::assertSame([], $analysis['mission_shape_errors']);
        self::assertSame([], $analysis['vm_shape_errors']);
        self::assertArrayNotHasKey('id', $analysis['vms'][0]['fields']);
        self::assertArrayNotHasKey('id', $analysis['vms'][0]['interfaces'][0]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableTimestamps(): array
    {
        return [
            'empty' => [''],
            'not a date' => ['oops'],
            'not scalar' => [['2026-08-20']],
            'boolean' => [true],
            'impossible date' => ['2026-13-45T00:00:00+02:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableTimestamps')]
    public function testAnUnusableExportedAtIsEmptiedAndNeverBlocks(mixed $value): void
    {
        $analysis = mission_transfer_document_analyze($this->document(['exported_at' => $value]));

        self::assertSame('', $analysis['exported_at']);
        self::assertSame('', $analysis['document_error']);
        self::assertSame([], $analysis['mission_shape_errors']);
        self::assertSame([], $analysis['vm_shape_errors']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unreadableDocuments(): array
    {
        return [
            'wrong version' => [['format_version' => 99, 'mission' => [], 'vms' => []]],
            'missing version' => [['mission' => [], 'vms' => []]],
            'mission not an array' => [['format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION, 'mission' => 'oops', 'vms' => []]],
            'vms missing' => [['format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION, 'mission' => []]],
            'vms not a list' => [['format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION, 'mission' => [], 'vms' => ['a' => []]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableDocuments')]
    public function testAnUnreadableDocumentReportsALocalizedReason(array $payload): void
    {
        $analysis = mission_transfer_document_analyze($payload);

        self::assertNotSame('', $analysis['document_error']);
        // Localized prose out of the catalog, never an English RuntimeException
        // message leaking into the portal.
        self::assertNotSame('missions.import_err_version', $analysis['document_error']);
        self::assertNotSame('missions.import_err_structure', $analysis['document_error']);
    }

    /**
     * A broken mission_name is only a missing suggestion for the editable field,
     * never a finding: the operator types the target name anyway.
     */
    public function testABrokenMissionNameOnlyEmptiesTheSuggestion(): void
    {
        $document = $this->document();
        $document['mission']['mission_name'] = ['x'];

        $analysis = mission_transfer_document_analyze($document);

        self::assertSame('', $analysis['suggested_name']);
        self::assertSame([], $analysis['mission_shape_errors']);
    }

    /**
     * Every message this module can produce exists in both locales with the same
     * placeholders. The keys are listed here because they are produced from
     * literals in the module and a grep for them is exactly what a rename breaks.
     */
    public function testEveryShapeMessageExistsInBothLocalesWithEqualPlaceholders(): void
    {
        $root = dirname(__DIR__, 2);
        $keys = [
            'mission_import_list_required',
            'mission_import_entry_invalid',
            'mission_import_vm_entry_invalid',
            'mission_import_scalar_required',
            'mission_import_package_name_required',
        ];
        $de = require $root . '/lang/de/validate.php';
        $en = require $root . '/lang/en/validate.php';

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $de, 'validate.' . $key . ' is missing in the German catalog');
            self::assertArrayHasKey($key, $en, 'validate.' . $key . ' is missing in the English catalog');
            preg_match_all('/:[a-z_]+/', (string) $de[$key], $dePlaceholders);
            preg_match_all('/:[a-z_]+/', (string) $en[$key], $enPlaceholders);
            sort($dePlaceholders[0]);
            sort($enPlaceholders[0]);
            self::assertSame($dePlaceholders[0], $enPlaceholders[0], 'placeholders differ for validate.' . $key);
        }

        // A message actually rendered by the module, so a key that exists but is
        // never reached cannot make this green.
        $vm = $this->vm(['interfaces' => 'oops']);
        $analysis = mission_transfer_document_analyze($this->document(['vms' => [$vm]]));
        self::assertStringContainsString('interfaces', $analysis['vm_shape_errors'][0]);
        self::assertStringNotContainsString(':field', $analysis['vm_shape_errors'][0]);
    }
}
