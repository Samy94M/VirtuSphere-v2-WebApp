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

    /** The table and the export take the same three arguments, in one place. */
    public function testRepoArgumentsAreDerivedFromTheStructAlone(): void
    {
        $filter = log_filter_from_query(['tab' => VIRTUSPHERE_LOG_TAB_SECURITY, 'q' => 'bob', 'ip' => '10.0.0.5']);

        self::assertSame(
            ['bob', '10.0.0.5', VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY]],
            log_filter_repo_args($filter)
        );
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

    /** The canonical form carries no derived or presentational field. */
    public function testCanonicalFormHasAFixedKeySet(): void
    {
        self::assertSame(
            ['categories', 'has_ip', 'has_search', 'ip', 'search', 'tab'],
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
     * Switching tabs keeps the free-text filters and drops the tab-scoped
     * category, which the target tab does not contain.
     */
    public function testTabSwitchDropsTheCategoryAndKeepsTheRest(): void
    {
        $filter = log_filter_from_query([
            'tab' => VIRTUSPHERE_LOG_TAB_SECURITY,
            'q' => 'bob',
            'category' => VIRTUSPHERE_LOG_CATEGORY_AUTH,
        ]);

        $url = log_filter_url([...$filter, 'tab' => VIRTUSPHERE_LOG_TAB_DEPLOY], [], false);
        self::assertStringContainsString('tab=' . VIRTUSPHERE_LOG_TAB_DEPLOY, $url);
        self::assertStringContainsString('q=bob', $url);
        self::assertStringNotContainsString('category=', $url);
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
