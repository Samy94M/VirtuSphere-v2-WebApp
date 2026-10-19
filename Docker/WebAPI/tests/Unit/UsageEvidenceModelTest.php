<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/usage_evidence.php';

/**
 * F08 "Verwendet in": the pure rules the reader and the renderer share. The
 * database behaviour is pinned by UsageEvidenceTest (integration).
 */
final class UsageEvidenceModelTest extends TestCase
{
    public function testOnlyOneDecimalUsageIdInTheIntRangeSelectsARow(): void
    {
        self::assertSame(12, usage_evidence_requested_id('12'));
        self::assertSame(12, usage_evidence_requested_id(12));
        self::assertSame(2147483647, usage_evidence_requested_id('2147483647'));

        // Anything that is not one plain number is "no row", never a near miss:
        // "12abc" must not select row 12, and an array must not throw.
        self::assertSame(0, usage_evidence_requested_id('12abc'));
        self::assertSame(0, usage_evidence_requested_id('012'));
        self::assertSame(0, usage_evidence_requested_id('0'));
        self::assertSame(0, usage_evidence_requested_id('-3'));
        self::assertSame(0, usage_evidence_requested_id(' 7'));
        self::assertSame(0, usage_evidence_requested_id('2147483648'));
        self::assertSame(0, usage_evidence_requested_id(['5']));
        self::assertSame(0, usage_evidence_requested_id(null));
        self::assertSame(0, usage_evidence_requested_id(''));
        self::assertSame(0, usage_evidence_requested_id(-1));
    }

    public function testHiddenCountIsTheCompleteTotalMinusTheShownRows(): void
    {
        self::assertSame(2, usage_evidence_hidden_count(12, 10));
        self::assertSame(0, usage_evidence_hidden_count(3, 10));
        self::assertSame(0, usage_evidence_hidden_count(0, 0));
        self::assertSame(0, usage_evidence_hidden_count(4, 9));
    }

    public function testTheRowLimitStaysInsideItsBounds(): void
    {
        self::assertSame(1, usage_evidence_clamp_limit(0));
        self::assertSame(1, usage_evidence_clamp_limit(-5));
        self::assertSame(VIRTUSPHERE_USAGE_ROW_LIMIT, usage_evidence_clamp_limit(VIRTUSPHERE_USAGE_ROW_LIMIT));
        self::assertSame(VIRTUSPHERE_USAGE_ROW_LIMIT_MAX, usage_evidence_clamp_limit(500));
    }

    public function testAnObjectIsUnusedOnlyWhenNoApplicableGroupHoldsAReference(): void
    {
        $none = ['applies' => true, 'total' => 0, 'rows' => []];
        $inapplicable = ['applies' => false, 'total' => 5, 'rows' => []];

        self::assertTrue(usage_evidence_is_unused(['groups' => [
            VIRTUSPHERE_USAGE_GROUP_DIRECT => $none,
            VIRTUSPHERE_USAGE_GROUP_MISSION => $inapplicable,
        ]]));

        // A count in a group that does not apply to the object is not a use of it.
        self::assertTrue(usage_evidence_is_unused(['groups' => [
            VIRTUSPHERE_USAGE_GROUP_DIRECT => ['applies' => false, 'total' => 3, 'rows' => []],
            VIRTUSPHERE_USAGE_GROUP_JOB_OPEN => $none,
        ]]));

        self::assertFalse(usage_evidence_is_unused(['groups' => [
            VIRTUSPHERE_USAGE_GROUP_DIRECT => ['applies' => true, 'total' => 1, 'rows' => []],
        ]]));
        self::assertFalse(usage_evidence_is_unused(['groups' => [
            VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY => ['applies' => true, 'total' => 2, 'rows' => []],
        ]]));
    }

    public function testAnExpansionOpensOnlyForARowThePageListsUnderTheFilter(): void
    {
        $rows = [['id' => 4, 'name' => 'a'], ['id' => 9, 'name' => 'b']];

        self::assertSame('b', usage_evidence_find_row($rows, 9)['name'] ?? null);
        self::assertNull(usage_evidence_find_row($rows, 5));
        self::assertNull(usage_evidence_find_row($rows, 0));
        self::assertNull(usage_evidence_find_row([], 4));
    }

    public function testAnchorsAreStableAndDistinctPerKind(): void
    {
        self::assertSame('usage-7', usage_evidence_anchor(7));
        self::assertSame('credential-usage-7', usage_evidence_credential_anchor(7));
    }
}
