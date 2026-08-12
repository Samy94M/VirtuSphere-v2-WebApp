<?php

declare(strict_types=1);

// The heartbeat half of the System status page: overview, MECM (result reports
// and site health), Ansible and internal services, plus the shared Ampel legend.
// The ESXi inventory cards and the deviation scan live in
// lib/system_status_esxi_panels.php (ADR-0006).
require_once __DIR__ . '/credentials_status.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/system_status_ansible_activity.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_shared_panels.php';

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

/** @param array<string,mixed> $snapshot */
function system_status_render_overview(array $snapshot): void
{
    $cards = [
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM, __t('system_status.overview_mecm'), $snapshot['mecm']['state'], 'heartbeat'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE, __t('system_status.overview_ansible'), $snapshot['ansible']['state'] ?? 'unknown', 'ansible'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI, __t('system_status.overview_esxi'), $snapshot['esxi']['state'] ?? 'unknown', 'esxi'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL, __t('system_status.overview_internal'), $snapshot['internal']['state'], 'heartbeat'],
    ];
    ?>
    <nav class="status-overview" aria-label="<?php echo h(__t('system_status.overview_heading')); ?>">
        <?php foreach ($cards as [$anchor, $label, $state, $kind]) { ?>
            <a class="status-overview-card" href="#<?php echo h((string) $anchor); ?>">
                <span><?php echo h((string) $label); ?></span>
                <?php
                echo match ($kind) {
                    'esxi' => esxi_state_badge((string) $state),
                    'ansible' => ansible_state_badge((string) $state),
                    default => heartbeat_badge((string) $state),
                };
                ?>
            </a>
        <?php } ?>
    </nav>
    <?php
}

/** @param list<array{source:string,row:array|null,state:string}> $rows */
function system_status_render_source_rows(array $rows, bool $suppressHints = false): void
{
    ?>
    <div class="status-list">
        <?php foreach ($rows as $entry) {
            $row = $entry['row'];
            $lastSeen = $row !== null && !empty($row['last_seen_at'])
                ? portal_format_timestamp($row['last_seen_at'])
                : __t('system_status.never_seen');
            $lastChecked = $row !== null && !empty($row['last_checked_at'])
                ? portal_format_timestamp($row['last_checked_at'])
                : __t('system_status.never_seen');
            $detail = trim((string) ($row['last_detail'] ?? ''));
            ?>
            <article class="status-row">
                <div class="status-row-head"><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo heartbeat_badge($entry['state']); ?></div>
                <?php
                // Fixed fields, including the check that equals the report: a
                // column that appears only when the two timestamps differ moved
                // every following column one place to the left, so the same
                // label sat under a different one in the row above.
                echo system_status_fact_list([
                    ['label' => __t('system_status.th_last_seen'), 'html' => h($lastSeen)],
                    ['label' => __t('system_status.th_last_checked'), 'html' => h($lastChecked)],
                    ['label' => __t('system_status.th_interval'), 'html' => $row !== null ? h(portal_format_duration((int) $row['interval_seconds'])) : '&mdash;'],
                ]);
                ?>
                <?php
                // The hint is a repair instruction, not a description, so it is
                // only true while the source is not OK. Printed unconditionally
                // it told the operator "the maintenance service is not running"
                // directly under a green OK badge, which is the opposite of what
                // the row means and what help promises ("a problematic state
                // carries an action hint").
                //
                // $suppressHints is the caller's "this cannot be repaired yet"
                // verdict: nothing was ever set up, so "restart the task" names a
                // task that does not exist. The caller says so once for the whole
                // group instead of five rows repeating a premature instruction.
                $actionHint = !$suppressHints && $entry['state'] !== 'ok' ? integration_action_hint($entry['source']) : '';
                if ($actionHint !== '') { ?><p class="status-action"><?php echo h($actionHint); ?></p><?php } ?>
                <?php if ($detail !== '') { ?>
                    <details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details>
                <?php } ?>
            </article>
        <?php } ?>
    </div>
    <?php
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
    $setupPending = $syncState === 'unknown' && $siteState === 'unknown';
    // "Nothing has ever reported" has three causes that used to render as one grey
    // row: never installed, installed but the first run is still pending, and
    // installed but REFUSED at the IP gate. The third is the commonest setup
    // mistake in the product, and a refusal is the one piece of positive evidence
    // that tells it apart: somebody IS knocking. Naming the IP turns the row into
    // the fix, because that IP is exactly what has to go on the allowlist.
    $denials = (array) ($snapshot['machine_api_denials'] ?? []);
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
                <?php echo h(__t('system_status.machine_api_denied', [
                    'ips' => implode(', ', array_map(static fn (array $row): string => (string) $row['ip'], $denials)),
                    'when' => portal_format_timestamp((string) $denials[0]['last_at']),
                ])); ?>
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
            $detail = trim((string) ($row['last_detail'] ?? ''));
            $errorLabel = $row !== null ? system_status_run_error_label(isset($row['last_error_category']) ? (string) $row['last_error_category'] : null) : '';
            $summary = ($row !== null && !empty($row['last_summary'])) ? json_decode((string) $row['last_summary'], true) : null;
            ?>
            <article class="status-row">
                <div class="status-row-head"><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo heartbeat_badge($state); ?><?php if ($isRunning && !empty($row['last_attempt_at'])) { ?><span class="muted"><?php echo h(__t('system_status.run_running_since', ['time' => portal_format_timestamp($row['last_attempt_at'])])); ?></span><?php } ?><?php if ($row !== null) { ?><span class="muted"><?php echo h(system_status_run_reporter_note($row)); ?></span><?php } ?></div>
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

/** @param array<string,mixed> $snapshot */
function system_status_render_ansible(array $snapshot, array $user): void
{
    $canOpenJobLog = can('deploy.run', $user);
    $canManageCredentials = can('credentials.manage', $user);
    $canViewCredentialAudit = can('users.manage', $user);
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE); ?>">
        <?php // The same heading shape as the MECM, ESXi and internal panels: the
              // link to the credentials page is this section's action and belongs
              // beside the title, not as a loose paragraph under the last row. ?>
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.ansible_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.ansible_hint')); ?></p></div>
            <?php // Not in the empty case: the empty-state below already carries this
                  // link as its call to action, and two identical buttons above each
                  // other read as two different destinations. ?>
            <?php if ($snapshot['ansible']['rows'] !== [] && can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a><?php } ?>
        </div>
        <?php if ($snapshot['ansible']['rows'] === []) { ?>
            <div class="empty-state"><p><?php echo h(__t('system_status.ansible_empty')); ?></p><?php if (can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a><?php } ?></div>
        <?php } else { ?>
            <div class="status-list">
            <?php foreach ($snapshot['ansible']['rows'] as $entry) {
                $credential = $entry['credential'];
                // $stateRow is the stored result, $entry['state'] the derived
                // Ampel. They were both called "state" here while only one of
                // them may colour a badge, which is how the row came to derive
                // its own instead of taking the snapshot's.
                $stateRow = $entry['state_row'];
                $lastMissionJob = is_array($entry['last_mission_job'] ?? null) ? $entry['last_mission_job'] : null;
                $component = trim((string) ($stateRow['last_component'] ?? ''));
                ?>
                <article class="status-row" id="credential-<?php echo h((string) $credential['id']); ?>">
                    <?php // The snapshot's state, not a fresh derivation from the row: re-deriving
                          // here would put a second clock on a page whose whole point is that every
                          // age is measured against one, and the row could then disagree with the
                          // overview card above it across a threshold. ?>
                    <div class="status-row-head"><strong><?php echo h((string) $credential['name']); ?></strong><?php echo ansible_state_badge((string) $entry['state']); ?></div>
                    <?php echo system_status_fact_list([
                        ['label' => __t('system_status.ansible_th_host'), 'html' => '<code>' . h((string) $credential['host']) . '</code>'],
                        ['label' => __t('system_status.ansible_th_last_full_test'), 'html' => ($stateRow !== null && !empty($stateRow['last_checked_at']))
                            ? h(portal_format_timestamp($stateRow['last_checked_at']))
                            : h(__t('system_status.ansible_never_tested'))],
                        ['label' => __t('system_status.ansible_th_last_mission_job'), 'html' => system_status_ansible_job_fact($lastMissionJob, $canOpenJobLog)],
                    ]); ?>
                    <?php if ((string) $entry['state'] === 'stale') { ?>
                        <p class="status-action"><?php echo h(__t('system_status.ansible_stale_detail', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS])); ?></p>
                    <?php } ?>
                    <?php // A badge over a timestamp reads as "last poll" everywhere else in
                          // the portal, and the preflight is the one status here that nothing
                          // refreshes. The line says so, from the same helper the Credentials
                          // page uses, so the two pages cannot describe the same row
                          // differently. ?>
                    <small class="status-cadence"><?php echo h(credential_cadence_ansible()); ?></small>
                    <?php if ($canManageCredentials || $canViewCredentialAudit) { ?>
                        <div class="actions">
                            <?php if ($canManageCredentials) { ?>
                                <form class="inline-form" method="post" action="credentials.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="test">
                                    <input type="hidden" name="credential_id" value="<?php echo h((string) $credential['id']); ?>">
                                    <input type="hidden" name="return_to" value="ansible_status">
                                    <button class="button button-secondary" type="submit" data-busy-label="<?php echo h(__t('system_status.ansible_testing')); ?>"><?php echo h(__t('system_status.ansible_test_now')); ?></button>
                                </form>
                            <?php } ?>
                            <?php if ($canViewCredentialAudit) { ?>
                                <a class="button button-ghost" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS)); ?>"><?php echo h(__t('system_status.ansible_test_logs')); ?></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php
                    // A preflight warning stores the check that raised it in the
                    // same column a failure stores its broken component in, so
                    // "failed at: allowlist" would read as a failure of a test
                    // that in fact passed. The warning gets its own sentence.
                    // Read the stored result here, not the age-derived Ampel:
                    // an old allowlist warning becomes stale, but it remains
                    // a successful restricted test and must never be described
                    // as a failed component merely because its evidence aged.
                    // Its last sentence names another page, so the box carries the way
                    // there, gated like that page and under the label the MECM empty
                    // state already uses: one destination, one name.
                    if (($stateRow['last_status'] ?? '') === 'warning') { ?><div class="alert alert-warning"><?php echo h(__t('system_status.ansible_allowlist_detail')); ?><?php if (can('system.config', $user)) { ?> <a href="<?php echo h(settings_url(VIRTUSPHERE_SETTINGS_TAB_MACHINE_API)); ?>"><?php echo h(__t('system_status.mecm_configure_allowlist')); ?></a><?php } ?></div><?php
                    } elseif ($component !== '') { ?><p class="muted"><?php echo h(__t('system_status.ansible_failed_component', ['component' => $component])); ?></p><?php } ?>
                </article>
            <?php } ?>
            </div>
        <?php } ?>
    </section>
    <?php
}

/** @param array<string,mixed> $snapshot */
function system_status_render_internal(array $snapshot, array $user): void
{
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL); ?>"><div class="section-heading-actions"><div><h2><?php echo h(__t('system_status.internal_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.internal_hint')); ?></p></div><?php if (can('users.manage', $user)) { ?><a class="button button-secondary" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_SYSTEM)); ?>"><?php echo h(__t('system_status.internal_logs')); ?></a><?php } ?></div><?php system_status_render_source_rows($snapshot['internal']['rows']); ?></section>
    <?php
    // The page renders three Ampeln with three vocabularies (heartbeat sources,
    // ESXi credentials, Ansible credentials); the legend explains all three, or
    // an operator has to guess which "warning" they are looking at.
    ?>
    <details class="panel status-legend"><summary><?php echo h(__t('system_status.legend_heading')); ?></summary>
        <p><?php echo h(__t('system_status.legend_intro')); ?></p>
        <h3><?php echo h(__t('system_status.legend_group_heartbeat')); ?></h3>
        <?php system_status_legend_items('heartbeat'); ?>
        <h3><?php echo h(__t('system_status.esxi_legend_heading')); ?></h3>
        <?php system_status_legend_items('esxi'); ?>
        <h3><?php echo h(__t('system_status.ansible_legend_heading')); ?></h3>
        <?php system_status_legend_items('ansible'); ?>
    </details>
    <?php
}
