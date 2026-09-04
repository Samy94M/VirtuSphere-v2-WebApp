<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/log_filter.php';

/**
 * The one validated filter struct behind the logs table and its CSV export.
 *
 * The property that matters is not that any single derivation is right; it is
 * that there is only ONE derivation. An export that answers a slightly
 * different question than the screen it was started from is worse than no
 * export, because it looks like evidence.
 */
final class LogFilterTest extends TestCase
{
    public function testAnUnknownTabFallsBackToTheFirstOne(): void
    {
        $first = (string) array_key_first(VIRTUSPHERE_LOG_TABS);

        self::assertSame($first, log_filter_from_query(['tab' => 'nope'])['tab']);
        self::assertSame($first, log_filter_from_query([])['tab']);
    }

    /**
     * A category outside the active tab means "every category in this tab".
     * Silently keeping it would render a tab whose rows cannot appear in it.
     */
    public function testACategoryOutsideTheTabIsDropped(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'category' => VIRTUSPHERE_LOG_CATEGORY_DEPLOY]);

        self::assertSame('', $filter['category']);
        self::assertSame(VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY], $filter['categories']);
    }

    public function testACategoryInsideTheTabNarrowsToExactlyIt(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'category' => VIRTUSPHERE_LOG_CATEGORY_AUTH]);

        self::assertSame([VIRTUSPHERE_LOG_CATEGORY_AUTH], $filter['categories']);
    }

    /**
     * An array-valued parameter must not become a fatal. `?q[]=x` reaches this
     * before any permission check on some paths, so a 500 here is an
     * unauthenticated crash.
     */
    public function testArrayValuedParametersDoNotCrash(): void
    {
        $filter = log_filter_from_query(['tab' => ['x'], 'q' => ['y'], 'ip' => ['z'], 'category' => ['c']]);

        self::assertSame((string) array_key_first(VIRTUSPHERE_LOG_TABS), $filter['tab']);
        self::assertSame('', $filter['search']);
        self::assertSame('', $filter['ip']);
    }

    /** The struct itself is what the repository is handed; nothing unpacks it. */
    public function testTheStructCarriesEveryFieldTheRepositoryReads(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob', 'ip' => '10.0.0.5']);

        self::assertSame('bob', $filter['search']);
        self::assertSame('10.0.0.5', $filter['ip']);
        self::assertSame(VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY], $filter['categories']);
        self::assertFalse(function_exists('log_filter_repo_args'), 'the unpacked argument list is gone for good');
    }

    /**
     * Two requests that select the same rows fingerprint identically, however
     * the parameters arrived. Naming a tab's only category explicitly and
     * leaving it implicit are the same query.
     */
    public function testCanonicalFormIsIndependentOfParameterOrder(): void
    {
        $a = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob', 'ip' => '10.0.0.5']);
        $b = log_filter_from_query(['ip' => '10.0.0.5', 'q' => 'bob', 'tab' => VIRTUSPHERE_LOG_TAB_SECURITY]);

        self::assertSame(log_filter_canonical($a), log_filter_canonical($b));
    }

    public function testDifferentFiltersCanonicaliseDifferently(): void
    {
        $a = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob']);
        $b = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'alice']);

        self::assertNotSame(log_filter_canonical($a), log_filter_canonical($b));
    }

    /**
     * The canonical form carries no derived or presentational field, and it
     * carries the UTC bounds rather than the typed dates: two readers in
     * different display timezones who select the same rows must fingerprint the
     * same, and the same two dates do not mean the same interval to both.
     */
    public function testCanonicalFormHasAFixedKeySet(): void
    {
        self::assertSame(
            [
                'categories', 'correlation', 'event_code', 'from_utc', 'has_ip', 'has_search',
                'ip', 'object_id', 'object_type', 'result', 'search', 'tab', 'to_utc',
            ],
            array_keys(log_filter_canonical(log_filter_from_query([])))
        );
    }

    /**
     * The export limit is a boundary, and 10 000 exactly is NOT truncated:
     * every matching row is in the file, so saying otherwise would send an
     * operator hunting for rows that are already in front of them.
     */
    public function testExportBoundsAtAndAroundTheLimit(): void
    {
        $limit = VIRTUSPHERE_LOG_EXPORT_MAX_ROWS;

        self::assertSame(['limit' => $limit, 'truncated' => false, 'exported' => 0], log_filter_export_bounds(0));
        self::assertSame(['limit' => $limit, 'truncated' => false, 'exported' => 1], log_filter_export_bounds(1));
        self::assertSame(['limit' => $limit, 'truncated' => false, 'exported' => $limit - 1], log_filter_export_bounds($limit - 1));
        self::assertSame(['limit' => $limit, 'truncated' => false, 'exported' => $limit], log_filter_export_bounds($limit));
        self::assertSame(['limit' => $limit, 'truncated' => true, 'exported' => $limit], log_filter_export_bounds($limit + 1));
    }

    /**
     * Every logs.php link is built from the struct, so a filter field cannot be
     * carried by pagination and silently dropped by the export.
     */
    public function testEveryLinkCarriesTheWholeFilter(): void
    {
        $filter = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'q' => 'bob',
            'ip' => '10.0.0.5',
            'category' => VIRTUSPHERE_LOG_CATEGORY_AUTH,
        ]);

        $page = log_filter_url($filter, ['page' => 3]);
        $export = log_filter_url($filter, ['export' => 'csv']);
        foreach (['tab=' . VIRTUSPHERE_LOG_TAB_SECURITY, 'q=bob', 'ip=10.0.0.5', 'category=' . VIRTUSPHERE_LOG_CATEGORY_AUTH] as $part) {
            self::assertStringContainsString($part, $page);
            self::assertStringContainsString($part, $export);
        }
        self::assertStringContainsString('page=3', $page);
        self::assertStringContainsString('export=csv', $export);
    }

    /**
     * Switching tabs (and resetting) lands on the unfiltered view of the target.
     *
     * Everything is dropped, not just the category. An event code, an object
     * type or a correlation id belongs to the section it was chosen in; carried
     * into another tab it selects nothing while still looking like an active
     * filter, so the operator reads an empty log instead of a filter that
     * cannot match here.
     */
    public function testSwitchingTabsLandsOnTheUnfilteredViewOfTheTarget(): void
    {
        $url = log_filter_url(log_filter_empty(VIRTUSPHERE_LOG_TAB_DEPLOY));

        self::assertSame('logs.php?tab=' . VIRTUSPHERE_LOG_TAB_DEPLOY, $url);
    }

    public function testNarrowedIsTrueForEveryFilterFieldAndFalseForABareTab(): void
    {
        self::assertFalse(log_filter_is_narrowed(log_filter_empty(VIRTUSPHERE_LOG_TAB_SECURITY)));

        foreach ([
            ['q' => 'bob'],
            ['ip' => '10.0.0.5'],
            ['category' => VIRTUSPHERE_LOG_CATEGORY_AUTH],
            ['from' => '2026-01-01'],
            ['to' => '2026-01-01'],
            ['correlation' => 'abcdef0123456789'],
            ['event' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN],
            ['object_type' => 'user'],
            ['object_id' => '7'],
            ['result' => VIRTUSPHERE_AUDIT_RESULT_DENIED],
        ] as $extra) {
            $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY] + $extra);
            self::assertTrue(log_filter_is_narrowed($filter), (string) array_key_first($extra) . ' must count as a narrowing');
        }
    }

    /**
     * A local day range is a half-open UTC interval: the whole "from" day, and
     * everything up to but excluding the start of the day after "to".
     */
    public function testALocalDayRangeBecomesAHalfOpenUtcInterval(): void
    {
        $filter = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'from' => '2026-07-01',
            'to' => '2026-07-01',
        ]);

        self::assertSame([], $filter['errors']);
        // Europe/Berlin in July is UTC+2 (the test stack's configured zone).
        self::assertSame('2026-06-30 22:00:00', $filter['from_utc']);
        self::assertSame('2026-07-01 22:00:00', $filter['to_utc']);
    }

    /**
     * The DST days are the whole reason the upper bound adds one DAY and not
     * 86400 seconds. On the March transition the local day is 23 hours long, on
     * the October one 25; fixed arithmetic would cut an hour off the first and
     * hand an hour of the next day to the second, silently and twice a year.
     */
    public function testTheDayAfterIsADayAndNotEightySixThousandFourHundredSeconds(): void
    {
        $spring = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'from' => '2026-03-29', 'to' => '2026-03-29']);
        $autumn = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'from' => '2026-10-25', 'to' => '2026-10-25']);

        $span = static fn (array $f): int => strtotime((string) $f['to_utc'] . ' UTC') - strtotime((string) $f['from_utc'] . ' UTC');

        self::assertSame(23 * 3600, $span($spring), 'the spring-forward day is 23 hours long');
        self::assertSame(25 * 3600, $span($autumn), 'the autumn-back day is 25 hours long');
    }

    public function testAnUnparsableOrImpossibleDateIsRejectedRatherThanIgnored(): void
    {
        foreach (['not-a-date', '2026-02-31', '01.07.2026', '2026-7-1'] as $bad) {
            $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'from' => $bad]);

            self::assertArrayHasKey('from', $filter['errors'], $bad . ' must be rejected');
            self::assertSame($bad, $filter['from'], 'the rejected value stays visible');
            self::assertNull($filter['from_utc'], 'a rejected date must not become a bound');
            self::assertFalse(log_filter_is_usable($filter));
        }
    }

    public function testAReversedRangeIsReportedAndNotSilentlySwapped(): void
    {
        $filter = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'from' => '2026-07-10',
            'to' => '2026-07-01',
        ]);

        self::assertArrayHasKey('to', $filter['errors']);
        self::assertNull($filter['from_utc']);
        self::assertNull($filter['to_utc']);
    }

    public function testAnEmptyBoundIsNoBoundAndNoError(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'from' => '2026-07-01', 'to' => '']);

        self::assertSame([], $filter['errors']);
        self::assertNotNull($filter['from_utc']);
        self::assertNull($filter['to_utc'], 'an open upper bound stays open');
    }

    /**
     * The correlation search is exact. A substring match over a diagnostic id
     * makes it behave like free text: "a1b2" would return the traces of a dozen
     * unrelated requests and read as if they belonged together.
     */
    public function testACorrelationIdIsAcceptedOnlyInFullAndCaseFolded(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'correlation' => 'A1B2C3D4E5F60718']);
        self::assertSame([], $filter['errors']);
        self::assertSame('a1b2c3d4e5f60718', $filter['correlation'], 'stored lowercase, as the writer wrote it');

        // A pasted id may carry surrounding whitespace and is trimmed, not rejected.
        self::assertSame('a1b2c3d4', log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'correlation' => '  a1b2c3d4 '])['correlation']);

        foreach (['a1b2', 'zzzzzzzz', 'a1b2c3d4z', str_repeat('a', 33)] as $bad) {
            $rejected = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'correlation' => $bad]);
            self::assertArrayHasKey('correlation', $rejected['errors'], $bad . ' must be rejected');
            self::assertFalse(log_filter_is_usable($rejected));
        }
    }

    /** A correlation link answers "what else did this request do", nothing narrower. */
    public function testTheCorrelationLinkDropsEveryOtherFilter(): void
    {
        $url = log_filter_correlation_url(VIRTUSPHERE_LOG_TAB_DEPLOY, 'a1b2c3d4');

        self::assertSame('logs.php?tab=' . VIRTUSPHERE_LOG_TAB_DEPLOY . '&correlation=a1b2c3d4', $url);
    }

    /** The structured fields are validated against the registry, not accepted. */
    public function testStructuredFieldsAreCheckedAgainstTheAuditRegistry(): void
    {
        $good = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'event' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN,
            'object_type' => 'user',
            'result' => VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ]);
        self::assertSame([], $good['errors']);

        foreach ([
            'event' => ['event' => 'auth.not_a_real_event'],
            'object_type' => ['object_type' => 'not_a_real_object'],
            'result' => ['result' => 'maybe'],
        ] as $field => $extra) {
            $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY] + $extra);
            self::assertArrayHasKey($field, $filter['errors']);
            self::assertFalse(log_filter_is_usable($filter));
        }
    }

    /**
     * An over-long object id is refused, never truncated: a shortened id selects
     * rows about a different object and nothing downstream can tell it was cut.
     */
    public function testAnOverLongObjectIdIsRefusedRatherThanTruncated(): void
    {
        $long = str_repeat('x', VIRTUSPHERE_LOG_FILTER_OBJECT_ID_MAX + 1);
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'object_id' => $long]);

        self::assertArrayHasKey('object_id', $filter['errors']);
        self::assertSame($long, $filter['object_id'], 'the value stays whole and visible');
    }

    /** Every accepted field survives every link the page builds. */
    public function testEveryFieldSurvivesTheUrlBuilder(): void
    {
        $filter = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'q' => 'bob',
            'ip' => '10.0.0.5',
            'category' => VIRTUSPHERE_LOG_CATEGORY_AUTH,
            'from' => '2026-07-01',
            'to' => '2026-07-02',
            'correlation' => 'a1b2c3d4',
            'event' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN,
            'object_type' => 'user',
            'object_id' => '7',
            'result' => VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ]);

        $url = log_filter_url($filter, ['page' => 2]);
        foreach ([
            'from=2026-07-01', 'to=2026-07-02', 'correlation=a1b2c3d4',
            'event=' . rawurlencode(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN),
            'object_type=user', 'object_id=7', 'result=' . VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ] as $part) {
            self::assertStringContainsString($part, $url, $part . ' must survive the URL builder');
        }
    }

    /**
     * A rejected value is carried through the URL too. Dropping it would clear
     * the field of the very page that is complaining about it, so a reload
     * would silently answer the wide question instead.
     */
    public function testARejectedValueStaysInTheUrl(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'correlation' => 'nope']);

        self::assertStringContainsString('correlation=nope', log_filter_url($filter, ['page' => 2]));
    }

    /**
     * The fingerprint is keyed. A bare digest of a filter this low-entropy is a
     * lookup table: the tab is one of four, the categories come from a list of
     * fourteen, an IP filter is a 32-bit space, and a search term is usually a
     * name. Keying it is what makes "the audit row does not contain the search
     * term" true rather than merely worded that way.
     */
    public function testFingerprintIsKeyedAndNotABareDigestOfTheFilter(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob']);
        $fingerprint = log_filter_fingerprint($filter);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);

        $canonical = json_encode(log_filter_canonical($filter), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertNotSame(hash('sha256', (string) $canonical), $fingerprint, 'the fingerprint is an unkeyed digest');
        self::assertStringNotContainsString('bob', $fingerprint);
    }

    public function testFingerprintIsStableForOneFilterAndDistinctBetweenTwo(): void
    {
        $a = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob']);
        $b = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'alice']);

        self::assertSame(log_filter_fingerprint($a), log_filter_fingerprint($a));
        self::assertNotSame(log_filter_fingerprint($a), log_filter_fingerprint($b));
    }
}
