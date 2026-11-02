<?php

declare(strict_types=1);

/**
 * Field comparison after a refused editor save (QL-01/QL-03 B,
 * docs/operations/edit-concurrency.md). Pure: it compares two arrays the
 * editors hand in (the operator's input and the stored row) and writes
 * nothing. The editors own the reads; the save path stays the only writer.
 */

require_once __DIR__ . '/lang.php';

/** Value of the "continue with my input" submit button. */
const VIRTUSPHERE_EDIT_CONFLICT_CONTINUE = 'continue';
/** Marker a redirecting editor remembers after the continue step. */
const VIRTUSPHERE_EDIT_CONFLICT_CONTINUED = 'continued';
/** The form still carries an older version than the stored row. */
const VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT = 'conflict';
/** The operator adopted the shown version; the next save writes. */
const VIRTUSPHERE_EDIT_CONFLICT_MODE_CONTINUED = 'continued';

/**
 * One value as the save path would compare it: trimmed, CRLF folded, a
 * checkbox as 1/0, an integer without leading zeros. A value that is not an
 * integer stays text, so a typo is shown as typed instead of as 0.
 *
 * @param 'text'|'int'|'bool' $kind
 */
function edit_conflict_normalize(mixed $value, string $kind): string
{
    $text = is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';

    return match ($kind) {
        'bool' => in_array(strtolower($text), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
        'int' => preg_match('/^-?[0-9]+$/D', $text) === 1 ? (string) (int) $text : $text,
        'text' => $text,
    };
}

/**
 * The fields whose normalized values differ, in spec order. A field the form
 * did not post is not part of this editor and is skipped.
 *
 * @param array<string, mixed> $submitted
 * @param array<string, mixed> $current
 * @param array<string, 'text'|'int'|'bool'> $kinds
 * @return list<array{field:string, submitted:string, current:string}>
 */
function edit_conflict_field_diff(array $submitted, array $current, array $kinds): array
{
    $diff = [];
    foreach ($kinds as $field => $kind) {
        if (!array_key_exists($field, $submitted)) {
            continue;
        }
        $mine = edit_conflict_normalize($submitted[$field], $kind);
        $stored = edit_conflict_normalize($current[$field] ?? '', $kind);
        if ($mine !== $stored) {
            $diff[] = ['field' => (string) $field, 'submitted' => $mine, 'current' => $stored];
        }
    }

    return $diff;
}

/**
 * Child tables (network cards, disks, packages) are compared as whole lists
 * of canonical rows and reported as counts only (F5): a per-row diff would
 * need row identities the editor does not keep across a conflict.
 *
 * @param list<array<string, scalar|null>> $submittedRows
 * @param list<array<string, scalar|null>> $currentRows
 * @return array{submitted:int, current:int}|null
 */
function edit_conflict_rows_summary(array $submittedRows, array $currentRows): ?array
{
    if ($submittedRows === $currentRows) {
        return null;
    }

    return ['submitted' => count($submittedRows), 'current' => count($currentRows)];
}

/**
 * 'conflict' while the form version is not the stored one (also when it is
 * missing), 'continued' after the continue step on a still current version,
 * '' when there is nothing to compare.
 */
function edit_conflict_mode(string $formVersion, string $storedVersion, bool $continued): string
{
    if ($formVersion !== $storedVersion) {
        return VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT;
    }

    return $continued ? VIRTUSPHERE_EDIT_CONFLICT_MODE_CONTINUED : '';
}

/**
 * The version a continue submit adopts: null for an ordinary save, '' for a
 * continue whose version is malformed. A continue never falls back to a save;
 * with '' the caller keeps the submitted version, so the next save fails again.
 *
 * @param array<string, mixed> $post
 */
function edit_conflict_continue_version(array $post): ?string
{
    if (($post['edit_conflict'] ?? null) !== VIRTUSPHERE_EDIT_CONFLICT_CONTINUE) {
        return null;
    }
    $version = $post['edit_conflict_version'] ?? '';

    return is_string($version) && preg_match('/^[1-9][0-9]*$/D', $version) === 1 ? $version : '';
}

/**
 * The input a redirecting editor remembers for the continue step: the posted
 * values with the shown version and the continued marker. Nothing is saved.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function edit_conflict_continue_state(array $post, string $version): array
{
    unset($post['edit_conflict_version']);
    if ($version !== '') {
        $post['edit_version'] = $version;
    }
    $post['edit_conflict'] = VIRTUSPHERE_EDIT_CONFLICT_CONTINUED;

    return $post;
}

/**
 * Display rows: label, both values through their option labels (an editor
 * passes labels for the two compared values when a value needs formatting), checkboxes
 * as yes/no, an empty value as the field's empty text or "empty".
 *
 * @param array<string, mixed> $submitted
 * @param array<string, mixed> $current
 * @param array<string, array{label:string, kind:'text'|'int'|'bool', options?:array<int|string, string>, empty?:string}> $fields
 * @return list<array{label:string, submitted:string, current:string, rows:bool}>
 */
function edit_conflict_rows(array $submitted, array $current, array $fields): array
{
    $kinds = array_map(static fn (array $spec): string => $spec['kind'], $fields);
    $rows = [];
    foreach (edit_conflict_field_diff($submitted, $current, $kinds) as $entry) {
        $spec = $fields[$entry['field']];
        $rows[] = [
            'label' => $spec['label'],
            'submitted' => edit_conflict_display($entry['submitted'], $spec),
            'current' => edit_conflict_display($entry['current'], $spec),
            'rows' => false,
        ];
    }

    return $rows;
}

/** @param array{kind:'text'|'int'|'bool', options?:array<int|string, string>, empty?:string} $spec */
function edit_conflict_display(string $value, array $spec): string
{
    if ($spec['kind'] === 'bool') {
        return __t($value === '1' ? 'common.yes' : 'common.no');
    }
    if ($value === '') {
        return $spec['empty'] ?? __t('edit_conflict.empty');
    }
    if (isset($spec['options'][$value])) {
        return $spec['options'][$value];
    }

    return $value;
}

/**
 * The display row of a child table that differs, or null.
 *
 * @param list<array<string, scalar|null>> $submittedRows
 * @param list<array<string, scalar|null>> $currentRows
 * @return array{label:string, submitted:string, current:string, rows:bool}|null
 */
function edit_conflict_child_row(string $label, array $submittedRows, array $currentRows): ?array
{
    $summary = edit_conflict_rows_summary($submittedRows, $currentRows);
    if ($summary === null) {
        return null;
    }

    return [
        'label' => $label,
        'submitted' => __t('edit_conflict.row_count', ['count' => $summary['submitted']]),
        'current' => __t('edit_conflict.row_count', ['count' => $summary['current']]),
        'rows' => true,
    ];
}
