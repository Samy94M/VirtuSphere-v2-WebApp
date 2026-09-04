<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

/**
 * The three answers an empty catalog table can give.
 *
 * The distinction is the whole point: a catalog its source has not filled yet
 * is a sentence about MECM or ESXi and there is nothing on this page to click;
 * a filter that matched nothing is a sentence about this page and carries the
 * way out. The third case is the one that is easy to get wrong: under
 * `status=all` there is no filter left to widen, so offering "show all entries"
 * would be a link back to the same empty table.
 */
final class CatalogEmptyStateTest extends TestCase
{
    private const LABELS = [
        'empty' => 'Nothing synced yet.',
        'empty_filtered' => 'No entry matches this filter.',
        'show_all' => 'Show all entries',
    ];

    public function testAnEmptyCatalogNamesItsSourceAndOffersNoWayOut(): void
    {
        $html = portal_catalog_empty_state('os.php', 'active', false, self::LABELS);

        self::assertSame('Nothing synced yet.', $html);
        self::assertStringNotContainsString('<a', $html, 'there is no filter to widen; a link would show the same nothing');
    }

    public function testAFilteredResultNamesTheFilterAndCarriesTheWayOut(): void
    {
        $html = portal_catalog_empty_state('os.php', 'retired', true, self::LABELS);

        self::assertStringContainsString('No entry matches this filter.', $html);
        self::assertStringContainsString('href="os.php?status=all"', $html);
        self::assertStringContainsString('>Show all entries</a>', $html);
    }

    public function testUnderShowAllThereIsNothingLeftToWiden(): void
    {
        $html = portal_catalog_empty_state('os.php', 'all', true, self::LABELS);

        self::assertSame('Nothing synced yet.', $html);
    }

    public function testTheWayOutKeepsTheSortAndPutsTheStatusLast(): void
    {
        $html = portal_catalog_empty_state('packages.php', 'active', true, self::LABELS, ['sort' => 'version', 'dir' => 'desc']);

        // Sort first, status last: a caller cannot accidentally hand in its own
        // `status` and win, which would send the reader back to the empty view.
        self::assertStringContainsString('href="packages.php?sort=version&amp;dir=desc&amp;status=all"', $html);
    }

    public function testEveryLabelIsEscaped(): void
    {
        $html = portal_catalog_empty_state('os.php', 'retired', true, [
            'empty' => '<b>x</b>',
            'empty_filtered' => 'Nothing <b>here</b>',
            'show_all' => 'Show "all"',
        ]);

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;here&lt;/b&gt;', $html);
        self::assertStringContainsString('Show &quot;all&quot;', $html);
    }
}
