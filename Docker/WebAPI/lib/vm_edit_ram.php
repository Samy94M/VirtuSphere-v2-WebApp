<?php

declare(strict_types=1);

require_once __DIR__ . '/vm_ram.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/request.php';

function vm_edit_ram_from_post(array $post): int
{
    $unit = array_key_exists('vm_ram_unit', $post) ? $post['vm_ram_unit'] : 'mb';
    $parsed = vm_ram_parse_input($post['vm_ram'] ?? '', $unit);
    if ($parsed['ok']) {
        return $parsed['mb'];
    }
    $message = __t(match ($parsed['error']) {
        'required' => 'validate.required',
        'unit' => 'vm_edit.ram_unit_invalid',
        'integer' => 'validate.integer',
        default => 'validate.number',
    }, ['field' => __t('vm_edit.label_ram')]);
    if ($parsed['error'] === 'range') {
        $factor = VIRTUSPHERE_RAM_INPUT_FACTORS_MB[$unit];
        $message = __t('validate.range_unit', ['field' => __t('vm_edit.label_ram'), 'min' => VIRTUSPHERE_VM_LIMITS['ram_mb_min'] / $factor, 'max' => VIRTUSPHERE_VM_LIMITS['ram_mb_max'] / $factor, 'unit' => strtoupper($unit)]);
    }
    throw new ValidationException(['vm_ram' => $message], $message);
}

function render_vm_ram_field(?array $vm, bool $canWrite, array $fieldErrors): void
{
    $state = vm_ram_display_state($vm['vm_ram'] ?? VIRTUSPHERE_VM_DEFAULTS['ram_mb']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state['value'] = request_string($_POST, 'vm_ram');
        $unit = array_key_exists('vm_ram_unit', $_POST) ? $_POST['vm_ram_unit'] : 'mb';
        $state['unit'] = is_string($unit) && isset(VIRTUSPHERE_RAM_INPUT_FACTORS_MB[$unit]) ? $unit : '';
    }
    $error = (string) ($fieldErrors['vm_ram'] ?? '');
    if (!$state['valid'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $error = __t('vm_edit.ram_invalid');
    }
    ?>
    <div role="group"<?php echo form_control_attrs('vm_edit', 'ram_group', null, true, ''); ?>>
        <label for="<?php echo h(form_element_id('vm_edit', 'vm_ram')); ?>"><?php echo h(__t('vm_edit.label_ram')); ?></label>
        <div class="compound-field ram-field" data-ram-field data-ram-factors="<?php echo h(json_encode(VIRTUSPHERE_RAM_INPUT_FACTORS_MB, JSON_THROW_ON_ERROR)); ?>" data-ram-min="<?php echo h((string) VIRTUSPHERE_VM_LIMITS['ram_mb_min']); ?>" data-ram-max="<?php echo h((string) VIRTUSPHERE_VM_LIMITS['ram_mb_max']); ?>" data-ram-invalid="<?php echo h(__t('vm_edit.ram_invalid')); ?>" data-ram-rounded="<?php echo h(__t('vm_edit.ram_rounded')); ?>" data-ram-preview="<?php echo h(__t('vm_edit.ram_preview')); ?>">
            <input name="vm_ram" type="text" inputmode="decimal" maxlength="16" required data-ram-value<?php echo form_control_attrs('vm_edit', 'vm_ram', null, false, $error); ?> value="<?php echo h($state['value']); ?>" <?php echo $canWrite ? '' : 'readonly'; ?>>
            <select name="vm_ram_unit" data-ram-unit<?php echo form_control_attrs('vm_edit', 'vm_ram_unit', null, false, ''); ?> aria-label="<?php echo h(__t('vm_edit.ram_unit')); ?>" <?php echo $canWrite ? '' : 'disabled'; ?>>
                <?php if ($state['unit'] === '') { ?><option value="" selected><?php echo h(__t('vm_edit.ram_unit')); ?></option><?php } ?>
                <?php foreach (VIRTUSPHERE_RAM_INPUT_FACTORS_MB as $unit => $factor) { ?><option value="<?php echo h($unit); ?>" <?php echo $state['unit'] === $unit ? 'selected' : ''; ?>><?php echo h(strtoupper($unit)); ?></option><?php } ?>
            </select>
            <select data-ram-preset<?php echo form_control_attrs('vm_edit', 'vm_ram_preset', null, false, ''); ?> aria-label="<?php echo h(__t('vm_edit.ram_preset')); ?>" disabled>
                <option value=""><?php echo h(__t('vm_edit.ram_custom')); ?></option>
                <?php foreach (VIRTUSPHERE_RAM_PRESETS_MB as $mb) { ?><option value="<?php echo h((string) $mb); ?>"><?php echo h(vm_ram_format($mb)); ?></option><?php } ?>
            </select>
        </div>
        <small class="hint" id="<?php echo h(form_hint_id('vm_edit', 'ram_group')); ?>"><?php echo h(__t('vm_edit.ram_rounding')); ?></small>
        <output data-ram-output aria-live="polite"></output>
        <?php echo form_error_html('vm_edit', 'vm_ram', null, $error); ?>
    </div>
    <?php
}
