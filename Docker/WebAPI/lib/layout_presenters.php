<?php

declare(strict_types=1);

// Single source for the badge markup. Callers pass a variant (the palette suffix
// success/warning/danger/info/neutral) and the already-resolved label; both are
// escaped here, so the label must be the raw text, not pre-escaped.
function portal_badge(string $variant, string $label): string
{
    return '<span class="badge badge-' . h($variant) . '">' . h($label) . '</span>';
}

function status_badge(string $legacyStatus): string
{
    $meta = virtusphere_status_meta($legacyStatus);

    return portal_badge((string) $meta['badge'], $legacyStatus);
}

function lifecycle_badge(string $lifecycleState): string
{
    $meta = virtusphere_lifecycle_meta($lifecycleState);

    return portal_badge((string) $meta['badge'], $lifecycleState);
}

function mecm_sync_badge(string $mecmSyncState): string
{
    $meta = virtusphere_mecm_sync_meta($mecmSyncState);

    return portal_badge((string) $meta['badge'], $mecmSyncState);
}

// Heartbeat/staleness badge (ADR-0018) with a localized, portal-authored label.
function heartbeat_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.status_ok'),
        'legacy' => __t('system_status.status_legacy'),
        'warning' => __t('system_status.status_warning'),
        'danger' => __t('system_status.status_danger'),
        'missing' => __t('system_status.status_missing'),
        default => __t('system_status.status_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

// One labeled signal row for the dashboard's split MECM tile: a short label and
// its heartbeat badge, stacked so the two MECM signals never collapse into one
// worst-of. Both label and badge are escaped.
function portal_signal_row(string $label, string $state): string
{
    return '<span class="signal-row"><span class="signal-label">' . h($label) . '</span>' . heartbeat_badge($state) . '</span>';
}

/**
 * ESXi credential badge (ADR-0023): the same palette as the heartbeat badge, but
 * its own labels. A heartbeat's `warning` means "delayed", which is true of a
 * stale inventory pull and plainly false of a host that pulls perfectly and
 * simply has a licence that forbids writing. Sharing the colours is right;
 * sharing the words was not.
 */
function esxi_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.esxi_state_ok'),
        'warning' => __t('system_status.esxi_state_warning'),
        'danger' => __t('system_status.esxi_state_danger'),
        default => __t('system_status.esxi_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/**
 * Badge for an already-derived Ansible state, so a caller holding the state
 * (the overview roll-up, the legend) does not have to fake a preflight row to
 * get its badge back.
 */
function ansible_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.ansible_state_ok'),
        'stale' => __t('system_status.ansible_state_stale'),
        'warning' => __t('system_status.ansible_state_warning'),
        'danger' => __t('system_status.ansible_state_danger'),
        default => __t('system_status.ansible_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/** @param array<string, mixed>|null $state */
function ansible_preflight_badge(?array $state, ?int $now = null): string
{
    return ansible_state_badge(ansible_preflight_ampel($state, $now));
}

/**
 * Badge for the overall AD status (directory_health_snapshot()'s 'overall').
 * Same ok/warning/danger/unknown palette as esxi_state_badge(), own labels.
 */
function directory_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.directory_state_ok'),
        'warning' => __t('system_status.directory_state_warning'),
        'danger' => __t('system_status.directory_state_danger'),
        default => __t('system_status.directory_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
}

/**
 * Badge for one controller row's state (directory_health_snapshot()'s
 * per-controller 'state'): adds 'stale' to the overall palette above, same
 * grey as an aged Ansible preflight result.
 */
function directory_controller_state_badge(string $state): string
{
    $meta = virtusphere_heartbeat_meta($state);
    $label = match ($state) {
        'ok' => __t('system_status.directory_controller_state_ok'),
        'warning' => __t('system_status.directory_controller_state_warning'),
        'danger' => __t('system_status.directory_controller_state_danger'),
        'stale' => __t('system_status.directory_controller_state_stale'),
        default => __t('system_status.directory_controller_state_unknown'),
    };

    return portal_badge((string) $meta['badge'], $label);
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
// deploy.js poller, so the class must be derivable without rendering a span.
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

// Localized label for a client deploy phase (fixed set, full-literal keys so
// the lang catalog test can verify them).
function client_phase_label(string $phase): string
{
    return match ($phase) {
        VIRTUSPHERE_CLIENT_PHASE_GETINFO => __t('vm_edit.phase_getinfo'),
        VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => __t('vm_edit.phase_hostname'),
        VIRTUSPHERE_CLIENT_PHASE_STATICIP => __t('vm_edit.phase_staticip'),
        VIRTUSPHERE_CLIENT_PHASE_DISKS => __t('vm_edit.phase_disks'),
        default => $phase,
    };
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

function integration_action_hint(string $source): string
{
    return match ($source) {
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => __t('system_status.action_device_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => __t('system_status.action_packages_sync'),
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => __t('system_status.action_autoimporter'),
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => __t('system_status.action_maintenance_worker'),
        VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER => __t('system_status.action_deploy_worker'),
        default => '',
    };
}

function portal_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds > 0 && $seconds % 3600 === 0) {
        return __t('common.duration_hours', ['count' => intdiv($seconds, 3600)]);
    }
    if ($seconds >= 60 && $seconds % 60 === 0) {
        return __t('common.duration_minutes', ['count' => intdiv($seconds, 60)]);
    }

    return __t('common.duration_seconds', ['count' => $seconds]);
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
