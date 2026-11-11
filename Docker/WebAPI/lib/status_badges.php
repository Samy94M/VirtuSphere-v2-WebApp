<?php

declare(strict_types=1);

// The two renderers of the status vocabulary (SY2). Portal only: they hand a
// tone to portal_badge(), so lib/layout_presenters.php, which defines it,
// requires this file. The tables themselves stay in lib/status_vocabulary.php,
// which workers load without any HTML.
require_once __DIR__ . '/status_vocabulary.php';

/**
 * A badge of the status vocabulary: word, colour and icon of the tone, or of a
 * registered phrase bound to a tone (VIRTUSPHERE_STATUS_PHRASES).
 */
function status_tone_badge(string $toneOrPhrase): string
{
    $tone = VIRTUSPHERE_STATUS_PHRASES[$toneOrPhrase] ?? $toneOrPhrase;
    $meta = status_tone_meta($tone);

    return portal_badge($meta['variant'], status_tone_word($toneOrPhrase), $meta['icon']);
}

/**
 * A value badge (a count, a deploy-service axis value, a job state): the value
 * is its own word, colour and icon are its tone's. Never pass a level word here;
 * a level is status_tone_badge().
 */
function status_value_badge(string $tone, string $label): string
{
    $meta = status_tone_meta($tone);

    return portal_badge($meta['variant'], $label, $meta['icon']);
}
