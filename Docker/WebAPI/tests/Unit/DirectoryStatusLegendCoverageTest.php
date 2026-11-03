<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/directory_constants.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/system_status.php';

/**
 * A state VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES/VIRTUSPHERE_DIRECTORY_AMPEL_STATES
 * lists but a locale has no key for would render the raw catalog key instead
 * of a word (Lang::get()'s documented missing-key fallback), in the exact
 * spot help_system_status.php's directory legend and the System status card
 * point an operator at. Walks the constants rather than listing five words:
 * a state added to either one without a matching key would otherwise reach
 * both the badge and the legend silently.
 */
final class DirectoryStatusLegendCoverageTest extends TestCase
{
    public function testEveryControllerStateHasABadgeLabelAndALegendLineInEveryLocale(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES as $state) {
                $badgeKey = 'system_status.directory_controller_state_' . $state;
                self::assertNotSame($badgeKey, __t($badgeKey), "$locale: controller badge label missing for state '$state'.");
                $legendKey = 'system_status.directory_legend_' . $state;
                self::assertNotSame($legendKey, __t($legendKey), "$locale: legend line missing for state '$state'.");
            }
        }
    }

    public function testEveryOverallStateHasABadgeLabelInEveryLocale(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (VIRTUSPHERE_DIRECTORY_AMPEL_STATES as $state) {
                $key = 'system_status.directory_state_' . $state;
                self::assertNotSame($key, __t($key), "$locale: overall badge label missing for state '$state'.");
            }
        }
    }

    /** The shared renderer must actually reach the 'directory' branch, not silently fall through to the default heartbeat legend. */
    public function testTheSharedLegendRendererListsExactlyTheControllerStatesInOrder(): void
    {
        Lang::load('de');
        ob_start();
        system_status_legend_items('directory');
        $html = (string) ob_get_clean();

        // Matches system_status_legend_items()'s own $params map: 'warning'
        // and 'stale' each quote a different constant under the same :days
        // placeholder name.
        $params = [
            'warning' => ['days' => VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS],
            'stale' => ['days' => VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS],
        ];
        foreach (VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES as $state) {
            self::assertStringContainsString(
                h(__t('system_status.directory_legend_' . $state, $params[$state] ?? [])),
                $html,
                "legend HTML is missing the line for state '$state'"
            );
        }
        self::assertSame(count(VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES), substr_count($html, '<li>'));
    }
}
