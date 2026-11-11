<?php

declare(strict_types=1);

// The one status vocabulary of the portal (SY2). Every status badge is a TONE:
// a fixed triple of colour, icon and word, the same in every section and on the
// dashboard. Three axes stay apart, because they answer different questions:
//
// - the domain state (is the thing alright?): ok, warning, limited, critical,
//   unknown;
// - the reporter state (does the evidence still arrive?): current, stale,
//   no_data, unreachable, disabled;
// - the activity (is something under way right now?): running.
//
// A source never invents a word for a level. It maps its own state tokens to a
// tone in status_tone_for() below (or, for the backup channel, next to its own
// state constants) and the badge renderer takes word, colour and icon from the
// tone. The tables here are pure (workers load them too); the renderers are in
// lib/status_badges.php, portal only. Visible words live in
// lang/{de,en}/status_vocabulary.php.

const VIRTUSPHERE_STATUS_LEVEL_TONES = ['ok', 'warning', 'limited', 'critical', 'unknown'];
const VIRTUSPHERE_STATUS_REPORTER_TONES = ['current', 'stale', 'no_data', 'unreachable', 'disabled'];
const VIRTUSPHERE_STATUS_ACTIVITY_TONES = ['running'];

// Fixed phrases with their own word, each bound to one tone. A phrase is not a
// free label: it is registered here, it renders with its tone's colour and icon,
// and the legend explains it next to its tone.
const VIRTUSPHERE_STATUS_PHRASES = [
    'evidence_incomplete' => 'unknown',
];

// The sources whose Ampel tokens this module maps. Every kind lists its complete
// token set, so a test can walk them and a new token fails the build instead of
// rendering as unknown.
const VIRTUSPHERE_STATUS_TONE_KINDS = [
    'result' => ['ok', 'legacy', 'warning', 'missing', 'danger', 'stale', 'unknown'],
    'liveness' => ['ok', 'legacy', 'warning', 'missing', 'danger', 'stale', 'unknown'],
    'esxi' => ['ok', 'warning', 'danger', 'stale', 'unknown'],
    'ansible' => ['ok', 'warning', 'danger', 'stale', 'unknown'],
    'directory' => ['ok', 'warning', 'danger', 'unknown'],
    'directory_controller' => ['ok', 'warning', 'danger', 'stale', 'unknown'],
    'mecm_site' => ['ok', 'warning', 'limited', 'critical', 'unknown', 'overdue', 'stale', 'none'],
];

/** @return list<string> every tone, in legend order */
function status_tones(): array
{
    return [...VIRTUSPHERE_STATUS_LEVEL_TONES, ...VIRTUSPHERE_STATUS_REPORTER_TONES, ...VIRTUSPHERE_STATUS_ACTIVITY_TONES];
}

/**
 * Colour (portal_badge variant) and icon of a tone. Exhaustive: an unknown tone
 * is a programming error, not a grey badge, because a silent fallback is how a
 * second vocabulary would grow back.
 *
 * @return array{variant: string, icon: string}
 */
function status_tone_meta(string $tone): array
{
    return match ($tone) {
        'ok' => ['variant' => 'success', 'icon' => 'ok'],
        'warning' => ['variant' => 'warning', 'icon' => 'warning'],
        'limited' => ['variant' => 'warning', 'icon' => 'limited'],
        'critical' => ['variant' => 'danger', 'icon' => 'critical'],
        'unknown' => ['variant' => 'neutral', 'icon' => 'unknown'],
        'current' => ['variant' => 'success', 'icon' => 'current'],
        'stale' => ['variant' => 'neutral', 'icon' => 'stale'],
        'no_data' => ['variant' => 'neutral', 'icon' => 'no-data'],
        'unreachable' => ['variant' => 'danger', 'icon' => 'unreachable'],
        'disabled' => ['variant' => 'neutral', 'icon' => 'disabled'],
        'running' => ['variant' => 'info', 'icon' => 'running'],
        default => throw new InvalidArgumentException('Unknown status tone: ' . $tone),
    };
}

/** The visible word of a tone or of a registered phrase. */
function status_tone_word(string $toneOrPhrase): string
{
    if (!in_array($toneOrPhrase, status_tones(), true) && !array_key_exists($toneOrPhrase, VIRTUSPHERE_STATUS_PHRASES)) {
        throw new InvalidArgumentException('Unknown status tone or phrase: ' . $toneOrPhrase);
    }

    return __t('status_vocabulary.' . $toneOrPhrase);
}

/**
 * The tone of one Ampel token of one source. One table for every source, so
 * "never checked" reads the same word everywhere and a green is always "OK"
 * (domain) or "Aktuell" (reporter), never a source's own synonym.
 *
 * `result` is a run or check result (MECM runs, the dashboard integration
 * signal); `liveness` is a pure heartbeat (internal services), whose red is a
 * silent reporter, not a failed result.
 */
function status_tone_for(string $kind, string $state): string
{
    return match ($kind) {
        'result' => match ($state) {
            'ok' => 'ok',
            'warning', 'legacy', 'missing' => 'warning',
            'danger' => 'critical',
            'stale' => 'stale',
            default => 'no_data',
        },
        'liveness' => match ($state) {
            'ok' => 'current',
            'warning', 'legacy', 'missing' => 'warning',
            'danger' => 'unreachable',
            'stale' => 'stale',
            default => 'no_data',
        },
        // A paused sign-in and a failure streak are both critical: neither pulls
        // again until someone fixes the credential. The pause box names which.
        'esxi', 'directory_controller' => match ($state) {
            'ok' => 'ok',
            'warning' => 'warning',
            'danger' => 'critical',
            'stale' => 'stale',
            default => 'no_data',
        },
        // Ansible yellow means the test passed with a part missing (the host IP
        // is not allowed on the machine API): limited, not a warning.
        'ansible' => match ($state) {
            'ok' => 'ok',
            'warning' => 'limited',
            'danger' => 'critical',
            'stale' => 'stale',
            default => 'no_data',
        },
        // directory_health_snapshot() reads 'unknown' only for a switched-off
        // directory; an enabled one without proof is already 'warning'.
        'directory' => match ($state) {
            'ok' => 'ok',
            'warning' => 'limited',
            'danger' => 'critical',
            default => 'disabled',
        },
        'mecm_site' => match ($state) {
            'ok' => 'ok',
            'warning', 'overdue' => 'warning',
            'limited' => 'limited',
            'critical' => 'critical',
            'stale' => 'stale',
            'none' => 'no_data',
            default => 'unknown',
        },
        default => throw new InvalidArgumentException('Unknown status source kind: ' . $kind),
    };
}

/**
 * The tone of a value badge that already has a colour (a deploy-service axis, a
 * job state): its icon follows its colour, its word stays the value.
 */
function status_tone_for_variant(string $variant): string
{
    return match ($variant) {
        'success' => 'ok',
        'warning' => 'warning',
        'danger' => 'critical',
        'info' => 'running',
        default => 'unknown',
    };
}

/**
 * Severity of a tone for roll-ups and the System status headline, on the one
 * scale of virtusphere_heartbeat_state_rank(): each tone ranks like the token it
 * stands for, so critical and unreachable rank with danger, limited with missing
 * (above warning), the healthy tones with ok, and the grey tones with unknown.
 * The token rank stays token-only; its arms are the audit's integration states.
 */
function status_tone_rank(string $tone): int
{
    return virtusphere_heartbeat_state_rank(match ($tone) {
        'critical', 'unreachable' => 'danger',
        'limited' => 'missing',
        'current', 'running', 'disabled' => 'ok',
        default => $tone,
    });
}
