<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * The portal audit log is pruned on two windows (ADR-0026): the security
 * categories keep a year, everything else a quarter, and the login-attempt
 * counter keeps its own short window. These invariants pin the policy so a
 * later "simplification" back to one flat window fails the build, and so a tab
 * reshuffle cannot quietly shorten a security category's retention.
 */
final class LogRetentionTest extends TestCase
{
    public function testWindowsAreOrderedAndPositive(): void
    {
        self::assertGreaterThan(VIRTUSPHERE_LOG_RETENTION_DAYS, VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS);
        self::assertGreaterThan(VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS, VIRTUSPHERE_LOG_RETENTION_DAYS);
        self::assertGreaterThan(0, VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS);
    }

    public function testSecurityTabGetsTheLongWindowAndEveryOtherTabTheGeneralOne(): void
    {
        foreach (array_keys(VIRTUSPHERE_LOG_TABS) as $tab) {
            $expected = $tab === VIRTUSPHERE_LOG_TAB_SECURITY
                ? VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS
                : VIRTUSPHERE_LOG_RETENTION_DAYS;
            self::assertSame($expected, log_retention_days_for_tab($tab), 'wrong window for tab ' . $tab);
        }

        // An unknown tab must not accidentally claim the long security window.
        self::assertSame(VIRTUSPHERE_LOG_RETENTION_DAYS, log_retention_days_for_tab('not_a_tab'));
    }

    public function testEveryCategoryResolvesToAPositiveWindowThroughItsTab(): void
    {
        foreach (VIRTUSPHERE_LOG_CATEGORIES as $category) {
            $tab = null;
            foreach (VIRTUSPHERE_LOG_TABS as $tabKey => $categories) {
                if (in_array($category, $categories, true)) {
                    $tab = $tabKey;
                    break;
                }
            }
            self::assertNotNull($tab, 'category ' . $category . ' belongs to no tab');
            self::assertGreaterThan(0, log_retention_days_for_tab($tab));
        }
    }

    public function testTheLongWindowCoversExactlyTheDecidedSecurityCategories(): void
    {
        // The security window is a compliance promise; a tab reshuffle now has to
        // change this list consciously instead of silently shortening a category.
        //
        // `machine_api` was added deliberately: a refused machine access is a
        // security event, and the misconfiguration behind the commonest one (a
        // missing IP allowlist entry) can sit unnoticed for months, which is
        // exactly the case a quarter-long window would lose.
        $security = VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY];
        sort($security);
        $expected = [
            VIRTUSPHERE_LOG_CATEGORY_AUTH,
            VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS,
            VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
            VIRTUSPHERE_LOG_CATEGORY_USERS,
        ];
        sort($expected);
        self::assertSame($expected, $security);
    }
}
