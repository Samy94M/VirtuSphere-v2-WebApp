<?php

declare(strict_types=1);

// Integration status page (ADR-0018): read-only traffic light for the MECM
// sync heartbeats and internal services. Deliberately visible to every
// signed-in user so support questions can be answered without admin rights.

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/heartbeats.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/esxi_capabilities.php';
require_once __DIR__ . '/../lib/integrations_page.php';

$user = portal_require_user($connection);

// Manual inventory refresh (Gate deploy.run) and mass VLAN reassignment
// (missions.write + vms.write) both live in integrations_handle_post(); each
// keeps its own permission gate so button visibility and handler agree.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    integrations_handle_post($connection, $user);
}

$statusRows = repo_integration_status_rows($connection);
$canSeeLogs = can('users.manage', $user);
$canRefreshInventory = can('deploy.run', $user);
$esxiOverview = esxi_inventory_overview($connection);
$inventoryIntervalHours = esxi_inventory_interval_hours($connection);
// Templates included and flagged: a stale VLAN living only in a template must
// surface here (it propagates into every mission created from it). Mission
// list badge and deploy hint keep excluding templates.
$deviations = esxi_inventory_mission_deviations($connection, true);
$canReassign = can('missions.write', $user) && can('vms.write', $user);
// Prefill for the reassign form (C2): the deviation rows link here with the
// stale name, which avoids the typo risk of the free-text field.
$reassignFrom = mb_substr(request_trimmed($_GET, 'reassign_from'), 0, 255);
$activeVlanNames = array_map(static fn (array $v): string => (string) $v['vlan_name'], repo_active_vlans($connection));

// The mass reassignment is destructive and rewrites every stored assignment of a
// VLAN name. Only offer it when there is actually a stale VLAN to repair; a
// datastore or datacenter deviation is not a reason to show a VLAN form.
$hasVlanDeviation = false;
foreach ($deviations as $deviation) {
    foreach ($deviation['issues'] as $issue) {
        if ($issue['field'] === 'vlan') {
            $hasVlanDeviation = true;
            break 2;
        }
    }
}
$showReassign = $canReassign && $hasVlanDeviation && $activeVlanNames !== [];

// Overview roll-ups: the worst state per system plus a count of sources that
// need a look. Uses the same helpers the dashboard tiles read, so the summary
// here and the dashboard tile cannot disagree.
$mecmWorst = repo_integration_worst_state($statusRows);
$mecmAttention = 0;
foreach ($statusRows as $entry) {
    if (in_array($entry['state'], ['danger', 'missing', 'warning'], true)) {
        $mecmAttention++;
    }
}
$esxiWorst = null;
$esxiAttention = 0;
foreach ($esxiOverview as $ov) {
    $credState = esxi_credential_state($ov['state'], $inventoryIntervalHours);
    if (in_array($credState, ['danger', 'warning'], true)) {
        $esxiAttention++;
    }
    if ($esxiWorst === null || esxi_state_rank($credState) > esxi_state_rank($esxiWorst)) {
        $esxiWorst = $credState;
    }
}
// A tile's one-line subtext: the count when something needs a look, "all clear"
// when the worst is green, nothing when it is merely unknown (badge says so).
$tileSubtext = static function (int $attention, ?string $worst): string {
    if ($attention > 0) {
        return __t('integrations.overview_attention', ['count' => $attention]);
    }

    return $worst === 'ok' ? __t('integrations.overview_all_ok') : '';
};

layout_header(__t('integrations.title'), $user, 'integrations', 'integrations');
?>
<div class="stack">
    <section class="panel">
        <h2><?php echo h(__t('integrations.overview_heading')); ?></h2>
        <div class="overview-grid">
            <?php $mecmSub = $tileSubtext($mecmAttention, $mecmWorst); ?>
            <div class="overview-tile">
                <span class="overview-tile-label"><?php echo h(__t('integrations.overview_mecm')); ?></span>
                <?php echo heartbeat_badge($mecmWorst); ?>
                <?php if ($mecmSub !== '') { ?><span class="muted"><?php echo h($mecmSub); ?></span><?php } ?>
            </div>
            <?php if ($esxiOverview !== []) {
                $esxiSub = $tileSubtext($esxiAttention, $esxiWorst); ?>
                <div class="overview-tile">
                    <span class="overview-tile-label"><?php echo h(__t('integrations.overview_esxi')); ?></span>
                    <?php echo esxi_state_badge($esxiWorst ?? 'unknown'); ?>
                    <?php if ($esxiSub !== '') { ?><span class="muted"><?php echo h($esxiSub); ?></span><?php } ?>
                </div>
            <?php } ?>
        </div>
    </section>

    <section class="panel">
        <h2><?php echo h(__t('integrations.heading')); ?></h2>
        <p class="muted"><?php echo h(__t('integrations.hint')); ?></p>
        <div class="table-wrap" tabindex="0">
            <table>
                <thead>
                <tr>
                    <th><?php echo h(__t('integrations.th_source')); ?></th>
                    <th><?php echo h(__t('integrations.th_status')); ?></th>
                    <th><?php echo h(__t('integrations.th_last_seen')); ?></th>
                    <th><?php echo h(__t('integrations.th_last_checked')); ?></th>
                    <th><?php echo h(__t('integrations.th_interval')); ?></th>
                    <th><?php echo h(__t('integrations.th_detail')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($statusRows as $entry) {
                    $source = (string) $entry['source'];
                    $row = $entry['row'];
                    $state = (string) $entry['state'];
                    $needsAction = in_array($state, ['danger', 'missing'], true);
                    ?>
                    <tr>
                        <td><?php echo h(integration_source_label($source)); ?></td>
                        <td><?php echo heartbeat_badge($state); ?></td>
                        <td><?php echo $row !== null && !empty($row['last_seen_at']) ? h(portal_format_timestamp((string) $row['last_seen_at'])) : h(__t('integrations.never_seen')); ?></td>
                        <td><?php echo $row !== null && !empty($row['last_checked_at']) ? h(portal_format_timestamp((string) $row['last_checked_at'])) : '&mdash;'; ?></td>
                        <td><?php echo $row !== null ? h((string) ((int) $row['interval_seconds']) . ' s') : '&mdash;'; ?></td>
                        <td><?php echo $row !== null && !empty($row['last_detail']) ? h((string) $row['last_detail']) : '&mdash;'; ?></td>
                    </tr>
                    <?php if ($needsAction) { ?>
                    <tr class="status-action-row">
                        <td colspan="6">
                            <div class="alert alert-error status-action">
                                <?php echo h(integration_action_hint($source)); ?>
                                <?php if ($canSeeLogs) { ?>
                                    <a href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_MECM)); ?>"><?php echo h(__t('integrations.open_logs')); ?></a>
                                <?php } else { ?>
                                    <span class="muted"><?php echo h(__t('integrations.logs_admin_only')); ?></span>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="actions">
            <h2><?php echo h(__t('integrations.inv_heading')); ?></h2>
            <?php if ($canRefreshInventory && $esxiOverview !== []) { ?>
                <form class="inline-form" method="post" action="integrations.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="refresh_inventory">
                    <input type="hidden" name="credential_id" value="0">
                    <button class="button button-secondary" type="submit"><?php echo h(__t('integrations.inv_refresh_all')); ?></button>
                </form>
            <?php } ?>
        </div>
        <p class="muted"><?php echo h(__t('integrations.inv_hint')); ?></p>
        <?php if ($esxiOverview === []) { ?>
            <p class="muted"><?php echo h(__t('integrations.inv_empty')); ?></p>
        <?php } ?>
        <div class="grid inv-cards">
        <?php foreach ($esxiOverview as $ov) {
            $cred = $ov['credential'];
            $inv = $ov['inventory'];
            $state = $ov['state'];
            $datacenters = $inv['datacenter'] ?? [];
            $datastores = $inv['datastore'] ?? [];
            $networks = $inv['network'] ?? [];
            $hosts = $inv['host'] ?? [];
            // "As of" comes from the fetch-state row (SSoT), not from whatever
            // inventory row happens to exist; kinds can carry different
            // fetched_at values after a kept-empty fetch.
            $fetchedAt = $state['last_success_at'] ?? null;
            ?>
            <div class="inv-card">
                <div class="inv-card-head">
                    <?php // The badge is the credential's fetch health only (esxi_credential_state):
                          // a free-licence or HA host that pulls fine stays green here, its limitation
                          // shown by the capability badges below, not by this colour. ?>
                    <h3><?php echo h((string) ($cred['name'] ?? '')); ?> <?php echo esxi_state_badge(esxi_credential_state($state, $inventoryIntervalHours)); ?></h3>
                    <?php if ($canRefreshInventory) { ?>
                        <form class="inline-form" method="post" action="integrations.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="refresh_inventory">
                            <input type="hidden" name="credential_id" value="<?php echo h((string) $cred['id']); ?>">
                            <button class="button button-ghost" type="submit"><?php echo h(__t('common.refresh')); ?></button>
                        </form>
                    <?php } ?>
                </div>
                <p class="muted">
                    <?php echo h(__t('integrations.inv_stand')); ?>:
                    <?php echo $fetchedAt !== null ? h(portal_format_timestamp((string) $fetchedAt)) : h(__t('integrations.inv_never')); ?>
                    <?php if ($state !== null && ($state['last_status'] ?? '') === 'failed') { ?>
                        &middot; <?php echo h(__t('integrations.inv_last_error')); ?>: <?php echo h(connection_error_message((string) ($state['last_error_category'] ?? ''), ['host' => (string) ($cred['host'] ?? '')])); ?>
                        <?php if ((int) ($state['paused_until_credential_change'] ?? 0) === 1) { ?>(<?php echo h(__t('integrations.inv_paused')); ?>)<?php } ?>
                    <?php } ?>
                </p>

                <?php
                // Capability facts of the last successful pull. A null fact prints
                // nothing at all: "not known" must never look like a clean bill of
                // health, and it must never look like a problem either.
                $capabilities = esxi_capabilities($state);
                $capabilityParts = [];
                if ($capabilities['product_version'] !== null) { $capabilityParts[] = $capabilities['product_version']; }
                if ($capabilities['license_product'] !== null) { $capabilityParts[] = $capabilities['license_product']; }
                if ($capabilities['api_type'] !== null) { $capabilityParts[] = __t('integrations.cap_api_' . (strtolower($capabilities['api_type']) === 'virtualcenter' ? 'vcenter' : 'host')); }
                ?>
                <?php if ($capabilityParts !== []) { ?>
                    <p class="muted"><?php echo h(__t('integrations.cap_heading')); ?>: <?php echo h(implode(', ', $capabilityParts)); ?></p>
                <?php } ?>
                <?php $capabilityWarnings = esxi_capability_warnings($state); ?>
                <?php if ($capabilityWarnings !== []) { ?>
                    <p class="inv-badges">
                        <?php foreach ($capabilityWarnings as $capabilityWarning) { ?>
                            <?php echo portal_badge($capabilityWarning['level'] === 'info' ? 'info' : 'warning', __t('integrations.cap_' . $capabilityWarning['key'])); ?>
                        <?php } ?>
                    </p>
                <?php } ?>

                <?php if ($hosts !== []) { ?>
                    <p class="muted"><?php echo h(__t('integrations.inv_th_hosts')); ?>:
                    <?php $hostParts = [];
                    foreach ($hosts as $host) {
                        $meta = json_decode((string) ($host['meta_json'] ?? '{}'), true) ?: [];
                        $bits = [(string) $host['name']];
                        if (!empty($meta['ram_mb'])) { $bits[] = backup_status_human_bytes((int) $meta['ram_mb'] * 1048576) . ' RAM'; }
                        if (!empty($meta['cpu_cores'])) { $bits[] = (int) $meta['cpu_cores'] . ' ' . __t('integrations.inv_cores'); }
                        if (!empty($meta['cpu_model'])) { $bits[] = (string) $meta['cpu_model']; }
                        if (isset($meta['clock_skew_seconds']) && abs((int) $meta['clock_skew_seconds']) > VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS) {
                            $bits[] = __t('integrations.inv_clock_skew', ['minutes' => (int) round(abs((int) $meta['clock_skew_seconds']) / 60)]);
                        }
                        $hostParts[] = implode(', ', $bits);
                    }
                    echo h(implode(' | ', $hostParts)); ?></p>
                <?php } ?>

                <div class="inv-grid">
                    <div>
                        <h4><?php echo h(__t('integrations.inv_th_datacenters')); ?></h4>
                        <?php echo $datacenters === [] ? '<p class="muted">&mdash;</p>' : '<ul>' . implode('', array_map(static fn (array $r): string => '<li>' . h((string) $r['name']) . '</li>', $datacenters)) . '</ul>'; ?>
                    </div>
                    <div>
                        <h4><?php echo h(__t('integrations.inv_th_networks')); ?> (<?php echo count($networks); ?>)</h4>
                        <?php echo $networks === [] ? '<p class="muted">&mdash;</p>' : '<ul>' . implode('', array_map(static fn (array $r): string => '<li>' . h((string) $r['name']) . '</li>', $networks)) . '</ul>'; ?>
                    </div>
                </div>

                <h4><?php echo h(__t('integrations.inv_th_datastores')); ?></h4>
                <?php if ($datastores === []) { ?>
                    <p class="muted">&mdash;</p>
                <?php } else { ?>
                    <div class="table-wrap" tabindex="0"><table>
                        <tbody>
                        <?php foreach ($datastores as $ds) {
                            $capacity = $ds['capacity_bytes'] !== null ? (int) $ds['capacity_bytes'] : 0;
                            $free = $ds['free_bytes'] !== null ? (int) $ds['free_bytes'] : 0;
                            $usedPct = $capacity > 0 ? (int) round(($capacity - $free) / $capacity * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo h((string) $ds['name']); ?></td>
                                <td class="inv-bar-cell">
                                    <?php if ($capacity > 0) { ?>
                                        <span class="capacity-bar"><span class="capacity-fill" data-capacity-pct="<?php echo h((string) $usedPct); ?>"></span></span>
                                    <?php } ?>
                                </td>
                                <td class="nowrap"><?php echo $capacity > 0 ? h(__t('integrations.inv_free_of', ['free' => backup_status_human_bytes($free), 'total' => backup_status_human_bytes($capacity)])) : '&mdash;'; ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table></div>
                <?php } ?>
            </div>
        <?php } ?>
        </div>
    </section>

    <?php if ($esxiOverview !== []) { ?>
        <section class="panel">
            <h2><?php echo h(__t('integrations.dev_heading')); ?></h2>
            <p class="muted"><?php echo h(__t('integrations.dev_hint')); ?></p>
            <?php if ($deviations === []) { ?>
                <p class="muted"><?php echo h(__t('integrations.dev_none')); ?></p>
            <?php } else { ?>
                <div class="table-wrap" tabindex="0"><table>
                    <thead><tr><th><?php echo h(__t('common.mission')); ?></th><th><?php echo h(__t('integrations.dev_th_vm')); ?></th><th><?php echo h(__t('common.type')); ?></th><th><?php echo h(__t('common.name')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($deviations as $dev) {
                        foreach ($dev['issues'] as $issue) { ?>
                            <tr>
                                <td>
                                    <a href="mission_details.php?id=<?php echo h((string) $dev['mission_id']); ?>"><?php echo h($dev['mission_name']); ?></a>
                                    <?php if (!empty($dev['is_template'])) { echo portal_badge('neutral', __t('integrations.dev_template_badge')); } ?>
                                </td>
                                <td>
                                    <?php if (isset($dev['vm_id'])) { ?>
                                        <a href="vm_edit.php?mission_id=<?php echo h((string) $dev['mission_id']); ?>&amp;vm_id=<?php echo h((string) $dev['vm_id']); ?>"><?php echo h((string) $dev['vm_name']); ?></a>
                                    <?php } else { ?>
                                        &mdash;
                                    <?php } ?>
                                </td>
                                <td><?php echo h(__t('integrations.dev_field_' . $issue['field'])); ?></td>
                                <td>
                                    <?php if ($issue['field'] === 'vlan' && $canReassign) { ?>
                                        <a href="integrations.php?reassign_from=<?php echo h(urlencode($issue['value'])); ?>#reassign" title="<?php echo h(__t('integrations.dev_reassign_link_title')); ?>"><?php echo h($issue['value']); ?></a>
                                    <?php } else { ?>
                                        <?php echo h($issue['value']); ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php }
                    } ?>
                    </tbody>
                </table></div>
            <?php } ?>

            <?php if ($showReassign) { ?>
                <h3 id="reassign"><?php echo h(__t('integrations.reassign_heading')); ?></h3>
                <p class="muted"><?php echo h(__t('integrations.reassign_hint')); ?></p>
                <form class="form-grid" method="post" action="integrations.php" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reassign_vlan">
                    <label><?php echo h(__t('integrations.reassign_from')); ?><input name="vlan_from" value="<?php echo h($reassignFrom); ?>" required></label>
                    <label><?php echo h(__t('integrations.reassign_to')); ?>
                        <select name="vlan_to" required>
                            <?php foreach ($activeVlanNames as $vlanName) { ?><option value="<?php echo h($vlanName); ?>"><?php echo h($vlanName); ?></option><?php } ?>
                        </select>
                    </label>
                    <div class="actions"><button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('integrations.reassign_confirm')); ?>"><?php echo h(__t('integrations.reassign_btn')); ?></button></div>
                </form>
            <?php } ?>
        </section>
    <?php } ?>

    <section class="panel">
        <h2><?php echo h(__t('integrations.legend_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('integrations.legend_intro')); ?></p>
        <?php // One colour key for both traffic lights: same colours, same meaning,
              // only the per-table wording differs. The badges are live so a colour
              // change in the palette shows here too. ?>
        <div class="legend-key">
            <span class="lk-head"><?php echo h(__t('integrations.legend_col_meaning')); ?></span>
            <span class="lk-head"><?php echo h(__t('integrations.legend_col_status')); ?></span>
            <span class="lk-head"><?php echo h(__t('integrations.legend_col_esxi')); ?></span>

            <span><?php echo h(__t('integrations.sev_ok')); ?></span>
            <span class="lk-badges"><?php echo heartbeat_badge('ok'); ?></span>
            <span class="lk-badges"><?php echo esxi_state_badge('ok'); ?></span>

            <span><?php echo h(__t('integrations.sev_warning')); ?></span>
            <span class="lk-badges"><?php echo heartbeat_badge('warning'); ?><?php echo heartbeat_badge('missing'); ?></span>
            <span class="lk-badges"><?php echo esxi_state_badge('warning'); ?></span>

            <span><?php echo h(__t('integrations.sev_danger')); ?></span>
            <span class="lk-badges"><?php echo heartbeat_badge('danger'); ?></span>
            <span class="lk-badges"><?php echo esxi_state_badge('danger'); ?></span>

            <span><?php echo h(__t('integrations.sev_unknown')); ?></span>
            <span class="lk-badges"><?php echo heartbeat_badge('unknown'); ?></span>
            <span class="lk-badges"><?php echo esxi_state_badge('unknown'); ?></span>
        </div>

        <h3><?php echo h(__t('integrations.cap_legend_heading')); ?></h3>
        <p class="muted"><?php echo h(__t('integrations.cap_legend_hint')); ?></p>
        <ul class="legend-caps">
            <li><?php echo portal_badge('warning', __t('integrations.cap_license_free')); ?> <?php echo h(__t('integrations.cap_legend_license_free')); ?></li>
            <li><?php echo portal_badge('warning', __t('integrations.cap_in_ha_cluster')); ?> <?php echo h(__t('integrations.cap_legend_in_ha_cluster')); ?></li>
            <li><?php echo portal_badge('info', __t('integrations.cap_in_maintenance')); ?> <?php echo h(__t('integrations.cap_legend_in_maintenance')); ?></li>
        </ul>
    </section>
</div>
<?php layout_footer(); ?>
