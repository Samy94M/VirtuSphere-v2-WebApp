<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_yaml.php';
require_once __DIR__ . '/repo/vms_validation.php';

const VIRTUSPHERE_EFFECTIVE_SOURCE_VM_OVERRIDE = 'vm_override';
const VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION = 'mission';
const VIRTUSPHERE_EFFECTIVE_SOURCE_VM_SETTING = 'vm_setting';
const VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION_BLOCK = 'mission_block';
const VIRTUSPHERE_EFFECTIVE_SOURCE_TARGET_HOST = 'target_host';
const VIRTUSPHERE_EFFECTIVE_SOURCE_UNAVAILABLE = 'unavailable';

/** @return array{value:string,source:string,parent:string} */
function portal_effective_descriptor(string $value, string $source, string $parent = ''): array
{
    if (!in_array($source, [
        VIRTUSPHERE_EFFECTIVE_SOURCE_VM_OVERRIDE,
        VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION,
        VIRTUSPHERE_EFFECTIVE_SOURCE_VM_SETTING,
        VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION_BLOCK,
        VIRTUSPHERE_EFFECTIVE_SOURCE_TARGET_HOST,
        VIRTUSPHERE_EFFECTIVE_SOURCE_UNAVAILABLE,
    ], true)) {
        throw new InvalidArgumentException('Unknown effective-value source.');
    }

    return ['value' => $value, 'source' => $source, 'parent' => $parent];
}

/** @param array{value:string,source:string,parent:string} $descriptor */
function portal_effective_value_text(array $descriptor): string
{
    return match ($descriptor['source']) {
        VIRTUSPHERE_EFFECTIVE_SOURCE_VM_OVERRIDE => $descriptor['parent'] !== ''
            ? __t('vm_edit.effective_vm_override', ['value' => $descriptor['value'], 'mission' => $descriptor['parent']])
            : __t('vm_edit.effective_vm_override_no_mission', ['value' => $descriptor['value']]),
        VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION => __t('vm_edit.effective_mission', ['value' => $descriptor['value']]),
        VIRTUSPHERE_EFFECTIVE_SOURCE_VM_SETTING => __t('vm_edit.effective_vm_setting', ['value' => $descriptor['value']]),
        VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION_BLOCK => __t('vm_edit.effective_mission_block', [
            'value' => $descriptor['value'],
            'vm' => $descriptor['parent'],
        ]),
        VIRTUSPHERE_EFFECTIVE_SOURCE_TARGET_HOST => __t('vm_edit.effective_target_host'),
        VIRTUSPHERE_EFFECTIVE_SOURCE_UNAVAILABLE => __t('vm_edit.effective_unavailable'),
        default => throw new LogicException('Unrenderable effective-value source.'),
    };
}

/** @return array{value:string,source:string,parent:string} */
function portal_vm_effective_location(string $field, array $mission, array $vm): array
{
    $map = [
        'vm_datastore' => 'hypervisor_datastorage',
        'vm_datacenter' => 'hypervisor_datacenter',
    ];
    if (!isset($map[$field])) {
        throw new InvalidArgumentException('Unknown VM location field.');
    }

    $override = trim((string) ($vm[$field] ?? ''));
    $missionValue = trim((string) ($mission[$map[$field]] ?? ''));
    $effective = $field === 'vm_datastore'
        ? ansible_effective_datastore($mission, $vm)
        : ansible_effective_datacenter($mission, $vm);

    if ($override !== '') {
        return portal_effective_descriptor($effective, VIRTUSPHERE_EFFECTIVE_SOURCE_VM_OVERRIDE, $missionValue);
    }
    if ($missionValue !== '') {
        return portal_effective_descriptor($effective, VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION);
    }

    return portal_effective_descriptor(
        '',
        $field === 'vm_datacenter'
            ? VIRTUSPHERE_EFFECTIVE_SOURCE_TARGET_HOST
            : VIRTUSPHERE_EFFECTIVE_SOURCE_UNAVAILABLE
    );
}

/** @return array<string, array{value:string,source:string,parent:string}> */
function portal_vm_effective_values(array $mission, array $vm): array
{
    $missionPolicy = ansible_mission_autostart($mission);
    $vmPolicy = ansible_vm_autostart($vm, $mission);
    $vmEnabled = repo_vm_autostart_flag($vm, 'autostart_enabled') === 1;
    $on = static fn (bool $value): string => __t($value ? 'common.yes' : 'common.no');
    $seconds = static fn (int $value): string => __t('vm_edit.effective_seconds', ['seconds' => $value]);

    $enabled = $missionPolicy['enabled']
        ? portal_effective_descriptor($on($vmPolicy['enabled']), VIRTUSPHERE_EFFECTIVE_SOURCE_VM_SETTING)
        : portal_effective_descriptor($on(false), VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION_BLOCK, $on($vmEnabled));

    $values = [
        'datastore' => portal_vm_effective_location('vm_datastore', $mission, $vm),
        'datacenter' => portal_vm_effective_location('vm_datacenter', $mission, $vm),
        'autostart' => $enabled,
    ];
    foreach ([
        'start_delay' => 'autostart_start_delay',
        'stop_delay' => 'autostart_stop_delay',
    ] as $key => $field) {
        $vmDelay = repo_vm_delay_value($vm, $field);
        $missionDelay = (int) $missionPolicy[$key];
        $values[$key] = $vmDelay === VIRTUSPHERE_AUTOSTART_DELAY_INHERIT
            ? portal_effective_descriptor($seconds($missionDelay), VIRTUSPHERE_EFFECTIVE_SOURCE_MISSION)
            : portal_effective_descriptor($seconds($vmDelay), VIRTUSPHERE_EFFECTIVE_SOURCE_VM_OVERRIDE, $seconds($missionDelay));
    }

    return $values;
}

/**
 * @param array<string, array{value:string,source:string,parent:string}> $values
 */
function portal_render_vm_effective_values(array $values, array $mission, bool $canWrite, string $missionDetailsUrl): void
{
    $rows = [
        'datastore' => ['label' => __t('vm_edit.label_datastore'), 'control' => 'vm_datastore', 'format' => 'plain'],
        'datacenter' => ['label' => __t('vm_edit.label_datacenter'), 'control' => 'vm_datacenter', 'format' => 'plain'],
        'autostart' => ['label' => __t('vm_edit.autostart_enabled'), 'control' => 'autostart_enabled', 'format' => 'toggle'],
        'start_delay' => ['label' => __t('vm_edit.autostart_start_delay'), 'control' => 'autostart_start_delay', 'format' => 'seconds'],
        'stop_delay' => ['label' => __t('vm_edit.autostart_stop_delay'), 'control' => 'autostart_stop_delay', 'format' => 'seconds'],
    ];
    $parents = [
        'datastore' => trim((string) ($mission['hypervisor_datastorage'] ?? '')),
        'datacenter' => trim((string) ($mission['hypervisor_datacenter'] ?? '')),
        'autostart' => (int) ($mission['autostart_enabled'] ?? 0) === 1 ? '1' : '0',
        'start_delay' => (string) ((int) ($mission['autostart_start_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)),
        'stop_delay' => (string) ((int) ($mission['autostart_stop_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)),
    ];
    ?>
    <div class="alert alert-info" data-effective-values
         data-template-override="<?php echo h(__t('vm_edit.effective_vm_override')); ?>"
         data-template-override-empty="<?php echo h(__t('vm_edit.effective_vm_override_no_mission')); ?>"
         data-template-mission="<?php echo h(__t('vm_edit.effective_mission')); ?>"
         data-template-vm="<?php echo h(__t('vm_edit.effective_vm_setting')); ?>"
         data-template-blocked="<?php echo h(__t('vm_edit.effective_mission_block')); ?>"
         data-template-host="<?php echo h(__t('vm_edit.effective_target_host')); ?>"
         data-template-unavailable="<?php echo h(__t('vm_edit.effective_unavailable')); ?>"
         data-template-seconds="<?php echo h(__t('vm_edit.effective_seconds')); ?>"
         data-value-on="<?php echo h(__t('common.yes')); ?>"
         data-value-off="<?php echo h(__t('common.no')); ?>">
        <strong><?php echo h(__t('vm_edit.effective_heading')); ?></strong>
        <p><?php echo h(__t('vm_edit.effective_scope')); ?></p>
        <?php foreach ($rows as $key => $row) {
            $resettable = $canWrite && $row['format'] !== 'toggle'; ?>
            <p data-effective-row="<?php echo h($key); ?>"
               data-effective-control="<?php echo h($row['control']); ?>"
               data-effective-format="<?php echo h($row['format']); ?>"
               data-effective-parent="<?php echo h($parents[$key]); ?>"
               data-effective-empty-source="<?php echo h($key === 'datacenter' ? 'target_host' : 'unavailable'); ?>">
                <span class="hint-subject"><?php echo h($row['label']); ?>:</span>
                <span data-effective-output><?php echo h(portal_effective_value_text($values[$key])); ?></span>
                <?php if ($resettable) { ?>
                    <button class="button-as-link" type="button" data-effective-reset="<?php echo h($row['control']); ?>" hidden><?php echo h(__t('vm_edit.effective_reset')); ?></button>
                <?php } ?>
            </p>
        <?php } ?>
        <p><a href="<?php echo h($missionDetailsUrl); ?>"><?php echo h(__t('vm_edit.effective_mission_link')); ?></a></p>
    </div>
    <?php
}
