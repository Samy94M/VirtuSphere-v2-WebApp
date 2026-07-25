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
 * The two decoration maps are required, exactly as esxi_inventory_options()
 * declares them: this docblock is a mirror of that producer, and the last time
 * it was hand-edited it kept `free_by_key?` optional and lost `unusable_by_key`
 * altogether. Optional would cost the only static guarantee available here, that
 * a producer dropping or renaming a key breaks the build at the call sites
 * instead of quietly taking the maintenance suffix off the flat picker.
 *
 * @param array{buckets?:array<int,array<string,mixed>>, names:array<int,string>, free_by_key:array<string,?int>, unusable_by_key:array<string,bool>} $options
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
        <?php if (esxi_inventory_options_are_bucketed($options)) { ?>
            <?php foreach ($options['buckets'] as $bucket) { ?>
                <optgroup label="<?php echo h(inventory_bucket_label($bucket)); ?>">
                    <?php foreach ($bucket['names'] as $optionName) { inventory_select_option($optionName, $value, $bucket['free_by_key'] ?? [], $bucket['unusable_by_key'] ?? []); } ?>
                </optgroup>
            <?php } ?>
        <?php } else { ?>
            <?php foreach ($options['names'] as $optionName) { inventory_select_option($optionName, $value, $options['free_by_key'], $options['unusable_by_key']); } ?>
        <?php } ?>
    </select>
    <?php
}

/**
 * Group heading of one presence bucket. It answers the question the operator
 * has at this field, "does this value survive the host choice I make later",
 * rather than the one the old per-credential grouping answered, "who reported
 * it". Exhaustive match, no default: a fourth scope has to be given a sentence
 * here rather than silently rendering as an unlabelled group.
 *
 * The scope is spelled out rather than typed `string`: it arrives through
 * carriers that widen to array<string,mixed>, so nothing on the way here checks
 * the three values, and a bare `string` made the default-free match above a
 * promise no analyser could keep. EsxiInventoryPresenceBucketsTest walks the
 * producer's scopes through this function, so a fourth one fails the build.
 *
 * @param array{scope:'all'|'some'|'only', credentials:array<int,array{name:string,host:string}>} $bucket
 */
function inventory_bucket_label(array $bucket): string
{
    $hosts = implode(', ', array_map('inventory_credential_label', $bucket['credentials']));

    return match ($bucket['scope']) {
        'all' => __t('common.inventory_bucket_all'),
        'some' => __t('common.inventory_bucket_some', ['hosts' => $hosts]),
        'only' => __t('common.inventory_bucket_only', ['host' => $hosts]),
    };
}

/**
 * "esxi-prod-02 (10.0.5.12)". The address is dropped when the credential has
 * none; a trailing empty bracket would state a fact nobody has.
 *
 * @param array{name:string, host:string} $credential
 */
function inventory_credential_label(array $credential): string
{
    $name = trim($credential['name']);
    $host = trim($credential['host']);
    if ($name === '') {
        return $host;
    }

    return $host !== '' ? $name . ' (' . $host . ')' : $name;
}

/**
 * One option. The value is always the bare name; everything after it is label
 * decoration.
 *
 * A datastore that a reporting host holds in maintenance says so instead of
 * showing a free number, because its size is not space a deploy can use. An
 * unknown free value shows nothing at all rather than a "0 B" or an "unknown"
 * suffix: the kinds without bytes (datacenters) would carry it on every single
 * option, and a suffix on every row states nothing while hiding the ones that do
 * (portal display restraint). The two are mutually exclusive by construction:
 * an unusable name never carries a free number.
 *
 * @param array<string, ?int> $freeByKey
 * @param array<string, bool> $unusableByKey
 */
function inventory_select_option(string $optionName, string $value, array $freeByKey, array $unusableByKey = []): void
{
    $key = esxi_inventory_name_key($optionName);
    $free = $freeByKey[$key] ?? null;
    $label = $optionName;
    if (!empty($unusableByKey[$key])) {
        $label .= ' (' . __t('common.maintenance_suffix') . ')';
    } elseif ($free !== null) {
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
