<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/audit_log_view.php';

/**
 * The logs page shows a structured row in the viewer's language and every other row
 * as stored. A display must never blank a row, so each failure below falls back to
 * the stored text.
 */
final class AuditLogDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::load('de');
    }

    /** @return array<string,mixed> */
    private function structuredRow(array $overrides = []): array
    {
        return array_merge([
            'event_code' => VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED,
            'object_type' => 'setting',
            'object_id' => 'portal_timezone',
            'result' => VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            'context_json' => '{"action":"updated","new_value":"UTC","old_value":"Europe/Berlin"}',
            'log_message' => 'updated portal timezone Europe/Berlin -> UTC',
        ], $overrides);
    }

    public function testAStructuredRowShowsTheSentenceInTheViewersLanguage(): void
    {
        self::assertSame('Einstellung portal timezone geändert: Europe/Berlin -> UTC', audit_log_display_message($this->structuredRow()));
    }

    public function testAnEnglishViewerSeesTheStoredSentence(): void
    {
        Lang::load('en');

        self::assertSame('updated portal timezone Europe/Berlin -> UTC', audit_log_display_message($this->structuredRow()));
    }

    public function testARowWithoutAnEventCodeShowsItsStoredText(): void
    {
        $row = $this->structuredRow(['event_code' => null, 'log_message' => 'legacy line written before the structured columns']);

        self::assertSame('legacy line written before the structured columns', audit_log_display_message($row));
    }

    public function testAnUnreadableContextShowsTheStoredText(): void
    {
        $row = $this->structuredRow(['context_json' => '{"action": ']);

        self::assertSame('updated portal timezone Europe/Berlin -> UTC', audit_log_display_message($row));
    }

    public function testAPresenterErrorShowsTheStoredText(): void
    {
        $row = $this->structuredRow(['event_code' => 'auth.not_an_event', 'context_json' => '{}', 'log_message' => 'stored sentence']);

        self::assertSame('stored sentence', audit_log_display_message($row));
    }

    public function testAMissingContextCountsAsAnEmptyOne(): void
    {
        $row = $this->structuredRow([
            'event_code' => VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT,
            'object_type' => 'user',
            'object_id' => '42',
            'context_json' => null,
        ]);

        self::assertSame('Abmeldung', audit_log_display_message($row));
    }
}
