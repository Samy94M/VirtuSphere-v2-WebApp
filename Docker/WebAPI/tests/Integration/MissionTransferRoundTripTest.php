<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * A2 mission export/import round-trip against the live DB. Proves payload
 * fidelity (VM/interface/disk/package counts), that MAC addresses never travel,
 * and that importing into the SAME environment is blocked on the global VM-name
 * uniqueness rule (MECM device names). Cleans up its own rows by name prefix.
 */
final class MissionTransferRoundTripTest extends TestCase
{
    private const PREFIX = 'phpunit_xfer_';
    private const PKG = 'phpunit_xfer_pkg-1.0';

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

    public function testRoundTripPreservesCountsAndDropsMacs(): void
    {
        $packageId = $this->makePackage();
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER1', '10.0.0.5', '00:11:22:33:44:55', $packageId);
        $this->makeVm($sourceId, 'PHPUNITXFER2', '10.0.0.6', 'AA:BB:CC:DD:EE:FF', $packageId);

        $payload = mission_export_payload($this->db, $sourceId);
        self::assertSame(VIRTUSPHERE_MISSION_EXPORT_VERSION, $payload['format_version']);
        self::assertCount(2, $payload['vms']);
        // The export format must not carry MAC addresses at all.
        foreach ($payload['vms'] as $vm) {
            foreach ($vm['interfaces'] as $interface) {
                self::assertArrayNotHasKey('mac', $interface);
            }
            self::assertSame('phpunit_xfer_pkg-1.0', $vm['packages'][0]['name']);
        }

        // Real transfer target = a different environment; emulate by removing the
        // source (its VM names free the global namespace) before importing.
        deleteMission($sourceId, $this->db);

        $report = mission_import($this->db, $payload, self::PREFIX . 'dst', false, 1);
        self::assertTrue($report['imported']);
        self::assertGreaterThan(0, (int) $report['mission_id']);

        $vms = getVMs($this->db, (int) $report['mission_id']);
        self::assertCount(2, $vms);
        $interfaceCount = 0;
        $diskCount = 0;
        foreach ($vms as $vm) {
            foreach ($vm['interfaces'] as $interface) {
                $interfaceCount++;
                // MAC must be empty after import (never carried across).
                self::assertSame('', (string) ($interface['mac'] ?? ''));
            }
            $diskCount += count($vm['disks']);
            self::assertCount(1, $vm['packages']);
        }
        self::assertSame(2, $interfaceCount);
        self::assertSame(2, $diskCount);
    }

    public function testImportIntoSameEnvironmentIsBlockedOnVmNameConflict(): void
    {
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src2',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER3', '10.0.0.7', '00:11:22:33:44:66', 0);

        $payload = mission_export_payload($this->db, $sourceId);

        // Source still present -> its VM name collides globally -> blocked.
        $report = mission_import($this->db, $payload, self::PREFIX . 'dst2', true);
        self::assertTrue($report['blocked']);
        self::assertNotEmpty($report['vm_name_conflicts']);

        // And a non-dry-run must refuse rather than write a partial mission.
        $this->expectException(RuntimeException::class);
        mission_import($this->db, $payload, self::PREFIX . 'dst2', false, 1);
    }

    /**
     * A name the confirm form can fix is a REPORT field, never an exception.
     *
     * The template prefix is the case that produced the original bug report: the
     * mission export does not distinguish a template from a mission, so the most
     * natural round trip (export a template, import it back) threw out of
     * mission_import() before the dry-run branch, and the page's catch swallowed
     * it without a flash. The preview then rendered nothing at all - "click
     * Preview, nothing happens".
     */
    public function testDryRunReportsTemplatePrefixedNameAsBlockedNotException(): void
    {
        $report = mission_import($this->db, $this->emptyPayload(), VIRTUSPHERE_TEMPLATE_PREFIX . 'phpunitxfertpl', true);

        self::assertTrue($report['name_invalid']);
        self::assertNotSame('', $report['name_invalid_message']);
        self::assertTrue($report['blocked']);
        self::assertFalse($report['imported']);
        // The whole point of reporting instead of throwing: the operator fixes
        // this in the confirm form. A name problem must therefore never reach the
        // flag that disables the button (see the panel renderer).
        self::assertFalse($report['blocked_in_file']);
    }

    /**
     * The two block flags must not collapse into one. A finding inside the file
     * disables the confirm button; a name problem must not, because the field
     * that fixes it is in that same form and the confirm re-runs this analysis
     * against the name actually typed.
     */
    public function testNameProblemsAreFixableInTheFormWhileFileProblemsAreNot(): void
    {
        $nameOnly = mission_import($this->db, $this->emptyPayload(), '   ', true);
        self::assertTrue($nameOnly['blocked']);
        self::assertFalse($nameOnly['blocked_in_file']);

        $payload = $this->emptyPayload();
        $payload['vms'] = [$this->payloadVm('PHPUNITBADCPU', ['vm_cpu' => '0'])];
        $inFile = mission_import($this->db, $payload, self::PREFIX . 'badcpu', true);
        self::assertNotSame([], $inFile['vm_field_errors']);
        self::assertTrue($inFile['blocked_in_file']);
        self::assertTrue($inFile['blocked']);
    }

    public function testDryRunReportsBlankNameAsBlocked(): void
    {
        $report = mission_import($this->db, $this->emptyPayload(), '   ', true);

        self::assertTrue($report['name_invalid']);
        self::assertTrue($report['blocked']);

        // A name with a space is the same class of problem and must not throw
        // either, or the preview goes blank for a file that is perfectly readable.
        $spaced = mission_import($this->db, $this->emptyPayload(), self::PREFIX . 'two words', true);
        self::assertTrue($spaced['name_invalid']);
        self::assertTrue($spaced['blocked']);
    }

    /**
     * A field the portal's own validators reject must be visible in the preview
     * WITH the rest of the report, not instead of it: an operator who cannot see
     * the counts cannot tell whether the file is the one they meant to import.
     */
    public function testDryRunCollectsFieldErrorsWithoutAbortingReport(): void
    {
        $payload = $this->emptyPayload();
        $payload['vms'] = [$this->payloadVm('PHPUNITBADRAM', [
            'vm_ram' => (string) ((int) VIRTUSPHERE_VM_LIMITS['ram_mb_max'] + 1),
        ])];

        $report = mission_import($this->db, $payload, self::PREFIX . 'badram', true);

        self::assertNotSame([], $report['vm_field_errors']);
        self::assertStringStartsWith('PHPUNITBADRAM: ', $report['vm_field_errors'][0]);
        // The report kept going: counts are what the operator checks the file by.
        self::assertSame(1, $report['counts']['vms']);
        self::assertSame(1, $report['counts']['interfaces']);
        self::assertTrue($report['blocked']);

        // The real write still refuses; the preview is not the security boundary.
        $this->expectException(RuntimeException::class);
        mission_import($this->db, $payload, self::PREFIX . 'badram', false, 1);
    }

    /**
     * Two VMs with the same name inside ONE file cannot both be written (the
     * second collides with the first the moment it exists), so the preview has to
     * say so up front instead of failing halfway through the transaction.
     */
    public function testDryRunDetectsIntraFileDuplicateVmNames(): void
    {
        $payload = $this->emptyPayload();
        $payload['vms'] = [
            $this->payloadVm('PHPUNITDUP1'),
            $this->payloadVm('phpunitdup1'),
        ];

        $report = mission_import($this->db, $payload, self::PREFIX . 'dup', true);

        // Equality is case-insensitive (esxi_inventory_name_key), and the pair
        // reports once, from the second occurrence.
        self::assertSame(['phpunitdup1'], $report['vm_name_duplicates']);
        self::assertTrue($report['blocked']);
    }

    /**
     * The global name conflict is reported by the loop AND re-detected inside
     * repo_validate_vm_payload(). A VM whose only problem is that one conflict
     * must be named once, or every conflicting row is listed twice in two
     * different wordings.
     */
    public function testGlobalConflictNotDuplicatedInFieldErrors(): void
    {
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src3',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER4', '10.0.0.8', '00:11:22:33:44:77', 0);

        $payload = mission_export_payload($this->db, $sourceId);
        $report = mission_import($this->db, $payload, self::PREFIX . 'dst3', true);

        self::assertCount(1, $report['vm_name_conflicts']);
        self::assertSame('PHPUNITXFER4', $report['vm_name_conflicts'][0]['vm_name']);
        self::assertSame([], $report['vm_field_errors']);
    }

    /**
     * The conflict entry carries the mission id, so the preview can link to the
     * mission that holds the name instead of only naming it.
     */
    public function testVmNameConflictsCarryMissionId(): void
    {
        $sourceId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'src4',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
        $this->makeVm($sourceId, 'PHPUNITXFER5', '10.0.0.9', '00:11:22:33:44:88', 0);

        $payload = mission_export_payload($this->db, $sourceId);
        $report = mission_import($this->db, $payload, self::PREFIX . 'dst4', true);

        self::assertSame($sourceId, $report['vm_name_conflicts'][0]['mission_id']);
        self::assertSame(self::PREFIX . 'src4', $report['vm_name_conflicts'][0]['mission_name']);
    }

    /**
     * A readable document with a VM-free mission block. Built literally rather
     * than exported, because the name cases under test must not depend on a
     * mission existing under that name.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
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
            'vms' => [],
        ];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function payloadVm(string $name, array $overrides = []): array
    {
        return array_merge([
            'vm_name' => $name,
            'vm_hostname' => $name,
            'vm_os' => 'Windows Server 2019',
            'vm_domain' => 'dc.example.com',
            'vm_guest_id' => VIRTUSPHERE_VM_DEFAULTS['guest_id'],
        ], $overrides) + [
            'interfaces' => [['ip' => '', 'subnet' => '', 'gateway' => '', 'vlan' => '', 'mode' => 'dhcp', 'type' => 'vmxnet3']],
            'disks' => [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            'packages' => [],
        ];
    }

    private function makeVm(int $missionId, string $name, string $ip, string $mac, int $packageId): void
    {
        $packages = $packageId > 0 ? [['id' => $packageId]] : [];
        repo_save_vm(
            $this->db,
            $missionId,
            null,
            [
                'vm_name' => $name,
                'vm_hostname' => $name,
                'vm_os' => 'Windows Server 2019',
                'vm_domain' => 'dc.example.com',
                'vm_guest_id' => 'windows2019srv_64Guest',
            ],
            [[
                'ip' => $ip,
                'subnet' => '255.255.255.0',
                'gateway' => '10.0.0.1',
                'mode' => 'static',
                'type' => 'vmxnet3',
                'vlan' => '',
                'mac' => $mac,
            ]],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            $packages,
            '',
            1
        );
    }

    private function makePackage(): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status) VALUES (?, ?, ?, ?)');
        $name = self::PKG;
        $base = 'phpunit_xfer_pkg';
        $version = '1.0';
        $statusActive = 'Active';
        $stmt->bind_param('ssss', $name, $base, $version, $statusActive);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        foreach ([self::PREFIX . '%'] as $pattern) {
            $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_packages WHERE package_basename = ?');
        $base = 'phpunit_xfer_pkg';
        $stmt->bind_param('s', $base);
        $stmt->execute();
    }
}
