<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Two portal shapes that look like styling decisions but are contracts.
 *
 * The first is the server-rendered page navigation. Missions/templates and the
 * detail/VM pair each switch between two ADDRESSES, and a set of links that
 * merely looks like tabs must not be announced as an ARIA tab widget: that
 * promises a panel swapped in place, arrow-key movement between the tabs and a
 * single tab stop, none of which exist here. So `role="tab"` and
 * `aria-selected` stay out of the portal pages by contract, and exactly one
 * entry carries `aria-current="page"` - two would say the reader is in two
 * places at once, none would leave the whole set unanchored. The one exception
 * is help.php/settings.php, which are real in-page tab widgets with real panels
 * and are named here rather than matched by a pattern.
 *
 * The second is the pinned action column. It is opt-in on purpose: the VM list
 * is the only table wide enough that the row actions leave the viewport while
 * the row is still the one the operator means, and pinning a column costs
 * horizontal space on every table that does not need it. users.php is called
 * out explicitly because it is the other table with row actions and the one a
 * later copy-paste would reach for first.
 */
final class PortalPageNavContractTest extends TestCase
{
    /**
     * The real ARIA tab widgets. Both render panels in the same document and
     * drive them from assets/core.js, so they keep role="tab"/aria-selected.
     *
     * @var list<string>
     */
    private const TAB_WIDGET_PAGES = ['portal/help.php', 'portal/settings.php'];

    /** The one table that opts into the pinned action column. */
    private const STICKY_ACTIONS_PAGE = 'portal/vms.php';

    private function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function source(string $relative): string
    {
        $path = $this->repoRoot() . '/' . $relative;
        self::assertFileExists($path, $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    /**
     * The file with its PHP comments removed.
     *
     * The guards below look for MARKUP, and every rule here is one a comment
     * has a legitimate reason to name: this contract is explained in prose in
     * lib/layout_presenters.php, and a page that opts out says why. Matching
     * the explanation of a rule as a violation of it is how a guard teaches
     * people to stop writing the explanation.
     */
    private function markup(string $relative): string
    {
        $out = '';
        foreach (token_get_all($this->source($relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Every portal page plus the lib/ modules they render through, which is
     * where a partial extracted out of a page would otherwise escape the scan
     * (the same reason PortalConfirmNamingContractTest globs instead of listing).
     *
     * @return list<string>
     */
    private function portalSources(): array
    {
        $out = [];
        foreach (['portal/*.php', 'lib/*.php', 'lib/*/*.php'] as $pattern) {
            foreach (glob($this->repoRoot() . '/' . $pattern) ?: [] as $path) {
                $out[] = substr(str_replace('\\', '/', $path), strlen($this->repoRoot()) + 1);
            }
        }
        self::assertNotSame([], $out, 'the portal source scan matched nothing at all');

        return $out;
    }

    public function testOnlyTheRealTabWidgetsUseTabRoles(): void
    {
        foreach ($this->portalSources() as $relative) {
            if (in_array($relative, self::TAB_WIDGET_PAGES, true)) {
                continue;
            }
            $source = $this->markup($relative);
            self::assertStringNotContainsString(
                'role="tab"',
                $source,
                $relative . ' announces an ARIA tab widget; page navigation uses portal_page_nav() instead'
            );
            self::assertStringNotContainsString(
                'aria-selected',
                $source,
                $relative . ' uses aria-selected outside a real tab widget; the current page is aria-current="page"'
            );
        }
    }

    public function testTheCurrentPageMarkerIsOnlyWrittenByTheHelper(): void
    {
        foreach ($this->portalSources() as $relative) {
            if ($relative === 'lib/layout_presenters.php' || $relative === 'lib/layout.php') {
                continue;
            }
            self::assertStringNotContainsString(
                'aria-current="page"',
                $this->markup($relative),
                $relative . ' hand-writes aria-current="page"; render the navigation with portal_page_nav()'
            );
        }
    }

    public function testTheHelperNeverEmitsTabWidgetSemantics(): void
    {
        $source = $this->source('lib/layout_presenters.php');
        $start = strpos($source, 'function portal_page_nav(');
        self::assertNotFalse($start, 'portal_page_nav() must exist in lib/layout_presenters.php');
        $body = substr($source, $start);

        self::assertStringNotContainsString('role="tab"', $body, 'portal_page_nav() must not emit role="tab"');
        self::assertStringNotContainsString('aria-selected', $body, 'portal_page_nav() must not emit aria-selected');
        self::assertStringContainsString('aria-current="page"', $body, 'portal_page_nav() must mark the current entry');
    }

    public function testOnlyTheVmListOptsIntoThePinnedActionColumn(): void
    {
        foreach ($this->portalSources() as $relative) {
            if ($relative === self::STICKY_ACTIONS_PAGE) {
                continue;
            }
            $source = $this->markup($relative);
            self::assertStringNotContainsString(
                'table-sticky-actions',
                $source,
                $relative . ' opts into the pinned action column; only ' . self::STICKY_ACTIONS_PAGE . ' is wide enough to need it'
            );
            self::assertStringNotContainsString(
                'table-action-cell',
                $source,
                $relative . ' marks a pinned action cell without opting the table in; the cell class alone does nothing'
            );
        }
    }

    public function testTheVmListMarksBothItsHeaderAndItsBodyCell(): void
    {
        $source = $this->source(self::STICKY_ACTIONS_PAGE);
        self::assertStringContainsString('class="table-sticky-actions"', $source, 'the VM list must opt its table in');
        self::assertStringContainsString(
            'th class="table-action-cell"',
            $source,
            'the VM list must pin the action header cell, or the header scrolls away from its column'
        );
        self::assertStringContainsString(
            'td class="actions table-action-cell"',
            $source,
            'the VM list must pin the action body cell'
        );
    }

    public function testUsersPageStaysOutOfThePinnedColumn(): void
    {
        $source = $this->source('portal/users.php');
        self::assertStringNotContainsString('table-sticky-actions', $source, 'users.php is exempt by decision, not by accident');
    }

    public function testTheStylesheetDefinesBothClassesAndStacksThemAboveTheStickyHeader(): void
    {
        $components = $this->source('portal/assets/css/components.css');
        $base = $this->source('portal/assets/css/base.css');

        self::assertStringContainsString('.table-sticky-actions .table-action-cell', $components);
        self::assertStringContainsString('position: sticky', $components);

        // The body cell slides over the data cells, the header cell over both.
        // Read out of the source rather than restated, so a later edit to any of
        // the three numbers has to keep the order.
        $bodyZ = $this->zIndexOf($components, '.table-sticky-actions td.table-action-cell');
        $headZ = $this->zIndexOf($components, '.table-sticky-actions th.table-action-cell');
        $headerZ = $this->zIndexOf($base, 'th');

        self::assertGreaterThan($headerZ, $bodyZ, 'the pinned body cell must clear the sticky table header');
        self::assertGreaterThan($bodyZ, $headZ, 'the pinned header cell must clear the pinned body cells');
    }

    public function testThePinnedColumnHasAnOpaqueBackgroundAndAHoverState(): void
    {
        $components = $this->source('portal/assets/css/components.css');

        self::assertMatchesRegularExpression(
            '/\.table-sticky-actions \.table-action-cell \{[^}]*background: var\(--surface\)/',
            $components,
            'the pinned cell must paint its own opaque background; it overlaps the cells beside it'
        );
        self::assertStringContainsString(
            'tbody tr:hover > td.table-action-cell',
            $components,
            'the pinned cell must repeat the row hover tint, or the hovered row breaks at the pinned column'
        );
    }

    public function testThePinnedColumnIsReleasedOnNarrowViewports(): void
    {
        $components = $this->source('portal/assets/css/components.css');
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 720px\) \{[^@]*\.table-sticky-actions \.table-action-cell \{[^}]*position: static/s',
            $components,
            'the pinned column must be released below the wrap breakpoint'
        );
    }

    /**
     * The z-index a selector declares. Anchored at the start of a line so a
     * short selector like `th` cannot match the tail of a longer one.
     */
    private function zIndexOf(string $css, string $selector): int
    {
        $pattern = '/^' . preg_quote($selector, '/') . ' \{(.*?)\}/ms';
        self::assertSame(1, preg_match($pattern, $css, $block), 'selector ' . $selector . ' must exist in the stylesheet');
        self::assertSame(1, preg_match('/z-index:\s*(\d+)/', $block[1], $m), $selector . ' must declare a z-index');

        return (int) $m[1];
    }
}
