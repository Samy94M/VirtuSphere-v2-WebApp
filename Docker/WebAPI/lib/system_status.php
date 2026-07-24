<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';

/**
 * SSoT for links into the visible System status page. The compatible endpoint
 * remains system_status.php; callers provide only an actual rendered anchor.
 *
 * @param array<string,int|string> $query
 */
function system_status_url(string $anchor, array $query = []): string
{
    if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $anchor) !== 1) {
        throw new InvalidArgumentException('Invalid System status anchor.');
    }
    $url = 'system_status.php';
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url . '#' . $anchor;
}

/**
 * SSoT renderer for one Ampel legend, shared by the System status page and the
 * help panel. Both used to hand-list their states and had drifted: the heartbeat
 * `missing` state was explained in help and absent from the page's own legend,
 * so the badge an operator sees there could not be looked up where they are.
 *
 * The state set comes from the constants, the badge from the same helper the
 * page renders with, so a legend entry can never show a colour or a word the
 * page does not use.
 */
function system_status_legend_items(string $kind): void
{
    [$states, $prefix] = match ($kind) {
        'esxi' => [VIRTUSPHERE_ESXI_AMPEL_STATES, 'esxi_legend_'],
        'ansible' => [VIRTUSPHERE_ANSIBLE_AMPEL_STATES, 'ansible_legend_'],
        default => [VIRTUSPHERE_HEARTBEAT_STATES, 'legend_'],
    };

    // Thresholds an explanation quotes are interpolated from the constant that
    // enforces them, so the sentence cannot keep its number after the constant
    // moves (CLAUDE.md bounds rule).
    $params = [
        'legend_warning' => ['multiplier' => VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER],
        'esxi_legend_danger' => ['streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER],
        'ansible_legend_stale' => ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS],
    ];

    echo '<ul class="ampel-legend">';
    foreach ($states as $state) {
        $badge = match ($kind) {
            'esxi' => esxi_state_badge($state),
            'ansible' => ansible_state_badge($state),
            default => heartbeat_badge($state),
        };
        $key = $prefix . $state;
        echo '<li>' . $badge . ' ' . h(__t('system_status.' . $key, $params[$key] ?? [])) . '</li>';
    }
    echo '</ul>';
}
