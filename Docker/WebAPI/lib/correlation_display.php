<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';

/**
 * How a correlation id is shown wherever a person reads one (ADR-0032).
 *
 * Three places render it - the audit table, the audit CSV and a deploy job's
 * header - and they must render it identically, because the whole point of the
 * id is that a reader carries it from one of them to the next. A second
 * rendering with a slightly different shape (linked in one place, bare in
 * another, copyable only in the third) is how a diagnostic identity turns back
 * into something people retype by hand.
 */

/**
 * One correlation id, readable, linkable and copyable.
 *
 * The value is rendered as text first and the button is an accelerator on top
 * of it, so the id stays visible and selectable with JavaScript disabled and on
 * a plain-HTTP LAN portal, where `navigator.clipboard` does not exist at all.
 * The link is the trace itself ("what else did this request do"); it is omitted
 * where the reader is already looking at that trace, because a link to the page
 * you are on reads as a different page.
 *
 * `$linkUrl` of '' renders no link. The confirmation lives in a `role="status"`
 * next to the button, so a screen reader hears the outcome instead of only
 * sighted users seeing a colour change; a silent success is indistinguishable
 * from a silent failure, and the failure is a real, common branch here.
 */
function portal_correlation_id(string $correlationId, string $linkUrl = ''): string
{
    $correlationId = trim($correlationId);
    if ($correlationId === '') {
        return '<span class="muted">&mdash;</span>';
    }

    $value = $linkUrl !== ''
        ? '<a href="' . h($linkUrl) . '" title="' . h(__t('logs.correlation_link_title')) . '"><code>' . h($correlationId) . '</code></a>'
        : '<code>' . h($correlationId) . '</code>';

    return '<span class="correlation-id">' . $value
        . '<button type="button" class="button button-ghost copy-button"'
        . ' data-copy-value="' . h($correlationId) . '"'
        . ' data-copy-done="' . h(__t('logs.copy_done')) . '"'
        . ' data-copy-failed="' . h(__t('logs.copy_failed')) . '">'
        . h(__t('logs.copy')) . '</button>'
        . '<span class="copy-status" role="status" data-copy-status hidden></span></span>';
}
