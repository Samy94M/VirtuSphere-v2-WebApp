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
// The site-health card and the overview badge (split by domain, ADR-0006).
require_once __DIR__ . '/system_status_mecm_site_panel.php';

/**
 * Presentation only: a start report is not proof that the process is still
 * alive. An open run keeps the tone of the previous result and says so in its
 * own words (status vocabulary, R59).
 */
function system_status_run_badge(string $state, bool $started): string
{
    if (!$started) {
        return heartbeat_badge($state);
    }
    return status_value_badge(status_tone_for('result', $state), __t('system_status.run_completion_open'));
}

/**
 * The activity axis is the reporter's, never the scan result's: a started run
 * is "running", a reported completion is current evidence, anything else has
 * no data. Explanations supplement the bounded technical detail; they never
 * drive state.
 */
function system_status_autoimporter_activity(string $event): string
{
    [$tone, $key] = match ($event) {
        VIRTUSPHERE_RUN_EVENT_STARTED => ['running', 'run_completion_open'],
        VIRTUSPHERE_RUN_EVENT_COMPLETED => ['current', 'run_activity_completed'],
        default => ['no_data', 'run_activity_unknown'],
    };
    return status_value_badge($tone, __t('system_status.' . $key));
}

/** The last completed scan in its own words, coloured by its domain tone. */
function system_status_autoimporter_result(?string $outcome): string
{
    [$tone, $key] = match ($outcome) {
        VIRTUSPHERE_RUN_OUTCOME_OK => ['ok', 'run_result_ok'],
        VIRTUSPHERE_RUN_OUTCOME_WARNING => ['warning', 'run_result_warning'],
        VIRTUSPHERE_RUN_OUTCOME_FAIL => ['critical', 'run_result_fail'],
        default => ['no_data', 'run_result_unknown'],
    };
    return status_value_badge($tone, __t('system_status.' . $key));
}

/**
 * @return list<string>
 */
function system_status_autoimporter_findings(string $detail, ?bool $distribution = null): array
{
    $findings = [];
    // package_content_group_empty (AV-F27) is a distribution finding by its prefix:
    // a DP group without members gives the initial request no target (AV2).
    // package_folder_failed (PS-09) is a placement finding and stays configuration.
    foreach (VIRTUSPHERE_AUTOIMPORTER_PACKAGE_CAUSES as $code) {
        if ($distribution !== null && str_starts_with($code, 'package_content_') !== $distribution) {
            continue;
        }
        if (preg_match('/(?:^|;\s*)' . preg_quote($code, '/') . '\s+target=/u', $detail) === 1) {
            $findings[] = __t('system_status.finding_' . $code);
        }
    }
    return $findings;
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
    // The site heading speaks the site's own words (lib/mecm_site_assessment.php).
    $siteState = (string) ($snapshot['mecm_site']['site_state'] ?? 'none');
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
            <?php system_status_render_run_rows($snapshot['mecm_sync']['rows'], $setupPending, $user); ?>
        </div>

        <div class="status-subgroup">
            <h3><?php echo h(__t('system_status.mecm_site_heading')); ?> <?php echo mecm_site_badge($siteState); ?></h3>
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
 * @param list<array{source:string,row:array|null,state:string,report_overdue?:bool}> $rows
 * @param array<string,mixed>|null $user gates the settings link of a rejected catalog
 */
function system_status_render_run_rows(array $rows, bool $suppressHints = false, ?array $user = null): void
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
            // A legacy heartbeat does not establish a completed-result tuple,
            // even if a pre-fix row still contains stale V2 fields. A regular V2
            // start may deliberately retain the preceding completed result.
            $hasCompletedResult = $row !== null && !$isLegacy && !empty($row['last_result_at']);
            $detail = trim((string) ($row['last_detail'] ?? ''));
            $completionDetail = !$isAutoimporter || $hasCompletedResult ? $detail : '';
            $errorLabel = ($row !== null && (!$isAutoimporter || $hasCompletedResult))
                ? system_status_run_error_label(isset($row['last_error_category']) ? (string) $row['last_error_category'] : null)
                : '';
            $summary = ($row !== null && (!$isAutoimporter || $hasCompletedResult) && !empty($row['last_summary']))
                ? json_decode((string) $row['last_summary'], true)
                : null;
            ?>
            <article class="status-row">
                <?php if ($isAutoimporter) { ?><h4><?php echo h(__t('system_status.run_activity_heading')); ?></h4><?php } ?>
                <div class="status-row-head"><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo $isAutoimporter ? system_status_autoimporter_activity($event) : system_status_run_badge($state, $isRunning); ?><?php if ($isRunning && !empty($row['last_attempt_at'])) { ?><span class="muted"><?php echo h(__t('system_status.run_running_since', ['time' => portal_format_timestamp($row['last_attempt_at'])])); ?></span><?php } ?><?php if ($row !== null) { ?><span class="muted"><?php echo h(system_status_run_reporter_note($row)); ?></span><?php } ?></div>
                <?php if ($isRunning) { ?><p class="muted"><?php echo h(__t('system_status.run_completion_hint')); ?></p><?php } ?>
                <?php if ($isAutoimporter) { ?><h4><?php echo h(__t('system_status.run_result_heading')); ?></h4><p><?php echo system_status_autoimporter_result($hasCompletedResult ? (string) ($row['last_status'] ?? '') : null); ?></p><p class="muted"><?php echo h(__t('system_status.run_result_scope')); ?></p><?php } elseif ($isRunning && !empty($row['last_result_at'])) { ?><p><strong><?php echo h(__t('system_status.run_last_completed_heading')); ?></strong></p><?php } ?>
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
                    ['label' => __t('system_status.th_last_result'), 'html' => system_status_fact_time((!$isAutoimporter || $hasCompletedResult) ? ($row['last_result_at'] ?? null) : null)],
                    $isLegacy
                        ? ['label' => __t('system_status.th_last_seen'), 'html' => system_status_fact_time($row['last_seen_at'] ?? null)]
                        : ['label' => __t('system_status.th_last_success'), 'html' => system_status_fact_time($row['last_success_at'] ?? null)],
                    ['label' => __t('system_status.th_last_failure'), 'html' => system_status_fact_time($row['last_failure_at'] ?? null)],
                    ['label' => __t('system_status.th_interval'), 'html' => $row !== null ? h(portal_format_duration((int) $row['interval_seconds'])) : '&mdash;'],
                    ['label' => __t('system_status.th_duration'), 'html' => ($row !== null && (!$isAutoimporter || $hasCompletedResult) && $row['last_duration_ms'] !== null && $row['last_duration_ms'] !== '') ? h(portal_format_duration_ms((int) $row['last_duration_ms'])) : '&mdash;'],
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
                    // Yellow on time is the run's own outcome, not a stuck task.
                    // An entry without the flag (no snapshot) keeps the old hint.
                    $openPoints = $state === 'warning' && !($entry['report_overdue'] ?? true);
                    $actionHint = integration_action_hint($entry['source'], $openPoints);
                    if ($actionHint !== '') { ?><p class="status-action"><?php echo h($actionHint); ?></p><?php }
                    if ($openPoints && $entry['source'] === VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC) {
                        ?><p><a href="<?php echo h(help_url('system-status', 'help-status-device-causes')); ?>"><?php echo h(__t('system_status.device_sync_causes_help')); ?></a></p><?php
                    } elseif ($openPoints && (string) ($row['last_error_category'] ?? '') === VIRTUSPHERE_RUN_ERROR_CATALOG_CONFLICT && $user !== null && can('system.config', $user)) {
                        ?><p><a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_CATALOG)); ?>"><?php echo h(__t('settings.retire_threshold_title')); ?></a></p><?php
                    }
                } ?>
                <?php if ($errorLabel !== '') { ?><p class="alert-inline"><?php echo h($errorLabel); ?></p><?php } ?>
                <?php if ($isAutoimporter && !$suppressHints) {
                    foreach (system_status_autoimporter_findings($completionDetail, false) as $finding) { ?><p class="status-action"><?php echo h($finding); ?></p><?php }
                } ?>
                <?php if ($isAutoimporter) { ?>
                    <h4><?php echo h(__t('system_status.run_distribution_heading')); ?></h4>
                    <p class="muted"><?php echo h(__t('system_status.run_distribution_scope', ['minutes' => (string) intdiv(VIRTUSPHERE_AUTOIMPORTER_HINT_REFRESH_SECONDS, 60)])); ?></p>
                    <?php echo system_status_fact_list([
                        ['label' => __t('system_status.th_last_result'), 'html' => system_status_fact_time($hasCompletedResult ? ($row['last_result_at'] ?? null) : null)],
                    ]);
                    $distributionFindings = system_status_autoimporter_findings($completionDetail, true);
                    if ($distributionFindings === []) { ?><p><?php echo h(__t('system_status.run_distribution_unknown')); ?></p><?php }
                    foreach ($distributionFindings as $finding) { ?><p class="status-action"><?php echo h($finding); ?></p><?php }
                    if (!$suppressHints) {
                    ?><p><a href="<?php echo h(help_url('system-status', 'help-status-mecm')); ?>"><?php echo h(__t('system_status.autoimporter_help')); ?></a></p><?php
                    }
                } ?>
                <?php if ($completionDetail !== '') { ?><details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($completionDetail); ?></pre></details><?php } ?>
            </article>
        <?php } ?>
    </div>
    <?php
}
