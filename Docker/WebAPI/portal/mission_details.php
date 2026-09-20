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
// For the deep link to the ESXi card of a credential that was never pulled.
require_once __DIR__ . '/../lib/system_status.php';
require_once __DIR__ . '/../lib/deploy_urls.php';
require_once __DIR__ . '/../lib/mission_nav.php';
require_once __DIR__ . '/../lib/mission_details_page.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);
$workContext = portal_work_context($_GET);
$missionId = request_int($_GET, 'id');
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to(portal_work_context_mission_list_url($workContext));
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);
if (!isset($workContext['work_list_type'])) {
    $workContext = portal_work_context(array_merge($workContext, [
        'work_list_type' => $isTemplate ? 'templates' : 'missions',
    ]));
}
$detailsUrl = mission_details_url($missionId, $workContext);
$missionListUrl = portal_work_context_mission_list_url($workContext, $missionId);

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
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'exported',
                'name' => (string) $mission['mission_name'],
            ], (int) $user['id']);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="mission-' . $safeName . '-' . date('Ymd-His') . '.json"');
            header('Content-Length: ' . strlen($json));
            header('X-Content-Type-Options: nosniff');
            echo $json;
            exit;
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
            redirect_to($detailsUrl);
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
            repo_update_mission_checked($connection, $missionId, $missionChanges, request_string($_POST, 'edit_version'), !$isTemplate, requireVersion: true);
            // $mission holds the pre-update row (loaded before the POST branch), so
            // the diff names which columns changed and from what: this is the entry
            // that answers "who moved the mission to the wrong datastore". Notes are
            // opaque (value withheld, "changed" only).
            $missionDiff = audit_change_summary($mission, $missionChanges, ['mission_notes']);
            $auditContext = ['action' => 'updated'];
            if ($missionDiff !== '') {
                $auditContext['changes'] = $missionDiff;
            }
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $auditContext, (int) $user['id']);
            flash_set('success', __t('mission_details.flash_saved'));
            redirect_to($detailsUrl);
        }
        if ($action === 'clone_template') {
            $result = repo_clone_template_to_new_mission($connection, $missionId, request_string($_POST, 'target_mission_name'), (int) $user['id']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'cloned_template',
                'target_mission_id' => (int) $result['target_mission_id'],
            ], (int) $user['id']);
            flash_set('success', __t('mission_details.flash_cloned', ['count' => (int) $result['created']]));
            redirect_to(mission_details_url((int) $result['target_mission_id'], ['work_list_type' => 'missions']));
        }
        if ($action === 'save_as_template') {
            $result = repo_save_mission_as_template($connection, $missionId, request_string($_POST, 'target_template_name'), (int) $user['id']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'saved_as_template',
                'target_mission_id' => (int) $result['target_mission_id'],
            ], (int) $user['id']);
            flash_set('success', __t('mission_details.flash_saved_as_template', ['count' => (int) $result['created']]));
            redirect_to(mission_details_url((int) $result['target_mission_id'], ['work_list_type' => 'templates']));
        }
    } catch (VmNetworkScopeActiveException $exception) {
        $message = __t('mission_details.err_wds_active_job');
        form_remember('update', $_POST, ['wds_vlan' => $message]);
        $action = can('deploy.run', $user)
            ? ['url' => deploy_job_log_url($exception->jobId), 'label' => __t('mission_details.open_active_job')]
            : null;
        flash_set('error', $message, '', $action);
        redirect_to($detailsUrl);
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
        redirect_to($detailsUrl);
    } catch (Throwable $exception) {
        $message = portal_error_message($exception);
        if (($_POST['action'] ?? '') === 'clone_template') {
            form_remember('clone', $_POST, ['target_mission_name' => $message]);
        } elseif (($_POST['action'] ?? '') === 'save_as_template') {
            form_remember('save_template', $_POST, ['target_template_name' => $message]);
        } else {
            // Optimistic-lock and other non-validation failures must preserve
            // the editor just like validation does. The restored form remains
            // explicitly unsaved until the browser compares it with a fresh,
            // confirmed GET baseline.
            form_remember('update', $_POST, []);
        }
        flash_set('error', $message);
        redirect_to($detailsUrl);
    }
}

$viewState = mission_details_view_state($connection, $missionId, $mission, $isTemplate);
['vlans' => $vlans, 'storedVlan' => $storedVlan, 'wdsImpactTotal' => $wdsImpactTotal,
    'wdsImpactCounts' => $wdsImpactCounts,
    'datacenterOptions' => $datacenterOptions, 'datastoreOptions' => $datastoreOptions,
    'datacenterValue' => $datacenterValue, 'datastoreValue' => $datastoreValue,
    'hideMissionDatacenter' => $hideMissionDatacenter, 'locationNotes' => $locationNotes] = $viewState;

// One title per page for tab and heading, as vms.php already builds one. They
// disagreed here: the tab said only "Missionsdetails" while the name sat far
// below as the settings heading, so the H1 named a page, not a mission.
$pageTitle = ($isTemplate ? __t('mission_details.title_template') : __t('mission_details.title_mission'))
    . ': ' . (string) ($mission['mission_name'] ?? '');

layout_header($pageTitle, $user, $isTemplate ? 'templates' : 'missions', 'missions');
?>
<div class="stack">
    <section class="panel">
        <?php // Details and VMs are two pages of one mission, so they are page
              // navigation (lib/mission_nav.php), not buttons; the way back to
              // the list is a different move and stays one. ?>
        <?php echo mission_detail_nav($missionId, $isTemplate, 'details', $workContext); ?>
        <div class="actions">
            <a class="button button-secondary" href="<?php echo h($missionListUrl); ?>"><?php echo h(__t('common.back')); ?></a>
            <?php if (!$isTemplate && can('deploy.run', $user)) { ?><a class="button button-secondary" href="<?php echo h(deploy_mission_url($missionId)); ?>"><?php echo h(__t('mission_details.open_deploy')); ?></a><?php } ?>
            <form class="inline-form" method="post" action="<?php echo h($detailsUrl); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="export">
                <button class="button button-secondary" type="submit" title="<?php echo h(__t('mission_details.export_title')); ?>"><?php echo h(__t('mission_details.export_json')); ?></button>
            </form>
        </div>
        <p class="muted"><?php echo h(__t('mission_details.export_hint')); ?></p>
    </section>

    <section class="panel">
        <?php // The name is the page heading now; this one names the section. ?>
        <h2><?php echo h($isTemplate ? __t('mission_details.heading_settings_template') : __t('mission_details.heading_settings_mission')); ?></h2>
        <form class="stack" method="post" action="<?php echo h($detailsUrl); ?>"<?php echo can('missions.write', $user) ? form_unsaved_attrs('mission-settings', form_has_state('update')) : ''; ?>>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="edit_version" value="<?php echo h($mission['edit_version'] ?? ''); ?>">
            <div class="form-grid">
                <label><?php echo h(__t('common.name')); ?><input name="mission_name"<?php echo form_control_attrs('update', 'mission_name'); ?> pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('update', 'mission_name', (string) ($mission['mission_name'] ?? ''))); ?>" required <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'mission_name'); ?></label>
                <?php $wdsHintId = form_hint_id('update', 'wds_vlan'); ?>
                <label><?php echo h(__t('mission_details.label_wds_vlan')); ?><?php vlan_select_field('wds_vlan', $storedVlan, $vlans, [
                    'none' => __t('mission_details.vlan_none'),
                    'unknown_suffix' => __t('mission_details.vlan_not_in_inventory'),
                ], !can('missions.write', $user), form_control_attrs('update', 'wds_vlan', null, [$wdsHintId], '')); ?>
                    <small class="hint" id="<?php echo h($wdsHintId); ?>"><?php echo h(__t('mission_details.wds_vlan_hint')); ?> <?php echo h(__t('mission_details.wds_vlan_existing_hint')); ?> <?php echo h(__t('mission_details.wds_vlan_impact', [
                        'total' => $wdsImpactTotal,
                        'ready' => $wdsImpactCounts[VIRTUSPHERE_WDS_READY],
                        'missing' => $wdsImpactCounts[VIRTUSPHERE_WDS_MISSION_MISSING] + $wdsImpactCounts[VIRTUSPHERE_WDS_PORTAL_MISSING],
                        'case' => $wdsImpactCounts[VIRTUSPHERE_WDS_PORTAL_CASE_MISMATCH],
                        'ambiguous' => $wdsImpactCounts[VIRTUSPHERE_WDS_PORTAL_AMBIGUOUS],
                    ])); ?></small><?php echo form_error_html('update', 'wds_vlan'); ?>
                </label>
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
                    if ($locationNotes !== []) {
                        $multiCredentialSubject = [__t('mission_details.label_datastore')];
                        if (!$hideMissionDatacenter) {
                            $multiCredentialSubject[] = __t('mission_details.label_datacenter');
                        }
                        // Exhaustive match, no default: a new note token has to
                        // be given a sentence rather than disappearing silently.
                        $locationHints[] = [
                            implode(' / ', $multiCredentialSubject),
                            implode(' ', array_map(static fn (string $note): string => match ($note) {
                                'host_choice' => __t('mission_details.location_host_choice_hint'),
                                'buckets' => __t('mission_details.location_bucket_hint'),
                                'never_pulled' => __t('mission_details.location_never_pulled_hint'),
                            }, $locationNotes)),
                        ];
                    }
                    // A lone datastore without prose is an ordinary field, not a group.
                    $groupLocationFields = !$hideMissionDatacenter || $locationHints !== [];
                    $hasLocationHint = $locationHints !== [];
                ?>
                <?php $locationHintId = form_hint_id('update', 'location_group'); ?>
                <?php if ($groupLocationFields) { ?><div class="field-group" role="group"<?php echo form_control_attrs('update', 'location_group', null, $hasLocationHint ? [$locationHintId] : false, ''); ?>><?php } ?>
                <label><?php echo h(__t('mission_details.label_datastore')); ?><?php inventory_select_field($datastoreOptions, [
                    'name' => 'hypervisor_datastorage',
                    'value' => $datastoreValue,
                    'empty_label' => __t('mission_details.datastore_select'),
                    'unknown_suffix' => __t('mission_details.location_not_in_inventory'),
                    'required' => !$isTemplate,
                    'disabled' => !can('missions.write', $user),
                    'attributes' => form_control_attrs('update', 'hypervisor_datastorage'),
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
                        'attributes' => form_control_attrs('update', 'hypervisor_datacenter'),
                    ]); ?><?php echo form_error_html('update', 'hypervisor_datacenter'); ?></label>
                <?php } ?>
                <?php if ($hasLocationHint) { ?><div id="<?php echo h($locationHintId); ?>"><?php } ?>
                <?php foreach ($locationHints as [$hintSubject, $hintText]) { ?>
                    <p class="hint"><span class="hint-subject"><?php echo h($hintSubject); ?>:</span> <?php echo h($hintText); ?></p>
                <?php } ?>
                <?php if (in_array('never_pulled', $locationNotes, true)) { ?>
                    <?php // The note above names the cause; this is the one place that
                          // can fix it, so the field links there instead of leaving the
                          // operator to find the page. ?>
                    <p class="hint"><a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><?php echo h(__t('mission_details.location_status_link')); ?></a></p>
                <?php } ?>
                <?php if ($hasLocationHint) { ?></div><?php } ?>
                <?php if ($groupLocationFields) { ?></div><?php } ?>
                <label><?php echo h(__t('mission_details.label_domain')); ?><input name="domain"<?php echo form_control_attrs('update', 'domain'); ?> value="<?php echo h(form_old('update', 'domain', (string) ($mission['domain'] ?? ''))); ?>" pattern="<?php echo h(VIRTUSPHERE_FQDN_INPUT_PATTERN); ?>" title="<?php echo h(__t('mission_details.domain_title')); ?>" autocomplete="off" spellcheck="false" <?php echo $isTemplate ? '' : 'required'; ?> <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'domain'); ?></label>
                <?php $missionCreator = (string) ($mission['mission_creator'] ?? ''); ?>
                <label><?php echo h(__t('mission_details.label_creator')); ?><input value="<?php echo h($missionCreator); ?>" placeholder="<?php echo h(__t('common.creator_unknown')); ?>" readonly></label>
                <label class="form-grid-span-2"><?php echo h(__t('mission_details.label_notes')); ?><textarea name="mission_notes"<?php echo form_control_attrs('update', 'mission_notes'); ?> <?php echo can('missions.write', $user) ? '' : 'readonly'; ?>><?php echo h(form_old('update', 'mission_notes', (string) ($mission['mission_notes'] ?? ''))); ?></textarea><?php echo form_error_html('update', 'mission_notes'); ?></label>

                <?php
                // ESXi autostart defaults (ADR-0025). These become the target host's
                // system_defaults, so every VM of the mission inherits them unless it
                // stores an own value.
                $missionWrite = can('missions.write', $user);
                $autostartOn = form_old('update', 'autostart_enabled', (string) ((int) ($mission['autostart_enabled'] ?? 0))) === '1';
                $waitHeartbeatOn = form_old('update', 'autostart_wait_for_heartbeat', (string) ((int) ($mission['autostart_wait_for_heartbeat'] ?? 0))) === '1';
                $missionStopAction = form_old('update', 'autostart_stop_action', (string) ($mission['autostart_stop_action'] ?? VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS['autostart_stop_action']));
                ?>
                <?php $autostartHintId = form_hint_id('update', 'autostart_group'); $heartbeatHintId = form_hint_id('update', 'autostart_heartbeat'); ?>
                <div class="form-grid-full" role="group"<?php echo form_control_attrs('update', 'autostart_group', null, [$autostartHintId, $heartbeatHintId], ''); ?>>
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
                    <p class="hint" id="<?php echo h($autostartHintId); ?>"><?php echo h(__t('mission_details.autostart_hint')); ?></p>
                    <p class="hint" id="<?php echo h($heartbeatHintId); ?>"><span class="hint-subject"><?php echo h(__t('mission_details.autostart_wait_heartbeat')); ?>:</span> <?php echo h(__t('mission_details.autostart_heartbeat_hint')); ?></p>
                </div>
                <?php $delayHintId = form_hint_id('update', 'autostart_delays'); ?>
                <label><?php echo h(__t('mission_details.autostart_start_delay')); ?><input type="number" name="autostart_start_delay"<?php echo form_control_attrs('update', 'autostart_start_delay', null, [$delayHintId]); ?> min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo h(form_old('update', 'autostart_start_delay', (string) ((int) ($mission['autostart_start_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)))); ?>" <?php echo $missionWrite ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'autostart_start_delay'); ?></label>
                <label><?php echo h(__t('mission_details.autostart_stop_delay')); ?><input type="number" name="autostart_stop_delay"<?php echo form_control_attrs('update', 'autostart_stop_delay', null, [$delayHintId]); ?> min="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_AUTOSTART_DELAY_MAX); ?>" value="<?php echo h(form_old('update', 'autostart_stop_delay', (string) ((int) ($mission['autostart_stop_delay'] ?? VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT)))); ?>" <?php echo $missionWrite ? '' : 'readonly'; ?>><?php echo form_error_html('update', 'autostart_stop_delay'); ?></label>
                <label><?php echo h(__t('mission_details.autostart_stop_action')); ?>
                    <select name="autostart_stop_action"<?php echo form_control_attrs('update', 'autostart_stop_action'); ?> <?php echo $missionWrite ? '' : 'disabled'; ?>>
                        <?php foreach (VIRTUSPHERE_AUTOSTART_STOP_ACTIONS as $stopActionValue) { ?>
                            <option value="<?php echo h($stopActionValue); ?>" <?php echo $missionStopAction === $stopActionValue ? 'selected' : ''; ?>><?php echo h(__t('mission_details.autostart_stop_' . $stopActionValue)); ?></option>
                        <?php } ?>
                    </select>
                    <?php echo form_error_html('update', 'autostart_stop_action'); ?>
                </label>
                <p class="hint form-grid-span-2" id="<?php echo h($delayHintId); ?>"><?php echo h(__t('mission_details.autostart_delay_hint')); ?></p>
            </div>
            <?php if (can('missions.write', $user)) { ?><?php echo form_unsaved_status_html(); ?><div class="actions"><button class="button" type="submit"><?php echo h(__t('common.save')); ?></button></div><?php } ?>
        </form>
    </section>

    <?php if ($isTemplate && can('missions.write', $user)) { ?>
        <section class="panel">
            <h2><?php echo h(__t('mission_details.copy_to_mission')); ?></h2>
            <form class="form-grid" method="post" action="<?php echo h($detailsUrl); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clone_template">
                <label><?php echo h(__t('mission_details.target_mission_name')); ?><input name="target_mission_name" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('clone', 'target_mission_name')); ?>"<?php echo form_control_attrs('clone', 'target_mission_name'); ?> required><?php echo form_error_html('clone', 'target_mission_name'); ?></label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('mission_details.copy')); ?></button></div>
            </form>
        </section>
    <?php } ?>

    <?php if (!$isTemplate && can('missions.write', $user)) { ?>
        <section class="panel">
            <h2><?php echo h(__t('mission_details.save_as_template')); ?></h2>
            <p class="muted"><?php echo h(__t('mission_details.save_as_template_hint')); ?></p>
            <form class="form-grid" method="post" action="<?php echo h($detailsUrl); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_as_template">
                <label><?php echo h(__t('mission_details.template_name')); ?><input name="target_template_name" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('save_template', 'target_template_name', VIRTUSPHERE_TEMPLATE_PREFIX . ($mission['mission_name'] ?? ''))); ?>"<?php echo form_control_attrs('save_template', 'target_template_name'); ?> required><?php echo form_error_html('save_template', 'target_template_name'); ?></label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('mission_details.save_as_template')); ?></button></div>
            </form>
        </section>
    <?php } ?>
</div>
<?php layout_footer(); ?>
