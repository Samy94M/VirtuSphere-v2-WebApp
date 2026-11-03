<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_preflight_bounds.php';
require_once dirname(__DIR__, 2) . '/lib/vm_network_preflight_result.php';

/**
 * Correction plan 16.4: how many findings a payload carries, how many bytes it
 * takes, and what the numbers beside it are still allowed to claim.
 */
final class DeployPreflightBoundsTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function findings(int $count, int $firstVmId = 1000): array
    {
        $findings = [];
        for ($index = 0; $index < $count; $index++) {
            $findings[] = vm_network_issue(
                VIRTUSPHERE_VM_NETWORK_AMBIGUOUS,
                7,
                $firstVmId + $index,
                sprintf('vm-%04d', $index),
                'Prod',
                [$firstVmId + $index],
                [0, 1],
                2
            );
        }

        return $findings;
    }

    public function testSelectionIsExactAtTheBoundaryInBothDirections(): void
    {
        foreach ([
            VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT - 1,
            VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT,
            VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT + 1,
        ] as $count) {
            $bounded = deploy_preflight_bounded_findings($this->findings($count));
            $expected = min($count, VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT);
            self::assertCount($expected, $bounded['items'], 'count ' . $count);
            self::assertSame($count, $bounded['total'], 'total stays the complete set at ' . $count);
            self::assertSame($count - $expected, $bounded['omitted_count'], 'omitted at ' . $count);
        }
    }

    public function testAnEmptyListIsNotTruncatedAndReportsZero(): void
    {
        $bounded = deploy_preflight_bounded_findings([]);
        self::assertSame([], $bounded['items']);
        self::assertSame(0, $bounded['total']);
        self::assertSame(0, $bounded['omitted_count']);
    }

    /**
     * The bound selects a prefix, so what "the first N" means is decided by the
     * canonical order the aggregator applies before handing the list over. Two
     * input orders of the same set must therefore produce the same selection.
     */
    public function testSelectionFollowsTheCanonicalOrderAndIsReproducible(): void
    {
        $findings = $this->findings(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT + 5);
        $findings[] = vm_network_issue(VIRTUSPHERE_VM_NETWORK_EMPTY, 7, 999999, 'zzz-last', '', [1], [0], 1);
        $shuffled = array_reverse($findings);
        vm_network_sort_issues($findings);
        vm_network_sort_issues($shuffled);

        $first = deploy_preflight_bounded_findings($findings);
        $second = deploy_preflight_bounded_findings($shuffled);

        self::assertSame(
            array_column($first['items'], 'vm_id'),
            array_column($second['items'], 'vm_id'),
            'the same set in another input order must select the same rows'
        );
        // Severity decides first, so the single EMPTY finding survives the cut
        // even though its VM name sorts last.
        self::assertSame(VIRTUSPHERE_VM_NETWORK_EMPTY, $first['items'][0]['code']);
        self::assertSame(999999, $first['items'][0]['vm_id']);
    }

    public function testCandidateBoundKeepsBothCountsComplete(): void
    {
        $bounded = deploy_preflight_bounded_candidates(['d', 'b', 'a', 'c', 'f', 'e', 'a']);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $bounded['items']);
        self::assertSame(6, $bounded['candidate_total']);
        self::assertSame(1, $bounded['candidate_omitted_count']);

        $exact = deploy_preflight_bounded_candidates(['a', 'b', 'c', 'd', 'e']);
        self::assertSame(5, $exact['candidate_total']);
        self::assertSame(0, $exact['candidate_omitted_count']);
    }

    public function testByteCapDropsFromTheListEndAndFlagsItself(): void
    {
        $envelope = ['ok' => true, 'blockers' => []];
        foreach ($this->findings(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT) as $finding) {
            // A payload large enough that the byte bound bites before the count
            // bound does: 50 x ~2 KiB is well past 64 KiB.
            $finding['message'] = str_repeat('x', 2048);
            $envelope['blockers'][] = $finding;
        }
        $total = count($envelope['blockers']);

        $result = deploy_preflight_bounded_json($envelope, 'blockers', $total);

        self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES, strlen($result['json']));
        self::assertTrue($result['truncated_by_bytes']);
        self::assertTrue($result['payload']['truncated_by_bytes']);
        self::assertSame($total, $result['payload']['total'], 'total stays the complete set');
        self::assertSame(
            $total - count($result['payload']['blockers']),
            $result['payload']['omitted_count'],
            'omitted_count must describe what this payload actually left out'
        );
        // Dropped from the END: the surviving rows are a prefix of the input.
        self::assertSame(
            array_slice(array_column($envelope['blockers'], 'vm_id'), 0, count($result['payload']['blockers'])),
            array_column($result['payload']['blockers'], 'vm_id')
        );
        self::assertSame($result['json'], deploy_preflight_bounded_json($envelope, 'blockers', $total)['json']);
    }

    public function testAPayloadInsideTheBoundIsUntouched(): void
    {
        $envelope = ['ok' => true, 'blockers' => $this->findings(3)];
        $result = deploy_preflight_bounded_json($envelope, 'blockers', 3);

        self::assertFalse($result['truncated_by_bytes']);
        self::assertCount(3, $result['payload']['blockers']);
        self::assertSame(0, $result['payload']['omitted_count']);
    }

    public function testTheStampSeesTheFinalOmittedCount(): void
    {
        $envelope = ['blockers' => [], 'label' => ''];
        foreach ($this->findings(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT) as $finding) {
            $finding['message'] = str_repeat('y', 2048);
            $envelope['blockers'][] = $finding;
        }
        $total = count($envelope['blockers']);

        $result = deploy_preflight_bounded_json(
            $envelope,
            'blockers',
            $total,
            VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES,
            'total',
            'omitted_count',
            static function (array $payload, int $omitted): array {
                $payload['label'] = 'omitted=' . $omitted;
                return $payload;
            }
        );

        self::assertSame('omitted=' . $result['payload']['omitted_count'], $result['payload']['label']);
        self::assertGreaterThan(0, $result['payload']['omitted_count']);
    }

    public function testAnEnvelopeThatCannotFitEvenEmptySaysSoInsteadOfThrowing(): void
    {
        $envelope = ['fixed' => str_repeat('z', VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES + 10), 'blockers' => $this->findings(3)];

        $result = deploy_preflight_bounded_json($envelope, 'blockers', 3);

        self::assertSame([], $result['payload']['blockers']);
        self::assertTrue($result['payload']['truncated_by_bytes']);
        self::assertSame(3, $result['payload']['omitted_count']);
    }

    public function testUtf8NamesAreNeverCutInsideACodepoint(): void
    {
        $envelope = ['blockers' => []];
        foreach ($this->findings(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT) as $index => $finding) {
            $finding['vm_name'] = 'vm-' . sprintf('%04d', $index) . '-' . str_repeat('äöüß', 400);
            $envelope['blockers'][] = $finding;
        }
        $total = count($envelope['blockers']);

        $result = deploy_preflight_bounded_json($envelope, 'blockers', $total);

        self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES, strlen($result['json']));
        self::assertTrue(mb_check_encoding($result['json'], 'UTF-8'));
        // Every surviving name is intact; the bound drops whole entries and
        // never shortens one.
        foreach ($result['payload']['blockers'] as $blocker) {
            self::assertStringEndsWith(str_repeat('äöüß', 400), (string) $blocker['vm_name']);
        }
        self::assertIsArray(json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTheSharedByteLimiterStopsBeforeAPartialCodepoint(): void
    {
        // 'ä' is two bytes, so an odd budget must end one byte short.
        self::assertSame('ä', virtusphere_bounded_utf8_bytes('äöü', 2));
        self::assertSame('ä', virtusphere_bounded_utf8_bytes('äöü', 3));
        self::assertSame('äö', virtusphere_bounded_utf8_bytes('äöü', 4));
        self::assertSame('', virtusphere_bounded_utf8_bytes('äöü', 1));
        self::assertSame('', virtusphere_bounded_utf8_bytes('äöü', 0));
        self::assertSame('äöü', virtusphere_bounded_utf8_bytes('äöü', 6));
        self::assertSame('äöü', virtusphere_bounded_utf8_bytes('äöü', 99));
        foreach ([1, 2, 3, 4, 5, 6, 7] as $budget) {
            $cut = virtusphere_bounded_utf8_bytes('äöü', $budget);
            self::assertTrue(mb_check_encoding($cut, 'UTF-8'), 'budget ' . $budget);
            self::assertLessThanOrEqual($budget, strlen($cut), 'budget ' . $budget);
        }
        // Invalid input is repaired first, so the result can always be encoded.
        $repaired = virtusphere_bounded_utf8_bytes("valid\xC3\x28tail", 64);
        self::assertTrue(mb_check_encoding($repaired, 'UTF-8'));
        self::assertNotSame('', (string) json_encode(['v' => $repaired], JSON_THROW_ON_ERROR));
    }

    public function testTheWorkerResultIsBoundedInsteadOfRefused(): void
    {
        $vms = [];
        $blockers = [];
        for ($index = 0; $index < VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT + 10; $index++) {
            $vmId = 5000 + $index;
            $vms[] = ['id' => $vmId, 'vm_name' => sprintf('vm-%04d', $index)];
            $blockers[] = vm_network_issue(VIRTUSPHERE_VM_NETWORK_EMPTY, 7, $vmId, sprintf('vm-%04d', $index), '', [$vmId], [0], 1);
        }

        $result = vm_network_preflight_result_contract(VIRTUSPHERE_DEPLOY_MODE_FULL, $vms, $blockers);
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES, strlen((string) $json));
        self::assertCount(VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT, $result['vm_results']);
        self::assertSame(count($vms), $result['vm_results_total']);
        self::assertSame(count($vms) - VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT, $result['vm_results_omitted_count']);
        self::assertSame(count($vms), $result['counts']['blocked_vms'], 'counts describe the complete decision');
        self::assertSame(count($blockers), $result['counts']['issues']);
        // And it stays readable: a bounded document must survive its own decoder.
        self::assertSame($result, vm_network_preflight_decode_result((string) $json));
    }

    public function testBoundingTheResultTwiceDoesNotDeclareTheTruncationAway(): void
    {
        $vms = [];
        $blockers = [];
        for ($index = 0; $index < VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT + 4; $index++) {
            $vmId = 6000 + $index;
            $vms[] = ['id' => $vmId, 'vm_name' => sprintf('vm-%04d', $index)];
            $blockers[] = vm_network_issue(VIRTUSPHERE_VM_NETWORK_EMPTY, 7, $vmId, sprintf('vm-%04d', $index), '', [$vmId], [0], 1);
        }
        $once = vm_network_preflight_result_contract(VIRTUSPHERE_DEPLOY_MODE_FULL, $vms, $blockers);

        $twice = deploy_preflight_bounded_result($once)['result'];

        self::assertSame($once, $twice);
        self::assertSame(count($vms), $twice['vm_results_total']);
        self::assertSame(4, $twice['vm_results_omitted_count']);
    }

    public function testAHistoricalUnboundedResultStaysReadable(): void
    {
        // Written before the display bounds existed: no vm_results_total, no
        // omitted count, no flag. It is a complete list and must read as one.
        $document = [
            'version' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_VERSION,
            'kind' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_KIND,
            'outcome' => 'failed',
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
            'counts' => ['expected_vms' => 1, 'blocked_vms' => 1, 'missing_vms' => 0, 'issues' => 1],
            'missing_vm_ids' => [],
            'vm_results' => [[
                'vm_id' => 42,
                'vm_name' => 'vm-42',
                'outcome' => 'failed',
                'issues' => [['code' => VIRTUSPHERE_VM_NETWORK_EMPTY]],
            ]],
        ];

        $decoded = vm_network_preflight_decode_result(json_encode($document, JSON_THROW_ON_ERROR));

        self::assertNotNull($decoded);
        self::assertSame($document, $decoded);
    }

    public function testADocumentWhoseBoundedCountsDisagreeIsRefused(): void
    {
        $base = [
            'version' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_VERSION,
            'kind' => VIRTUSPHERE_VM_NETWORK_PREFLIGHT_RESULT_KIND,
            'outcome' => 'failed',
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
            'counts' => ['expected_vms' => 3, 'blocked_vms' => 3, 'missing_vms' => 0, 'issues' => 3],
            'missing_vm_ids' => [],
            'vm_results' => [[
                'vm_id' => 42,
                'vm_name' => 'vm-42',
                'outcome' => 'failed',
                'issues' => [['code' => VIRTUSPHERE_VM_NETWORK_EMPTY]],
            ]],
            'vm_results_total' => 3,
            'vm_results_omitted_count' => 2,
            'truncated_by_bytes' => false,
        ];
        self::assertNotNull(vm_network_preflight_decode_result(json_encode($base, JSON_THROW_ON_ERROR)));

        $mismatched = $base;
        $mismatched['vm_results_omitted_count'] = 1;
        self::assertNull(
            vm_network_preflight_decode_result(json_encode($mismatched, JSON_THROW_ON_ERROR)),
            'listed rows plus omitted must equal the declared total'
        );

        $overstated = $base;
        $overstated['counts']['issues'] = 0;
        self::assertNull(
            vm_network_preflight_decode_result(json_encode($overstated, JSON_THROW_ON_ERROR)),
            'a document may say less than it decided, never more'
        );

        $wrongType = $base;
        $wrongType['truncated_by_bytes'] = 'yes';
        self::assertNull(vm_network_preflight_decode_result(json_encode($wrongType, JSON_THROW_ON_ERROR)));

        $completeButShort = $base;
        $completeButShort['vm_results_total'] = 1;
        $completeButShort['vm_results_omitted_count'] = 0;
        $completeButShort['counts'] = ['expected_vms' => 1, 'blocked_vms' => 1, 'missing_vms' => 0, 'issues' => 1];
        self::assertNotNull(vm_network_preflight_decode_result(json_encode($completeButShort, JSON_THROW_ON_ERROR)));
    }
}
