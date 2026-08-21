<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * Preview and write against the live DB, on the one thing the two used to
 * disagree about: the SHAPE of the uploaded document.
 *
 * The report counted a raw `(array)` cast while the transaction projected a
 * field list, so a count in the preview was not a promise about the write, and a
 * field the transfer format drops (a MAC) could block a preview it would never
 * reach. This file pins the two directions that broke: what the report counts is
 * what the transaction writes, and a document the report blocks writes nothing
 * at all.
 *
 * Cleans up its own rows by name prefix and preserves setup failure causes, like
 * the round-trip test next to it.
 */
final class MissionImportShapeContractTest extends TestCase
{
    private const PREFIX = 'phpunit_shape_';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    /**
     * A MAC in the file is not part of the transfer format. It must not block
     * the preview (it did: repo_validate_interfaces() saw the raw entry), and it
     * must not reach the database (it never did, because the write projected).
     * Both directions now come from ONE projection.
     */
    public function testAForeignMacNeitherBlocksThePreviewNorIsStored(): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHP1', [
            'interfaces' => [$this->interface(['mac' => 'not-a-mac'])],
        ])]);

        $dryRun = mission_import($this->db, $payload, self::PREFIX . 'mac', true);
        self::assertFalse($dryRun['blocked'], 'a MAC the import discards blocked the preview');
        self::assertSame([], $dryRun['vm_field_errors']);
        self::assertSame(1, $dryRun['counts']['interfaces']);

        $written = mission_import($this->db, $payload, self::PREFIX . 'mac', false, 1);
        self::assertTrue($written['imported']);
        $vms = getVMs($this->db, (int) $written['mission_id']);
        self::assertSame('', (string) $vms[0]['interfaces'][0]['mac']);
    }

    /**
     * The core promise: for a report that is not blocked, every count is exactly
     * the number of rows the transaction writes.
     */
    public function testAnUnblockedReportWritesExactlyItsCounts(): void
    {
        $payload = $this->payload([
            $this->vm('PHPUNITSHP2', [
                'interfaces' => [$this->interface(), $this->interface(['ip' => ''])],
                'disks' => [$this->disk('System'), $this->disk('Data')],
            ]),
            $this->vm('PHPUNITSHP3'),
        ]);

        $report = mission_import($this->db, $payload, self::PREFIX . 'counts', true);
        self::assertFalse($report['blocked']);
        self::assertSame(['vms' => 2, 'interfaces' => 3, 'disks' => 3, 'packages' => 0], $report['counts']);

        $written = mission_import($this->db, $payload, self::PREFIX . 'counts', false, 1);
        $vms = getVMs($this->db, (int) $written['mission_id']);
        $interfaces = 0;
        $disks = 0;
        foreach ($vms as $vm) {
            $interfaces += count($vm['interfaces']);
            $disks += count($vm['disks']);
        }
        self::assertSame($report['counts']['vms'], count($vms));
        self::assertSame($report['counts']['interfaces'], $interfaces);
        self::assertSame($report['counts']['disks'], $disks);
    }

    /**
     * Every broken container shape: blocked in the report, blocked in the file
     * (so the button is disabled), and NOTHING written when the write is asked
     * for anyway. The last part is what a "belt and suspenders" refusal is worth
     * only if the transaction really leaves no half mission behind.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function brokenShapes(): array
    {
        return [
            'interfaces is a string' => [['interfaces' => 'oops']],
            'disks is a string' => [['disks' => 'oops']],
            'packages is a string' => [['packages' => 'oops']],
            'interfaces is an object' => [['interfaces' => ['first' => ['vlan' => 'x']]]],
            'an interface entry is a string' => [['interfaces' => ['oops']]],
            'a package has no name' => [['packages' => [['name' => '', 'version' => '1.0']]]],
            'a known field is an array' => [['vm_os' => ['Windows']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenShapes')]
    public function testABrokenShapeBlocksAndWritesNothing(array $overrides): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHP4', $overrides)]);
        $target = self::PREFIX . 'broken';

        $report = mission_import($this->db, $payload, $target, true);
        self::assertTrue($report['blocked'], 'a broken container shape did not block');
        self::assertTrue($report['blocked_in_file'], 'a file problem must disable the confirm button');
        self::assertNotSame([], $report['vm_field_errors']);

        try {
            mission_import($this->db, $payload, $target, false, 1);
            self::fail('the write accepted a blocked report');
        } catch (MissionTransferBlockedException) {
            // Expected: the refusal is typed, so the portal can keep the hand-off.
        }
        self::assertFalse(repo_mission_name_exists($this->db, $target), 'a refused import left a mission row behind');
    }

    /** A VM entry that is not an object at all used to abort the whole preview. */
    public function testAScalarVmEntryIsReportedInsteadOfAbortingThePreview(): void
    {
        $payload = $this->payload(['oops', $this->vm('PHPUNITSHP5')]);

        $report = mission_import($this->db, $payload, self::PREFIX . 'scalarvm', true);

        self::assertTrue($report['blocked_in_file']);
        self::assertSame(1, $report['counts']['vms'], 'the readable VM still has to be counted');
        self::assertNotSame([], $report['vm_field_errors']);
    }

    /**
     * A name repeated inside the file that ALSO exists globally is two different
     * findings, and the global one must be listed once: the repetition is what
     * the duplicate message is for, and a second identical conflict link reads
     * as a second foreign mission.
     */
    public function testAnIntraFileDuplicateDoesNotDoubleTheGlobalConflict(): void
    {
        $foreignId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'foreign',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($foreignId, 'PHPUNITSHP6');

        $payload = $this->payload([$this->vm('PHPUNITSHP6'), $this->vm('phpunitshp6')]);
        $report = mission_import($this->db, $payload, self::PREFIX . 'dup', true);

        self::assertCount(1, $report['vm_name_conflicts'], 'the same foreign mission was linked twice');
        self::assertSame($foreignId, $report['vm_name_conflicts'][0]['mission_id']);
        self::assertSame(['phpunitshp6'], $report['vm_name_duplicates']);
        self::assertTrue($report['blocked_in_file']);
    }

    /**
     * The preview is a report, not a lock. A conflict that appears between
     * preview and confirm has to be caught by the confirm's own live analysis,
     * because the disabled button is client state and the operator may have had
     * the preview open for minutes.
     */
    public function testAConflictCreatedAfterThePreviewIsRefusedAtWrite(): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHP7')]);
        $target = self::PREFIX . 'race';

        $clean = mission_import($this->db, $payload, $target, true);
        self::assertFalse($clean['blocked'], 'the preview was not clean; this test needs a different fixture');

        // Someone else takes the VM name in the meantime.
        $foreignId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'race_foreign',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($foreignId, 'PHPUNITSHP7');

        try {
            mission_import($this->db, $payload, $target, false, 1);
            self::fail('the confirm wrote a mission whose VM name had been taken in the meantime');
        } catch (MissionTransferBlockedException) {
            // Expected.
        }
        self::assertFalse(repo_mission_name_exists($this->db, $target), 'a refused import left a partial mission');
    }

    /** The same for the target mission name itself. */
    public function testAMissionNameTakenAfterThePreviewIsRefusedAtWrite(): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHP8')]);
        $target = self::PREFIX . 'nametaken';

        $first = mission_import($this->db, $payload, $target, true);
        self::assertFalse($first['blocked'], 'the fixture has to start from a clean preview');
        repo_create_mission($this->db, [
            'mission_name' => $target,
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);

        $second = mission_import($this->db, $payload, $target, true);
        self::assertTrue($second['name_conflict']);
        self::assertTrue($second['blocked']);
        // A name problem still must not disable the field that fixes it.
        self::assertFalse($second['blocked_in_file']);

        $this->expectException(MissionTransferBlockedException::class);
        mission_import($this->db, $payload, $target, false, 1);
    }

    /**
     * An unreadable export timestamp is display metadata: it must not block, and
     * the report must hand the renderer an empty string rather than file content
     * that portal_format_datetime() would print back verbatim under "exported on".
     */
    public function testAnUnreadableExportedAtIsEmptiedNotRendered(): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHP9')]);
        $payload['exported_at'] = 'whenever';

        $report = mission_import($this->db, $payload, self::PREFIX . 'stamp', true);

        self::assertSame('', $report['exported_at']);
        self::assertFalse($report['blocked']);
    }

    /**
     * Two spellings of one missing VLAN are one finding, shown in the spelling
     * the file used first. The equality rule is esxi_inventory_name_key(), the
     * project-wide SSoT, so the preview cannot disagree with the catalog about
     * what "the same VLAN" means.
     */
    public function testTwoSpellingsOfOneMissingVlanReportOnce(): void
    {
        $payload = $this->payload([
            $this->vm('PHPUNITSHPC', ['interfaces' => [$this->interface(['vlan' => 'PhpunitShapeVlan'])]]),
            $this->vm('PHPUNITSHPD', ['interfaces' => [$this->interface(['vlan' => 'PHPUNITSHAPEVLAN'])]]),
        ]);

        $report = mission_import($this->db, $payload, self::PREFIX . 'vlan', true);

        self::assertSame(['PhpunitShapeVlan'], $report['missing_vlans']);
        self::assertTrue($report['blocked_in_file']);
    }

    /**
     * A well-formed reference the catalog cannot resolve stays a warning: the
     * import runs and skips the link. That is the one finding that must NOT
     * block, and it is easy to lose next to the new blocking cases.
     */
    public function testAnUnresolvablePackageWarnsAndIsSkipped(): void
    {
        $payload = $this->payload([$this->vm('PHPUNITSHPE', [
            'packages' => [['name' => 'phpunit_shape_absent_pkg', 'version' => '9.9']],
        ])]);

        $report = mission_import($this->db, $payload, self::PREFIX . 'pkg', true);
        self::assertSame(['phpunit_shape_absent_pkg'], $report['missing_packages']);
        self::assertSame(1, $report['counts']['packages'], 'a well-formed reference still counts');
        self::assertFalse($report['blocked'], 'a missing package must not block the import');

        $written = mission_import($this->db, $payload, self::PREFIX . 'pkg', false, 1);
        self::assertTrue($written['imported']);
        $vms = getVMs($this->db, (int) $written['mission_id']);
        self::assertSame([], $vms[0]['packages'], 'the unresolvable link is skipped, not invented');
    }

    /**
     * The importer writes no audit row itself, in either direction. The single
     * `missions` row for a successful import belongs to the portal handler, and
     * an expected refusal must leave no trace at all - otherwise every mistyped
     * name becomes audit noise.
     */
    public function testTheImporterWritesNoAuditRowOfItsOwn(): void
    {
        $before = $this->auditRowCount();

        mission_import($this->db, $this->payload([$this->vm('PHPUNITSHPA')]), self::PREFIX . 'audit', false, 1);
        try {
            mission_import($this->db, $this->payload([$this->vm('PHPUNITSHPB', ['disks' => 'oops'])]), self::PREFIX . 'audit2', false, 1);
        } catch (MissionTransferBlockedException) {
            // Expected refusal.
        }

        self::assertSame($before, $this->auditRowCount(), 'the importer wrote its own log rows');
    }

    private function auditRowCount(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs', '', []);
    }

    /**
     * @param list<mixed> $vms
     * @return array<string, mixed>
     */
    private function payload(array $vms): array
    {
        return [
            'format_version' => VIRTUSPHERE_MISSION_EXPORT_VERSION,
            'exported_at' => date('c'),
            'mission' => [
                'mission_name' => self::PREFIX . 'file',
                'mission_status' => '',
                'mission_notes' => '',
                'wds_vlan' => '',
                'hypervisor_datastorage' => 'ds1',
                'hypervisor_datacenter' => 'DC1',
                'domain' => 'dc.example.com',
            ],
            'vms' => $vms,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function vm(string $name, array $overrides = []): array
    {
        return array_merge([
            'vm_name' => $name,
            'vm_hostname' => $name,
            'vm_os' => 'Windows Server 2019',
            'vm_domain' => 'dc.example.com',
            'vm_guest_id' => VIRTUSPHERE_VM_DEFAULTS['guest_id'],
            'interfaces' => [$this->interface()],
            'disks' => [$this->disk('System')],
            'packages' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function interface(array $overrides = []): array
    {
        return array_merge([
            'ip' => '', 'subnet' => '', 'gateway' => '', 'dns1' => '', 'dns2' => '',
            'vlan' => '', 'mode' => 'dhcp', 'type' => 'vmxnet3',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function disk(string $name): array
    {
        return ['disk_name' => $name, 'disk_size' => 40, 'disk_type' => 'thick'];
    }

    private function makeVm(int $missionId, string $name): void
    {
        repo_save_vm(
            $this->db,
            $missionId,
            null,
            [
                'vm_name' => $name,
                'vm_hostname' => $name,
                'vm_os' => 'Windows Server 2019',
                'vm_domain' => 'dc.example.com',
                'vm_guest_id' => VIRTUSPHERE_VM_DEFAULTS['guest_id'],
            ],
            [['ip' => '', 'subnet' => '', 'gateway' => '', 'mode' => 'dhcp', 'type' => 'vmxnet3', 'vlan' => '', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    private function cleanup(): void
    {
        $pattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
    }
}
