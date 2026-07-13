<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/format.php';

/**
 * The two location pickers of the mission and VM editors (datacenter, datastore)
 * plus the VLAN picker. All three are hard <select>s over a known name list with
 * one shared rule: a stored value the list does not know is rendered as an extra,
 * selected option instead of being silently dropped.
 *
 * Datacenter and datastore have no catalog table and no admin UI, they only live
 * in the ESXi inventory cache. An empty cache must not lock the operator out, so
 * inventory_select_field() falls back to a free-text input for exactly that case:
 * as long as a kind has no cached names, nothing could be offered anyway. Once
 * names exist, new ones arrive the way they arrived before: through an inventory
 * pull (ADR-0023). The machine API and the mission import keep accepting any
 * string, and a value outside the inventory is caught by the deviation report
 * afterwards, not prevented up front.
 */

/**
 * @param array{grouped:bool, groups:array<int,array{credential_name:string,names:array<int,string>,free_by_key?:array<string,?int>}>, names:array<int,string>, free_by_key?:array<string,?int>} $options
 * @param array{
 *     name:string, value:string, empty_label:string, unknown_suffix:string,
 *     required?:bool, disabled?:bool, input_placeholder?:string
 * } $config
 */
function inventory_select_field(array $options, array $config): void
{
    $value = (string) $config['value'];
    $required = !empty($config['required']);
    $disabled = !empty($config['disabled']);

    // Empty inventory of this kind: nothing to offer, so the field stays a plain
    // input. Decided server-side, so the escape hatch also works without JS.
    if ($options['names'] === []) {
        $placeholder = (string) ($config['input_placeholder'] ?? '');
        ?>
        <input name="<?php echo h($config['name']); ?>" value="<?php echo h($value); ?>"
               <?php echo $placeholder !== '' ? 'placeholder="' . h($placeholder) . '"' : ''; ?>
               <?php echo $required ? 'required' : ''; ?>
               <?php echo $disabled ? 'readonly' : ''; ?>>
        <?php
        return;
    }

    // Exact match on purpose: a value that differs only in case shows the
    // "not in the inventory" suffix instead of silently snapping to the
    // inventory spelling on the next save. The warn logic stays
    // case-insensitive (esxi_inventory_value_unknown), so this never produces a
    // warning; same documented exception as the VLAN selects (ADR-0023).
    // It also carries the sticky-form case: a value posted before a failed
    // validation comes back through form_old() and renders as this option.
    $showUnknown = $value !== '' && !in_array($value, $options['names'], true);
    ?>
    <select name="<?php echo h($config['name']); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
        <option value=""><?php echo h($config['empty_label']); ?></option>
        <?php if ($showUnknown) { ?>
            <option value="<?php echo h($value); ?>" selected><?php echo h($value); ?> (<?php echo h($config['unknown_suffix']); ?>)</option>
        <?php } ?>
        <?php if ($options['grouped']) { ?>
            <?php foreach ($options['groups'] as $group) { ?>
                <optgroup label="<?php echo h($group['credential_name']); ?>">
                    <?php foreach ($group['names'] as $optionName) { inventory_select_option($optionName, $value, $group['free_by_key'] ?? []); } ?>
                </optgroup>
            <?php } ?>
        <?php } else { ?>
            <?php foreach ($options['names'] as $optionName) { inventory_select_option($optionName, $value, $options['free_by_key'] ?? []); } ?>
        <?php } ?>
    </select>
    <?php
}

/**
 * One option. The value is always the bare name; the free space of the last pull
 * is label decoration and is omitted when the kind carries no bytes (datacenters)
 * or the row predates the column.
 *
 * @param array<string, ?int> $freeByKey
 */
function inventory_select_option(string $optionName, string $value, array $freeByKey): void
{
    $free = $freeByKey[esxi_inventory_name_key($optionName)] ?? null;
    $label = $optionName;
    if ($free !== null) {
        $label .= ' (' . __t('common.free_suffix', ['free' => virtusphere_human_bytes($free)]) . ')';
    }
    ?>
    <option value="<?php echo h($optionName); ?>" <?php echo $optionName === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
    <?php
}

/**
 * Hard <select> over the active VLAN catalog with the stored-unknown fallback
 * option: a stored value the catalog does not know stays selectable, labelled
 * with a suffix, so the picker can never silently drop it (ADR-0023
 * decoupling). The match is exact on purpose: a case variant shows the suffix
 * instead of silently snapping to the catalog spelling on the next save (see
 * the case note on repo_esxi_vlan_sync). Shared by the mission editor and the
 * VM interface rows.
 *
 * @param array<int, array<string, mixed>> $vlans active catalog rows (vlan_name)
 * @param array{none: string, unknown_suffix: string} $labels
 */
function vlan_select_field(string $name, string $value, array $vlans, array $labels, bool $disabled = false): void
{
    $vlanNames = array_map(static fn (array $v): string => (string) ($v['vlan_name'] ?? ''), $vlans);
    $unknown = $value !== '' && !in_array($value, $vlanNames, true);
    ?>
    <select name="<?php echo h($name); ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
        <option value=""><?php echo h($labels['none']); ?></option>
        <?php if ($unknown) { ?>
            <option value="<?php echo h($value); ?>" selected><?php echo h($value); ?> (<?php echo h($labels['unknown_suffix']); ?>)</option>
        <?php } ?>
        <?php foreach ($vlanNames as $vlanName) { ?>
            <option value="<?php echo h($vlanName); ?>" <?php echo $vlanName === $value ? 'selected' : ''; ?>><?php echo h($vlanName); ?></option>
        <?php } ?>
    </select>
    <?php
}
