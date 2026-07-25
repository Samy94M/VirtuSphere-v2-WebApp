<?php

declare(strict_types=1);

/**
 * The one row shape the System status page repeats: a head line and a list of
 * labelled facts under it.
 *
 * Before this module the same thing existed in three forms: a class-less `<dl>`
 * in the heartbeat rows, a `<dl class="status-facts">` in the site card, and a
 * `<p>…<br>…</p>` in the ESXi card. All three were flex items of a
 * `justify-content: space-between` head, so the width of the list depended on
 * how many of its conditionally rendered fields happened to exist, and the
 * blocks of three cards holding the same kind of value started at three
 * different x positions.
 *
 * The list is therefore fixed, not conditional: a field with nothing stored
 * renders an em dash and keeps its column, which is the one placeholder the
 * i18n rules allow (an empty value in a table cell). A dash says "nothing is
 * stored here", never "this never happened" - the help says so once, so the
 * cards do not have to.
 *
 * The filename has to end in `*panels.php`: AmpelLegendContractTest,
 * PhaseCContractTest, PortalActionInventory and PortalConfirmNamingContractTest
 * all glob `lib/system_status_*panels.php`, and a module outside that pattern
 * would drop out of four contracts without a word (ADR-0006).
 */

require_once __DIR__ . '/system_status.php';

/**
 * A fixed fact list.
 *
 * `html` is inserted verbatim, so every caller escapes its own value: the values
 * here carry `<code>`, badges and the `&mdash;` placeholder, and running them
 * through h() again would print the markup. Use system_status_fact_time() for a
 * timestamp, h() for anything else.
 *
 * @param list<array{label: string, html: string}> $facts
 */
function system_status_fact_list(array $facts): string
{
    $html = '<dl class="status-facts">';
    foreach ($facts as $fact) {
        $html .= '<div><dt>' . h((string) $fact['label']) . '</dt><dd>' . (string) $fact['html'] . '</dd></div>';
    }

    return $html . '</dl>';
}

/**
 * One timestamp cell: the formatted value, or the em dash for "not stored".
 *
 * A NULL and an empty string mean the same thing here (the column exists, the
 * reporter never filled it), and both used to make the whole field disappear.
 */
function system_status_fact_time(?string $timestamp): string
{
    $value = trim((string) ($timestamp ?? ''));

    return $value === '' ? '&mdash;' : h(portal_format_timestamp($value));
}
