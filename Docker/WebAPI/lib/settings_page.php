<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/**
 * SSoT for links into settings.php.
 *
 * The page renders its forms inside tabs and `initTabs` (assets/core.js) picks
 * the tab from the fragment. A link without one, or with a fragment naming a
 * panel the page does not render, silently opens the FIRST tab: no error, no
 * empty panel, just a settings page that does not contain the field the message
 * pointing here just named. Eight call sites spelled the link out by hand, one
 * of them the redirect every POST on the page goes through.
 *
 * This is the twin of system_status_url() and log_category_url(); it validates
 * against the rendered ids instead of a shape, because "#panel-" plus a typo is
 * a well-formed fragment.
 */
function settings_url(string $anchor): string
{
    if (!in_array($anchor, VIRTUSPHERE_SETTINGS_TABS, true)
        && !in_array($anchor, VIRTUSPHERE_SETTINGS_SECTIONS, true)
    ) {
        throw new InvalidArgumentException('Unknown settings anchor: ' . $anchor);
    }

    return 'settings.php#panel-' . $anchor;
}
