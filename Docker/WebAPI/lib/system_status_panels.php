<?php

declare(strict_types=1);

// The heartbeat half of the System status page: overview, MECM, Ansible and
// internal services, plus the shared Ampel legend. The ESXi inventory cards and
// the deviation scan live in lib/system_status_esxi_panels.php (ADR-0006).
require_once __DIR__ . '/mecm_probe.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/system_status.php';

function system_status_probe_error_label(?string $category): string
{
    return match ($category) {
        VIRTUSPHERE_PROBE_ERROR_DNS => __t('system_status.probe_error_dns'),
        VIRTUSPHERE_PROBE_ERROR_TIMEOUT => __t('system_status.probe_error_timeout'),
        VIRTUSPHERE_PROBE_ERROR_REFUSED => __t('system_status.probe_error_refused'),
        VIRTUSPHERE_PROBE_ERROR_NETWORK => __t('system_status.probe_error_network'),
        VIRTUSPHERE_PROBE_ERROR_UNKNOWN => __t('system_status.probe_error_unknown'),
        default => '',
    };
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
            $errorLabel = '';
            if ($entry['source'] === VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE) {
                $decoded = mecm_probe_decode_detail($detail);
                $detail = $decoded['detail'];
                $errorLabel = system_status_probe_error_label($decoded['error_category']);
            }
            ?>
            <article class="status-row">
                <div class="status-row-main">
                    <div><strong><?php echo h(integration_source_label($entry['source'])); ?></strong><?php echo heartbeat_badge($entry['state']); ?></div>
                    <dl>
                        <div><dt><?php echo h(__t('system_status.th_last_seen')); ?></dt><dd><?php echo h($lastSeen); ?></dd></div>
                        <?php if ($lastChecked !== $lastSeen) { ?><div><dt><?php echo h(__t('system_status.th_last_checked')); ?></dt><dd><?php echo h($lastChecked); ?></dd></div><?php } ?>
                        <div><dt><?php echo h(__t('system_status.th_interval')); ?></dt><dd><?php echo $row !== null ? h(portal_format_duration((int) $row['interval_seconds'])) : '&mdash;'; ?></dd></div>
                    </dl>
                </div>
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
                <?php if ($errorLabel !== '') { ?><p class="alert-inline"><?php echo h($errorLabel); ?></p><?php } ?>
                <?php if ($detail !== '') { ?>
                    <details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h($detail); ?></pre></details>
                <?php } ?>
            </article>
        <?php } ?>
    </div>
    <?php
}

/** @param array<string,mixed> $snapshot @param array<string,mixed> $probe */
function system_status_render_mecm(array $snapshot, array $probe, array $user): void
{
    $probeRow = $snapshot['by_source'][VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE]['row'] ?? null;
    $probeDetail = mecm_probe_decode_detail(isset($probeRow['last_detail']) ? (string) $probeRow['last_detail'] : null);
    // `unknown` for the whole sync group means no wire source has ever reported,
    // which is exactly "MECM is not connected yet" (a source that reported once
    // and went quiet is warning/danger, and its siblings then read `missing`).
    // Nothing there can be restarted, so the rows drop their repair hints and
    // the section says once what actually has to happen. Same idea as the
    // ESXi/Ansible sections, which already open with an empty state instead of
    // per-row instructions.
    $mecmUnconfigured = (string) $snapshot['mecm_sync']['state'] === 'unknown';
    // The probe hint claims the MECM server is unreachable. Without an effective
    // target nothing was contacted, so there is nothing to declare unreachable.
    $probeHasTarget = ($probe['host'] ?? null) !== null;
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM); ?>">
        <div class="section-heading-actions">
            <div><h2><?php echo h(__t('system_status.mecm_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.mecm_hint')); ?></p></div>
            <?php if (can('users.manage', $user)) { ?><a class="button button-secondary" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_MECM)); ?>"><?php echo h(__t('system_status.open_logs')); ?></a><?php } ?>
        </div>
        <div class="signal-matrix-note"><?php echo h(__t('system_status.mecm_signal_explanation', ['port' => (string) $probe['port']])); ?></div>
        <?php if (!empty($snapshot['mecm_ip_mismatch'])) { ?>
            <div class="alert alert-warning"><?php echo h(__t('system_status.mecm_ip_mismatch', ['ips' => implode(', ', array_keys($snapshot['mecm_fresh_ips']))])); ?></div>
        <?php } ?>
        <?php if ($mecmUnconfigured) { ?>
            <div class="empty-state">
                <p><?php echo h(__t('system_status.mecm_not_configured')); ?></p>
                <?php if (can('system.config', $user)) { ?><a class="button button-secondary" href="settings.php#panel-machine-api"><?php echo h(__t('system_status.mecm_configure_allowlist')); ?></a><?php } ?>
            </div>
        <?php } ?>
        <h3><?php echo h(__t('system_status.mecm_sync_heading')); ?> <?php echo heartbeat_badge((string) $snapshot['mecm_sync']['state']); ?></h3>
        <?php system_status_render_source_rows($snapshot['mecm_sync']['rows'], $mecmUnconfigured); ?>

        <div class="probe-status-card">
            <div>
                <h3><?php echo h(__t('system_status.mecm_network_heading')); ?> <?php echo heartbeat_badge((string) $snapshot['mecm_network']['state']); ?></h3>
                <dl class="status-facts">
                    <div><dt><?php echo h(__t('system_status.probe_mode')); ?></dt><dd><?php echo h($probe['mode'] === VIRTUSPHERE_PROBE_MODE_AUTO ? __t('system_status.probe_mode_auto') : __t('system_status.probe_mode_manual')); ?></dd></div>
                    <div><dt><?php echo h(__t('system_status.probe_target')); ?></dt><dd><code><?php echo h($probe['host'] ?? __t('system_status.probe_target_waiting')); ?></code></dd></div>
                    <div><dt><?php echo h(__t('system_status.probe_port')); ?></dt><dd><?php echo h((string) $probe['port']); ?></dd></div>
                    <?php if ($probe['mode'] === VIRTUSPHERE_PROBE_MODE_AUTO) { ?><div><dt><?php echo h(__t('system_status.probe_origin')); ?></dt><dd><?php echo h($probe['source_ip'] ?? __t('system_status.probe_target_waiting')); ?><?php echo $probe['source_seen_at'] !== null ? ' · ' . h(portal_format_timestamp($probe['source_seen_at'])) : ''; ?></dd></div><?php } ?>
                    <?php if ($probeDetail['error_category'] !== null) { ?><div><dt><?php echo h(__t('system_status.probe_result')); ?></dt><dd><?php echo h(system_status_probe_error_label($probeDetail['error_category'])); ?></dd></div><?php } ?>
                </dl>
            </div>
            <?php system_status_render_source_rows($snapshot['mecm_network']['rows'], !$probeHasTarget); ?>
            <?php if (can('system.config', $user)) { ?>
                <div class="actions">
                    <form method="post" action="system_status.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="run_mecm_probe"><button class="button" type="submit" data-busy-label="<?php echo h(__t('system_status.probe_running')); ?>"><?php echo h(__t('system_status.probe_run')); ?></button></form>
                    <a class="button button-secondary" href="settings.php#panel-machine-api"><?php echo h(__t('system_status.probe_configure')); ?></a>
                </div>
            <?php } ?>
        </div>
    </section>
    <?php
}

/** @param array<string,mixed> $snapshot */
function system_status_render_ansible(array $snapshot, array $user): void
{
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE); ?>">
        <h2><?php echo h(__t('system_status.ansible_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('system_status.ansible_hint')); ?></p>
        <?php if ($snapshot['ansible']['rows'] === []) { ?>
            <div class="empty-state"><p><?php echo h(__t('system_status.ansible_empty')); ?></p><?php if (can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a><?php } ?></div>
        <?php } else { ?>
            <div class="status-list">
            <?php foreach ($snapshot['ansible']['rows'] as $entry) {
                $credential = $entry['credential'];
                $state = $entry['state_row'];
                $component = trim((string) ($state['last_component'] ?? ''));
                ?>
                <article class="status-row" id="credential-<?php echo h((string) $credential['id']); ?>">
                    <div class="status-row-main"><div><strong><?php echo h((string) $credential['name']); ?></strong><?php echo ansible_preflight_badge($state); ?></div><code><?php echo h((string) $credential['host']); ?></code></div>
                    <p><?php echo h(__t('system_status.ansible_th_last_test')); ?>: <?php echo $state !== null && !empty($state['last_checked_at']) ? h(portal_format_timestamp($state['last_checked_at'])) : h(__t('system_status.ansible_never_tested')); ?></p>
                    <?php
                    // A preflight warning stores the check that raised it in the
                    // same column a failure stores its broken component in, so
                    // "failed at: allowlist" would read as a failure of a test
                    // that in fact passed. The warning gets its own sentence.
                    if ($entry['state'] === 'warning') { ?><div class="alert alert-warning"><?php echo h(__t('system_status.ansible_allowlist_detail')); ?></div><?php
                    } elseif ($component !== '') { ?><p class="muted"><?php echo h(__t('system_status.ansible_failed_component', ['component' => $component])); ?></p><?php } ?>
                </article>
            <?php } ?>
            </div>
            <?php if (can('credentials.manage', $user)) { ?><p><a href="credentials.php"><?php echo h(__t('system_status.ansible_test_link')); ?></a></p><?php } ?>
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
