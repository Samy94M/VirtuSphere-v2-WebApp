<?php

declare(strict_types=1);

// The verdict line of a System status card (SY3): the first line under a
// section heading answers "Do I have to do something?" in one sentence. It reads
// the tone the overview card of the same area shows (R58), so the strip, the
// headline and the section cannot disagree, and it never evaluates a state of
// its own. What to do is the section's own sentence; a card without one points
// at the entry below that carries it.
require_once __DIR__ . '/status_badges.php';

/**
 * What a tone asks of the operator, on the one rank scale of the headline:
 * `act` for warning and worse, `evidence` for grey (no current proof, never
 * "in order"), `none` for healthy, running and switched off.
 */
function system_status_tone_need(string $tone): string
{
    $rank = status_tone_rank($tone);
    if ($rank >= status_tone_rank('warning')) {
        return 'act';
    }

    return $rank > status_tone_rank('ok') ? 'evidence' : 'none';
}

/**
 * The verdict line. `$factHtml` is an escaped summary that holds in every state
 * (the scan and the distribution named apart); `$actionHtml` is the escaped
 * next step, ignored for `none`, because "nothing to do" needs no instruction.
 */
function system_status_verdict(string $tone, string $actionHtml = '', string $factHtml = ''): string
{
    $need = system_status_tone_need($tone);
    $meta = status_tone_meta($tone);
    $text = '<strong>' . h(__t('system_status_cards.verdict_' . $need)) . '</strong>';
    if ($factHtml !== '') {
        $text .= ' ' . $factHtml;
    }
    if ($need !== 'none') {
        $text .= ' ' . ($actionHtml !== '' ? $actionHtml : h(__t('system_status_cards.verdict_see_entry')));
    }

    return '<p class="status-verdict status-verdict-' . h($need) . '" data-verdict="' . h($need) . '" data-verdict-tone="' . h($tone) . '">'
        . portal_badge_icon($meta['icon']) . '<span>' . $text . '</span></p>';
}
