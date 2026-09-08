<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/system_status_urls.php';

/**
 * How many mission/VM deviations the scan found, or null when it could not run.
 *
 * The three answers are genuinely different and the difference is the whole
 * point: null is "not checked", because without an ESXi inventory there is
 * nothing to compare against and reporting that as a green zero would hand out
 * a clean bill of health nobody issued; 0 is "checked, nothing found"; anything
 * above is a count. The overview card and the section heading both read THIS
 * value rather than each summing the issue lists themselves, because two
 * derivations of one number are two numbers as soon as one of them is edited,
 * and a strip saying "3" over a section listing four is worse than no strip.
 *
 * @param array<int,array<string,mixed>> $deviations
 */
function system_status_deviation_count(array $deviations, bool $hasInventory): ?int
{
    if (!$hasInventory) {
        return null;
    }

    return array_sum(array_map(
        static fn (array $entry): int => count((array) ($entry['issues'] ?? [])),
        $deviations
    ));
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
        // The controller-row state set (5 states, includes 'stale'), not the
        // coarser overall-card badge: this is what a reader has open when
        // trying to explain one row's colour, and the overall badge is only
        // ever a roll-up of these same words minus 'stale' (directory_health_snapshot()).
        'directory' => [VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES, 'directory_legend_'],
        default => [VIRTUSPHERE_HEARTBEAT_STATES, 'legend_'],
    };

    // Thresholds an explanation quotes are interpolated from the constant that
    // enforces them, so the sentence cannot keep its number after the constant
    // moves (CLAUDE.md bounds rule).
    $params = [
        'legend_warning' => ['multiplier' => VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER],
        'esxi_legend_danger' => ['streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER],
        'ansible_legend_stale' => ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS],
        'directory_legend_warning' => ['days' => VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS],
        'directory_legend_stale' => ['days' => VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS],
    ];

    echo '<ul class="ampel-legend">';
    foreach ($states as $state) {
        $badge = match ($kind) {
            'esxi' => esxi_state_badge($state),
            'ansible' => ansible_state_badge($state),
            'directory' => directory_controller_state_badge($state),
            default => heartbeat_badge($state),
        };
        $key = $prefix . $state;
        echo '<li>' . $badge . ' ' . h(__t('system_status.' . $key, $params[$key] ?? [])) . '</li>';
    }
    echo '</ul>';
}
