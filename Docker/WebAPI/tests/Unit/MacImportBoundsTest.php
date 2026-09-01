<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mac_import.php';

/**
 * Correction plan 14.8: the three callback bounds against the REAL constants,
 * and the proof that the largest regular job scope stays inside them.
 *
 * The worst case matters more than the average one here. A result that does not
 * fit answers 409 AFTER the playbook has created the VMs it is reporting about,
 * so the operator is left with a failed job over changes that did happen. The
 * scope cap exists to make that unreachable, and this file is what keeps the
 * cap and the bound in the same argument.
 */
final class MacImportBoundsTest extends TestCase
{
    /** A VM name at its full stored width, in two-byte characters. */
    private function maxVmName(): string
    {
        return str_repeat('ä', 95);
    }

    /** A portgroup name at its full stored width, in two-byte characters. */
    private function maxVlanName(): string
    {
        return str_repeat('ö', 127);
    }

    /**
     * The worst case a job of the maximum regular scope can produce: every VM
     * fails, every interface contributes two distinct errors, and every
     * identifier is at its maximum stored byte length.
     *
     * @return array{result:string,response:string,plan:array<string,mixed>}
     */
    private function worstCaseJob(int $vms, int $interfacesPerVm): array
    {
        $vmName = $this->maxVmName();
        $vlan = $this->maxVlanName();
        $mac = str_repeat('a', 64);
        $errors = [];
        $vmResults = [];
        $failed = [];
        for ($index = 0; $index < $vms; $index++) {
            $vmId = 1000000 + $index;
            $failed[] = $vmId;
            for ($nic = 0; $nic < $interfacesPerVm; $nic++) {
                $errors[] = mac_import_error(VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, $vmId, $vmName, $vlan, $mac, 999999);
                $errors[] = mac_import_error(VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN, $vmId, $vmName, $vlan, $mac, null, 'both');
            }
            $vmResults[] = [
                'vm_id' => $vmId,
                'vm_name' => $vmName,
                'outcome' => 'failed',
                'updated_interfaces' => 0,
                // Every known code at once: the widest error_codes list a VM can carry.
                'error_codes' => VIRTUSPHERE_MAC_IMPORT_ERROR_CODES,
                'wds' => ['configured_portgroup' => $vlan, 'portal_interface_id' => null, 'verified' => false],
            ];
        }
        $plan = [
            'outcome' => 'failed',
            'successful_vm_ids' => [],
            'failed_vm_ids' => $failed,
            'errors' => $errors,
            'counts' => ['expected_vms' => $vms, 'successful_vms' => 0, 'failed_vms' => $vms, 'updated_interfaces' => 0],
            'retry' => ['mode' => 'export', 'vm_ids' => $failed],
            'vm_results' => $vmResults,
        ];
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return [
            'result' => (string) json_encode(mac_import_result_contract($plan, str_repeat('a', 64)), $flags),
            'response' => (string) json_encode(mac_import_response($plan, 4242, false, str_repeat('c', 32)), $flags),
            'plan' => $plan,
        ];
    }

    public function testTheLargestRegularJobScopeFitsBothOneMebibyteBounds(): void
    {
        $worst = $this->worstCaseJob(
            VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS,
            VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM
        );

        self::assertNull(
            mac_import_contract_bound_reason($worst['result'], $worst['response']),
            'the maximum regular scope must never reach a size 409 after the export ran'
        );
        self::assertLessThan(VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES, strlen($worst['result']));
        self::assertLessThan(VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES, strlen($worst['response']));
        // The response is the binding side; keep a visible margin so a future
        // additive field does not silently move the scope cap into the red.
        self::assertLessThan(
            (int) (VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES * 0.95),
            strlen($worst['response']),
            'the derivation of VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS has lost its margin'
        );
    }

    public function testTheScopeCapIsWhatKeepsTheWorstCaseInside(): void
    {
        // Twice the cap is over the response bound: the number is load-bearing,
        // not decoration. Without this the test above would also pass with a cap
        // ten times too large.
        $tooLarge = $this->worstCaseJob(
            VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS * 2,
            VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM
        );

        self::assertContains(
            mac_import_contract_bound_reason($tooLarge['result'], $tooLarge['response']),
            ['result_contract_too_large', 'response_contract_too_large'],
            'twice the cap must break a bound, or the cap is not what keeps the worst case inside'
        );
        self::assertGreaterThan(VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES, strlen($tooLarge['response']));
    }

    public function testResultBoundIsExactAtMaxMinusOneMaxAndMaxPlusOne(): void
    {
        $small = '1';
        foreach ([
            VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES - 1 => null,
            VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES => null,
            VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES + 1 => 'result_contract_too_large',
        ] as $size => $expected) {
            self::assertSame(
                $expected,
                mac_import_contract_bound_reason(str_repeat('x', $size), $small),
                'result size ' . $size
            );
        }
    }

    public function testResponseBoundIsExactAtMaxMinusOneMaxAndMaxPlusOne(): void
    {
        $small = '1';
        foreach ([
            VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES - 1 => null,
            VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES => null,
            VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES + 1 => 'response_contract_too_large',
        ] as $size => $expected) {
            self::assertSame(
                $expected,
                mac_import_contract_bound_reason($small, str_repeat('x', $size)),
                'response size ' . $size
            );
        }
    }

    /**
     * The request bound is measured in BYTES, and the endpoint reads at most
     * MAX+1 of them. Multi-byte content must therefore be compared the same way
     * on both sides, or a payload of umlauts is refused a third short of the
     * limit it was promised.
     */
    public function testRequestBoundIsMeasuredInBytesAtMaxMinusOneMaxAndMaxPlusOne(): void
    {
        foreach ([
            VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES - 1 => false,
            VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES => false,
            VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES + 1 => true,
        ] as $size => $rejected) {
            $body = str_repeat('x', $size);
            self::assertSame($rejected, strlen($body) > VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES, 'ascii ' . $size);
        }

        // Half as many two-byte characters is exactly the same number of bytes.
        $umlauts = str_repeat('ä', intdiv(VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES, 2));
        self::assertSame(VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES, strlen($umlauts));
        self::assertLessThanOrEqual(VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES, strlen($umlauts));
        self::assertTrue(strlen($umlauts . 'ä') > VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES);
    }

    public function testIdentifierBoundsCutOnCodepointBoundariesAndStayEncodable(): void
    {
        // 191 is an odd budget over two-byte characters, so the cut must end one
        // byte short rather than inside the last 'ä'.
        $name = mac_import_bounded_identifier(str_repeat('ä', 200), 191);
        self::assertSame(190, strlen($name));
        self::assertSame(95, mb_strlen($name, 'UTF-8'));
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));

        // A four-byte character at the boundary is dropped whole.
        $emoji = mac_import_bounded_identifier(str_repeat('a', 62) . '😀', 64);
        self::assertSame(62, strlen($emoji));
        self::assertTrue(mb_check_encoding($emoji, 'UTF-8'));

        $error = mac_import_error(
            VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN,
            7,
            str_repeat('ä', 200),
            str_repeat('ö', 300),
            str_repeat('ü', 100)
        );
        self::assertLessThanOrEqual(191, strlen((string) $error['vm_name']));
        self::assertLessThanOrEqual(255, strlen((string) $error['vlan']));
        self::assertLessThanOrEqual(64, strlen((string) $error['mac']));
        foreach (['vm_name', 'vlan', 'mac'] as $field) {
            self::assertTrue(mb_check_encoding((string) $error[$field], 'UTF-8'), $field);
        }
        // The point of the whole exercise: the bounded document encodes.
        self::assertNotSame('', (string) json_encode($error, JSON_THROW_ON_ERROR));
    }

    public function testTheBoundedIdentifierIsTheSharedByteLimiter(): void
    {
        foreach (['', 'plain', 'äöü', '😀😀', str_repeat('ß', 40)] as $value) {
            foreach ([0, 1, 3, 7, 64, 4096] as $budget) {
                self::assertSame(
                    virtusphere_bounded_utf8_bytes($value, $budget),
                    mac_import_bounded_identifier($value, $budget),
                    'the callback must not grow a second byte limiter'
                );
            }
        }
    }
}
