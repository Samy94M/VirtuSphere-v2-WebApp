<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * The log tabs are a display grouping over a flat category set (deploy_logs.category
 * is a VARCHAR, not an ENUM). Two invariants keep the logs page coherent: every
 * category belongs to exactly one tab, and every category and tab has a label.
 */
final class LogTaxonomyTest extends TestCase
{
    public function testEveryCategoryBelongsToExactlyOneTab(): void
    {
        $seen = [];
        foreach (VIRTUSPHERE_LOG_TABS as $tab => $categories) {
            foreach ($categories as $category) {
                self::assertContains($category, VIRTUSPHERE_LOG_CATEGORIES, $category . ' in tab ' . $tab . ' is not a known category');
                self::assertArrayNotHasKey($category, $seen, $category . ' appears in both ' . ($seen[$category] ?? '') . ' and ' . $tab);
                $seen[$category] = $tab;
            }
        }

        $covered = array_keys($seen);
        sort($covered);
        $all = VIRTUSPHERE_LOG_CATEGORIES;
        sort($all);
        self::assertSame($all, $covered, 'every category must be reachable through exactly one tab');
    }

    public function testTheAuthCategoryExistsAndSitsInTheSecurityTab(): void
    {
        // The security channel is the reason the tabs were reworked; pin it so a
        // future reshuffle cannot quietly bury sign-in events under settings.
        self::assertContains(VIRTUSPHERE_LOG_CATEGORY_AUTH, VIRTUSPHERE_LOG_CATEGORIES);
        self::assertContains(VIRTUSPHERE_LOG_CATEGORY_AUTH, VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY]);
    }

    public function testTheDefaultTabIsSecurity(): void
    {
        // logs.php lands on the first tab; on an admin-only page that is security.
        self::assertSame(VIRTUSPHERE_LOG_TAB_SECURITY, array_key_first(VIRTUSPHERE_LOG_TABS));
    }

    public function testEveryCategoryDeepLinkOpensTheTabThatHoldsIt(): void
    {
        // logs.php validates ?category= against the *active* tab's categories and
        // drops it otherwise. Re-run that exact check against what the builder emits,
        // so a deep link can never arrive at a tab that filters it away.
        foreach (VIRTUSPHERE_LOG_CATEGORIES as $category) {
            $query = [];
            parse_str((string) parse_url(log_category_url($category), PHP_URL_QUERY), $query);

            self::assertArrayHasKey('tab', $query, 'deep link to ' . $category . ' must name its tab');
            self::assertSame($category, $query['category'] ?? null, 'deep link to ' . $category . ' must keep the category');
            self::assertContains(
                $category,
                VIRTUSPHERE_LOG_TABS[$query['tab']] ?? [],
                'tab ' . $query['tab'] . ' does not contain ' . $category . ', so logs.php would discard the filter'
            );
        }
    }

    public function testAnUnknownCategoryDegradesToTheUnfilteredView(): void
    {
        // Better the whole default tab than a filter the page silently ignores.
        self::assertSame('logs.php', log_category_url('not_a_category'));
    }

    public function testEveryCategoryHasANonKeyLabel(): void
    {
        Lang::load('de');
        foreach (VIRTUSPHERE_LOG_CATEGORIES as $category) {
            $label = log_category_label($category);
            self::assertNotSame($category, $label, 'category ' . $category . ' has no translated label');
            self::assertNotSame('', trim($label));
        }
    }

    public function testEveryTabHasANonKeyLabel(): void
    {
        Lang::load('de');
        foreach (array_keys(VIRTUSPHERE_LOG_TABS) as $tab) {
            $label = log_tab_label($tab);
            self::assertNotSame($tab, $label, 'tab ' . $tab . ' has no translated label');
            self::assertNotSame('', trim($label));
        }
    }
}
