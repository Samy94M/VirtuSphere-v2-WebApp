<?php

declare(strict_types=1);

// The MECM half of the System status page: the result reports of every
// integration source and the site-health card next to them. Both read the same
// `integration_status` rows, which is why they share the label and reporter
// presenters at the top; Ansible, the internal services and the ESXi inventory
// each have their own module (ADR-0006).
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_shared_panels.php';
require_once __DIR__ . '/help_page.php';

/** Presentation only: a start report is not proof that the process is still alive. */
function system_status_run_badge(string $state, bool $started): string
{
    if (!$started) {
        return heartbeat_badge($state);
    }
    $meta = virtusphere_heartbeat_meta($state);
    return portal_badge((string) $meta['badge'], __t('system_status.run_completion_open'));
}

/** Explanations supplement the bounded technical detail; they never drive state. */
function system_status_autoimporter_activity(string $event): string
{
    $key = match ($event) {
        VIRTUSPHERE_RUN_EVENT_STARTED => 'run_completion_open',
        VIRTUSPHERE_RUN_EVENT_COMPLETED => 'run_activity_completed',
        default => 'run_activity_unknown',
    };
    return portal_badge('neutral', __t('system_status.' . $key));
}

function system_status_autoimporter_result(?string $outcome): string
{
    [$variant, $key] = match ($outcome) {
        VIRTUSPHERE_RUN_OUTCOME_OK => ['success', 'run_result_ok'],
        VIRTUSPHERE_RUN_OUTCOME_WARNING => ['warning', 'run_result_warning'],
        VIRTUSPHERE_RUN_OUTCOME_FAIL => ['danger', 'run_result_fail'],
        default => ['neutral', 'run_result_unknown'],
    };
    return portal_badge($variant, __t('system_status.' . $key));
}

function system_status_autoimporter_findings(string $detail, ?bool $distribution = null): array
{
    $findings = [];
    foreach (['package_config_invalid', 'package_content_failed', 'package_content_in_progress',
        'package_content_unknown', 'package_definition_drift', 'package_cleanup_failed'] as $code) {
        if ($distribution !== null && str_starts_with($code, 'package_content_') !== $distribution) {
            continue;
        }
        if (preg_match('/(?:^|;\s*)' . preg_quote($code, '/') . '\s+target=/u', $detail) === 1) {
            $findings[] = __t('system_status.finding_' . $code);
        }
    }
    return $findings;
}

// Maps a reportRun error category (sync or site) to a localized label.
function system_status_run_error_label(?string $category): string
{
    return match ($category) {
        VIRTUSPHERE_RUN_ERROR_PORTAL_UNREACHABLE => __t('system_status.err_portal_unreachable'),
        VIRTUSPHERE_RUN_ERROR_MECM_UNAVAILABLE => __t('system_status.err_mecm_unavailable'),
        VIRTUSPHERE_RUN_ERROR_PARTIAL_FAILURE => __t('system_status.err_partial_failure'),
        VIRTUSPHERE_RUN_ERROR_SOURCE_MISSING => __t('system_status.err_source_missing'),
        VIRTUSPHERE_RUN_ERROR_CATALOG_CONFLICT => __t('system_status.err_catalog_conflict'),
        VIRTUSPHERE_RUN_ERROR_SITE_WARNING => __t('system_status.err_site_warning'),
        VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL => __t('system_status.err_site_critical'),
        VIRTUSPHERE_RUN_ERROR_PROVIDER_ACCESS_DENIED => __t('system_status.err_provider_access_denied'),
        VIRTUSPHERE_RUN_ERROR_PROVIDER_UNREACHABLE => __t('system_status.err_provider_unreachable'),
        VIRTUSPHERE_RUN_ERROR_QUERY_FAILED => __t('system_status.err_query_failed'),
        default => '',
    };
}

// Localized label for one summary counter key.
function system_status_run_summary_label(string $key): string
{
    return match ($key) {
        'received' => __t('system_status.summary_received'),
        'imported' => __t('system_status.summary_imported'),
        'item_failures' => __t('system_status.summary_item_failures'),
        'data_warnings' => __t('system_status.summary_data_warnings'),
        'resource_update_failures' => __t('system_status.summary_resource_update_failures'),
        'packages' => __t('system_status.summary_packages'),
        'task_sequences' => __t('system_status.summary_task_sequences'),
        'sent' => __t('system_status.summary_sent'),
        'unchanged' => __t('system_status.summary_unchanged'),
        'folders' => __t('system_status.summary_folders'),
        'created' => __t('system_status.summary_created'),
        'removed' => __t('system_status.summary_removed'),
        'open_points' => __t('system_status.summary_open_points'),
        default => $key,
    };
}

/**
 * The reporter line: legacy note, concrete script version or the plain V2 label.
 *
 * @param array<string,mixed> $row
 */
function system_status_run_reporter_note(array $row): string
{
    if ((string) ($row['last_event'] ?? '') === VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT) {
        return __t('system_status.run_reporter_legacy');
    }
    $version = trim((string) ($row['last_script_version'] ?? ''));
    if ($version !== '') {
        return __t('system_status.run_reporter_version', ['version' => $version]);
    }

    return __t('system_status.run_reporter_v2');
}

/**
 * The MECM section is two visually equal but clearly separated subgroups that
 * are never collapsed into one worst-of: the VirtuSphere-MECM integration (the
 * three result reporters) and the official MECM site status. A critical site
 * must not present the data flow as failed, and a failed sync must not claim
 * MECM itself is critical.
 *
 * @param array<string,mixed> $snapshot
 * @param array<string,mixed> $user
 */
function system_status_render_mecm(array $snapshot, array $user): void
{
    $syncState = (string) $snapshot['mecm_sync']['state'];
    $siteState = (string) $snapshot['mecm_site']['state'];
    // `unknown` for a whole group means no source there has ever reported. On a
    // brand-new install both groups are unknown, so a single setup empty-state
    // replaces four repeated repair instructions; the rows drop their per-row
    // hints in that state. A source that reported once and went quiet is
    // warning/danger, and its silent siblings then read `missing`.
    $syncHasReports = array_key_exists('has_reports', $snapshot['mecm_sync'])
        ? (bool) $snapshot['mecm_sync']['has_reports']
        : count(array_filter($snapshot['mecm_sync']['rows'], static fn (array $entry): bool => $entry['row'] !== null)) > 0;
    $siteHasReports = array_key_exists('has_reports', $snapshot['mecm_site'])
        ? (bool) $snapshot['mecm_site']['has_reports']
        : count(array_filter($snapshot['mecm_site']['rows'], static fn (array $entry): bool => $entry['row'] !== null)) > 0;
    $setupPending = !$syncHasReports && !$siteHasReports;
    // "Nothing has ever reported" has three causes that used to render as one grey
    // row: never installed, installed but the first run is still pending, and
    // installed but REFUSED at the IP gate. The third is the commonest setup
    // mistake in the product, and a refusal is the one piece of positive evidence
    // that tells it apart: somebody IS knocking. Naming the IP turns the row into
    // the fix, because that IP is exactly what has to go on the allowlist.
    $denialSummary = (array) ($snapshot['machine_api_denials'] ?? []);
    $denials = isset($denialSummary['rows']) && is_array($denialSummary['rows']) ? $denialSummary['rows'] : $denialSummary;
    $denialTotal = isset($denialSummary['total']) ? (int) $denialSummary['total'] : count($denials);
    $denialOmitted = isset($denialSummary['omitted']) ? (int) $denialSummary['omitted'] : 0;
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM); ?>">
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.mecm_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.mecm_hint')); ?></p></div>
            <?php if (can('users.manage', $user)) { ?><a class="button button-secondary" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_MECM)); ?>"><?php echo h(__t('system_status.open_logs')); ?></a><?php } ?>
        </div>
        <?php if (!empty($snapshot['mecm_ip_mismatch'])) { ?>
            <div class="alert alert-warning"><?php echo h(__t('system_status.mecm_ip_mismatch', ['ips' => implode(', ', array_keys($snapshot['mecm_fresh_ips']))])); ?></div>
        <?php } ?>
        <?php if ($denials !== []) { ?>
            <?php // Rendered whether or not the group is grey: a refusal is a finding
                  // even next to sources that otherwise report, because it means one
                  // more host is being turned away than the rows show. ?>
            <div class="alert alert-warning">
                <?php echo h(__t('system_status.machine_api_denied_history', [
                    'ips' => implode(', ', array_map(static fn (array $row): string => (string) $row['ip'], $denials)),
                    'when' => portal_format_timestamp((string) $denials[0]['last_at']),
                    'total' => $denialTotal,
                    'hours' => (int) (VIRTUSPHERE_MACHINE_API_DENIAL_WINDOW_SECONDS / 3600),
                ])); ?>
                <?php if ($denialOmitted > 0) { echo ' ' . h(__t('system_status.machine_api_denied_more', ['count' => $denialOmitted])); } ?>
                <?php if (can('system.config', $user)) { ?><a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('system_status.mecm_configure_allowlist')); ?></a><?php } ?>
                <?php if (can('users.manage', $user)) { ?><a href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_MACHINE_API)); ?>"><?php echo h(__t('system_status.machine_api_open_log')); ?></a><?php } ?>
            </div>
        <?php } ?>
        <?php if ($setupPending) { ?>
            <div class="empty-state">
                <?php // The sentence differs by which of the three causes applies: with a
                      // refusal on record, "probably not set up yet" is simply false. ?>
                <p><?php echo h($denials === [] ? __t('system_status.mecm_setup_empty') : __t('system_status.mecm_setup_denied')); ?></p>
                <?php if (can('system.config', $user)) { ?><a class="button button-secondary" href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('system_status.mecm_configure_allowlist')); ?></a><?php } ?>
            </div>
        <?php } ?>

        <?php if ($setupPending) { ?><details class="technical-details"><summary><?php echo h(__t('system_status.mecm_setup_sources_summary')); ?></summary><?php } ?>
        <div class="status-subgroup">
            <h3><?php echo h(__t('system_status.mecm_sync_heading')); ?> <?php echo heartbeat_badge($syncState); ?></h3>
            <p class="muted"><?php echo h(__t('system_status.mecm_sync_hint')); ?></p>
            <?php system_status_render_run_rows($snapshot['mecm_sync']['rows'], $setupPending); ?>
        </div>

        <div class="status-subgroup">
            <h3><?php echo h(__t('system_status.mecm_site_heading')); ?> <?php echo heartbeat_badge($siteState); ?></h3>
            <p class="muted"><?php echo h(__t('system_status.mecm_site_hint')); ?></p>
            <?php system_status_render_site($snapshot['mecm_site']['rows']); ?>
        </div>
        <?php if ($setupPending) { ?></details><?php } ?>
    </section>
    <?php
}

/**
 * Rich result-report rows for the three MECM sync reporters: activity badge,
 * "running since" while a run is in progress, the reporter version or legacy
 * note, the attempt/result/success/failure timestamps, interval, duration,
 * source-specific counters and the sanitized technical detail.
 *
 * @param list<array{source:string,row:array|null,state:string}> $rows
 */
function system_status_render_run_rows(array $rows, bool $suppressHints = false): void
{
    ?>
    <div class="status-list">
        <?php foreach ($rows as $entry) {
            $row = $entry['row'];
            $state = (string) $entry['state'];
            $event = $row !== null ? (string) ($row['last_event'] ?? VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT) : '';
            $isRunning = $event === VIRTUSPHERE_RUN_EVENT_STARTED;
            $isLegacy = $event === VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT;
            $isAutoimporter = $entry['source'] === 'autoimporter';
            $detail = trim((string) ($row['last_detail'] ?? ''));
            $errorLabel = $row !== null ? system_status_run_error_label(isset($row['last_error_category']) ? (string) $row['last_error_category'] : null) : '';
            $summary = ($row !== null && !empty($row['last_summary'])) ? json_decode((string) $row['last_summary'], true) : null;
            ?>
            <article class="status-row">
                <?php if ($isAutoimporter) { ?><h4><?php echo h(__t('system_status.run_activity_heading')); ?></h4><?php } ?>
                <div class="status-row-head"><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo $isAutoimporter ? system_status_autoimporter_activity($event) : system_status_run_badge($state, $isRunning); ?><?php if ($isRunning && !empty($row['last_attempt_at'])) { ?><span class="muted"><?php echo h(__t('system_status.run_running_since', ['time' => portal_format_timestamp($row['last_attempt_at'])])); ?></span><?php } ?><?php if ($row !== null) { ?><span class="muted"><?php echo h(system_status_run_reporter_note($row)); ?></span><?php } ?></div>
                <?php if ($isRunning) { ?><p class="muted"><?php echo h(__t('system_status.run_completion_hint')); ?></p><?php } ?>
                <?php if ($isAutoimporter) { ?><h4><?php echo h(__t('system_status.run_result_heading')); ?></h4><p><?php echo system_status_autoimporter_result(!empty($row['last_result_at']) ? (string) ($row['last_status'] ?? '') : null); ?></p><p class="muted"><?php echo h(__t('system_status.run_result_scope')); ?></p><?php } elseif ($isRunning && !empty($row['last_result_at'])) { ?><p><strong><?php echo h(__t('system_status.run_last_completed_heading')); ?></strong></p><?php } ?>
                <?php
                // All six fields, always, in this order. They used to appear only
                // when they had a value, so the number of columns differed per
                // reporter (the package sync has a duration, the others may not)
                // and the three cards of one group started their block at three
                // different x positions. A missing value is an em dash and keeps
                // its column: it says nothing is stored, not that nothing
                // happened.
                //
                // The one deliberate exception to "same field under same field":
                // a legacy reporter has no success timestamp, only the heartbeat
                // it last sent, so the third column carries the other label. The
                // position is the contract, the label is the truth.
                echo system_status_fact_list([
                    ['label' => __t('system_status.th_last_attempt'), 'html' => system_status_fact_time($row['last_attempt_at'] ?? null)],
                    ['label' => __t('system_status.th_last_result'), 'html' => system_status_fact_time($row['last_result_at'] ?? null)],
                    $isLegacy
                        ? ['label' => __t('system_status.th_last_seen'), 'html' => system_status_fact_time($row['last_seen_at'] ?? null)]
                        : ['label' => __t('system_status.th_last_success'), 'html' => system_status_fact_time($row['last_success_at'] ?? null)],
                    ['label' => __t('system_status.th_last_failure'), 'html' => system_status_fact_time($row['last_failure_at'] ?? null)],
                    ['label' => __t('system_status.th_interval'), 'html' => $row !== null ? h(portal_format_duration((int) $row['interval_seconds'])) : '&mdash;'],
                    ['label' => __t('system_status.th_duration'), 'html' => ($row !== null && $row['last_duration_ms'] !== null && $row['last_duration_ms'] !== '') ? h(portal_format_duration_ms((int) $row['last_duration_ms'])) : '&mdash;'],
                ]);
                ?>
                <?php if (is_array($summary) && $summary !== []) { ?>
                    <dl class="status-counters">
                        <?php foreach ($summary as $summaryKey => $summaryValue) { if (!is_string($summaryKey)) { continue; } ?><div><dt><?php echo h(system_status_run_summary_label($summaryKey)); ?></dt><dd><?php echo h((string) $summaryValue); ?></dd></div><?php } ?>
                    </dl>
                <?php } ?>
                <?php
                if ($state === 'legacy') { ?><p class="status-action"><?php echo h(__t('system_status.run_legacy_hint')); ?></p><?php
                } elseif (!$suppressHints && $state !== 'ok') {
                    $actionHint = integration_action_hint($entry['source']);
                    if ($actionHint !== '') { ?><p class="status-action"><?php echo h($actionHint); ?></p><?php }
                } ?>
                <?php if ($errorLabel !== '') { ?><p class="alert-inline"><?php echo h($errorLabel); ?></p><?php } ?>
                <?php if ($entry['source'] === 'autoimporter' && !$suppressHints) {
                    foreach (system_status_autoimporter_findings($detail, false) as $finding) { ?><p class="status-action"><?php echo h($finding); ?></p><?php }
                } ?>
                <?php if ($isAutoimporter) { ?>
                    <h4><?php echo h(__t('system_status.run_distribution_heading')); ?></h4>
                    <p class="muted"><?php echo h(__t('system_status.run_distribution_scope')); ?></p>
                    <?php echo system_status_fact_list([
                        ['label' => __t('system_status.th_last_result'), 'html' => system_status_fact_time($row['last_result_at'] ?? null)],
                    ]);
                    $distributionFindings = !empty($row['last_result_at']) ? system_status_autoimporter_findings($detail, true) : [];
                    if ($distributionFindings === []) { ?><p><?php echo h(__t('system_status.run_distribution_unknown')); ?></p><?php }
                    foreach ($distributionFindings as $finding) { ?><p class="status-action"><?php echo h($finding); ?></p><?php }
                    if (!$suppressHints) {
                    ?><p><a href="<?php echo h(help_url('system-status', 'help-status-mecm')); ?>"><?php echo h(__t('system_status.autoimporter_help')); ?></a></p><?php
                    }
                } ?>
                <?php if ($detail !== '') { ?><details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details><?php } ?>
            </article>
        <?php } ?>
    </div>
    <?php
}

/**
 * The MECM site-status card: site code, SMS provider, the official MECM state
 * badge, the check/healthy/failure timestamps, the report interval and a hint
 * that distinguishes MECM warning (yellow), MECM critical (red) and the grey
 * provider faults (unreachable, access denied) from each other in text.
 *
 * @param list<array{source:string,row:array|null,state:string}> $rows
 */
function system_status_render_site(array $rows): void
{
    $entry = $rows[0] ?? null;
    $row = $entry['row'] ?? null;
    if ($row === null) {
        ?><div class="empty-state"><p><?php echo h(__t('system_status.mecm_site_empty')); ?></p></div><?php
        return;
    }
    $state = (string) ($entry['state'] ?? 'unknown');
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
        // Seven fixed fields, same shape as the sync rows above. This card used
        // to be the one whose only child was the list, so neither the
        // space-between of the old head nor its first-child rule applied and the
        // block sat left while the job cards sat right: an asymmetry that was
        // pure accident and read as a difference in kind.
        echo system_status_fact_list([
            ['label' => __t('system_status.site_code_label'), 'html' => $siteCode !== '' ? '<code>' . h($siteCode) . '</code>' : '&mdash;'],
            ['label' => __t('system_status.site_provider_label'), 'html' => $provider !== '' ? '<code>' . h($provider) . '</code>' : '&mdash;'],
            ['label' => __t('system_status.site_state_label'), 'html' => heartbeat_badge($state) . ($errorLabel !== '' ? ' <span>' . h($errorLabel) . '</span>' : '')],
            ['label' => __t('system_status.site_last_check'), 'html' => system_status_fact_time($row['last_result_at'] ?? null)],
            ['label' => __t('system_status.site_last_healthy'), 'html' => system_status_fact_time($row['last_success_at'] ?? null)],
            ['label' => __t('system_status.th_last_failure'), 'html' => system_status_fact_time($row['last_failure_at'] ?? null)],
            ['label' => __t('system_status.site_interval'), 'html' => h(portal_format_duration((int) $row['interval_seconds']))],
        ]);
        ?>
        <?php $hint = system_status_site_hint($errorCategory); if ($hint !== '') { ?><p class="status-action"><?php echo h($hint); ?></p><?php } ?>
        <?php if ($detail !== '') { ?><details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details><?php } ?>
    </article>
    <?php
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
