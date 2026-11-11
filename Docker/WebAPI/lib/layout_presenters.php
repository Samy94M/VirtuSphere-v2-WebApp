<?php

declare(strict_types=1);

// The badge variants come from the meta SSoT in lib/status.php (loaded by the
// layout facade); the visible labels come from the portal-only display module,
// which is required here rather than by the facade so this file's own require
// closure stays complete.
require_once __DIR__ . '/portal_status_display.php';
require_once __DIR__ . '/status_badges.php';
require_once __DIR__ . '/portal_time_display.php';

// Single source for the badge markup. Callers pass a variant (the palette suffix
// success/warning/danger/info/neutral) and the already-resolved label; both are
// escaped here, so the label must be the raw text, not pre-escaped. A status
// badge also carries its tone's icon (SY2): a decorative, aria-hidden span whose
// glyph comes from feedback.css, so the text stays the badge's whole text content
// and colour is never the only signal (WCAG 1.4.1).
function portal_badge(string $variant, string $label, string $icon = ''): string
{
    $iconHtml = $icon === '' ? '' : portal_badge_icon($icon);

    return '<span class="badge badge-' . h($variant) . '">' . $iconHtml . h($label) . '</span>';
}

/** The decorative icon of a tone, also used where a sentence leads like a badge (the System status headline). */
function portal_badge_icon(string $icon): string
{
    return '<span class="badge-icon badge-icon-' . h($icon) . '" aria-hidden="true"></span>';
}

/**
 * A label badge that names a kind of thing ("Vorlage"), not a state: no tone,
 * so no icon. The System status modules use this or the two tone renderers of
 * lib/status_badges.php, never portal_badge() directly (StatusVocabularyTest).
 */
function portal_label_badge(string $label): string
{
    return portal_badge('info', $label);
}

function status_badge(string $legacyStatus): string
{
    $meta = virtusphere_status_meta($legacyStatus);

    return portal_badge((string) $meta['badge'], $legacyStatus);
}

// Variant from the meta SSoT, visible text from the portal label helper. The
// two halves come from different places on purpose: the colour belongs to the
// state machine, the wording belongs to the reader, and an unknown value gets a
// neutral badge with a neutral sentence instead of its raw token.
function lifecycle_badge(string $lifecycleState): string
{
    $meta = virtusphere_lifecycle_meta($lifecycleState);

    return portal_badge((string) $meta['badge'], portal_lifecycle_label($lifecycleState));
}

function mecm_sync_badge(string $mecmSyncState): string
{
    $meta = virtusphere_mecm_sync_meta($mecmSyncState);

    return portal_badge((string) $meta['badge'], portal_mecm_sync_label($mecmSyncState));
}

// Heartbeat/staleness badge (ADR-0018) in the status vocabulary. A run result
// (MECM sync tasks) reads domain words; a pure heartbeat (internal services,
// $kind 'liveness') reads reporter words, so a silent worker is "unreachable"
// and never "critical", and its green is "current" next to the deploy-service
// card's "ready" instead of a second "OK" for the same service.
function heartbeat_badge(string $state, string $kind = 'result'): string
{
    return status_tone_badge(status_tone_for($kind, $state));
}

// One labeled signal row for the dashboard's split MECM tile: a short label and
// its heartbeat badge, stacked so the two MECM signals never collapse into one
// worst-of. Both label and badge are escaped; the MECM site passes its own
// rendered badge (mecm_site_badge()), never a heartbeat word.
function portal_signal_row(string $label, string $state, ?string $badgeHtml = null): string
{
    return '<span class="signal-row"><span class="signal-label">' . h($label) . '</span>' . ($badgeHtml ?? heartbeat_badge($state)) . '</span>';
}

/**
 * ESXi credential badge (ADR-0023) in the status vocabulary (SY2). It used to
 * carry its own words ("Prüfen", "Fehler", "Noch kein Abruf"); the tone table in
 * lib/status_vocabulary.php now decides the word, so a paused sign-in no longer
 * reads "Fehler" and "never pulled" reads like every other "never checked".
 */
function esxi_state_badge(string $state): string
{
    return status_tone_badge(status_tone_for('esxi', $state));
}

/**
 * Badge for the mission/VM deviation count, from the count alone.
 *
 * The overview card and the section heading render the same answers from the
 * same number (system_status_deviation_count()), so the strip cannot report a
 * colour or a wording the section below it contradicts. `null` says the scan did
 * not run and reads "no data"; it is deliberately not green, because "not
 * checked" and "nothing found" are different facts and only one of them is
 * reassuring. A zero is green only on complete, current evidence (SY1): a zero
 * over some kinds or over old names is the registered phrase
 * "evidence_incomplete", grey, and the section says why.
 */
function deviation_count_badge(?int $count, bool $evidenceCurrent): string
{
    return match (true) {
        $count === null => status_tone_badge('no_data'),
        $count === 0 && !$evidenceCurrent => status_tone_badge('evidence_incomplete'),
        $count === 0 => status_value_badge('ok', __t('system_status.dev_count_none')),
        $count === 1 => status_value_badge('warning', __t('system_status.dev_count_one')),
        default => status_value_badge('warning', __t('system_status.dev_count_many', ['count' => $count])),
    };
}

/**
 * The tone of the deviation count, for the overview headline. Same branches as
 * deviation_count_badge(), which renders it.
 */
function deviation_count_tone(?int $count, bool $evidenceCurrent): string
{
    return match (true) {
        $count === null => 'no_data',
        $count === 0 && !$evidenceCurrent => VIRTUSPHERE_STATUS_PHRASES['evidence_incomplete'],
        $count === 0 => 'ok',
        default => 'warning',
    };
}

/**
 * Badge for an already-derived Ansible state, so a caller holding the state
 * (the overview roll-up, the legend) does not have to fake a preflight row to
 * get its badge back.
 */
function ansible_state_badge(string $state): string
{
    return status_tone_badge(status_tone_for('ansible', $state));
}

/** @param array<string, mixed>|null $state */
function ansible_preflight_badge(?array $state, ?int $now = null, int $intervalHours = 0): string
{
    return ansible_state_badge(ansible_preflight_ampel($state, $now, $intervalHours));
}

/**
 * Badge for the overall AD status (directory_health_snapshot()'s 'overall').
 * Green reads "OK" like every other area (it read "Aktiv"); a switched-off
 * directory reads "Deaktiviert" and counts as nothing to do.
 */
function directory_state_badge(string $state): string
{
    return status_tone_badge(status_tone_for('directory', $state));
}

/**
 * Badge for one controller row's state (directory_health_snapshot()'s
 * per-controller 'state'): adds 'stale' to the overall palette above, same
 * grey as an aged Ansible preflight result.
 */
function directory_controller_state_badge(string $state): string
{
    return status_tone_badge(status_tone_for('directory_controller', $state));
}

// Client deploy-phase badge (none|running|unconfirmed|finished|failed).
function client_phase_badge(string $phaseState): string
{
    $meta = virtusphere_client_phase_meta($phaseState);
    $label = match ($phaseState) {
        'running' => __t('vm_edit.phase_state_running'),
        'unconfirmed' => __t('vm_edit.phase_state_unconfirmed'),
        'finished' => __t('vm_edit.phase_state_finished'),
        'failed' => __t('vm_edit.phase_state_failed'),
        default => __t('vm_edit.phase_state_none'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

// Deploy-job status to badge variant. Lives here with the other badge helpers,
// not in the repo layer; the deploy_log.php JSON path hands the variant to the
// deploy_log.js poller, so the class must be derivable without rendering a span.
function deploy_job_status_badge_class(string $status): string
{
    return match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => 'success',
        VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => 'danger',
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING => 'info',
        // Explicitly warning, not info: the wish is recorded but the sequence
        // may still be executing its current step (ADR-0033), which is exactly
        // the "look here" shade between a healthy run and a terminal state.
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => 'warning',
        // Same variant as the default, pinned on purpose: partial is a terminal
        // per-VM verdict, not a transient state that merely lacks a mapping.
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => 'warning',
        // Pinned for the same reason: the reaper saw silence, not a failure, so
        // red would claim what nobody observed (WK-04/WK-07 A).
        VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN => 'warning',
        default => 'warning',
    };
}

// Catalog status badge (E3): free-text status column, so map case-insensitive
// active variants ('Aktiv'/'active') to success and 'Retired' to neutral.
function catalog_status_badge(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($status === VIRTUSPHERE_CATALOG_STATUS_RETIRED) {
        return portal_badge('neutral', __t('packages.status_retired'));
    }
    if (in_array($normalized, ['aktiv', 'active'], true)) {
        return portal_badge('success', __t('packages.status_active'));
    }

    return portal_badge('neutral', $status);
}

/**
 * Renders the shared catalog status-filter <select> form (os.php, packages.php).
 * Iterates VIRTUSPHERE_CATALOG_FILTERS so the option set stays in one place.
 * $labels carries the localized texts ('label', 'apply', and one per filter
 * token); the caller builds it with static __t() literals so the lang catalog
 * test still sees the keys. $hidden preserves query state (sort/dir) across the
 * GET submit.
 *
 * @param array<string,string> $labels
 * @param array<string,string> $hidden
 */
function portal_catalog_status_filter(string $action, string $current, array $labels, array $hidden = []): string
{
    $html = '<form class="actions" method="get" action="' . h($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    $html .= '<label class="filter-field">' . h($labels['label'] ?? '');
    $html .= '<select name="status">';
    foreach (VIRTUSPHERE_CATALOG_FILTERS as $token) {
        $selected = $current === $token ? ' selected' : '';
        $html .= '<option value="' . h($token) . '"' . $selected . '>' . h($labels[$token] ?? $token) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<button class="button button-secondary" type="submit">' . h($labels['apply'] ?? '') . '</button>';
    $html .= '</form>';

    return $html;
}

/**
 * What an empty catalog table says, which depends on WHY it is empty.
 *
 * The three catalogs printed one sentence for both cases, and the two cases are
 * opposite instructions. A genuinely empty catalog is waiting for its source:
 * MECM has not synced a Task Sequence or a package yet, ESXi has not reported a
 * portgroup, and the fix is at the other system. A catalog that holds rows and
 * shows none is a FILTER result, and the fix is one click away on this page,
 * which the old sentence ("no entries") not only failed to mention but actively
 * contradicted. So the filtered case names the filter and carries the way out,
 * and the empty case does not offer a link that would show the same nothing.
 *
 * The link keeps the current sort, because dropping it re-sorts the table under
 * a reader who only asked to widen it.
 *
 * @param array<string,string> $labels 'empty', 'empty_filtered' and 'show_all'
 * @param array<string,string> $keep query state to carry (sort/dir)
 */
function portal_catalog_empty_state(string $action, string $statusFilter, bool $catalogHasRows, array $labels, array $keep = []): string
{
    if (!$catalogHasRows || $statusFilter === 'all') {
        return h($labels['empty'] ?? '');
    }

    $url = $action . '?' . http_build_query([...$keep, 'status' => 'all']);

    return h($labels['empty_filtered'] ?? '')
        . ' <a href="' . h($url) . '">' . h($labels['show_all'] ?? '') . '</a>';
}

/**
 * Server-side page navigation: a set of links of which exactly one is the page
 * currently open.
 *
 * This is deliberately NOT an ARIA tab widget. A tab widget promises a panel
 * that swaps in place, arrow-key movement between the tabs and a single tab
 * stop; these controls navigate to another URL, keep their own address and are
 * reached with Tab like every other link. Announcing them as tabs would make a
 * screen reader wait for a panel that never arrives and hand the operator a
 * keyboard model that does not exist here, which is why `role="tab"` and
 * `aria-selected` are absent by contract (PortalPageNavContractTest). The
 * current entry is marked with `aria-current="page"` and there is exactly one
 * of it: two would say the reader is in two places at once, none would leave
 * the whole set unanchored.
 *
 * The look is the existing `.tab-list`/`.tab` pair, which the log sections
 * already use in exactly this link-plus-aria-current shape.
 *
 * @param list<array{href:string,label:string,current:bool}> $items
 */
function portal_page_nav(string $ariaLabel, array $items): string
{
    $html = '<nav class="tab-list" aria-label="' . h($ariaLabel) . '">';
    foreach ($items as $item) {
        $current = $item['current'] ? ' aria-current="page"' : '';
        $html .= '<a class="tab" href="' . h($item['href']) . '"' . $current . '>' . h($item['label']) . '</a>';
    }

    return $html . '</nav>';
}

// The VM phase badge: colour from the phase state machine, words from the
// portal label helper (lib/portal_status_display.php).
function vm_phase_badge(string $phase): string
{
    $meta = virtusphere_vm_phase_meta($phase);

    return portal_badge((string) $meta['badge'], portal_vm_phase_label($phase));
}

// One VM finding as a badge: severity colour from the finding table, the whole
// sentence as its text (the cell puts the same sentence in the tooltip).
function vm_finding_badge(array $finding): string
{
    $meta = virtusphere_vm_finding_meta($finding);

    return portal_badge((string) $meta['badge'], portal_vm_finding_text($finding));
}

// Localized label and action hint for an integration heartbeat source.
function integration_source_label(string $source): string
{
    return match ($source) {
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => __t('system_status.source_device_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => __t('system_status.source_packages_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => __t('system_status.source_autoimporter'),
        VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH => __t('system_status.source_mecm_site_health'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => __t('system_status.source_maintenance_worker'),
        VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER => __t('system_status.source_deploy_worker'),
        default => $source,
    };
}

// $openPoints: reported on time, the run itself had open points; Task Scheduler is not the cause.
function integration_action_hint(string $source, bool $openPoints = false): string
{
    return match ($source) {
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => $openPoints ? __t('system_status.action_device_sync_open_points') : __t('system_status.action_device_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => $openPoints ? __t('system_status.action_packages_sync_open_points') : __t('system_status.action_packages_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => __t('system_status.action_autoimporter'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => __t('system_status.action_maintenance_worker'),
        VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER => __t('system_status.action_deploy_worker'),
        default => '',
    };
}

/**
 * A duration in at most two units, the largest first (operator decision
 * 09.11.2026): 495 s is "8 Minuten 15 Sekunden", not "495 Sekunden"; 3725 s is
 * "1 Stunde 2 Minuten" (the seconds drop out). A zero second unit is left out.
 */
function portal_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $unit = static fn (int $count, string $one, string $many): string => __t($count === 1 ? 'common.duration_' . $one : 'common.duration_' . $many, ['count' => $count]);
    if ($seconds < 60) {
        return $unit($seconds, 'second', 'seconds');
    }
    [$first, $second] = $seconds < 3600
        ? [$unit(intdiv($seconds, 60), 'minute', 'minutes'), $seconds % 60 === 0 ? '' : $unit($seconds % 60, 'second', 'seconds')]
        : [$unit(intdiv($seconds, 3600), 'hour', 'hours'), intdiv($seconds % 3600, 60) === 0 ? '' : $unit(intdiv($seconds % 3600, 60), 'minute', 'minutes')];

    return $second === '' ? $first : __t('common.duration_pair', ['first' => $first, 'second' => $second]);
}

// Sub-second run durations are reported in milliseconds; anything longer rounds
// to whole seconds and reuses the second/minute/hour formatter.
function portal_format_duration_ms(int $milliseconds): string
{
    $milliseconds = max(0, $milliseconds);
    if ($milliseconds < 1000) {
        return __t('common.duration_ms', ['count' => $milliseconds]);
    }

    return portal_format_duration((int) round($milliseconds / 1000));
}

function mecm_updated_display(mixed $updated): string
{
    return (int) $updated === 1 ? __t('common.mecm_queued') : '—';
}
