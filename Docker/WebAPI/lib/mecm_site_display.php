<?php

declare(strict_types=1);

// Visible words of the MECM site state (ADR-0018 amendment 2026-11-09). Shared
// by the System status page (card, group heading, overview card) and the
// Dashboard tile, so all four say the same word for the same state.
require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/mecm_site_assessment.php';

/**
 * MECM site state in the status vocabulary (SY2): the five verdicts are the five
 * domain tones, so a MECM "Kritisch" is the same word, colour and icon as every
 * other critical area. `overdue` (the reporter is late) is a warning, `stale`
 * the grey reporter word, `none` (no report yet) "no data". The mapping is the
 * 'mecm_site' row of status_tone_for().
 */
function mecm_site_badge(string $state): string
{
    return status_tone_badge(mecm_site_tone($state));
}

function mecm_site_tone(string $state): string
{
    return status_tone_for('mecm_site', $state);
}
