<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/inventory_field.php';
// vm_parse_interfaces() rejects an empty interface list with a field error.
require_once __DIR__ . '/validate.php';

/**
 * VM editor form helpers: the default interface/disk rows, the POST parsers that
 * turn the repeat-row arrays into clean records, the subnet CIDR/netmask
 * conversion (the server-rendered twin of the forms.js mirror), the row
 * renderers and the read-only status panel. Split out of portal/vm_edit.php so
 * the page keeps only its controller and view. __t()/h(), the badge helpers and
 * virtusphere_client_phase_state() come from the portal bootstrap/layout the
 * page has already loaded, as with the row renderers.
 */

function vm_default_interfaces(array $mission): array
{
    return [[
        'id' => 0,
        'ip' => '',
        'subnet' => '',
        'gateway' => '',
        'dns1' => '',
        'dns2' => '',
        'vlan' => (string) ($mission['wds_vlan'] ?? ''),
        'mac' => '',
        'mode' => VIRTUSPHERE_VM_DEFAULTS['interface_mode'],
        'type' => VIRTUSPHERE_VM_DEFAULTS['interface_type'],
    ]];
}

function vm_default_disks(): array
{
    return [[
        'disk_name' => VIRTUSPHERE_VM_DEFAULTS['disk_name'],
        'disk_size' => VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
        'disk_type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
    ]];
}

/**
 * The interface rows of a submitted VM form.
 *
 * Removing the LAST row is rejected instead of falling back to a default NIC.
 * repo_save_vm rewrites deploy_interfaces from what this returns, so the empty
 * default replaced the real row: a MAC that Ansible had exported and MECM was
 * waiting for was silently gone, together with the VLAN, the IP and the mode,
 * and the save reported success. There is no honest default for "the operator
 * removed every interface", so the form says so and keeps the stored rows.
 *
 * vm_default_interfaces() stays the RENDER default (a new VM, the sticky
 * re-render): offering a prefilled row is not the same as writing one.
 */
function vm_parse_interfaces(array $rows, array $mission): array
{
    $interfaces = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hasData = false;
        foreach (['id', 'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type'] as $key) {
            $hasData = $hasData || trim((string) ($row[$key] ?? '')) !== '';
        }
        if (!$hasData) {
            continue;
        }
        $interfaces[] = [
            'id' => (int) ($row['id'] ?? 0),
            'ip' => trim((string) ($row['ip'] ?? '')),
            'subnet' => trim((string) ($row['subnet'] ?? '')),
            'gateway' => trim((string) ($row['gateway'] ?? '')),
            'dns1' => trim((string) ($row['dns1'] ?? '')),
            'dns2' => trim((string) ($row['dns2'] ?? '')),
            'vlan' => trim((string) ($row['vlan'] ?? '')),
            'mode' => trim((string) ($row['mode'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_mode'])),
            'type' => trim((string) ($row['type'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_type'])),
        ];
    }

    if ($interfaces === []) {
        throw new ValidationException(
            ['interfaces' => __t('vm_edit.err_interfaces_required')],
            __t('vm_edit.err_interfaces_required')
        );
    }

    return $interfaces;
}

function vm_parse_disks(array $rows): array
{
    $allowed = VIRTUSPHERE_DISK_TYPES;
    $disks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['disk_name'] ?? ''));
        $size = (int) ($row['disk_size'] ?? 0);
        $type = strtolower(trim((string) ($row['disk_type'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_type'])));
        if ($name === '' && $size <= 0) {
            continue;
        }
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException(__t('vm_edit.err_invalid_disk_type'));
        }
        $disks[] = ['disk_name' => $name !== '' ? $name : 'System', 'disk_size' => max(1, $size), 'disk_type' => $type];
    }

    return $disks !== [] ? $disks : vm_default_disks();
}

function vm_parse_packages(array $packageIds): array
{
    $packages = [];
    foreach ($packageIds as $packageId) {
        $id = (int) $packageId;
        if ($id > 0) {
            $packages[] = ['id' => $id];
        }
    }

    return $packages;
}

function vm_cidr_to_netmask(int $prefix): string
{
    $bits = str_repeat('1', $prefix) . str_repeat('0', 32 - $prefix);
    $octets = str_split($bits, 8);

    return implode('.', array_map(static fn (string $octet): string => (string) bindec($octet), $octets));
}

function vm_subnet_input_value(string $subnet): string
{
    if (preg_match('/^\/(?:[0-9]|[12][0-9]|30)$/', $subnet) === 1) {
        return vm_cidr_to_netmask((int) substr($subnet, 1));
    }

    return $subnet;
}

function vm_subnet_picker_value(string $subnet): string
{
    if (preg_match('/^\/(?:[0-9]|[12][0-9]|30)$/', $subnet) === 1) {
        return $subnet;
    }

    for ($mask = 0; $mask <= 30; $mask++) {
        if ($subnet === vm_cidr_to_netmask($mask)) {
            return '/' . $mask;
        }
    }

    return '';
}

function render_interface_row(array $interface, int|string $index, array $vlans, bool $canWrite, bool $template = false): void
{
    $prefix = $template ? 'interfaces[__INDEX__]' : 'interfaces[' . h((string) $index) . ']';
    $subnet = (string) ($interface['subnet'] ?? '');
    $subnetInput = vm_subnet_input_value($subnet);
    $subnetPicker = vm_subnet_picker_value($subnet);
    $mode = (string) ($interface['mode'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_mode']);
    $type = (string) ($interface['type'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_type']);
    ?>
    <div class="form-row interface-row" data-repeat-row>
        <input type="hidden" name="<?php echo $prefix; ?>[id]" value="<?php echo h((string) ($interface['id'] ?? 0)); ?>">
        <label><?php echo h(__t('vm_edit.label_ip')); ?><input name="<?php echo $prefix; ?>[ip]" value="<?php echo h($interface['ip'] ?? ''); ?>" data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_subnet')); ?><span class="compound-field"><input name="<?php echo $prefix; ?>[subnet]" value="<?php echo h($subnetInput); ?>" data-subnet-input data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>><select data-subnet-picker data-dhcp-disable aria-label="<?php echo h(__t('vm_edit.subnet_mask')); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><option value=""><?php echo h(__t('vm_edit.mask')); ?></option><?php for ($mask = 0; $mask <= 30; $mask++) { $cidr = '/' . $mask; $value = vm_cidr_to_netmask($mask); ?><option value="<?php echo h($value); ?>" <?php echo $subnetPicker === $cidr ? 'selected' : ''; ?>><?php echo h($cidr); ?></option><?php } ?></select></span></label>
        <label><?php echo h(__t('vm_edit.label_gateway')); ?><input name="<?php echo $prefix; ?>[gateway]" value="<?php echo h($interface['gateway'] ?? ''); ?>" data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_dns1')); ?><input name="<?php echo $prefix; ?>[dns1]" value="<?php echo h($interface['dns1'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_dns2')); ?><input name="<?php echo $prefix; ?>[dns2]" value="<?php echo h($interface['dns2'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_vlan')); ?><?php vlan_select_field($prefix . '[vlan]', (string) ($interface['vlan'] ?? ''), $vlans, [
            'none' => __t('vm_edit.vlan_none'),
            'unknown_suffix' => __t('vm_edit.vlan_not_in_inventory'),
        ], !$canWrite); ?></label>
        <label><?php echo h(__t('vm_edit.label_mode')); ?><select name="<?php echo $prefix; ?>[mode]" data-mode-select="<?php echo h(VIRTUSPHERE_INTERFACE_MODE_DHCP); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><?php foreach (VIRTUSPHERE_INTERFACE_MODES as $option) { ?><option value="<?php echo h($option); ?>" <?php echo $mode === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option><?php } ?></select></label>
        <label><?php echo h(__t('vm_edit.label_type')); ?><select name="<?php echo $prefix; ?>[type]" <?php echo $canWrite ? '' : 'disabled'; ?>><?php foreach (VIRTUSPHERE_INTERFACE_TYPES as $option) { ?><option value="<?php echo h($option); ?>" <?php echo $type === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option><?php } ?></select></label>
        <label><?php echo h(__t('vm_edit.label_mac')); ?><input value="<?php echo h($interface['mac'] ?? ''); ?>" readonly></label>
        <?php if ($canWrite) { ?><button class="button button-danger" type="button" data-remove-row><?php echo h(__t('common.remove')); ?></button><?php } ?>
    </div>
    <?php
}

function render_disk_row(array $disk, int|string $index, bool $canWrite, bool $template = false): void
{
    $prefix = $template ? 'disks[__INDEX__]' : 'disks[' . h((string) $index) . ']';
    $type = strtolower((string) ($disk['disk_type'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_type']));
    ?>
    <div class="form-row" data-repeat-row>
        <label><?php echo h(__t('common.name')); ?><input name="<?php echo $prefix; ?>[disk_name]" value="<?php echo h($disk['disk_name'] ?? 'System'); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_size_gb')); ?><input name="<?php echo $prefix; ?>[disk_size]" type="number" min="1" value="<?php echo h((string) ($disk['disk_size'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'])); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_type')); ?><select name="<?php echo $prefix; ?>[disk_type]" <?php echo $canWrite ? '' : 'disabled'; ?>>
            <?php foreach (VIRTUSPHERE_DISK_TYPES as $option) { ?>
                <option value="<?php echo h($option); ?>" <?php echo $type === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
            <?php } ?>
        </select></label>
        <?php if ($canWrite) { ?><button class="button button-danger" type="button" data-remove-row><?php echo h(__t('common.remove')); ?></button><?php } ?>
    </div>
    <?php
}

function vm_field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="field-error">' . h($errors[$field]) . '</span>' : '';
}

function vm_guest_os_options_for_value(string $guestId): array
{
    $options = VIRTUSPHERE_GUEST_OS_OPTIONS;
    if ($guestId !== '' && !in_array($guestId, virtusphere_guest_os_ids(), true)) {
        $options[] = ['guest_id' => $guestId, 'legacy' => true];
    }

    return $options;
}

function vm_guest_os_option_label(array $option): string
{
    $guestId = (string) ($option['guest_id'] ?? '');
    if (!empty($option['legacy'])) {
        return __t('portal.vm_guest_os_legacy', ['guest_id' => $guestId]);
    }

    return __t((string) ($option['label_key'] ?? 'portal.vm_guest_os_unknown')) . ' (' . $guestId . ')';
}

/**
 * Read-only status/diagnostics panel: legacy status badge, the lifecycle/MECM
 * diagnostics and the client deploy-phase track with its recent-events table.
 * The caller owns the visibility guard (a real VM, not a template), matching how
 * the row renderers leave permission checks to the page.
 *
 * @param array<string, mixed> $vm
 * @param array<string, array<string, mixed>> $clientPhaseSummary latest event per phase
 * @param array<int, array<string, mixed>> $clientEvents
 */
function vm_edit_render_status_panel(array $vm, array $clientPhaseSummary, array $clientEvents): void
{
    ?>
        <section class="panel">
            <div class="vm-status-head">
                <h2><?php echo h(__t('vm_edit.heading_status')); ?></h2>
                <?php echo status_badge((string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED)); ?>
            </div>
            <dl class="diagnostics">
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_lifecycle')); ?></dt>
                    <dd><?php echo lifecycle_badge((string) ($vm['lifecycle_state'] ?? '')); ?></dd>
                </div>
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_mecm')); ?></dt>
                    <dd><?php echo mecm_sync_badge((string) ($vm['mecm_sync_state'] ?? '')); ?></dd>
                </div>
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_updated')); ?></dt>
                    <dd><?php echo h((string) ($vm['updated'] ?? 0)); ?></dd>
                </div>
            </dl>
            <h3><?php echo h(__t('vm_edit.heading_client_phases')); ?></h3>
            <ol class="phase-track">
                <?php foreach (VIRTUSPHERE_CLIENT_PHASES as $index => $phase) {
                    $latestEvent = $clientPhaseSummary[$phase] ?? null;
                    $phaseState = virtusphere_client_phase_state($latestEvent);
                    ?>
                    <li class="phase-step phase-step-<?php echo h($phaseState); ?>">
                        <span class="phase-step-index"><?php echo h((string) ($index + 1)); ?></span>
                        <span class="phase-step-body">
                            <span class="phase-step-name"><?php echo h(client_phase_label($phase)); ?></span>
                            <span class="phase-step-state">
                                <?php echo client_phase_badge($phaseState); ?>
                                <?php if ($latestEvent !== null) { ?>
                                    <span class="muted"><?php echo h(portal_format_timestamp((string) $latestEvent['created_at'])); ?></span>
                                <?php } ?>
                            </span>
                        </span>
                    </li>
                <?php } ?>
            </ol>
            <?php if ($clientEvents !== []) { ?>
                <details>
                    <summary><?php echo h(__t('vm_edit.client_events_summary')); ?></summary>
                    <div class="table-wrap" tabindex="0">
                        <table>
                            <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_edit.th_phase')); ?></th><th><?php echo h(__t('vm_edit.th_event')); ?></th><th><?php echo h(__t('vm_edit.th_detail')); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($clientEvents as $event) { ?>
                                <tr>
                                    <td><?php echo h(portal_format_timestamp((string) $event['created_at'])); ?></td>
                                    <td><?php echo h(client_phase_label((string) $event['phase'])); ?></td>
                                    <td><?php echo h((string) $event['event']); ?></td>
                                    <td><?php echo h((string) ($event['detail'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php } else { ?>
                <p class="muted"><?php echo h(__t('vm_edit.client_events_empty')); ?></p>
            <?php } ?>
        </section>
    <?php
}

/**
 * The VM's transition history (B11 rest): every state write records a row and
 * until Etappe 8 no page ever read one back, so the trail existed only for the
 * database. Read-only, collapsed by default, hidden entirely without events (a
 * fresh VM has nothing to explain). The caller owns the visibility guard like
 * the panel above.
 *
 * @param array<int, array<string, mixed>> $events newest first (repo_vm_status_events)
 */
function render_vm_status_history(array $events): void
{
    if ($events === []) {
        return;
    }
    ?>
        <section class="panel">
            <details>
                <summary><?php echo h(__t('vm_edit.status_history_heading')); ?></summary>
                <p class="muted"><?php echo h(__t('vm_edit.status_history_hint', [
                    'limit' => VIRTUSPHERE_STATUS_EVENT_HISTORY_LIMIT,
                    'days' => VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS,
                ])); ?></p>
                <div class="table-wrap" tabindex="0">
                    <table>
                        <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_edit.diagnostics_lifecycle')); ?></th><th><?php echo h(__t('vm_edit.diagnostics_mecm')); ?></th><th><?php echo h(__t('vm_edit.th_detail')); ?></th><th><?php echo h(__t('vm_edit.status_history_actor')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event) { ?>
                            <tr>
                                <td class="nowrap"><?php echo h(portal_format_timestamp((string) $event['created_at'])); ?></td>
                                <td><?php echo lifecycle_badge((string) $event['lifecycle_state']); ?></td>
                                <td><?php echo mecm_sync_badge((string) $event['mecm_sync_state']); ?></td>
                                <td><?php echo h((string) ($event['note'] ?? '') !== '' ? (string) $event['note'] : '—'); ?></td>
                                <td><?php echo h((string) ($event['actor_name'] ?? '') !== '' ? (string) $event['actor_name'] : '—'); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    <?php
}
