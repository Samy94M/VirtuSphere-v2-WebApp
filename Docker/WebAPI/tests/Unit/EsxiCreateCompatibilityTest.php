<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_create_compatibility.php';
require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * ADR-0023 amendment 7: the hardware version and the guest OS a host can
 * create, derived from the release the last inventory pull reported. The
 * incident of 15.10.2026 is the first case: a 7.0.3 host refused every create
 * because the playbook hardwired vmx-21.
 */
final class EsxiCreateCompatibilityTest extends TestCase
{
    private const NOW = 1791201600;

    /** @return array<string,mixed> */
    private static function state(?string $product, string $apiType = 'HostAgent', bool $fresh = true): array
    {
        return [
            'last_success_at' => $fresh ? gmdate('Y-m-d H:i:s', self::NOW) : '2025-01-01 00:00:00',
            'api_type' => $apiType,
            'product_version' => $product,
            'license_free' => 0,
        ];
    }

    /** @return list<array{id:int, vm_name:string, vm_guest_id:string}> */
    private static function vms(string ...$guestIds): array
    {
        $vms = [];
        foreach ($guestIds as $index => $guestId) {
            $vms[] = ['id' => $index + 1, 'vm_name' => 'VM' . ($index + 1), 'vm_guest_id' => $guestId];
        }

        return $vms;
    }

    public function testTheReleaseIsTheFirstDottedNumberOfAStandaloneHostNeverTheBuild(): void
    {
        $facts = static fn (?string $product, string $api = 'HostAgent'): array => ['api_type' => $api, 'product_version' => $product];

        self::assertSame('7.0.3', esxi_host_release($facts('VMware ESXi 7.0.3 build-23794027')));
        self::assertSame('8.0.2', esxi_host_release($facts('VMware ESXi 8.0.2')));
        self::assertSame('8.0.0', esxi_host_release($facts('VMware ESXi 8.0')));
        // Through vCenter the number is the vCenter's, not the creating host's.
        self::assertNull(esxi_host_release($facts('VMware vCenter Server 8.0.3 build-24022515', 'VirtualCenter')));
        self::assertNull(esxi_host_release($facts(null)));
        self::assertNull(esxi_host_release($facts('build-23794027')));
        self::assertNull(esxi_host_release(['api_type' => null, 'product_version' => 'VMware ESXi 7.0.3']));
    }

    public function testEachReleaseCreatesTheHighestVersionBroadcomDocumentsForIt(): void
    {
        $expected = [
            '6.7.0' => null, '7.0.0' => 17, '7.0.1' => 18, '7.0.2' => 19, '7.0.3' => 19,
            '8.0.0' => 20, '8.0.1' => 20, '8.0.2' => 21, '8.0.3' => 21, '9.0.0' => 21,
        ];
        foreach ($expected as $release => $vmx) {
            self::assertSame($vmx, esxi_create_hardware_version_for($release), $release);
        }
        self::assertSame('7.0', esxi_release_label(esxi_create_release_floor()));
        self::assertSame('8.0 Update 2', esxi_release_label('8.0.2'));
    }

    public function testTheTableAndTheGuestFloorsAreConsistentWithEachOther(): void
    {
        $previous = null;
        foreach (VIRTUSPHERE_ESXI_CREATE_HARDWARE_VERSIONS as $row) {
            if ($previous !== null) {
                self::assertTrue(version_compare($row['esxi'], $previous['esxi'], '>'), 'releases must ascend');
                self::assertGreaterThan($previous['vmx'], $row['vmx'], 'versions must ascend');
            }
            $previous = $row;
        }
        // A guest floor below the create floor would promise a guest OS on a
        // host where no VM can be created at all.
        foreach (VIRTUSPHERE_GUEST_OS_OPTIONS as $option) {
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $option['min_esxi'], $option['guest_id']);
            self::assertTrue(version_compare($option['min_esxi'], esxi_create_release_floor(), '>='), $option['guest_id']);
        }
    }

    public function testTheIncidentHostCreatesAtVersion19ForServer2019And2022(): void
    {
        $verdict = esxi_create_compatibility(
            self::state('VMware ESXi 7.0.3 build-23794027'),
            0,
            'full',
            self::vms('windows2019srv_64Guest', 'windows2019srvNext_64Guest'),
            self::NOW
        );

        self::assertSame('ok', $verdict['verdict']);
        self::assertSame(19, $verdict['hardware_version']);
        self::assertTrue($verdict['known']);
        self::assertSame([], $verdict['unsupported_guests']);
    }

    public function testAGuestOsTheHostCannotKnowBlocksAndNamesEveryAffectedVm(): void
    {
        $verdict = esxi_create_compatibility(
            self::state('VMware ESXi 7.0.3 build-23794027'),
            0,
            'create',
            self::vms('windows2019srv_64Guest', 'windows11_64Guest', 'windows2022srvNext_64Guest'),
            self::NOW
        );

        self::assertSame('block', $verdict['verdict']);
        self::assertSame(VIRTUSPHERE_ESXI_CREATE_BLOCK_GUEST, $verdict['reason']);
        self::assertSame(['VM2', 'VM3'], array_column($verdict['unsupported_guests'], 'vm_name'));
        $message = esxi_create_compatibility_message($verdict);
        self::assertStringContainsString('VM2', $message);
        self::assertStringContainsString('7.0 Update 3', $message);
        self::assertStringContainsString('8.0', $message);
    }

    public function testAHostBelowTheTableBlocksBeforeAnyGuestIsLookedAt(): void
    {
        $verdict = esxi_create_compatibility(self::state('VMware ESXi 6.7.0 build-17167734'), 0, 'create', self::vms('windows11_64Guest'), self::NOW);

        self::assertSame('block', $verdict['verdict']);
        self::assertSame(VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST, $verdict['reason']);
        self::assertNull($verdict['hardware_version']);
        self::assertSame([], $verdict['unsupported_guests']);
        self::assertStringContainsString('6.7', esxi_create_compatibility_message($verdict));
    }

    public function testUnconfirmedFactsNeitherBlockNorPinAVersion(): void
    {
        $unknown = [
            null,
            self::state('VMware ESXi 7.0.3 build-23794027', 'HostAgent', false),
            self::state(null),
            self::state('VMware vCenter Server 8.0.3', 'VirtualCenter'),
        ];
        foreach ($unknown as $state) {
            $verdict = esxi_create_compatibility($state, 0, 'full', self::vms('windows11_64Guest'), self::NOW);
            self::assertSame('ok', $verdict['verdict']);
            self::assertNull($verdict['hardware_version'], 'ESXi picks its own default when the release is not current');
            self::assertFalse($verdict['known']);
        }
        self::assertStringContainsString('not currently known', esxi_create_compatibility_log_line(
            esxi_create_compatibility(null, 0, 'create', [], self::NOW)
        ));
    }

    public function testModesThatCreateNothingAndLegacyGuestIdsAreNeverRefused(): void
    {
        $old = self::state('VMware ESXi 6.7.0');
        foreach (['powercycle', 'start', 'export', 'autostart', 'inventory'] as $mode) {
            self::assertSame('ok', esxi_create_compatibility($old, 0, $mode, self::vms('windows11_64Guest'), self::NOW)['verdict'], $mode);
        }
        // A value from before the catalog has no recorded floor: ESXi decides.
        $legacy = esxi_create_compatibility(self::state('VMware ESXi 7.0.3'), 0, 'create', self::vms('windows9Server64Guest'), self::NOW);
        self::assertSame('ok', $legacy['verdict']);
        self::assertSame(19, $legacy['hardware_version']);
    }

    public function testTheArtifactCarriesThePinnedVersionOrAnEmptyValue(): void
    {
        self::assertSame("create_hardware_version: 19\n", ansible_create_hardware_yml(19));
        self::assertSame("create_hardware_version: ''\n", ansible_create_hardware_yml(null));
        self::assertSame("create_hardware_version: ''\n", ansible_create_hardware_yml(0));
    }
}
