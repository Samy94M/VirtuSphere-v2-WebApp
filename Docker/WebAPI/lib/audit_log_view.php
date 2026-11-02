<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_presenter.php';
require_once __DIR__ . '/lang.php';

/**
 * Context fields that can carry a free text from a host or from Ansible (event =>
 * [context key => code field]). A closed word of the field stays in the sentence;
 * anything else is a technical detail and never on the first look of the logs page
 * (portal rule of 29.10.2026). The stored sentence keeps it either way.
 */
const VIRTUSPHERE_AUDIT_LOG_DETAIL_FIELDS = [
    VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME => ['reason' => 'deploy_reason'],
];

/**
 * The text a logs-page row shows, in the viewer's locale, and the technical detail
 * that is shown folded beneath it.
 *
 * A structured row (an event code is stored) is rendered again from its columns,
 * so the same row reads in German for a German viewer and in English for an English
 * one. Search, the CSV export and the saved filters keep the stored English text
 * (log_message). A row without an event code is a legacy line and shows its stored
 * text. Every failure (an unreadable context, a presenter error) also shows the
 * stored text, so a row is never blank because of its display.
 *
 * @param array<string,mixed> $row A deploy_logs row with log_message and, when present, event_code, object_type, object_id, result and context_json.
 * @return array{message:string, detail:string}
 */
function audit_log_display(array $row): array
{
    $stored = ['message' => (string) ($row['log_message'] ?? ''), 'detail' => ''];
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

    $details = [];
    foreach (VIRTUSPHERE_AUDIT_LOG_DETAIL_FIELDS[$eventCode] ?? [] as $key => $field) {
        $value = is_scalar($context[$key] ?? null) ? (string) $context[$key] : '';
        if ($value !== '' && !audit_code_is_closed($field, $value)) {
            $details[] = $value;
            unset($context[$key]);
        }
    }

    $objectId = $row['object_id'] ?? null;
    try {
        return [
            'message' => audit_event_description(
                $eventCode,
                (string) ($row['object_type'] ?? ''),
                $objectId === null ? null : (string) $objectId,
                (string) ($row['result'] ?? ''),
                $context,
                Lang::locale()
            ),
            'detail' => implode("\n", $details),
        ];
    } catch (Throwable $exception) {
        return $stored;
    }
}

/** The row text alone (audit_log_display()). */
function audit_log_display_message(array $row): string
{
    return audit_log_display($row)['message'];
}

/** Whether the word is one of the field's closed words, which have a label in every locale. */
function audit_code_is_closed(string $field, string $code): bool
{
    $key = 'audit_log.' . audit_code_key($field, $code);

    return Lang::getIn('en', $key) !== $key;
}
