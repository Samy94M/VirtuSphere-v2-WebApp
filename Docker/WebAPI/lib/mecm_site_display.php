<?php

declare(strict_types=1);

// Visible words of the MECM site state (ADR-0018 amendment 2026-11-09). Shared
// by the System status page (card, group heading, overview card) and the
// Dashboard tile, so all four say the same word for the same state.
require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/mecm_site_assessment.php';

/**
 * MECM site state in its own words, never the reporter liveness words of
 * heartbeat_badge(): a MECM verdict is not "down" or "delayed". `limited` is
 * MECM critical where only supporting parts are critical. `overdue` and
 * `stale` belong to the reporter and its evidence, `none` means no report yet.
 */
function mecm_site_badge(string $state): string
{
    [$variant, $key] = match ($state) {
        VIRTUSPHERE_MECM_SITE_VERDICT_OK => ['success', 'site_state_ok'],
        VIRTUSPHERE_MECM_SITE_VERDICT_WARNING => ['warning', 'site_state_warning'],
        VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED => ['warning', 'site_state_limited'],
        VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL => ['danger', 'site_state_critical'],
        'overdue' => ['warning', 'site_reporter_overdue'],
        'stale' => ['neutral', 'status_stale'],
        'none' => ['neutral', 'status_unknown'],
        default => ['neutral', 'site_state_unknown'],
    };

    return portal_badge($variant, __t('system_status.' . $key));
}
