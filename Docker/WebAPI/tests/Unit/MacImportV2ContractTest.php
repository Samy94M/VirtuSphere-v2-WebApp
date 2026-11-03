<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mac_import.php';

final class MacImportV2ContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function validContract(): array
    {
        return [
            'version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'partial',
            'successful_vm_ids' => [1],
            'failed_vm_ids' => [2],
            'errors' => [[
                'code' => VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED,
                'vm_id' => 2,
                'vm_name' => 'vm-2',
            ]],
            'counts' => [
                'expected_vms' => 2,
                'successful_vms' => 1,
                'failed_vms' => 1,
                'updated_interfaces' => 1,
            ],
            'retry' => ['mode' => 'export', 'vm_ids' => [2]],
            'vm_results' => [
                [
                    'vm_id' => 1,
                    'vm_name' => 'vm-1',
                    'outcome' => 'success',
                    'updated_interfaces' => 1,
                    'error_codes' => [],
                    'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => 11, 'verified' => true],
                ],
                [
                    'vm_id' => 2,
                    'vm_name' => 'vm-2',
                    'outcome' => 'failed',
                    'updated_interfaces' => 0,
                    'error_codes' => [VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED],
                    'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => null, 'verified' => false],
                ],
            ],
            'callback_fingerprint' => str_repeat('a', 64),
        ];
    }

    public function testValidV2RoundTripsAndLegacyV1RemainsReadable(): void
    {
        $contract = $this->validContract();
        $decoded = mac_import_decode_result(json_encode($contract, JSON_THROW_ON_ERROR));
        self::assertNotNull($decoded);
        self::assertSame(2, $decoded['version']);
        self::assertSame([2], $decoded['retry']['vm_ids']);

        $legacy = ['version' => 1, 'kind' => 'mac_import', 'outcome' => 'failed'];
        self::assertSame(1, mac_import_decode_result(json_encode($legacy, JSON_THROW_ON_ERROR))['version']);
    }

    public function testV2RejectsCorruptionInsteadOfGuessing(): void
    {
        $mutations = [];
        $missing = $this->validContract();
        unset($missing['callback_fingerprint']);
        $mutations['missing field'] = $missing;
        $overlap = $this->validContract();
        $overlap['failed_vm_ids'] = [1, 2];
        $mutations['overlapping scopes'] = $overlap;
        $order = $this->validContract();
        $order['vm_results'] = array_reverse($order['vm_results']);
        $mutations['noncanonical VM order'] = $order;
        $count = $this->validContract();
        $count['counts']['updated_interfaces'] = 9;
        $mutations['wrong count'] = $count;
        $wds = $this->validContract();
        $wds['vm_results'][0]['wds']['verified'] = false;
        $mutations['unverified successful WDS'] = $wds;
        $unknown = $this->validContract();
        $unknown['errors'][0]['code'] = 'future_error';
        $unknown['vm_results'][1]['error_codes'] = ['future_error'];
        $mutations['unknown error code'] = $unknown;
        $inconsistent = $this->validContract();
        $inconsistent['errors'] = [];
        $mutations['missing top error'] = $inconsistent;

        self::assertNotEmpty($mutations);
        foreach ($mutations as $name => $contract) {
            self::assertNull(mac_import_decode_result(json_encode($contract, JSON_THROW_ON_ERROR)), $name);
        }
    }

    public function testCallbackFingerprintCanonicalizesSemanticOrderAndIgnoresDiagnostics(): void
    {
        $left = [['instance' => ['hw_name' => 'vm-1', 'hw_eth0' => ['summary' => 'WDS']], 'changed' => false]];
        $same = [['msg' => 'diagnostic only', 'instance' => ['hw_eth0' => ['summary' => 'WDS'], 'hw_name' => 'vm-1']]];
        $failed = ['failed' => true, 'item' => ['vm_name' => 'vm-2'], 'msg' => 'free text'];
        self::assertSame(mac_import_callback_fingerprint(3, 4, $left, [8, 7]), mac_import_callback_fingerprint(3, 4, $same, [7, 8]));
        self::assertSame(
            mac_import_callback_fingerprint(3, 4, [$left[0], $failed], [7, 8]),
            mac_import_callback_fingerprint(3, 4, [$failed, $left[0]], [8, 7]),
            'pure result-list ordering is not semantic'
        );
        self::assertNotSame(mac_import_callback_fingerprint(3, 4, $left, [7]), mac_import_callback_fingerprint(3, 4, [$left[0], $left[0]], [7]));
        self::assertNotSame(mac_import_callback_fingerprint(3, 4, $left, [7]), mac_import_callback_fingerprint(3, 4, $left, [7, 8]));
    }

    public function testWdsValidationIsExactAcrossPortalAndEsxi(): void
    {
        $plan = ['vm' => ['id' => 5, 'vm_name' => 'vm-5'], 'errors' => []];
        mac_import_validate_wds(
            $plan,
            [['id' => 51, 'vlan' => 'WDS']],
            [['vlan' => 'WDS', 'mac' => '00:11:22:33:44:55', 'normalized_mac' => '00:11:22:33:44:55']],
            'WDS'
        );
        self::assertTrue($plan['wds']['verified']);
        self::assertSame(51, $plan['wds']['portal_interface_id']);
        self::assertSame([], $plan['errors']);

        $case = ['vm' => ['id' => 5, 'vm_name' => 'vm-5'], 'errors' => []];
        mac_import_validate_wds(
            $case,
            [['id' => 51, 'vlan' => 'wds']],
            [['vlan' => 'Wds', 'mac' => '00:11:22:33:44:55', 'normalized_mac' => '00:11:22:33:44:55']],
            'WDS'
        );
        self::assertFalse($case['wds']['verified']);
        self::assertSame(
            [VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_CASE, VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_CASE],
            array_column(array_values($case['errors']), 'code')
        );

        $boundaryWhitespace = ['vm' => ['id' => 5, 'vm_name' => 'vm-5'], 'errors' => []];
        mac_import_validate_wds(
            $boundaryWhitespace,
            [['id' => 51, 'vlan' => 'WDS ']],
            [['vlan' => 'WDS', 'mac' => '00:11:22:33:44:55', 'normalized_mac' => '00:11:22:33:44:55']],
            'WDS'
        );
        self::assertFalse($boundaryWhitespace['wds']['verified'], 'boundary whitespace is part of the raw portgroup name');
        self::assertContains(VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_MISSING, array_column(array_values($boundaryWhitespace['errors']), 'code'));

        $allUnsupported = ['vm' => ['id' => 5, 'vm_name' => 'vm-5'], 'errors' => []];
        mac_import_validate_wds(
            $allUnsupported,
            [['id' => 51, 'vlan' => 'WDS ']],
            [['vlan' => 'WDS ', 'mac' => '00:11:22:33:44:55', 'normalized_mac' => '00:11:22:33:44:55']],
            'WDS '
        );
        self::assertFalse($allUnsupported['wds']['verified'], 'an unsupported ESXi raw name can never authorize a mapping');
        self::assertContains(VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_MISSING, array_column(array_values($allUnsupported['errors']), 'code'));
    }

    public function testCallbackPayloadConsumesTheDerivedCallbackRegistryForLegacyAndRemoteJobs(): void
    {
        foreach ([VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY, VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE] as $contract) {
            $job = [
                'execution_contract' => $contract,
                'payload_json' => json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'vm_ids' => [9]], JSON_THROW_ON_ERROR),
            ];
            self::assertSame(
                ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'scope_ids' => [9]],
                mac_import_callback_job_payload($job),
                $contract
            );
        }

        $this->expectException(UnexpectedValueException::class);
        mac_import_callback_job_payload(['payload_json' => json_encode(['mode' => 'create'], JSON_THROW_ON_ERROR)]);
    }

    public function testResultAndResponseBoundsFailClosedAtTheExactBoundary(): void
    {
        self::assertNull(mac_import_contract_bound_reason('1234', '1234', 4, 4));
        self::assertSame('result_contract_too_large', mac_import_contract_bound_reason('12345', '1', 4, 4));
        self::assertSame('response_contract_too_large', mac_import_contract_bound_reason('1234', '12345', 4, 4));
        self::assertSame(
            'result_contract_too_large',
            mac_import_contract_bound_reason('12345', '12345', 4, 4),
            'result precedence is stable when both bounds fail'
        );
    }
}
