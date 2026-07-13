<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/repo/esxi_inventory.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/inventory_field.php';
require_once __DIR__ . '/../lib/mission_transfer.php';

$user = portal_require_user($connection);
$missionId = request_int($_GET, 'id');
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to('missions.php?type=missions');
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    // Export is a read-only view of the same data the page already shows, so it
    // is allowed for any signed-in user (parity with page visibility) and runs
    // before the write gate. It streams a JSON download and never redirects.
    if (request_string($_POST, 'action') === 'export') {
        try {
            $payload = mission_export_payload($connection, $missionId);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $mission['mission_name']);
            $safeName = trim((string) $safeName, '_');
            if ($safeName === '') {
                $safeName = 'mission';
            }
            // A full-mission download (VMs, NICs, disks, packages), allowed for any
            // signed-in user and therefore worth a record of who took a copy.
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'exported mission id ' . $missionId . ' ("' . audit_snippet($mission['mission_name']) . '") as JSON', (int) $user['id']);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="mission-' . $safeName . '-' . date('Ymd-His') . '.json"');
            header('Content-Length: ' . strlen($json));
            header('X-Content-Type-Options: nosniff');
            echo $json;
            exit;
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
            redirect_to('mission_details.php?id=' . $missionId);
        }
    }

    if (!can('missions.write', $user)) {
        portal_forbid($connection, $user, 'missions.write');
    }

    try {
        $action = request_string($_POST, 'action');
        if ($action === 'update') {
            $name = request_trimmed($_POST, 'mission_name');
            if ($isTemplate && $name !== '' && !mission_name_is_template($name)) {
                throw new ValidationException(['mission_name' => __t('mission_details.err_template_prefix_keep')]);
            }
            if (!$isTemplate && mission_name_is_template($name)) {
                throw new ValidationException(['mission_name' => __t('mission_details.err_mission_prefix')]);
            }
            $missionChanges = [
                'mission_name' => $name,
                'mission_notes' => request_string($_POST, 'mission_notes'),
                'wds_vlan' => request_string($_POST, 'wds_vlan'),
                'hypervisor_datastorage' => request_string($_POST, 'hypervisor_datastorage'),
                'hypervisor_datacenter' => request_string($_POST, 'hypervisor_datacenter'),
                'domain' => request_string($_POST, 'domain'),
                // Autostart defaults (ADR-0025). The checkboxes ship a hidden "0",
                // so an unchecked box arrives as 0 rather than as an absent key.
                'autostart_enabled' => request_string($_POST, 'autostart_enabled', '0'),
                'autostart_start_delay' => request_string($_POST, 'autostart_start_delay'),
                'autostart_stop_delay' => request_string($_POST, 'autostart_stop_delay'),
                'autostart_stop_action' => request_string($_POST, 'autostart_stop_action'),
                'autostart_wait_for_heartbeat' => request_string($_POST, 'autostart_wait_for_heartbeat', '0'),
            ];
            repo_update_mission_checked($connection, $missionId, $missionChanges, request_string($_POST, 'updated_at'), !$isTemplate);
            // $mission holds the pre-update row (loaded before the POST branch), so
            // the diff names which columns changed and from what: this is the entry
            // that answers "who moved the mission to the wrong datastore". Notes are
            // opaque (value withheld, "changed" only).
            $missionDiff = audit_change_summary($mission, $missionChanges, ['mission_notes']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'updated mission id ' . $missionId . audit_change_note($missionDiff), (int) $user['id']);
            flash_set('success', __t('mission_details.flash_saved'));
            redirect_to('mission_details.php?id=' . $missionId);
        }
        if ($action === 'clone_template') {
            $result = repo_clone_template_to_new_mission($connection, $missionId, request_string($_POST, 'target_mission_name'), (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'cloned template mission id ' . $missionId . ' to mission id ' . $result['target_mission_id'], (int) $user['id']);
            flash_set('success', __t('mission_details.flash_cloned', ['count' => (int) $result['created']]));
            redirect_to('mission_details.php?id=' . $result['target_mission_id']);
        }
        if ($action === 'save_as_template') {
            $result = repo_save_mission_as_template($connection, $missionId, request_string($_POST, 'target_template_name'), (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'saved mission id ' . $missionId . ' as template mission id ' . $result['target_mission_id'], (int) $user['id']);
            flash_set('success', __t('mission_details.flash_saved_as_template', ['count' => (int) $result['created']]));
            redirect_to('mission_details.php?id=' . $result['target_mission_id']);
        }
    } catch (ValidationException $exception) {
        $message = portal_error_message($exception);
        if (($_POST['action'] ?? '') === 'clone_template') {
            // The clone form has a single target_mission_name input, so surface any
            // mission validation error against that field instead of mission_name.
            form_remember('clone', $_POST, ['target_mission_name' => $message]);
        } elseif (($_POST['action'] ?? '') === 'save_as_template') {
            form_remember('save_template', $_POST, ['target_template_name' => $message]);
        } else {
            form_remember('update', $_POST, $exception->errors());
        }
        flash_set('error', $message);
        redirect_to('mission_details.php?id=' . $missionId);
    } catch (Throwable $exception) {
        $message = portal_error_message($exception);
        if (($_POST['action'] ?? '') === 'clone_template') {
            form_remember('clone', $_POST, ['target_mission_name' => $message]);
        } elseif (($_POST['action'] ?? '') === 'save_as_template') {
            form_remember('save_template', $_POST, ['target_template_name' => $message]);
        }
        flash_set('error', $message);
        redirect_to('mission_details.php?id=' . $missionId);
    }
}

// ESXi-owned VLAN catalog: offer active entries; the stored value stays selectable
// even if retired/unknown (decoupling, ADR-0023). Datacenter/datastore are the
// same kind of hard select over the inventory cache (see inventory_field.php).
$vlans = repo_active_vlans($connection);
$storedVlan = (string) ($mission['wds_vlan'] ?? '');
$datacenterOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATACENTER);
$datastoreOptions = esxi_inventory_options($connection, VIRTUSPHERE_INVENTORY_KIND_DATASTORE);

$datacenterValue = form_old('update', 'hypervisor_datacenter', (string) ($mission['hypervisor_datacenter'] ?? ''));
$datastoreValue = form_old('update', 'hypervisor_datastorage', (string) ($mission['hypervisor_datastorage'] ?? ''));
// The datastore is mandatory and has no fallback, so a lone unambiguous value is
// preselected as a convenience. The datacenter deliberately gets NO preselect:
// the deploy resolves an empty one from the target host, and pre-filling it would
// store a copy of a derivable value - exactly the defect migration 0014 removed
// one level below.
if (!$isTemplate && $datastoreValue === '' && esxi_inventory_options_are_exact($datastoreOptions) && count($datastoreOptions['names']) === 1) {
    $datastoreValue = $datastoreOptions['names'][0];
}
// Every possible deploy target reports the same single datacenter (a standalone
// host's implicit `ha-datacenter`), so a stored value could not point anywhere
// else and the deploy derives it. Hide the control, but keep posting the value:
// an unrendered field would come back as '' and wipe an existing one. Same rule
// as $hideVmDatacenter in vm_edit.php, and deliberately on the form_old value,
// so a failed validation with a filled datacenter shows the field again.
$hideMissionDatacenter = $datacenterValue === ''
    && esxi_inventory_options_are_exact($datacenterOptions)
    && count($datacenterOptions['names']) === 1;
// Only warn when the credentials really disagree, or when one of them has never
// been pulled. Three hosts that all report the same names need no warning.
$showTargetHostHint = $datacenterOptions['credential_count'] > 1
    && (!esxi_inventory_options_are_exact($datacenterOptions) || !esxi_inventory_options_are_exact($datastoreOptions));

layout_header($isTemplate ? __t('mission_details.title_template') : __t('mission_details.title_mission'), $user, $isTemplate ? 'templates' : 'missions', 'missions');
?>
<div class="stack">
    <section class="panel">
        <div class="actions">
            <a class="button button-secondary" href="<?php echo $isTemplate ? 'missions.php?type=templates' : 'missions.php?type=missions'; ?>"><?php echo h(__t('common.back')); ?></a>
            <a class="button button-secondary" href="vms.php?mission_id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('common.vms')); ?></a>
            <form class="inline-form" method="post" action="mission_details.php?id=<?php echo h((string) $missionId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="export">
                <button class="button button-secondary" type="submit" title="<?php echo h(__t('mission_details.export_title')); ?>"><?php echo h(__t('mission_details.export_json')); ?></button>
            </form>
        </div>
        <p class="muted"><?php echo h(__t('mission_details.export_hint')); ?></p>
    </section>

    <section class="panel">
        <h2><?php echo h($mission['mission_name'] ?? ''); ?></h2>
        <form class="stack" method="post" action="mission_details.php?id=<?php echo h((string) $missionId); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="updated_at" value="<?php echo h($mission['updated_at'] ?? ''); ?>">
            <div class="form-grid">
                <label><?php echo h(__t('common.name')); ?><input name="mission_name" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('update', 'mission_name', (string) ($mission['mission_name'] ?? ''))); ?>" required <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'mission_name'); ?></label>
                <label><?php echo h(__t('mission_details.label_wds_vlan')); ?><?php vlan_select_field('wds_vlan', $storedVlan, $vlans, [
                    'none' => __t('mission_details.vlan_none'),
                    'unknown_suffix' => __t('mission_details.vlan_not_in_inventory'),
                ], !can('missions.write', $user)); ?></label>
                <?php
                    // The location hints belong under the two controls they explain, so the
                    // fields and their prose form one group spanning two grid tracks. A hint
                    // inside the datacenter cell would set the height of the whole first row;
                    // one as a full-width row would start at the far left, under "Name".
                    $locationHints = [];
                    if (!$hideMissionDatacenter) {
                        $locationHints[] = [
                            __t('mission_details.label_datacenter'),
                            __t('mission_details.datacenter_optional_hint'),
                        ];
                    }
                    if ($showTargetHostHint) {
                        $multiCredentialSubject = [__t('mission_details.label_datastore')];
                        if (!$hideMissionDatacenter) {
                            $multiCredentialSubject[] = __t('mission_details.label_datacenter');
                        }
                        $locationHints[] = [
                            implode(' / ', $multiCredentialSubject),
                            __t('mission_details.location_multi_credential_hint'),
                        ];
                    }
                    // A lone datastore without prose is an ordinary field, not a group.
                    $groupLocationFields = !$hideMissionDatacenter || $locationHints !== [];
                ?>
                <?php if ($groupLocationFields) { ?><div class="field-group"><?php } ?>
                <label><?php echo h(__t('mission_details.label_datastore')); ?><?php inventory_select_field($datastoreOptions, [
                    'name' => 'hypervisor_datastorage',
                    'value' => $datastoreValue,
                    'empty_label' => __t('mission_details.datastore_select'),
                    'unknown_suffix' => __t('mission_details.location_not_in_inventory'),
                    'required' => !$isTemplate,
                    'disabled' => !can('missions.write', $user),
                ]); ?><?php echo form_error_html('update', 'hypervisor_datastorage'); ?></label>
                <?php if ($hideMissionDatacenter) { ?>
                    <input type="hidden" name="hypervisor_datacenter" value="<?php echo h($datacenterValue); ?>">
                <?php } else { ?>
                    <label><?php echo h(__t('mission_details.label_datacenter')); ?><?php inventory_select_field($datacenterOptions, [
                        'name' => 'hypervisor_datacenter',
                        'value' => $datacenterValue,
                        'empty_label' => __t('mission_details.datacenter_from_host'),
                        'unknown_suffix' => __t('mission_details.location_not_in_inventory'),
                        'disabled' => !can('missions.write', $user),
                    ]); ?><?php echo form_error_html('update', 'hypervisor_datacenter'); ?></label>
                <?php } ?>
                <?php foreach ($locationHints as [$hintSubject, $hintText]) { ?>
                    <p class="hint"><span class="hint-subject"><?php echo h($hintSubject); ?>:</span> <?php echo h($hintText); ?></p>
                <?php } ?>
                <?php if ($groupLocationFields) { ?></div><?php } ?>
                <label><?php echo h(__t('mission_details.label_domain')); ?><input name="domain" value="<?php echo h(form_old('update', 'domain', (string) ($mission['domain'] ?? ''))); ?>" pattern="<?php echo h(VIRTUSPHERE_FQDN_INPUT_PATTERN); ?>" title="<?php echo h(__t('mission_details.domain_title')); ?>" autocomplete="off" spellcheck="false" <?php echo $isTemplate ? '' : 'required'; ?> <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'domain'); ?></label>
                <?php $missionCreator = (string) ($mission['mission_creator'] ?? ''); ?>
                <label><?php echo h(__t('mission_details.label_creator')); ?><input value="<?php echo h($missionCreator); ?>" placeholder="<?php echo h(__t('common.creator_unknown')); ?>" readonly></label>
                <label class="form-grid-span-2"><?php echo h(__t('mission_details.label_notes')); ?><textarea name="mission_notes" <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo h(form_old('update', 'mission_notes', (string) ($mission['mission_notes'] ?? ''))); ?></textarea><?php echo form_error_html('update', 'mission_notes'); ?></label>

                <?php
                // ESXi autostart defaults (ADR-0025). These become the target host's
                // system_defaults, so every VM of the mission inherits them unless it
                // stores an own value.
                $missionWrite = can('missions.write', $user);
                $autostartOn = form_old('update', 'autostart_enabled', (string) ((int) ($mission['autostart_enabled'] ?? 0))) === '1';
                $waitHeartbeatOn = form_old('update', 'autostart_wait_for_heartbeat', (string) ((int) ($mission['autostart_wait_for_heartbeat'] ?? 0))) === '1';
                $missionStopAction = form_old('update', 'autostart_stop_action', (string) ($mission['autostart_stop_action'] ?? VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS['autostart_stop_action']));
                ?>
                <div class="form-grid-full">
                    <span class="field-label"><?php echo h(__t('mission_details.autostart_heading')); ?></span>
                    <div class="checkbox-grid checkbox-grid-aligned">
                        <label class="checkbox-item">
                            <input type="hidden" name="autostart_enabled" value="0">
                            <input type="checkbox" name="autostart_enabled" value="1" <?php echo $autostartOn ? 'checked' : ''; ?> <?php echo $missionWrite ? '' : 'disabled'; ?>>
                            <?php echo h(__t('mission_details.autostart_enabled')); ?>
                        </label>
                        <label class="checkbox-item">
                            <input type="hidden" name="autostart_wait_for_heartbeat" value="0">
                            <input type="checkbox" name="autostart_wait_for_heartbeat" value="1" <?php echo $waitHeartbeatOn ? 'checked' : ''; ?> <?php echo $missionWrite ? '' : 'disabled'; ?>>
                            <?php echo h(__t('mission_details.autostart_wait_heartbeat')); ?>
                        </label>
                    </div>
                    <p class="hint"><?php echo h(__t('mission_details.autostart_hint')); ?></p>
                    <p class="hint"><span class="hint-subject"><?php echo h(__t('mission_details.autostart_wait_heartbeat')); ?>:</span> <?php echo h(__t('mission_details.autostart_heartbeat_hint')); ?></p>
                </div>
                <label><?php echo h(__t('mission_details.autostart_start_delay')); ?><input type="number" name="autostart_start_delay" min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo h(form_old('update', 'autostart_start_delay', (string) ((int) ($mission['autostart_start_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)))); ?>" <?php echo $missionWrite ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'autostart_start_delay'); ?></label>
                <label><?php echo h(__t('mission_details.autostart_stop_delay')); ?><input type="number" name="autostart_stop_delay" min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo h(form_old('update', 'autostart_stop_delay', (string) ((int) ($mission['autostart_stop_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)))); ?>" <?php echo $missionWrite ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'autostart_stop_delay'); ?></label>
                <label><?php echo h(__t('mission_details.autostart_stop_action')); ?>
                    <select name="autostart_stop_action" <?php echo $missionWrite ? '' : 'disabled'; ?>>
                        <?php foreach (VIRTUSPHERE_AUTOSTART_STOP_ACTIONS as $stopActionValue) { ?>
                            <option value="<?php echo h($stopActionValue); ?>" <?php echo $missionStopAction === $stopActionValue ? 'selected' : ''; ?>><?php echo h(__t('mission_details.autostart_stop_' . $stopActionValue)); ?></option>
                        <?php } ?>
                    </select>
                    <?php echo form_error_html('update', 'autostart_stop_action'); ?>
                </label>
                <p class="hint form-grid-span-2"><?php echo h(__t('mission_details.autostart_delay_hint')); ?></p>
            </div>
            <?php if (can('missions.write', $user)) { ?><div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div><?php } ?>
        </form>
    </section>

    <?php if ($isTemplate && can('missions.write', $user)) { ?>
        <section class="panel">
            <h2><?php echo h(__t('mission_details.copy_to_mission')); ?></h2>
            <form class="form-grid" method="post" action="mission_details.php?id=<?php echo h((string) $missionId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clone_template">
                <label><?php echo h(__t('mission_details.target_mission_name')); ?><input name="target_mission_name" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('clone', 'target_mission_name')); ?>"<?php echo form_input_class('clone', 'target_mission_name'); ?> required><?php echo form_error_html('clone', 'target_mission_name'); ?></label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('mission_details.copy')); ?></button></div>
            </form>
        </section>
    <?php } ?>

    <?php if (!$isTemplate && can('missions.write', $user)) { ?>
        <section class="panel">
            <h2><?php echo h(__t('mission_details.save_as_template')); ?></h2>
            <p class="muted"><?php echo h(__t('mission_details.save_as_template_hint')); ?></p>
            <form class="form-grid" method="post" action="mission_details.php?id=<?php echo h((string) $missionId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_as_template">
                <label><?php echo h(__t('mission_details.template_name')); ?><input name="target_template_name" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('save_template', 'target_template_name', VIRTUSPHERE_TEMPLATE_PREFIX . ($mission['mission_name'] ?? ''))); ?>"<?php echo form_input_class('save_template', 'target_template_name'); ?> required><?php echo form_error_html('save_template', 'target_template_name'); ?></label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('mission_details.save_as_template')); ?></button></div>
            </form>
        </section>
    <?php } ?>
</div>
<?php layout_footer(); ?>