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
        $mission = missions_empty_list_html(false, false, true);
        self::assertSame(
            h(__t('missions.empty_mission')) . ' <a href="#mission-create">' . h(__t('missions.empty_create_link')) . '</a>',
            $mission
        );

        $template = missions_empty_list_html(false, true, true);
        self::assertStringStartsWith(h(__t('missions.empty_template')), $template);
        self::assertStringContainsString('href="#mission-create"', $template);
    }

    public function testAReaderGetsTheSentenceWithoutAnUnusableLink(): void
    {
        $html = missions_empty_list_html(false, false, false);
        self::assertSame(h(__t('missions.empty_mission')), $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testTheAttentionFilterKeepsItsOwnSentenceAndNoLink(): void
    {
        self::assertSame(h(__t('missions.attention_empty')), missions_empty_list_html(true, false, true));
        self::assertSame(h(__t('missions.attention_empty')), missions_empty_list_html(true, true, true));
    }
}
