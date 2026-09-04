<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * os.php, packages.php and vlans.php share one catalog status filter. The token
 * set lives in VIRTUSPHERE_CATALOG_FILTERS (lib/constants.php) so the three
 * pages cannot drift apart. This pins that: no page may re-inline the
 * ['active','retired','all'] literal, and each must reference the constant. A
 * new catalog page copy-pasting the old literal fails here instead of silently
 * gaining a fourth, unvalidated token or losing one.
 */
final class CatalogFilterContractTest extends TestCase
{
    private const PAGES = ['portal/os.php', 'portal/packages.php', 'portal/vlans.php'];

    private function source(string $page): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $page;
        self::assertFileExists($path, $page . ' must exist');

        return (string) file_get_contents($path);
    }

    public function testNoPageInlinesTheFilterTokenList(): void
    {
        foreach (self::PAGES as $page) {
            $source = $this->source($page);
            self::assertSame(
                0,
                preg_match("/\\[\\s*'active'\\s*,\\s*'retired'\\s*,\\s*'all'\\s*\\]/", $source),
                $page . ' re-inlines the catalog filter list; use VIRTUSPHERE_CATALOG_FILTERS instead'
            );
        }
    }

    public function testEveryPageReferencesTheConstant(): void
    {
        foreach (self::PAGES as $page) {
            self::assertStringContainsString(
                'VIRTUSPHERE_CATALOG_FILTERS',
                $this->source($page),
                $page . ' must validate its status filter against VIRTUSPHERE_CATALOG_FILTERS'
            );
        }
    }

    /**
     * The control itself, not just its tokens. vlans.php rendered a row of
     * links over the same constant: same values, a different shape, and the one
     * of the three that could not carry further query state. Sharing the token
     * list while re-inventing the control is exactly the drift the constant was
     * supposed to end.
     */
    public function testEveryPageRendersTheSharedFilterControl(): void
    {
        foreach (self::PAGES as $page) {
            $source = $this->source($page);
            self::assertStringContainsString(
                'portal_catalog_status_filter(',
                $source,
                $page . ' must render the shared catalog filter, not its own control'
            );
            self::assertSame(
                0,
                preg_match('/foreach \(VIRTUSPHERE_CATALOG_FILTERS/', $source),
                $page . ' walks the filter tokens to build its own control; portal_catalog_status_filter() owns that'
            );
        }
    }

    /**
     * An empty table means one of two opposite things, and the page must not
     * decide it with its own inline condition. A catalog its source has not
     * filled yet points at MECM or ESXi; a filter that matched nothing points at
     * a link on this page. All three printed the first sentence for both.
     */
    public function testEveryPageTellsAnEmptyCatalogFromAnEmptyFilterResult(): void
    {
        foreach (self::PAGES as $page) {
            self::assertStringContainsString(
                'portal_catalog_empty_state(',
                $this->source($page),
                $page . ' must answer an empty table through the shared empty-state helper'
            );
        }
    }

    /**
     * The way out of a filter keeps the sort. Dropping it re-sorts the table
     * under a reader who only asked to see more rows, and packages.php is the
     * one of the three that has a sort to lose.
     */
    public function testTheWayOutOfAFilterKeepsTheSort(): void
    {
        $source = $this->source('portal/packages.php');
        self::assertMatchesRegularExpression(
            "/portal_catalog_empty_state\([^;]*'sort' => \\\$sort/s",
            $source,
            'packages.php must carry its sort through the "show all entries" link'
        );
    }
}
