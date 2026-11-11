<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/status_evidence.php';
require_once __DIR__ . '/system_status_urls.php';

// The package codes the autoimporter writes into its cause line, in the order of
// `$script:VsRunCauseVocabulary` (Powershell-MECM/mecm/VirtuSphere-Common.ps1),
// which owns the code set. The System status card and the help list both read
// this one list; AutoimporterStatusPresentationTest derives the package codes
// from the PowerShell vocabulary and fails when this list, a card label or a
// help entry in either locale is missing one. A `package_content_` prefix makes
// a code a distribution note, everything else is a configuration finding.
const VIRTUSPHERE_AUTOIMPORTER_PACKAGE_CAUSES = [
    'package_config_invalid',
    'package_content_failed',
    'package_content_in_progress',
    'package_content_unknown',
    'package_content_request_failed',
    'package_content_group_empty',
    'package_definition_drift',
    'package_source_missing',
    'package_deploy_failed',
    'package_deployto_missing',
    'package_deployto_ambiguous',
    'package_deployto_invalid',
    'package_scan_failed',
    'package_cleanup_failed',
    'package_folder_failed',
    'package_template_failed',
];

// How often a quiet autoimporter run rereads the distribution state of packages
// with a note (AV2). The PowerShell side owns the value
// (`$script:VsDistributionHintRefreshSeconds` in VirtuSphere-Common.ps1); the
// card and the help state it from here, and AutoimporterStatusPresentationTest
// fails when the two differ.
const VIRTUSPHERE_AUTOIMPORTER_HINT_REFRESH_SECONDS = 1800;

/**
 * Whether the deviation scan compared every object kind against current names.
 * Only then is a zero a release; both readers of the count pass this to
 * deviation_count_badge(). A missing report is not current (fail closed).
 *
 * @param array<string,mixed>|null $report esxi_inventory_deviation_report()
 */
function system_status_deviation_evidence_current(?array $report): bool
{
    return !empty($report['fully_current']);
}

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
 * The legend of the status vocabulary itself (SY2): every tone and every
 * registered phrase with its badge and one sentence, grouped by axis. Derived
 * from the tone lists, so a new tone appears here without a second list; shared
 * by the System status page and the help panel.
 */
function system_status_vocabulary_legend(int $headingLevel = 3): void
{
    $axes = [
        'axis_level' => VIRTUSPHERE_STATUS_LEVEL_TONES,
        'axis_reporter' => VIRTUSPHERE_STATUS_REPORTER_TONES,
        'axis_activity' => VIRTUSPHERE_STATUS_ACTIVITY_TONES,
    ];
    foreach ($axes as $axisKey => $tones) {
        echo '<h' . $headingLevel . '>' . h(__t('status_vocabulary.' . $axisKey)) . '</h' . $headingLevel . '><ul class="ampel-legend" data-legend="' . h($axisKey) . '">';
        foreach ($tones as $tone) {
            echo '<li>' . status_tone_badge($tone) . ' ' . h(__t('status_vocabulary.legend_' . $tone)) . '</li>';
            foreach (array_keys(VIRTUSPHERE_STATUS_PHRASES, $tone, true) as $phrase) {
                echo '<li>' . status_tone_badge($phrase) . ' ' . h(__t('status_vocabulary.legend_' . $phrase)) . '</li>';
            }
        }
        echo '</ul>';
    }
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
        'legend_warning' => ['multiplier' => VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER, 'factor' => VIRTUSPHERE_RUN_DURATION_GRACE_FACTOR],
        'esxi_legend_danger' => ['streak' => VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER],
        'esxi_legend_stale' => ['days' => VIRTUSPHERE_ESXI_INVENTORY_STALE_AFTER_DAYS, 'factor' => VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR],
        'ansible_legend_stale' => ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS],
        'directory_legend_warning' => ['days' => VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS],
        'directory_legend_stale' => ['days' => VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS],
    ];

    echo '<ul class="ampel-legend" data-legend="' . h($kind) . '">';
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
