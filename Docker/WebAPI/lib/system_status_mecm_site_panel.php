<?php

declare(strict_types=1);

// The MECM site half of the System status page (ADR-0006 split of
// system_status_mecm_panels.php): the site-health card with the assessed site
// state, the part counts by type and the hint, plus the MECM card of the
// overview strip. The words come from lib/mecm_site_display.php, the reading
// from lib/mecm_site_assessment.php (ADR-0018 amendment 2026-11-09).
require_once __DIR__ . '/system_status_shared_panels.php';
require_once __DIR__ . '/mecm_site_display.php';

/**
 * The MECM card of the overview strip: two signal rows, "Datenfluss" (the sync
 * tasks' results) and "Standort" (the site verdict), like the dashboard tile.
 * They are never folded into one worst-of badge (SY2): a red MECM card did not
 * say whether the portal's data flow or MECM itself was meant. The headline
 * takes the worse of the two (system_status_mecm_overview_tone()).
 *
 * @param array<string,mixed> $snapshot
 */
function system_status_mecm_overview_badge(array $snapshot): string
{
    return '<span class="status-overview-signals">'
        . portal_signal_row(__t('system_status_overview.mecm_flow'), (string) ($snapshot['mecm_sync']['state'] ?? 'unknown'))
        . portal_signal_row(__t('system_status_overview.mecm_site'), '', mecm_site_badge((string) ($snapshot['mecm_site']['site_state'] ?? 'none')))
        . '</span>';
}

/**
 * The tone the MECM card stands for in the headline: the worse of data flow and
 * site. On equal rank the data flow wins, because the portal can act on it.
 *
 * @param array<string,mixed> $snapshot
 */
function system_status_mecm_overview_tone(array $snapshot): string
{
    $flow = status_tone_for('result', (string) ($snapshot['mecm_sync']['state'] ?? 'unknown'));
    $site = mecm_site_tone((string) ($snapshot['mecm_site']['site_state'] ?? 'none'));

    return status_tone_rank($site) > status_tone_rank($flow) ? $site : $flow;
}

/**
 * The MECM site-status card: site code, SMS provider, the assessed site state
 * next to what MECM itself reported, the parts MECM rates warning or critical
 * by type, the check/healthy/failure timestamps, the report interval and a hint
 * that distinguishes MECM warning, limited (only supporting parts critical),
 * MECM critical and the grey provider faults from each other in text.
 *
 * @param list<array{source:string,row:array|null,state:string,result_state?:string,site_state?:string,reporter_state?:string}> $rows
 */
function system_status_render_site(array $rows): void
{
    $entry = $rows[0] ?? null;
    $row = $entry['row'] ?? null;
    if ($row === null) {
        ?><div class="empty-state"><p><?php echo h(__t('system_status.mecm_site_empty')); ?></p></div><?php
        return;
    }
    $state = (string) ($entry['result_state'] ?? $entry['state'] ?? 'unknown');
    $assessment = mecm_site_assessment($row);
    $siteState = (string) ($entry['site_state'] ?? ($state === 'stale' ? 'stale' : $assessment['verdict']));
    $reporterState = (string) ($entry['reporter_state'] ?? ($state === 'stale' ? 'warning' : ($state === 'unknown' ? 'unknown' : 'ok')));
    $summary = !empty($row['last_summary']) ? json_decode((string) $row['last_summary'], true) : [];
    $siteCode = is_array($summary) && isset($summary['site_code']) ? (string) $summary['site_code'] : '';
    $provider = is_array($summary) && isset($summary['provider']) ? (string) $summary['provider'] : '';
    $errorCategory = isset($row['last_error_category']) ? (string) $row['last_error_category'] : '';
    $errorLabel = system_status_run_error_label($errorCategory);
    $detail = trim((string) ($row['last_detail'] ?? ''));
    ?>
    <article class="status-row">
        <?php if ($state === 'stale') { ?><p class="status-action"><?php echo h(__t('system_status.site_historical_result')); ?></p><?php } ?>
        <?php
        // Fixed facts, with reporter availability separate from the result. This card used
        // to be the one whose only child was the list, so neither the
        // space-between of the old head nor its first-child rule applied and the
        // block sat left while the job cards sat right: an asymmetry that was
        // pure accident and read as a difference in kind.
        echo system_status_fact_list([
            // The reporter row is liveness, so it reads reporter words: "Aktuell",
            // a late reporter "Warnung", never a site verdict (SY2).
            ['label' => __t('system_status.site_reporter_label'), 'html' => heartbeat_badge($reporterState, 'liveness')],
            ['label' => __t('system_status.site_code_label'), 'html' => $siteCode !== '' ? '<code>' . h($siteCode) . '</code>' : '&mdash;'],
            ['label' => __t('system_status.site_provider_label'), 'html' => $provider !== '' ? '<code>' . h($provider) . '</code>' : '&mdash;'],
            // The badge is the portal's reading, the text next to it what MECM itself reported.
            ['label' => __t('system_status.site_state_label'), 'html' => mecm_site_badge($siteState) . ($errorLabel !== '' ? ' <span>' . h($errorLabel) . '</span>' : '')],
            ['label' => __t('system_status.site_last_check'), 'html' => system_status_fact_time($row['last_result_at'] ?? null)],
            ['label' => __t('system_status.site_last_healthy'), 'html' => system_status_fact_time($row['last_success_at'] ?? null)],
            ['label' => __t('system_status.th_last_failure'), 'html' => system_status_fact_time($row['last_failure_at'] ?? null)],
            ['label' => __t('system_status.site_interval'), 'html' => h(portal_format_duration((int) $row['interval_seconds']))],
        ]);
        ?>
        <?php system_status_render_site_parts($assessment); ?>
        <?php $hint = $assessment['verdict'] === VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED ? __t('system_status.site_hint_limited') : system_status_site_hint($errorCategory); if ($hint !== '') { ?><p class="status-action"><?php echo h($hint); ?></p><?php } ?>
        <?php if ($detail !== '') { ?><details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details><?php } ?>
    </article>
    <?php
}

/** "1 role" / "4 roles": the catalogs carry a _one and an _other form. */
function system_status_site_count(string $base, int $count): string
{
    return __t('system_status.' . $base . ($count === 1 ? '_one' : '_other'), ['count' => $count]);
}

/**
 * What MECM rates warning or critical, by type: one summary sentence and one
 * table row per type with its class from the type table. Without part counts
 * (older reporter, failed detail query) one sentence says the MECM verdict
 * applies; nothing is shown while MECM reports the site as OK.
 *
 * @param array{verdict: string, reported: string, has_parts: bool, explained: bool, omitted: int, parts: list<array{kind: string, name: string, class: string, label: ?string, critical: int, warning: int}>, critical: array<string,int>, warning: array<string,int>} $assessment
 */
function system_status_render_site_parts(array $assessment): void
{
    if (!in_array($assessment['reported'], [VIRTUSPHERE_MECM_SITE_VERDICT_WARNING, VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL], true)) {
        return;
    }
    if (!$assessment['has_parts']) {
        ?><p class="muted"><?php echo h(__t('system_status.site_parts_none')); ?></p><?php
        return;
    }
    $counts = [];
    foreach ([
        ['critical', VIRTUSPHERE_SITE_PART_KIND_ROLES, 'site_parts_roles_critical'],
        ['critical', VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, 'site_parts_components_critical'],
        ['warning', VIRTUSPHERE_SITE_PART_KIND_ROLES, 'site_parts_roles_warning'],
        ['warning', VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, 'site_parts_components_warning'],
    ] as [$level, $kind, $key]) {
        $count = (int) ($assessment[$level][$kind] ?? 0);
        if ($count > 0) {
            $counts[] = system_status_site_count($key, $count);
        }
    }
    if ($counts !== []) {
        ?><p><?php echo h(implode(', ', $counts)); ?></p>
        <div class="table-wrap"><table>
            <thead><tr>
                <th><?php echo h(__t('system_status.site_parts_th_part')); ?></th>
                <th><?php echo h(__t('system_status.site_parts_th_kind')); ?></th>
                <th><?php echo h(__t('system_status.site_parts_th_critical')); ?></th>
                <th><?php echo h(__t('system_status.site_parts_th_warning')); ?></th>
                <th><?php echo h(__t('system_status.site_parts_th_class')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($assessment['parts'] as $part) {
                $name = $part['label'] !== null ? h(__t('system_status.site_role_' . $part['label'])) : '<code>' . h($part['name']) . '</code>';
                ?><tr>
                    <td><?php echo $name; ?></td>
                    <td><?php echo h(__t($part['kind'] === VIRTUSPHERE_SITE_PART_KIND_ROLES ? 'system_status.site_part_kind_role' : 'system_status.site_part_kind_component')); ?></td>
                    <td><?php echo h((string) $part['critical']); ?></td>
                    <td><?php echo h((string) $part['warning']); ?></td>
                    <td><?php echo h(__t('system_status.site_part_class_' . $part['class'])); ?></td>
                </tr><?php
            } ?>
            </tbody>
        </table></div><?php
    }
    if ($assessment['omitted'] > 0) {
        ?><p class="muted"><?php echo h(__t('system_status.site_parts_omitted', ['count' => $assessment['omitted']])); ?></p><?php
    } elseif ($assessment['reported'] === VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL && !$assessment['explained']) {
        ?><p class="muted"><?php echo h(__t('system_status.site_parts_unexplained')); ?></p><?php
    }
}

// Site-status feedback: MECM warning and critical both point at the MECM
// console; the grey provider faults point at the provider and its SYSTEM rights.
function system_status_site_hint(string $category): string
{
    return match ($category) {
        VIRTUSPHERE_RUN_ERROR_SITE_WARNING, VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL => __t('system_status.site_hint_console'),
        VIRTUSPHERE_RUN_ERROR_PROVIDER_ACCESS_DENIED => __t('system_status.site_hint_access_denied'),
        VIRTUSPHERE_RUN_ERROR_PROVIDER_UNREACHABLE => __t('system_status.site_hint_provider_unreachable'),
        VIRTUSPHERE_RUN_ERROR_QUERY_FAILED => __t('system_status.site_hint_query_failed'),
        default => '',
    };
}
