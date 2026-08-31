<?php

declare(strict_types=1);

/** VM-editor dynamic interface/disk rows, group hints and field-error wrapper. */

function render_interface_row(array $interface, int|string $index, array $vlans, bool $canWrite, bool $template = false): void
{
    $prefix = $template ? 'interfaces[__INDEX__]' : 'interfaces[' . h((string) $index) . ']';
    $scope = $template ? '__INDEX__' : $index;
    $subnet = (string) ($interface['subnet'] ?? '');
    $subnetInput = vm_subnet_input_value($subnet);
    $subnetPicker = vm_subnet_picker_value($subnet);
    $mode = (string) ($interface['mode'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_mode']);
    $type = (string) ($interface['type'] ?? VIRTUSPHERE_VM_DEFAULTS['interface_type']);
    ?>
    <div class="form-row interface-row" data-repeat-row>
        <input type="hidden" name="<?php echo $prefix; ?>[id]" value="<?php echo h((string) ($interface['id'] ?? 0)); ?>">
        <label><?php echo h(__t('vm_edit.label_ip')); ?><input name="<?php echo $prefix; ?>[ip]"<?php echo form_control_attrs('vm_edit', 'interface_ip', $scope, false, ''); ?> value="<?php echo h($interface['ip'] ?? ''); ?>" data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_subnet')); ?><span class="compound-field"><input name="<?php echo $prefix; ?>[subnet]"<?php echo form_control_attrs('vm_edit', 'interface_subnet', $scope, false, ''); ?> value="<?php echo h($subnetInput); ?>" data-subnet-input data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>><select<?php echo form_control_attrs('vm_edit', 'interface_subnet_picker', $scope, false, ''); ?> data-subnet-picker data-dhcp-disable aria-label="<?php echo h(__t('vm_edit.subnet_mask')); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><option value=""><?php echo h(__t('vm_edit.mask')); ?></option><?php for ($mask = 0; $mask <= 30; $mask++) { $cidr = '/' . $mask; $value = vm_cidr_to_netmask($mask); ?><option value="<?php echo h($value); ?>" <?php echo $subnetPicker === $cidr ? 'selected' : ''; ?>><?php echo h($cidr); ?></option><?php } ?></select></span></label>
        <label><?php echo h(__t('vm_edit.label_gateway')); ?><input name="<?php echo $prefix; ?>[gateway]"<?php echo form_control_attrs('vm_edit', 'interface_gateway', $scope, false, ''); ?> value="<?php echo h($interface['gateway'] ?? ''); ?>" data-dhcp-disable <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_dns1')); ?><input name="<?php echo $prefix; ?>[dns1]"<?php echo form_control_attrs('vm_edit', 'interface_dns1', $scope, false, ''); ?> value="<?php echo h($interface['dns1'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_dns2')); ?><input name="<?php echo $prefix; ?>[dns2]"<?php echo form_control_attrs('vm_edit', 'interface_dns2', $scope, false, ''); ?> value="<?php echo h($interface['dns2'] ?? ''); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_vlan')); ?><?php vlan_select_field($prefix . '[vlan]', (string) ($interface['vlan'] ?? ''), $vlans, [
            'none' => __t('vm_edit.vlan_none'),
            'unknown_suffix' => __t('vm_edit.vlan_not_in_inventory'),
        ], !$canWrite, form_control_attrs('vm_edit', 'interface_vlan', $scope, false, '')); ?></label>
        <label><?php echo h(__t('vm_edit.label_mode')); ?><select name="<?php echo $prefix; ?>[mode]"<?php echo form_control_attrs('vm_edit', 'interface_mode', $scope, false, ''); ?> data-mode-select="<?php echo h(VIRTUSPHERE_INTERFACE_MODE_DHCP); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>><?php foreach (VIRTUSPHERE_INTERFACE_MODES as $option) { ?><option value="<?php echo h($option); ?>" <?php echo $mode === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option><?php } ?></select></label>
        <label><?php echo h(__t('vm_edit.label_type')); ?><select name="<?php echo $prefix; ?>[type]"<?php echo form_control_attrs('vm_edit', 'interface_type', $scope, false, ''); ?> <?php echo $canWrite ? '' : 'disabled'; ?>><?php foreach (VIRTUSPHERE_INTERFACE_TYPES as $option) { ?><option value="<?php echo h($option); ?>" <?php echo $type === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option><?php } ?></select></label>
        <label><?php echo h(__t('vm_edit.label_mac')); ?><input<?php echo form_control_attrs('vm_edit', 'interface_mac', $scope, false, ''); ?> value="<?php echo h($interface['mac'] ?? ''); ?>" readonly></label>
        <?php if ($canWrite) { ?><button class="button button-danger" type="button" data-remove-row><?php echo h(__t('common.remove')); ?></button><?php } ?>
    </div>
    <?php
}

function render_disk_row(array $disk, int|string $index, bool $canWrite, bool $template = false): void
{
    $prefix = $template ? 'disks[__INDEX__]' : 'disks[' . h((string) $index) . ']';
    $scope = $template ? '__INDEX__' : $index;
    $type = strtolower((string) ($disk['disk_type'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_type']));
    ?>
    <div class="form-row" data-repeat-row>
        <?php // Die beiden data-autoname-Werte sind die einzige Stelle, an der forms.js
              // erfaehrt, wie eine hinzugefuegte Platte heissen soll; die Regel selbst
              // bleibt vm_disk_default_name(). Ohne sie erbte jede neue Zeile den Wert
              // der ersten und hiess wieder "System". ?>
        <label><?php echo h(__t('common.name')); ?><input name="<?php echo $prefix; ?>[disk_name]"<?php echo form_control_attrs('vm_edit', 'disk_name', $scope, false, ''); ?> value="<?php echo h($disk['disk_name'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_name']); ?>" data-autoname-first="<?php echo h(VIRTUSPHERE_VM_DEFAULTS['disk_name']); ?>" data-autoname-prefix="<?php echo h(VIRTUSPHERE_VM_DEFAULTS['disk_name_prefix']); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_size_gb')); ?><input name="<?php echo $prefix; ?>[disk_size]"<?php echo form_control_attrs('vm_edit', 'disk_size', $scope, false, ''); ?> type="number" min="1" value="<?php echo h((string) ($disk['disk_size'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'])); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>></label>
        <label><?php echo h(__t('vm_edit.label_type')); ?><select name="<?php echo $prefix; ?>[disk_type]"<?php echo form_control_attrs('vm_edit', 'disk_type', $scope, false, ''); ?> <?php echo $canWrite ? '' : 'disabled'; ?>>
            <?php foreach (VIRTUSPHERE_DISK_TYPES as $option) { ?>
                <option value="<?php echo h($option); ?>" <?php echo $type === $option ? 'selected' : ''; ?>><?php echo h(disk_type_label($option)); ?></option>
            <?php } ?>
        </select></label>
        <?php if ($canWrite) { ?><button class="button button-danger" type="button" data-remove-row><?php echo h(__t('common.remove')); ?></button><?php } ?>
    </div>
    <?php
}

/**
 * Der Satz unter der Datenträgerliste. Die Auswahl selbst trägt nur noch
 * sprechende Bezeichnungen; was hier fehlte, war die Vorbelegung und der
 * Hinweis, dass der Typ eine bestehende Platte nicht mehr anfasst. Der
 * vorbelegte Typ kommt aus der Konstante, nicht als ausgeschriebener Name:
 * sonst nennt der Satz beim nächsten Wechsel den falschen.
 */
function render_disk_type_hint(): void
{
    ?>
    <p class="hint" id="<?php echo h(form_hint_id('vm_edit', 'disks')); ?>"><?php echo h(__t('vm_edit.disk_type_hint', [
        'default' => disk_type_label(VIRTUSPHERE_VM_DEFAULTS['disk_type']),
    ])); ?></p>
    <?php
}

/**
 * Der Satz unter der Schnittstellenliste. Das Gateway ist auch im Modus
 * 'static' optional (repo_validate_interfaces), und ein leeres Feld neben IP
 * und Maske liest sich sonst wie ein vergessener Wert. Was danach passiert,
 * steht dabei, weil genau das die Frage am Feld ist.
 */
function render_interface_gateway_hint(): void
{
    ?>
    <p class="hint" id="<?php echo h(form_hint_id('vm_edit', 'interfaces')); ?>"><?php echo h(__t('vm_edit.gateway_hint')); ?></p>
    <?php
}

function vm_field_error(array $errors, string $field, string|int|null $scope = null): string
{
    return form_error_html('vm_edit', $field, $scope, (string) ($errors[$field] ?? ''));
}
