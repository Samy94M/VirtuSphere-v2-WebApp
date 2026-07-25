<?php

declare(strict_types=1);

/**
 * The ESXi half of the System status page: inventory cards, host facts and the
 * mission/VM deviation scan with its VLAN repair form.
 *
 * Split out of lib/system_status_panels.php, which crossed the ADR-0006 line
 * budget when the host facts and the deviation branches were added. The seam is
 * the data source, not the file size: everything here reads the ESXi inventory
 * cache, everything left there reads integration heartbeats.
 */

require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/system_status.php';

/**
 * What the last successful pull talked to: "through vCenter, VMware ESXi 8.0.2".
 * Every fact is optional, so the line disappears rather than stating a dash. The
 * endpoint kind matters operationally: an autostart deviation on a vCenter-
 * managed host is expected, on a standalone host it is not.
 *
 * @param array<string, mixed>|null $state
 */
function system_status_capability_facts(?array $state): string
{
    $facts = esxi_capabilities($state);
    $parts = [];
    $apiType = $facts['api_type'] !== null ? mb_strtolower($facts['api_type']) : null;
    if ($apiType === 'virtualcenter') {
        $parts[] = __t('system_status.cap_api_vcenter');
    } elseif ($apiType === 'hostagent') {
        $parts[] = __t('system_status.cap_api_host');
    }
    if ($facts['product_version'] !== null) {
        $parts[] = $facts['product_version'];
    }

    return $parts !== [] ? implode(' · ', $parts) : '';
}

function system_status_capability_badges(?array $state): string
{
    $html = '';
    foreach (esxi_capability_warnings($state) as $warning) {
        $label = match ($warning['key']) {
            VIRTUSPHERE_ESXI_CAPABILITY_LICENSE_FREE => __t('system_status.cap_license_free'),
            VIRTUSPHERE_ESXI_CAPABILITY_HA_CLUSTER => __t('system_status.cap_in_ha_cluster'),
            VIRTUSPHERE_ESXI_CAPABILITY_MAINTENANCE => __t('system_status.cap_in_maintenance'),
            default => (string) $warning['key'],
        };
        $html .= portal_badge((string) $warning['level'], $label);
    }

    return $html;
}

/**
 * Host facts of one inventory row, read from the meta the pull already stores:
 * core count, and a clock-skew warning past
 * VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS. The pull has collected both since
 * ADR-0023 and docs/operations/esxi-inventory.md documented the skew warning,
 * but nothing rendered it, so a skew that breaks the Kerberos domain join of a
 * fresh VM was diagnosable only in the database.
 *
 * @param array<string, mixed> $row
 */
function system_status_host_facts(array $row): string
{
    $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) {
        return '';
    }

    $html = '';
    $cores = (int) ($meta['cpu_cores'] ?? 0);
    if ($cores > 0) {
        $html .= ' <small>' . h($cores . ' ' . __t('system_status.inv_cores')) . '</small>';
    }
    // Only a skew that matters is shown; a few seconds is normal and would turn
    // the warning into noise nobody reads.
    $skew = isset($meta['clock_skew_seconds']) ? (int) $meta['clock_skew_seconds'] : 0;
    if (abs($skew) >= VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS) {
        $html .= ' ' . portal_badge('warning', __t('system_status.inv_clock_skew', [
            'minutes' => (string) (int) round(abs($skew) / 60),
        ]));
    }

    return $html;
}

/**
 * Size line of one cached datastore row. A NULL free value is a hole in the
 * cache (never pulled, a host that refused the property), and `(int) null`
 * rendered it as a confident "0 B free" right next to a full capacity: the one
 * reading an operator must not get from a row that knows nothing.
 *
 * The third caller of esxi_datastore_usable_free_bytes(), so this line, the
 * deploy verdict and the picker label answer "how much space is here" with one
 * rule: a datastore in maintenance has a size but no usable free space either.
 *
 * @param array<string, mixed> $row
 */
function system_status_datastore_size(array $row): string
{
    $capacity = $row['capacity_bytes'] ?? null;
    if ($capacity === null) {
        return '';
    }
    $free = esxi_datastore_usable_free_bytes(
        $row['free_bytes'] !== null ? (int) $row['free_bytes'] : null,
        $row['meta_json'] ?? null
    );
    if ($free === null) {
        return __t('system_status.inv_free_unknown_of', ['total' => backup_status_human_bytes((int) $capacity)]);
    }

    return __t('system_status.inv_free_of', [
        'free' => backup_status_human_bytes($free),
        'total' => backup_status_human_bytes((int) $capacity),
    ]);
}

/**
 * The maintenance badge of one cached datastore row, or ''. Named next to the
 * size line rather than folded into it: "free: unknown" says the number is
 * missing, this says why, and the operator's next step differs (wait for the
 * maintenance to end versus check why the pull reported nothing).
 *
 * @param array<string, mixed> $row
 */
function system_status_datastore_health_badge(array $row): string
{
    $health = esxi_datastore_health($row['meta_json'] ?? null);
    $html = '';
    if ($health['maintenance'] === true) {
        $html .= ' ' . portal_badge('warning', __t('system_status.inv_ds_maintenance'));
    }
    if ($health['accessible'] === false) {
        $html .= ' ' . portal_badge('danger', __t('system_status.inv_ds_inaccessible'));
    }

    return $html;
}

/** @param array<string,array<int,array<string,mixed>>> $detail */
function system_status_render_inventory_detail(array $detail): void
{
    foreach ([
        VIRTUSPHERE_INVENTORY_KIND_HOST => __t('system_status.inv_th_hosts'),
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER => __t('system_status.inv_th_datacenters'),
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE => __t('system_status.inv_th_datastores'),
        VIRTUSPHERE_INVENTORY_KIND_NETWORK => __t('system_status.inv_th_networks'),
    ] as $kind => $label) {
        $rows = $detail[$kind] ?? [];
        ?>
        <div class="inventory-detail-group"><h4><?php echo h($label); ?> (<?php echo h((string) count($rows)); ?>)</h4>
        <?php if ($rows === []) { ?><p class="muted"><?php echo h(__t('system_status.inv_kind_empty')); ?></p><?php } else { ?><ul><?php foreach ($rows as $row) { $isDatastore = $kind === VIRTUSPHERE_INVENTORY_KIND_DATASTORE; $size = $isDatastore ? system_status_datastore_size($row) : ''; ?><li><span class="break-anywhere"><?php echo h((string) $row['name']); ?></span><?php if ($size !== '') { ?> <small><?php echo h($size); ?></small><?php } ?><?php if ($isDatastore) { echo system_status_datastore_health_badge($row); } ?><?php if ($kind === VIRTUSPHERE_INVENTORY_KIND_HOST) { echo system_status_host_facts($row); } ?></li><?php } ?></ul><?php } ?>
        </div>
        <?php
    }
}

/** @param array<string,mixed> $snapshot */
function system_status_render_esxi(array $snapshot, array $user, int $selectedId, ?array $selectedDetail): void
{
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI); ?>">
        <div class="section-heading-actions"><div><h2><?php echo h(__t('system_status.inv_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.inv_hint')); ?></p></div>
        <?php if (can('deploy.run', $user) && $snapshot['esxi']['rows'] !== []) { ?><form method="post" action="system_status.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="refresh_inventory"><button class="button" type="submit" data-busy-label="<?php echo h(__t('system_status.refreshing')); ?>"><?php echo h(__t('system_status.inv_refresh_all')); ?></button></form><?php } ?></div>
        <?php if ($snapshot['esxi']['rows'] === []) { ?>
            <div class="empty-state"><p><?php echo h(__t('system_status.inv_empty')); ?></p><?php if (can('credentials.manage', $user)) { ?><a class="button button-secondary" href="credentials.php"><?php echo h(__t('system_status.inv_configure_credentials')); ?></a><?php } ?></div>
        <?php } else { ?><div class="inventory-card-grid">
        <?php foreach ($snapshot['esxi']['rows'] as $entry) {
            $credential = $entry['credential'];
            $credentialId = (int) $credential['id'];
            $state = $entry['state'];
            $pending = $entry['pending_job'];
            $counts = $entry['counts'];
            $isSelected = $credentialId === $selectedId && $selectedDetail !== null;
            ?>
            <article class="inventory-card<?php echo $isSelected ? ' inventory-card-open' : ''; ?>" id="credential-<?php echo h((string) $credentialId); ?>">
                <header><div><h3><?php echo h((string) $credential['name']); ?></h3><code class="break-anywhere"><?php echo h((string) $credential['host']); ?></code></div><?php echo esxi_state_badge((string) $entry['health']); ?></header>
                <?php $capabilityFacts = system_status_capability_facts($state); ?>
                <?php if ($capabilityFacts !== '') { ?><p class="muted"><?php echo h(__t('system_status.cap_heading')); ?>: <?php echo h($capabilityFacts); ?></p><?php } ?>
                <div class="capability-badges"><?php echo system_status_capability_badges($state); ?></div>
                <dl class="inventory-counts">
                    <div><dt><?php echo h(__t('system_status.inv_th_hosts')); ?></dt><dd><?php echo h((string) ($counts[VIRTUSPHERE_INVENTORY_KIND_HOST] ?? 0)); ?></dd></div>
                    <div><dt><?php echo h(__t('system_status.inv_th_datacenters')); ?></dt><dd><?php echo h((string) ($counts[VIRTUSPHERE_INVENTORY_KIND_DATACENTER] ?? 0)); ?></dd></div>
                    <div><dt><?php echo h(__t('system_status.inv_th_datastores')); ?></dt><dd><?php echo h((string) ($counts[VIRTUSPHERE_INVENTORY_KIND_DATASTORE] ?? 0)); ?></dd></div>
                    <div><dt><?php echo h(__t('system_status.inv_th_networks')); ?></dt><dd><?php echo h((string) ($counts[VIRTUSPHERE_INVENTORY_KIND_NETWORK] ?? 0)); ?></dd></div>
                </dl>
                <p><?php echo h(__t('system_status.inv_last_attempt')); ?>: <?php echo $state !== null && !empty($state['last_attempt_at']) ? h(portal_format_timestamp($state['last_attempt_at'])) : h(__t('system_status.inv_never')); ?><br><?php echo h(__t('system_status.inv_last_success')); ?>: <?php echo $state !== null && !empty($state['last_success_at']) ? h(portal_format_timestamp($state['last_success_at'])) : h(__t('system_status.inv_never')); ?></p>
                <?php if ($state !== null && (string) ($state['last_status'] ?? '') === 'failed' && !empty($state['last_error_category'])) { ?><div class="alert alert-error"><?php echo h(connection_error_message((string) $state['last_error_category'], ['host' => (string) $credential['host']])); ?></div><?php } ?>
                <?php if ($state !== null && (int) ($state['paused_until_credential_change'] ?? 0) === 1) { ?><div class="alert alert-warning"><?php echo h(__t('system_status.inv_paused')); ?></div><?php } ?>
                <?php if ($pending !== null) { ?><p><?php echo portal_badge($pending['status'] === VIRTUSPHERE_DEPLOY_STATUS_RUNNING ? 'info' : 'warning', $pending['status'] === VIRTUSPHERE_DEPLOY_STATUS_RUNNING ? __t('system_status.inv_job_running') : __t('system_status.inv_job_queued')); ?> <?php if (can('deploy.run', $user)) { ?><a href="deploy_log.php?id=<?php echo h((string) $pending['id']); ?>"><?php echo h(__t('system_status.inv_open_job_log')); ?></a><?php } ?></p><?php } ?>
                <div class="actions">
                    <?php if ($isSelected) { ?><a class="button button-secondary" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('system_status.inv_close_details')); ?></a><?php } else { ?><a class="button button-secondary" href="<?php echo h(system_status_url('credential-' . $credentialId, ['inventory' => $credentialId])); ?>"><?php echo h(__t('system_status.inv_open_details')); ?></a><?php } ?>
                    <?php if (can('deploy.run', $user)) { ?><form method="post" action="system_status.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="refresh_inventory"><input type="hidden" name="credential_id" value="<?php echo h((string) $credentialId); ?>"><button class="button" type="submit"<?php echo $pending !== null ? ' disabled' : ''; ?> data-busy-label="<?php echo h(__t('system_status.refreshing')); ?>"><?php echo h(__t('system_status.inv_refresh_one')); ?></button></form><?php } ?>
                </div>
                <?php if ($isSelected) { ?><div class="inventory-details"><?php system_status_render_inventory_detail($selectedDetail); ?></div><?php } ?>
            </article>
        <?php } ?>
        </div><?php } ?>
    </section>
    <?php
}

/** @param array<int,array<string,mixed>> $deviations @param string[] $activeVlanNames */
function system_status_render_deviations(array $deviations, array $activeVlanNames, array $user, string $reassignFrom, bool $hasInventory): void
{
    $issueCount = array_sum(array_map(static fn (array $entry): int => count($entry['issues']), $deviations));
    $hasVlanDeviation = false;
    foreach ($deviations as $entry) {
        foreach ($entry['issues'] as $issue) {
            if (($issue['field'] ?? '') === 'vlan') {
                $hasVlanDeviation = true;
                break 2;
            }
        }
    }
    ?>
    <?php
    // Without an ESXi inventory there is nothing to compare against, so the
    // scan never runs. Reporting that as a green "0 deviations" claimed a clean
    // bill of health the page had not checked; say that it could not check.
    $countBadge = $hasInventory
        ? portal_badge($issueCount > 0 ? 'warning' : 'success', match (true) {
            $issueCount === 0 => __t('system_status.dev_count_none'),
            $issueCount === 1 => __t('system_status.dev_count_one'),
            default => __t('system_status.dev_count_many', ['count' => $issueCount]),
        })
        : portal_badge('neutral', __t('system_status.dev_count_unknown'));
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS); ?>">
        <h2><?php echo h(__t('system_status.dev_heading')); ?> <?php echo $countBadge; ?></h2>
        <p class="muted"><?php echo h(__t('system_status.dev_hint')); ?></p>
        <?php if (!$hasInventory) { ?><p class="muted"><?php echo h(__t('system_status.dev_no_inventory')); ?></p><?php } elseif ($deviations === []) { ?><p class="muted"><?php echo h(__t('system_status.dev_none')); ?></p><?php } else { ?>
            <div class="deviation-groups"><?php foreach ($deviations as $entry) { ?>
                <article>
                    <h3>
                        <a href="mission_details.php?id=<?php echo h((string) $entry['mission_id']); ?>"><?php echo h((string) $entry['mission_name']); ?></a>
                        <?php // A deviation on a template is not an outage: it becomes one only when a mission is created from it.
                              // The flag the scan computed, not a second str_starts_with: one predicate, one answer. ?>
                        <?php if (!empty($entry['is_template'])) { echo ' ' . portal_badge('info', __t('system_status.dev_template_badge')); } ?>
                        <?php if (!empty($entry['vm_name'])) { ?> · <a href="vm_edit.php?mission_id=<?php echo h((string) $entry['mission_id']); ?>&amp;vm_id=<?php echo h((string) $entry['vm_id']); ?>"><?php echo h((string) $entry['vm_name']); ?></a><?php } ?>
                    </h3>
                    <ul><?php foreach ($entry['issues'] as $issue) { ?>
                        <li><?php echo h(__t('system_status.dev_field_' . $issue['field'])); ?>: <code class="break-anywhere"><?php echo h((string) $issue['value']); ?></code><?php if ($issue['field'] === 'vlan' && can('missions.write', $user) && can('vms.write', $user)) { ?> <a href="<?php echo h(system_status_url('reassign', ['reassign_from' => $issue['value']])); ?>" title="<?php echo h(__t('system_status.dev_reassign_link_title')); ?>"><?php echo h(__t('system_status.dev_reassign_link')); ?></a><?php } ?></li>
                    <?php } ?></ul>
                </article>
            <?php } ?></div>
        <?php } ?>
        <?php if ($hasVlanDeviation && can('missions.write', $user) && can('vms.write', $user) && $activeVlanNames !== []) { ?>
            <details class="repair-actions" id="reassign"<?php echo form_has_state('vlan_reassign') || $reassignFrom !== '' ? ' open' : ''; ?>><summary><?php echo h(__t('system_status.repair_heading')); ?></summary>
                <h3><?php echo h(__t('system_status.reassign_heading')); ?></h3>
                <p class="muted"><?php echo h(__t('system_status.reassign_hint')); ?></p>
                <form class="form-grid" method="post" action="system_status.php" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reassign_vlan">
                    <label><?php echo h(__t('system_status.reassign_from')); ?><input name="vlan_from" maxlength="255" value="<?php echo h(form_old('vlan_reassign', 'vlan_from', $reassignFrom)); ?>"<?php echo form_input_class('vlan_reassign', 'vlan_from'); ?> required><?php echo form_error_html('vlan_reassign', 'vlan_from'); ?></label>
                    <label><?php echo h(__t('system_status.reassign_to')); ?><select name="vlan_to"<?php echo form_input_class('vlan_reassign', 'vlan_to'); ?> required><option value=""><?php echo h(__t('system_status.reassign_choose')); ?></option><?php $oldTarget = form_old('vlan_reassign', 'vlan_to'); foreach ($activeVlanNames as $name) { ?><option value="<?php echo h($name); ?>"<?php echo $oldTarget === $name ? ' selected' : ''; ?>><?php echo h($name); ?></option><?php } ?></select><?php echo form_error_html('vlan_reassign', 'vlan_to'); ?></label>
                    <div class="actions"><button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('system_status.reassign_confirm')); ?>"><?php echo h(__t('system_status.reassign_btn')); ?></button></div>
                </form>
            </details>
        <?php } ?>
    </section>
    <?php
}
