<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_presenter.php';
require_once __DIR__ . '/lang.php';

/**
 * The text a logs-page row shows, in the viewer's locale.
 *
 * A structured row (an event code is stored) is rendered again from its columns,
 * so the same row reads in German for a German viewer and in English for an English
 * one. Search, the CSV export and the saved filters keep the stored English text
 * (log_message). A row without an event code is a legacy line and shows its stored
 * text. Every failure (an unreadable context, a presenter error) also shows the
 * stored text, so a row is never blank because of its display.
 *
 * @param array<string,mixed> $row A deploy_logs row with log_message and, when present, event_code, object_type, object_id, result and context_json.
 */
function audit_log_display_message(array $row): string
{
    $stored = (string) ($row['log_message'] ?? '');
    $eventCode = (string) ($row['event_code'] ?? '');
    if ($eventCode === '') {
        return $stored;
    }

    $json = $row['context_json'] ?? null;
    $context = [];
    if (is_string($json) && $json !== '') {
        $decoded = json_decode($json, true, 32);
        if (!is_array($decoded)) {
            return $stored;
        }
        $context = $decoded;
    }

    $objectId = $row['object_id'] ?? null;
    try {
        return audit_event_description(
            $eventCode,
            (string) ($row['object_type'] ?? ''),
            $objectId === null ? null : (string) $objectId,
            (string) ($row['result'] ?? ''),
            $context,
            Lang::locale()
        );
    } catch (Throwable $exception) {
        return $stored;
    }
}
