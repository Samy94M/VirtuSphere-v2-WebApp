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

/** @param list<array{source:string,row:array|null,state:string}> $rows */
function system_status_render_source_rows(array $rows, bool $suppressHints = false): void
{
    ?>
    <div class="status-list">
        <?php foreach ($rows as $entry) {
            $row = $entry['row'];
            $lastSeen = $row !== null && !empty($row['last_seen_at'])
                ? portal_format_timestamp($row['last_seen_at'])
                : __t('system_status.never_seen');
            $lastChecked = $row !== null && !empty($row['last_checked_at'])
                ? portal_format_timestamp($row['last_checked_at'])
                : __t('system_status.never_seen');
            $detail = trim((string) ($row['last_detail'] ?? ''));
            ?>
            <article class="status-row">
                <div class="status-row-head"><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo heartbeat_badge($entry['state']); ?></div>
                <?php
                // Fixed fields, including the check that equals the report: a
                // column that appears only when the two timestamps differ moved
                // every following column one place to the left, so the same
                // label sat under a different one in the row above.
                echo system_status_fact_list([
                    ['label' => __t('system_status.th_last_seen'), 'html' => h($lastSeen)],
                    ['label' => __t('system_status.th_last_checked'), 'html' => h($lastChecked)],
                    ['label' => __t('system_status.th_interval'), 'html' => $row !== null ? h(portal_format_duration((int) $row['interval_seconds'])) : '&mdash;'],
                ]);
                ?>
                <?php
                // The hint is a repair instruction, not a description, so it is
                // only true while the source is not OK. Printed unconditionally
                // it told the operator "the maintenance service is not running"
                // directly under a green OK badge, which is the opposite of what
                // the row means and what help promises ("a problematic state
                // carries an action hint").
                //
                // $suppressHints is the caller's "this cannot be repaired yet"
                // verdict: nothing was ever set up, so "restart the task" names a
                // task that does not exist. The caller says so once for the whole
                // group instead of five rows repeating a premature instruction.
                $actionHint = !$suppressHints && $entry['state'] !== 'ok' ? integration_action_hint($entry['source']) : '';
                if ($actionHint !== '') { ?><p class="status-action"><?php echo h($actionHint); ?></p><?php } ?>
                <?php if ($detail !== '') { ?>
                    <details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details>
                <?php } ?>
            </article>
        <?php } ?>
    </div>
    <?php
}
