<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_network_contract.php';

final class VmNetworkContractTest extends TestCase
{
    public function testGeneralAmbiguityUsesExactStoredValuesWithoutTrimming(): void
    {
        $separate = vm_network_issues_for_interfaces([
            ['id' => 1, 'vlan' => 'WDS'],
            ['id' => 2, 'vlan' => 'WDS '],
            ['id' => 3, 'vlan' => '01'],
            ['id' => 4, 'vlan' => '1'],
        ], 7, 8, 'vm');
        self::assertSame([], $separate);

        $duplicate = vm_network_issues_for_interfaces([
            ['id' => 1, 'vlan' => 'WDS '],
            ['id' => 2, 'vlan' => 'WDS '],
        ], 7, 8, 'vm');
        self::assertSame(VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, $duplicate[0]['code']);
        self::assertSame('WDS ', $duplicate[0]['vlan']);
    }
    public function testEmptyAndAmbiguousVlansAreGroupedWithoutLosingRows(): void
    {
        $issues = vm_network_issues_for_interfaces([
            ['id' => 11, 'vlan' => '  '],
            ['id' => 12, 'vlan' => 'Prod'],
            ['id' => 13, 'vlan' => 'Prod'],
            ['id' => 14, 'vlan' => 'Prod'],
            ['id' => 15, 'vlan' => 'prod'],
            ['vlan' => ''],
            ['id' => 16, 'vlan' => '0'],
        ], 4, 7, 'vm-7');

        // Canonical order (correction plan 16.4): the severity registry decides
        // first, so the interface without any VLAN comes before the duplicate.
        self::assertCount(2, $issues);
        self::assertSame(VIRTUSPHERE_VM_NETWORK_EMPTY, $issues[0]['code']);
        self::assertSame([11], $issues[0]['interface_ids']);
        self::assertSame([0, 5], $issues[0]['row_indexes']);
        self::assertSame(2, $issues[0]['occurrences']);
        self::assertSame(VIRTUSPHERE_VM_NETWORK_AMBIGUOUS, $issues[1]['code']);
        self::assertSame('Prod', $issues[1]['vlan']);
        self::assertSame([12, 13, 14], $issues[1]['interface_ids']);
        self::assertSame([1, 2, 3], $issues[1]['row_indexes']);
        self::assertSame(3, $issues[1]['occurrences']);
        self::assertSame([], array_values(array_filter(
            $issues,
            static fn (array $issue): bool => (string) $issue['vlan'] === 'prod' || (string) $issue['vlan'] === '0'
        )));
    }

    public function testFingerprintIsVersionedOrderedAndNormalizesPreservedMac(): void
    {
        $left = [
            ['id' => 2, 'vlan' => 'Prod', 'mode' => 'static', 'type' => 'vmxnet3', 'ip' => '10.0.0.2', 'mac' => 'AA-BB-CC-DD-EE-FF'],
            ['id' => 3, 'vlan' => 'WDS', 'mode' => 'dhcp', 'type' => 'e1000e', 'mac' => ''],
        ];
        $normalized = $left;
        $normalized[0]['mac'] = 'aa:bb:cc:dd:ee:ff';
        $reordered = array_reverse($left);

        self::assertSame(vm_network_bundle_fingerprint($left), vm_network_bundle_fingerprint($normalized));
        self::assertNotSame(vm_network_bundle_fingerprint($left), vm_network_bundle_fingerprint($reordered));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', vm_network_bundle_fingerprint($left));
    }

    public function testWdsVerdictsAndModeMatrixAreExact(): void
    {
        self::assertSame(VIRTUSPHERE_WDS_MISSION_MISSING, vm_network_wds_verdict([], '', 1, 2, 'vm')['code']);
        self::assertSame(VIRTUSPHERE_WDS_PORTAL_MISSING, vm_network_wds_verdict([['id' => 1, 'vlan' => 'Prod']], 'WDS', 1, 2, 'vm')['code']);
        self::assertSame(VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH, vm_network_wds_verdict([['id' => 1, 'vlan' => 'wds']], 'WDS', 1, 2, 'vm')['code']);
        self::assertSame(
            VIRTUSPHERE_WDS_PORTAL_MISSING,
            vm_network_wds_verdict([['id' => 1, 'vlan' => 'WDS ']], 'WDS', 1, 2, 'vm')['code'],
            'boundary whitespace must not be normalized into an exact portgroup match'
        );
        self::assertSame(VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS, vm_network_wds_verdict([
            ['id' => 1, 'vlan' => 'WDS'],
            ['id' => 2, 'vlan' => 'WDS'],
        ], 'WDS', 1, 2, 'vm')['code']);
        $ready = vm_network_wds_verdict([['id' => 9, 'vlan' => 'WDS', 'mac' => '00:11:22:33:44:55']], 'WDS', 1, 2, 'vm');
        self::assertSame(VIRTUSPHERE_WDS_READY, $ready['code']);
        self::assertSame(9, $ready['portal_interface_id']);
        self::assertTrue($ready['wds_mac_present']);

        $hardGeneral = [];
        $hardWds = [];
        foreach (virtusphere_user_deploy_modes() as $mode) {
            if (deploy_mode_requires_unique_network_mapping($mode)) {
                $hardGeneral[] = $mode;
            }
            if (deploy_mode_requires_wds_ready($mode)) {
                $hardWds[] = $mode;
            }
        }
        sort($hardGeneral);
        sort($hardWds);
        self::assertSame(['create', 'export', 'full', 'powercycle'], $hardGeneral);
        self::assertSame(['export', 'full', 'powercycle'], $hardWds);
        self::assertFalse(deploy_mode_has_hard_network_gate('start'));
        self::assertFalse(deploy_mode_has_hard_network_gate(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART));
    }

    /**
     * Walk the registry against the codes, in both directions. A new code
     * without a rank would sort last and quietly become the first thing a byte
     * cap drops; a rank for a code that no longer exists is dead weight nobody
     * would find.
     */
    public function testSeverityRegistryCoversEveryFindingCodeAndNothingElse(): void
    {
        $codes = array_merge(
            [VIRTUSPHERE_VM_NETWORK_EMPTY, VIRTUSPHERE_VM_NETWORK_AMBIGUOUS],
            VIRTUSPHERE_WDS_ERROR_CODES
        );
        sort($codes, SORT_STRING);
        $ranked = array_keys(VIRTUSPHERE_PREFLIGHT_SEVERITY_RANK);
        sort($ranked, SORT_STRING);
        self::assertSame($codes, $ranked);
        self::assertSame(
            array_values(array_unique(array_values(VIRTUSPHERE_PREFLIGHT_SEVERITY_RANK))),
            array_values(VIRTUSPHERE_PREFLIGHT_SEVERITY_RANK),
            'two codes with the same rank leave their order to the next key by accident'
        );
        self::assertSame(
            [VIRTUSPHERE_PREFLIGHT_SOURCE_INTERFACE_VLAN, VIRTUSPHERE_PREFLIGHT_SOURCE_WDS],
            array_keys(VIRTUSPHERE_PREFLIGHT_SOURCE_KIND_RANK)
        );
        self::assertSame(
            VIRTUSPHERE_PREFLIGHT_SOURCE_INTERFACE_VLAN,
            vm_network_issues_for_interfaces([['id' => 1, 'vlan' => '']], 1, 2, 'vm')[0]['source_kind']
        );
        self::assertSame(
            VIRTUSPHERE_PREFLIGHT_SOURCE_WDS,
            vm_network_wds_verdict([], '', 1, 2, 'vm')['source_kind']
        );
        self::assertSame(PHP_INT_MAX, vm_network_finding_severity_rank(['code' => 'not_a_code']));
        self::assertSame(PHP_INT_MAX, vm_network_finding_source_rank([]));
    }

    /**
     * Candidate names are operator-assigned ESXi values, so they are bounded at
     * the one place they are produced and both counts stay complete.
     */
    public function testCaseMismatchCandidatesAreBoundedAndCounted(): void
    {
        // Seven distinct spellings that all fold onto the same diagnostic key.
        $interfaces = [
            ['id' => 101, 'vlan' => 'wds'],
            ['id' => 102, 'vlan' => 'Wds'],
            ['id' => 103, 'vlan' => 'wDs'],
            ['id' => 104, 'vlan' => 'wdS'],
            ['id' => 105, 'vlan' => 'WDs'],
            ['id' => 106, 'vlan' => 'WdS'],
            ['id' => 107, 'vlan' => 'wDS'],
        ];
        $verdict = vm_network_wds_verdict($interfaces, 'WDS', 1, 2, 'vm');

        self::assertSame(VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH, $verdict['code']);
        self::assertCount(VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT, $verdict['candidates']);
        self::assertSame(7, $verdict['candidate_total']);
        self::assertSame(7 - VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT, $verdict['candidate_omitted_count']);
        // Deterministic: the same input must select the same five names.
        self::assertSame($verdict['candidates'], vm_network_wds_verdict($interfaces, 'WDS', 1, 2, 'vm')['candidates']);
        self::assertSame(['WDs', 'WdS', 'Wds', 'wDS', 'wDs'], $verdict['candidates']);
    }
}
