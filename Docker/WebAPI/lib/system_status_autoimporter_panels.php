<?php

declare(strict_types=1);

// The autoimporter's bounded detail line, read as data (SY3, part E). The
// reporter writes one fixed grammar (Format-VsRunDetail and Add-VsRunCause in
// Powershell-MECM/mecm/VirtuSphere-Common.ps1, AR1): causes joined by "; ", each
// `code[ target=<value>][ collection=<value>]`, values with `%`, `;`, CR and LF
// percent-escaped, and a closing `(+n weitere)` when the line ran out of room.
// This module only reads that grammar; the line itself is stored and shown
// unchanged (wire contract, "Rohdaten"). An unknown code is shown with its raw
// code, never dropped and never turned into a state.
//
// The filename ends in `*panels.php` so the System status contracts that glob
// `lib/system_status_*panels.php` cover it (ADR-0006).
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/copy_control.php';

/**
 * One cause entry of the detail line.
 *
 * @return array{code: string, target: string, collection: string, raw: string}|array{more: int}
 */
function system_status_autoimporter_detail_entry(string $piece): array
{
    if (preg_match('/\A\(\+(\d+) weitere\)\z/u', $piece, $match) === 1) {
        return ['more' => (int) $match[1]];
    }
    $entry = ['code' => '', 'target' => '', 'collection' => '', 'raw' => $piece];
    if (preg_match('/\A([a-z][a-z0-9_]*)(?:\s+(.*))?\z/su', $piece, $match) !== 1) {
        return $entry;
    }
    $rest = trim((string) ($match[2] ?? ''));
    $values = [];
    if ($rest !== '' && preg_match('/\A(?:target=(.*?))?\s*(?:collection=(.*))?\z/su', $rest, $values) !== 1) {
        return $entry;
    }
    if ($rest !== '' && ($values[1] ?? '') === '' && ($values[2] ?? '') === '') {
        return $entry;
    }
    $entry['code'] = $match[1];
    $entry['target'] = rawurldecode(trim((string) ($values[1] ?? '')));
    $entry['collection'] = rawurldecode(trim((string) ($values[2] ?? '')));

    return $entry;
}

/**
 * The detail line grouped by cause, in display order: configuration problems,
 * then distribution problems, then distribution still running, then codes this
 * portal does not know (raw), then pieces outside the grammar.
 *
 * @return array{causes: list<array{code: string, known: bool, distribution: bool, entries: list<array{code: string, target: string, collection: string, raw: string}>}>, more: int}
 */
function system_status_autoimporter_causes(string $detail): array
{
    $groups = [];
    $more = 0;
    foreach (preg_split('/;\s*/u', trim($detail)) ?: [] as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        $entry = system_status_autoimporter_detail_entry($piece);
        if (isset($entry['more'])) {
            $more += $entry['more'];
            continue;
        }
        $groups[$entry['code']][] = $entry;
    }
    $known = array_flip(VIRTUSPHERE_AUTOIMPORTER_PACKAGE_CAUSES);
    $causes = [];
    foreach ($groups as $code => $entries) {
        $code = (string) $code;
        $isKnown = isset($known[$code]);
        $distribution = $isKnown && str_starts_with($code, 'package_content_');
        $rank = match (true) {
            $code === '' => 4,
            !$isKnown => 3,
            $code === 'package_content_in_progress' => 2,
            $distribution => 1,
            default => 0,
        };
        $causes[] = ['rank' => $rank, 'order' => $isKnown ? $known[$code] : count($causes), 'code' => $code, 'known' => $isKnown, 'distribution' => $distribution, 'entries' => $entries];
    }
    usort($causes, static fn (array $a, array $b): int => [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']]);

    return [
        'causes' => array_map(static fn (array $cause): array => array_diff_key($cause, ['rank' => true, 'order' => true]), $causes),
        'more' => $more,
    ];
}

/**
 * How many packages a cause names: "1 Paket", "3 Pakete", or "mindestens 3
 * Pakete" while the line ran out of room, because the rest could be any cause.
 */
function system_status_autoimporter_cause_count(int $count, int $more): string
{
    $key = $more > 0 ? 'cause_count_at_least' : ($count === 1 ? 'cause_count_one' : 'cause_count_many');

    return __t('system_status_cards.' . $key, ['count' => $count]);
}

/**
 * The explanations of the known causes of one kind (configuration or
 * distribution), each led by how many packages it names.
 *
 * @param array{causes: list<array<string,mixed>>, more: int} $parsed
 * @return list<array{code: string, count: string, text: string}>
 */
function system_status_autoimporter_cause_findings(array $parsed, bool $distribution): array
{
    $findings = [];
    foreach ($parsed['causes'] as $cause) {
        if (!$cause['known'] || $cause['distribution'] !== $distribution) {
            continue;
        }
        $findings[] = [
            'code' => (string) $cause['code'],
            'count' => system_status_autoimporter_cause_count(count($cause['entries']), (int) $parsed['more']),
            'text' => __t('system_status.finding_' . $cause['code']),
        ];
    }

    return $findings;
}

/** The plain-language name of a cause, or the "unknown code" label. */
function system_status_autoimporter_cause_label(array $cause): string
{
    if ($cause['code'] === '') {
        return __t('system_status_cards.cause_unparsed');
    }

    return $cause['known'] ? __t('system_status_cards.cause_' . $cause['code']) : __t('system_status_cards.cause_unknown');
}

/**
 * Technical details of an autoimporter report: one table row per cause with its
 * count and a collapsible package list, the note for the rest that did not fit,
 * and the raw line with a copy button for a support ticket. Every value is
 * escaped; the raw line is the stored text, unchanged.
 */
function system_status_render_autoimporter_details(string $detail): string
{
    $parsed = system_status_autoimporter_causes($detail);
    $count = count($parsed['causes']);
    $summary = match (true) {
        $count === 0 => __t('common.technical_details'),
        $count === 1 => __t('system_status_cards.details_summary_one', ['count' => $count]),
        default => __t('system_status_cards.details_summary_many', ['count' => $count]),
    };
    $html = '<details class="technical-details" data-autoimporter-details><summary>' . h($summary) . '</summary>';
    if ($parsed['causes'] !== []) {
        $html .= '<div class="table-wrap" tabindex="0"><table class="status-causes"><thead><tr>'
            . '<th scope="col">' . h(__t('system_status_cards.th_cause')) . '</th>'
            . '<th scope="col">' . h(__t('system_status_cards.th_count')) . '</th>'
            . '<th scope="col">' . h(__t('system_status_cards.th_packages')) . '</th></tr></thead><tbody>';
        foreach ($parsed['causes'] as $cause) {
            $entries = $cause['entries'];
            $items = '';
            foreach ($entries as $entry) {
                $value = $cause['code'] === '' ? $entry['raw'] : ($entry['target'] !== '' ? $entry['target'] : __t('system_status_cards.cause_no_target'));
                $items .= '<li>' . h($value)
                    . ($entry['collection'] !== '' ? ' <span class="muted">' . h(__t('system_status_cards.cause_collection', ['name' => $entry['collection']])) . '</span>' : '')
                    . '</li>';
            }
            $html .= '<tr data-cause="' . h($cause['code']) . '"><td>' . h(system_status_autoimporter_cause_label($cause))
                . ($cause['code'] !== '' ? ' <code>' . h($cause['code']) . '</code>' : '') . '</td>'
                . '<td>' . h((string) count($entries)) . '</td>'
                . '<td><details><summary>' . h(__t('system_status_cards.cause_list_summary', ['count' => count($entries)])) . '</summary><ul class="status-cause-list">' . $items . '</ul></details></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    if ($parsed['more'] > 0) {
        $html .= '<p class="muted" data-cause-more>' . h(__t('system_status_cards.cause_more', ['count' => $parsed['more']])) . '</p>';
    }
    $html .= '<details class="raw-details"><summary>' . h(__t('system_status_cards.raw_summary')) . '</summary>'
        . '<pre>' . h($detail) . '</pre>'
        . portal_copy_button(__t('common.copy_value', ['label' => __t('system_status_cards.raw_summary')]), $detail)
        . '</details></details>';

    return $html;
}
