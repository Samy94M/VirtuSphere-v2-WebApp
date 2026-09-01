<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';
require_once dirname(__DIR__, 2) . '/lib/mac_import.php';

/**
 * The worker-side read contract of AP1.6: which modes must present a durable
 * MAC import result, and what counts as a usable one. Anything unusable must
 * decode to NULL, because NULL is what fails the job - a malformed result must
 * never pass as a green one.
 */
final class DeployWorkerResultEvaluationTest extends TestCase
{
    public function testOnlyModesWithAnExportStepExpectAMacResult(): void
    {
        // Derived from ansible_playbooks_for_mode(): the sequence is the SSoT.
        self::assertTrue(ansible_mode_expects_mac_result('export'));
        self::assertTrue(ansible_mode_expects_mac_result('powercycle'));
        self::assertTrue(ansible_mode_expects_mac_result(VIRTUSPHERE_DEPLOY_MODE_FULL));

        self::assertFalse(ansible_mode_expects_mac_result('create'));
        self::assertFalse(ansible_mode_expects_mac_result('start'));
        self::assertFalse(ansible_mode_expects_mac_result(VIRTUSPHERE_DEPLOY_MODE_AUTOSTART));
    }

    public function testHistoricalV1ResultsRemainReadableAndNormalizeVmIdLists(): void
    {
        $json = json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'partial',
            'successful_vm_ids' => [5, '3', 3, 0, -1, 'x'],
            'failed_vm_ids' => [9],
            'errors' => [['vm_id' => 9, 'code' => 'interface_not_found']],
            'counts' => ['expected_vms' => 3, 'successful_vms' => '2', 'failed_vms' => 1, 'updated_interfaces' => 2],
            'retry' => ['mode' => 'export', 'vm_ids' => [9]],
        ], JSON_THROW_ON_ERROR);

        $result = mac_import_decode_result($json);

        self::assertNotNull($result);
        self::assertSame('partial', $result['outcome']);
        self::assertSame([3, 5], $result['successful_vm_ids'], 'ids must be de-duplicated, int-cast and sorted; junk dropped');
        self::assertSame([9], $result['failed_vm_ids']);
        self::assertSame(['expected_vms' => 3, 'successful_vms' => 2, 'failed_vms' => 1, 'updated_interfaces' => 2], $result['counts']);
    }

    public function testEveryContractOutcomeIsAccepted(): void
    {
        foreach (['success', 'partial', 'failed'] as $outcome) {
            $result = mac_import_decode_result($this->resultJson(['outcome' => $outcome]));
            self::assertNotNull($result, $outcome);
            self::assertSame($outcome, $result['outcome']);
        }
    }

    public function testUnusableResultsDecodeToNull(): void
    {
        self::assertNull(mac_import_decode_result(null), 'no result recorded');
        self::assertNull(mac_import_decode_result(''), 'empty column');
        self::assertNull(mac_import_decode_result('   '), 'whitespace only');
        self::assertNull(mac_import_decode_result('{not json'), 'malformed JSON');
        self::assertNull(mac_import_decode_result('"a string"'), 'JSON but not an object');
        self::assertNull(mac_import_decode_result($this->resultJson(['version' => 999])), 'unknown version must not be misread');
        self::assertNull(mac_import_decode_result($this->resultJson(['kind' => 'inventory'])), 'foreign kind');
        self::assertNull(mac_import_decode_result($this->resultJson(['outcome' => 'green'])), 'unknown outcome');
        self::assertNull(mac_import_decode_result($this->resultJson(['outcome' => ''])), 'missing outcome');
    }

    public function testHistoricalV1MissingIdListsAndCountsDegradeToEmptyNotToAnError(): void
    {
        $json = json_encode([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'success',
        ], JSON_THROW_ON_ERROR);

        $result = mac_import_decode_result($json);

        self::assertNotNull($result);
        self::assertSame([], $result['successful_vm_ids']);
        self::assertSame([], $result['failed_vm_ids']);
        self::assertSame([], $result['counts']);
    }

    public function testWriteSideContractRoundTripsThroughTheDecoder(): void
    {
        // What db_importMAC.php persists (mac_import_result_contract) must stay
        // readable by the worker; if either side drifts, this breaks first.
        $plan = [
            'outcome' => 'partial',
            'successful_vm_ids' => [1, 2],
            'failed_vm_ids' => [3],
            'errors' => [['vm_id' => 3, 'vm_name' => 'vm03', 'code' => 'interface_not_found', 'vlan' => 'WDS']],
            'counts' => ['expected_vms' => 3, 'successful_vms' => 2, 'failed_vms' => 1, 'updated_interfaces' => 2],
            'retry' => ['mode' => 'export', 'vm_ids' => [3]],
            'vm_results' => [
                ['vm_id' => 1, 'vm_name' => 'vm01', 'outcome' => 'success', 'updated_interfaces' => 1, 'error_codes' => [], 'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => 11, 'verified' => true]],
                ['vm_id' => 2, 'vm_name' => 'vm02', 'outcome' => 'success', 'updated_interfaces' => 1, 'error_codes' => [], 'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => 12, 'verified' => true]],
                ['vm_id' => 3, 'vm_name' => 'vm03', 'outcome' => 'failed', 'updated_interfaces' => 0, 'error_codes' => ['interface_not_found'], 'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => null, 'verified' => false]],
            ],
        ];

        $decoded = mac_import_decode_result(json_encode(mac_import_result_contract($plan, str_repeat('a', 64)), JSON_THROW_ON_ERROR));

        self::assertNotNull($decoded);
        self::assertSame('partial', $decoded['outcome']);
        self::assertSame([1, 2], $decoded['successful_vm_ids']);
        self::assertSame([3], $decoded['failed_vm_ids']);
        self::assertSame($plan['counts'], $decoded['counts']);
    }

    /** @param array<string, mixed> $overrides */
    private function resultJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'version' => VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION,
            'kind' => VIRTUSPHERE_MAC_IMPORT_RESULT_KIND,
            'outcome' => 'success',
            'successful_vm_ids' => [1],
            'failed_vm_ids' => [],
            'errors' => [],
            'counts' => ['expected_vms' => 1, 'successful_vms' => 1, 'failed_vms' => 0, 'updated_interfaces' => 1],
            'retry' => ['mode' => 'export', 'vm_ids' => []],
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}
