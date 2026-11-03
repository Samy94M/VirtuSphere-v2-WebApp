<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/missions_page.php';

/**
 * QL-16: the empty mission and template table names the way to the create form that
 * sits above it, but only for a reader who can submit that form.
 */
final class MissionsEmptyListTest extends TestCase
{
    public function testAWriterIsLinkedToTheCreateFormAboveTheTable(): void
    {
        $mission = missions_empty_list_html(false, true);
        self::assertSame(
            h(__t('missions.empty_mission')) . ' <a href="#mission-create">' . h(__t('missions.empty_create_link')) . '</a>',
            $mission
        );

        $template = missions_empty_list_html(true, true);
        self::assertStringStartsWith(h(__t('missions.empty_template')), $template);
        self::assertStringContainsString('href="#mission-create"', $template);
    }

    public function testAReaderGetsTheSentenceWithoutAnUnusableLink(): void
    {
        $html = missions_empty_list_html(false, false);
        self::assertSame(h(__t('missions.empty_mission')), $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    /**
     * NL1 (user decision 01.11.2026): the attention-only filter is gone; the dashboard
     * tile "VM check due" with its four groups replaces it. An old link with
     * attention=1 loads the normal list, the parameter is simply ignored.
     */
    public function testTheMissionListHasNoAttentionOnlyFilterAnyMore(): void
    {
        $missions = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/missions.php');
        self::assertDoesNotMatchRegularExpression("/request_string\\(\\\$_GET, 'attention'/", $missions, 'missions.php reads no attention parameter');
        self::assertStringNotContainsString('attention=1', $missions, 'no link or export carries the old filter');
        foreach (['de', 'en'] as $locale) {
            $texts = require dirname(__DIR__, 2) . '/lang/' . $locale . '/missions.php';
            foreach (['attention_filter_hint', 'attention_clear', 'attention_empty'] as $key) {
                self::assertArrayNotHasKey($key, $texts, $locale . ': ' . $key . ' belongs to the removed filter');
            }
        }
    }
}
