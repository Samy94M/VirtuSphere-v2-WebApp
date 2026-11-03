<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_object_names.php';

final class EsxiObjectNamesTest extends TestCase
{
    public function testRawClassificationRejectsUnsafeNamesAndPreservesBoundaryEvidence(): void
    {
        $invalid = [
            '' => 'empty',
            "\t" => 'control_character',
            "bad\0name" => 'nul',
            "bad\u{200B}name" => 'control_character',
            str_repeat('x', VIRTUSPHERE_ESXI_OBJECT_NAME_MAX_CHARS + 1) => 'exceeds_internal_name_limit',
            "\xC3\x28" => 'invalid_utf8',
        ];
        foreach ($invalid as $name => $reason) {
            $result = esxi_object_name_classify_raw($name);
            self::assertFalse($result['persistable'], $reason);
            self::assertSame($reason, $result['reason']);
        }

        $boundary = esxi_object_name_classify_raw("\u{00A0}Prod");
        self::assertTrue($boundary['persistable']);
        self::assertFalse($boundary['supported']);
        self::assertSame("\u{00A0}Prod", $boundary['name']);
        self::assertSame('boundary_whitespace', $boundary['reason']);
    }

    public function testExactMatchingNeverAuthorizesCaseOrUnicodeVariants(): void
    {
        self::assertSame(VIRTUSPHERE_ESXI_NAME_EXACT, esxi_object_name_match('Pröd', ['pröd', 'Pröd'])['state']);
        $case = esxi_object_name_match('PRÖD', ['Pröd', 'pröd']);
        self::assertSame(VIRTUSPHERE_ESXI_NAME_CASE_MISMATCH, $case['state']);
        self::assertSame(['Pröd', 'pröd'], $case['candidates']);
        self::assertFalse(esxi_object_name_equals('Pröd', 'pröd'));
        self::assertSame(VIRTUSPHERE_ESXI_NAME_INVENTORY_UNKNOWN, esxi_object_name_match('Pröd', [], false)['state']);
        self::assertSame(2, $case['candidate_total']);
        self::assertSame(0, $case['candidate_omitted_count']);
    }

    /**
     * The inventory decides how many spellings exist, so the candidate list is
     * bounded here and both counts stay complete (correction plan 16.4).
     */
    public function testCandidatesAreBoundedWhileTheirCountsStayComplete(): void
    {
        $inventory = ['PROD', 'PROd', 'PRoD', 'PRod', 'pROD', 'pROd', 'proD'];

        $match = esxi_object_name_match('prod', $inventory);

        self::assertSame(VIRTUSPHERE_ESXI_NAME_CASE_MISMATCH, $match['state']);
        self::assertCount(VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT, $match['candidates']);
        self::assertSame(7, $match['candidate_total']);
        self::assertSame(7 - VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT, $match['candidate_omitted_count']);
        self::assertSame($match['candidates'], esxi_object_name_match('prod', $inventory)['candidates']);
        self::assertSame(['PROD', 'PROd', 'PRoD', 'PRod', 'pROD'], $match['candidates']);

        // An exact hit is one candidate, and a state without candidates reports
        // zero rather than omitting the fields.
        $exact = esxi_object_name_match('PROD', $inventory);
        self::assertSame(1, $exact['candidate_total']);
        self::assertSame(0, $exact['candidate_omitted_count']);
        $missing = esxi_object_name_match('Other', $inventory);
        self::assertSame(VIRTUSPHERE_ESXI_NAME_MISSING, $missing['state']);
        self::assertSame(0, $missing['candidate_total']);
        self::assertSame(0, $missing['candidate_omitted_count']);
    }
}
