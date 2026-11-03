<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/audit_registry.php';
require_once __DIR__ . '/audit_event_definitions.php';

/**
 * The filter vocabulary the audit registry owns.
 *
 * Split from lib/log_filter.php by data source, not by size: everything here is
 * DERIVED from audit_event_definitions.php and nothing here reads a request.
 * Keeping it separate is what stops the filter from growing a hand-written copy
 * of the event list, which would then be the list an operator can search while
 * the registry is the list that can actually appear in a row.
 */

/** Every event code the audit registry knows, sorted for a stable option list. */
function log_filter_event_codes(): array
{
    static $codes = null;
    if ($codes === null) {
        $codes = array_keys(audit_event_registry());
        sort($codes, SORT_STRING);
    }

    return $codes;
}

/** Every object type any registered event may carry. */
function log_filter_object_types(): array
{
    static $types = null;
    if ($types === null) {
        $types = [];
        foreach (audit_event_registry() as $definition) {
            foreach ((array) ($definition['objects'] ?? []) as $object) {
                $types[(string) $object] = true;
            }
        }
        $types = array_keys($types);
        sort($types, SORT_STRING);
    }

    return $types;
}

/**
 * The event codes of one tab, so the picker offers the vocabulary of the
 * section the operator is in instead of the whole registry. A code whose
 * definition leaves the category open (it is chosen per row) belongs to every
 * tab that could carry it, because excluding it would hide rows that exist.
 *
 * @return list<string>
 */
function log_filter_event_codes_for_tab(string $tab): array
{
    $categories = VIRTUSPHERE_LOG_TABS[$tab] ?? [];
    $out = [];
    foreach (audit_event_registry() as $code => $definition) {
        $category = $definition['category'] ?? null;
        $open = array_values(array_unique(array_merge(
            (array) ($definition['categories'] ?? []),
            array_values((array) ($definition['categoryById'] ?? []))
        )));
        $possible = $category !== null ? [$category] : $open;
        if ($possible === [] || array_intersect($possible, $categories) !== []) {
            $out[] = (string) $code;
        }
    }
    sort($out, SORT_STRING);

    return $out;
}
