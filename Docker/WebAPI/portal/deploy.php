<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/ansible.php';
require_once __DIR__ . '/../lib/deploy_form_state.php';
require_once __DIR__ . '/../lib/deploy_page.php';
require_once __DIR__ . '/../lib/deploy_storage.php';
require_once __DIR__ . '/../lib/repo/credentials.php';
require_once __DIR__ . '/../lib/repo/deploy_jobs.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/esxi_inventory.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/esxi_capabilities.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);
if (!can('deploy.run', $user)) {
    portal_forbid($connection, $user, 'deploy.run');
}

// From the same source as the rest of the form (lib/deploy_form_state.php).
// Reading only $_GET left the preview render and the JS-less submit drawing one
// mission's form around another mission's VM list.
$selectedMissionId = (int) deploy_form_value('mission_id', '0');

$redirectBase = 'deploy.php' . ($selectedMissionId > 0 ? '?mission_id=' . $selectedMissionId : '');
$deployPreview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    try {
        $action = request_string($_POST, 'action');
        if ($action === 'start') {
            ansible_resolve_api_base_url($connection);
            $missionIdPost = request_int($_POST, 'mission_id');
            $esxiId = request_int($_POST, 'credential_esxi_id');
            $ansibleId = request_int($_POST, 'credential_ansible_id');
            // Normalized once, then used everywhere this handler needs it. The
            // gate below used to read $_POST a second time and un-normalized,
            // so a mode differing only in case took a different branch here
            // than in the enqueue path that follows it: the portal refused an
            // autostart job the backend would have queued, which is the
            // disagreeing-twin defect the gate exists to prevent.
            $mode = deploy_job_normalize_mission_mode(request_string($_POST, 'mode', VIRTUSPHERE_DEPLOY_MODE_FULL));
            $payloadData = [
                'mode' => $mode,
                'verbose' => $_POST['verbose'] ?? false,
                'vm_ids' => is_array($_POST['vm_ids'] ?? null) ? $_POST['vm_ids'] : [],
                'powercycle_wait' => $_POST['powercycle_wait'] ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT,
                'start_wait' => $_POST['start_wait'] ?? VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT,
            ];
            $schedule = deploy_parse_schedule($_POST);
            $confirmed = ($_POST['confirmed'] ?? '') === '1';

            // Before the branch on purpose: the preview path never reaches a
            // readiness gate, so without this a mission whose datacenter cannot
            // be resolved would show a schedule preview and only fail on confirm.
            deploy_assert_datacenter_resolvable($connection, $missionIdPost, $esxiId, $mode);

            if ($schedule['has_schedule'] && !$confirmed) {
                // Show a preview of the computed start times; do NOT redirect so
                // the confirm step re-submits with confirmed=1 (B3.3).
                // The storage table is rendered server-side here: the credential
                // and the VM selection are known, so its verdict is authoritative
                // even with JavaScript disabled. It informs, it never blocks.
                $previewMission = repo_get_mission($connection, $missionIdPost);
                $deployPreview = [
                    'schedule' => $schedule,
                    'rows' => deploy_preview_rows($connection, $missionIdPost, $payloadData, $schedule),
                    'storage' => $previewMission !== null
                        ? ansible_storage_by_datastore($previewMission, deploy_selected_vms(getVMs($connection, $missionIdPost), $payloadData['vm_ids']))
                        : [],
                    'capacity' => deploy_datastore_capacity($connection, $esxiId),
                ];
            } elseif ($schedule['stagger'] !== null) {
                $result = repo_enqueue_deploy_group($connection, $missionIdPost, (int) $user['id'], $esxiId, $ansibleId, $payloadData, $schedule['base_utc'], $schedule['stagger']);
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'queued deploy group ' . $result['group_id'] . ' (' . $result['count'] . ' jobs)', (int) $user['id']);
                flash_set('success', __t('deploy.flash_group_queued', ['count' => $result['count']]));
                redirect_to($redirectBase);
            } else {
                $jobId = repo_create_deploy_job($connection, $missionIdPost, (int) $user['id'], $esxiId, $ansibleId, $payloadData, $schedule['base_utc']);
                audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'queued deploy job id ' . $jobId . ($schedule['base_utc'] !== null ? ' (scheduled)' : ''), (int) $user['id']);
                if ($schedule['base_utc'] !== null) {
                    flash_set('success', __t('deploy.flash_scheduled'));
                    redirect_to($redirectBase);
                }
                flash_set('success', __t('deploy.flash_queued'));
                redirect_to('deploy_log.php?id=' . $jobId);
            }
        } elseif ($action === 'cancel') {
            $jobId = request_int($_POST, 'job_id');
            repo_cancel_deploy_job($connection, $jobId, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'cancelled deploy job id ' . $jobId, (int) $user['id']);
            flash_set('success', __t('deploy.flash_cancelled'));
            redirect_to($redirectBase);
        } elseif ($action === 'cancel_group') {
            $groupId = request_string($_POST, 'group_id');
            $count = repo_cancel_deploy_group($connection, $groupId, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'cancelled deploy group ' . $groupId . ' (' . $count . ' jobs)', (int) $user['id']);
            flash_set('success', __t('deploy.flash_group_cancelled', ['count' => $count]));
            redirect_to($redirectBase);
        } elseif ($action === 'retry') {
            $jobId = request_int($_POST, 'job_id');
            $newJobId = repo_retry_deploy_job($connection, $jobId, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'queued deploy job id ' . $newJobId . ' (retry of job id ' . $jobId . ')', (int) $user['id']);
            flash_set('success', __t('deploy.flash_retried'));
            redirect_to('deploy_log.php?id=' . $newJobId);
        } else {
            redirect_to($redirectBase);
        }
    } catch (ValidationException $exception) {
        form_remember('schedule', $_POST, $exception->errors());
        flash_set('error', portal_error_message($exception));
        redirect_to($redirectBase);
    } catch (Throwable $exception) {
        // Keep the form context for repo diagnostics too (a double submit, a
        // missing datastore): without this the whole form is discarded, while the
        // same class of failure keeps its context in the ValidationException path.
        form_remember('schedule', $_POST, []);
        flash_set('error', portal_error_message($exception));
        redirect_to($redirectBase);
    }
    // Reaching here means a preview was built (no redirect): fall through to the
    // normal page render, which shows the preview + confirm panel.
}

$missions = array_values(array_filter(getMissions($connection), static function (array $mission): bool {
    return !mission_name_is_template((string) ($mission['mission_name'] ?? ''));
}));
$esxiCredentials = repo_credentials_by_type($connection, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
$ansibleCredentials = repo_credentials_by_type($connection, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
$jobs = repo_deploy_jobs($connection, 100, $selectedMissionId > 0 ? $selectedMissionId : null);
$selectedMission = $selectedMissionId > 0 ? repo_get_mission($connection, $selectedMissionId) : null;
$missionVms = $selectedMission !== null ? getVMs($connection, $selectedMissionId) : [];
$apiBaseUrlReady = true;
$apiBaseUrlError = '';
try {
    ansible_resolve_api_base_url($connection);
} catch (Throwable $exception) {
    $apiBaseUrlReady = false;
    $apiBaseUrlError = portal_error_message($exception);
}
$canQueue = $missions !== [] && $esxiCredentials !== [] && $ansibleCredentials !== [] && $apiBaseUrlReady;
// A selected mission with no VMs has nothing to enqueue; the vms_empty hint below
// explains the disabled button. The repo gate is the JS-less backstop.
$selectedMissionEmpty = $selectedMission !== null && $missionVms === [];
$canQueue = $canQueue && !$selectedMissionEmpty;

// Warn (never block) when the selected mission references datacenter/datastore/
// VLAN values that are not in the ESXi inventory (E4.4).
$selectedMissionDeviates = false;
if ($selectedMissionId > 0) {
    $selectedMissionDeviates = isset(esxi_inventory_deviating_mission_ids($connection)[$selectedMissionId]);
}

// Live per-host warning data (JSON island): which of the mission's values are
// missing from a SPECIFIC credential's inventory. Disjoint from the union
// warning above by construction (values missing everywhere are subtracted in
// esxi_inventory_mission_missing_by_credential), so the two boxes never name
// the same value. Pre-localized here; deploy.js only picks by credential id.
$hostWarnings = [];
if ($selectedMission !== null && $esxiCredentials !== []) {
    $credentialNames = [];
    foreach ($esxiCredentials as $credential) {
        $credentialNames[(int) $credential['id']] = (string) ($credential['name'] ?? '');
    }
    foreach (esxi_inventory_mission_missing_by_credential($connection, $selectedMissionId) as $credId => $missingValues) {
        $hostWarnings[(string) $credId] = __t('deploy.host_missing_warn', [
            'host' => $credentialNames[$credId] ?? ('#' . $credId),
            'values' => implode(', ', $missingValues),
        ]);
    }
}

// Capability warnings per ESXi credential (ADR-0025): what the chosen host cannot
// do, as opposed to $hostWarnings above, which names mission values the host does
// not have. Both are warn-only. Independent of the mission, so it is built for
// every credential regardless of the selection.
$capabilityWarnings = [];
// One query for every credential's state instead of one per credential: the
// same bulk read the credentials page already uses.
$esxiStates = repo_esxi_inventory_states($connection);
foreach ($esxiCredentials as $credential) {
    $credentialId = (int) $credential['id'];
    $messages = [];
    foreach (esxi_capability_warnings($esxiStates[$credentialId] ?? null) as $warning) {
        if ($warning['level'] === 'warning') {
            // The legend sentence, not the short badge label: the consequence
            // belongs to the capability, not to the wrapper. The wrapper used to
            // end in "the job is not blocked", which is false for a free licence
            // (ADR-0025 refuses an autostart write outright), and no single
            // sentence can be true for every capability that may land here.
            // Same key the help renders, so warning and help cannot disagree.
            $messages[] = __t('system_status.cap_legend_' . $warning['key']);
        }
    }
    if ($messages !== []) {
        $capabilityWarnings[(string) $credentialId] = __t('deploy.capability_warn', [
            'host' => (string) ($credential['name'] ?? ('#' . $credentialId)),
            'notes' => implode(' ', $messages),
        ]);
    }
}

// Storage requirement of the selected mission per target datastore, plus the data
// deploy.js needs to keep it live. Warn-only, like every other inventory signal on
// this page: the free-space numbers are as old as the last pull, and no deploy is
// ever blocked by them (ADR-0023).
//
// Over the VMs the form actually has checked, not the whole mission: the
// schedule preview has always computed the requirement of the selection, and a
// queue form that adds up VMs the operator unchecked states a different number
// for the same job.
$storageRows = $selectedMission !== null
    ? ansible_storage_by_datastore($selectedMission, deploy_selected_vms($missionVms, array_keys(deploy_form_vm_selection() ?? [])))
    : [];
$storageIsland = deploy_storage_island($connection, $storageRows, $esxiCredentials);

// Everything below is what the page shows BEFORE deploy.js runs. Without it the
// immediate-job path rendered empty warning boxes and dash-filled storage cells
// for a credential that was already chosen, while the schedule preview (rendered
// server-side) showed the full picture for the same input. JS refreshes all of
// it on the first change; this is the starting state, and the only state a
// browser without JavaScript ever sees.
$selectedEsxiId = deploy_form_value('credential_esxi_id');
$initialHostWarning = $hostWarnings[$selectedEsxiId] ?? '';
$initialCapabilityWarning = $capabilityWarnings[$selectedEsxiId] ?? '';
$initialCapacity = $selectedEsxiId !== '' ? deploy_datastore_capacity($connection, (int) $selectedEsxiId) : [];

// datetime-local `min` in portal wall time keeps most past-time mistakes out.
$scheduleMinLocal = (new DateTimeImmutable('now', new DateTimeZone(portal_timezone())))->format('Y-m-d\TH:i');
$selectedPendingScheduled = 0;
if ($selectedMissionId > 0) {
    $selectedPendingScheduled = (int) repo_scalar(
        $connection,
        "SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ? AND status = 'queued' AND cancelled_at IS NULL AND scheduled_at IS NOT NULL AND scheduled_at > UTC_TIMESTAMP()",
        'i',
        [$selectedMissionId]
    );
}

// Position within a stagger group (i/n) for the job list badges. Ascending id
// is the boot order; total is approximate to the fetched window.
$groupPositions = [];
$byGroup = [];
foreach ($jobs as $job) {
    $groupKey = (string) ($job['group_id'] ?? '');
    if ($groupKey !== '') {
        $byGroup[$groupKey][] = (int) $job['id'];
    }
}
foreach ($byGroup as $ids) {
    sort($ids);
    $total = count($ids);
    foreach ($ids as $pos => $id) {
        $groupPositions[$id] = [$pos + 1, $total];
    }
}

layout_header(__t('deploy.title'), $user, 'deploy', 'deploy');
?>
<div class="stack">
    <?php if ($deployPreview !== null) { ?>
        <section class="panel">
            <h2><?php echo h(__t('deploy.preview_heading')); ?></h2>
            <p class="muted"><?php echo h(__t('deploy.preview_hint', ['tz' => portal_timezone()])); ?></p>
            <div class="table-wrap" tabindex="0"><table>
                <thead><tr><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('deploy.preview_when')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($deployPreview['rows'] as $row) { ?>
                    <tr><td><?php echo h((string) $row['vm_name']); ?></td><td><?php echo h(portal_format_epoch((int) $row['epoch'])); ?></td></tr>
                <?php } ?>
                <?php if ($deployPreview['rows'] === []) { ?><tr><td colspan="2"><?php echo h(__t('deploy.vms_empty')); ?></td></tr><?php } ?>
                </tbody>
            </table></div>
            <?php if ($deployPreview['storage'] !== []) { ?>
                <h3><?php echo h(__t('deploy.storage_heading')); ?></h3>
                <?php deploy_render_storage_table($deployPreview['storage'], $deployPreview['capacity']); ?>
                <p class="hint"><?php echo h(__t('deploy.storage_hint')); ?></p>
            <?php } ?>
            <form method="post" action="<?php echo h($redirectBase); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="confirmed" value="1">
                <?php foreach (VIRTUSPHERE_DEPLOY_QUEUE_FIELDS as $field) { ?>
                    <input type="hidden" name="<?php echo h($field); ?>" value="<?php echo h(deploy_form_value($field)); ?>">
                <?php } ?>
                <?php if (deploy_form_value('verbose') === '1') { ?><input type="hidden" name="verbose" value="1"><?php } ?>
                <?php foreach (array_keys(deploy_form_vm_selection() ?? []) as $vid) { ?><input type="hidden" name="vm_ids[]" value="<?php echo h((string) (int) $vid); ?>"><?php } ?>
                <div class="actions">
                    <button class="button" type="submit"><?php echo h(__t('deploy.preview_confirm')); ?></button>
                    <a class="button button-secondary" href="<?php echo h($redirectBase); ?>"><?php echo h(__t('common.cancel')); ?></a>
                </div>
            </form>
        </section>
    <?php } ?>

    <section class="panel">
        <h2><?php echo h(__t('deploy.queue_heading')); ?></h2>
        <?php // Only when a base resource is truly missing. A missing API base URL has
              // its own actionable box below, and an empty mission is explained by its
              // own hint, so neither should also trigger this generic list. ?>
        <?php if ($missions === [] || $esxiCredentials === [] || $ansibleCredentials === []) { ?>
            <div class="alert alert-info"><?php echo h(__t('deploy.requirements_hint')); ?></div>
        <?php } ?>
        <?php if (!$apiBaseUrlReady) { ?>
            <div class="alert alert-info"><?php echo h($apiBaseUrlError); ?></div>
        <?php } ?>
        <?php if ($selectedMissionDeviates) { ?>
            <div class="alert alert-warning"><?php echo h(__t('deploy.inventory_deviation_warn')); ?></div>
        <?php } ?>
        <form class="form-grid" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="start">
            <label><?php echo h(__t('deploy.label_mission')); ?>
                <select name="mission_id" required data-deploy-mission <?php echo $missions === [] ? 'disabled' : ''; ?>>
                    <option value=""><?php echo h(__t('deploy.select_mission')); ?></option>
                    <?php foreach ($missions as $mission) { ?>
                        <option value="<?php echo h((string) $mission['id']); ?>" <?php echo (int) $mission['id'] === $selectedMissionId ? 'selected' : ''; ?>><?php echo h($mission['mission_name'] ?? ''); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label><?php echo h(__t('deploy.label_esxi')); ?>
                <select name="credential_esxi_id" required data-deploy-esxi <?php echo $esxiCredentials === [] ? 'disabled' : ''; ?>>
                    <option value=""><?php echo h(__t('deploy.select_esxi')); ?></option>
                    <?php foreach ($esxiCredentials as $credential) { ?>
                        <option value="<?php echo h((string) $credential['id']); ?>" <?php echo $selectedEsxiId === (string) $credential['id'] ? 'selected' : ''; ?>><?php echo h($credential['name'] ?? ''); ?></option>
                    <?php } ?>
                </select>
                <?php echo form_error_html('schedule', 'credential_esxi_id'); ?>
            </label>
            <label><?php echo h(__t('deploy.label_ansible')); ?>
                <?php $selectedAnsibleId = deploy_form_value('credential_ansible_id'); ?>
                <select name="credential_ansible_id" required <?php echo $ansibleCredentials === [] ? 'disabled' : ''; ?>>
                    <option value=""><?php echo h(__t('deploy.select_ansible')); ?></option>
                    <?php foreach ($ansibleCredentials as $credential) { ?>
                        <option value="<?php echo h((string) $credential['id']); ?>" <?php echo $selectedAnsibleId === (string) $credential['id'] ? 'selected' : ''; ?>><?php echo h($credential['name'] ?? ''); ?></option>
                    <?php } ?>
                </select>
            </label>
            <?php if ($hostWarnings !== []) { ?>
                <script type="application/json" data-deploy-host-warnings nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
                    // JSON_HEX_TAG: credential names and stored values are user
                    // input; "</script>" must not break out of the island.
                    echo json_encode($hostWarnings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                ?></script>
                <?php // form-grid-full spans the row; without it the alert becomes a narrow cell beside the selects.
                      // role="status" (implies aria-live="polite"): deploy.js swaps the text on
                      // every credential change, and a warning that only appears visually is
                      // one a screen-reader user never learns about before submitting. The
                      // element is in the tree from the start, so the later fill is announced. ?>
                <p class="alert alert-warning form-grid-full" role="status" data-deploy-host-warning <?php echo $initialHostWarning === '' ? 'hidden' : ''; ?>><?php echo h($initialHostWarning); ?></p>
            <?php } ?>
            <?php if ($capabilityWarnings !== []) { ?>
                <?php // Disjoint from the host warnings above: those name mission values
                      // the chosen host does not have, these name what the host cannot do. ?>
                <script type="application/json" data-deploy-capability-warnings nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
                    echo json_encode($capabilityWarnings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                ?></script>
                <p class="alert alert-warning form-grid-full" role="status" data-deploy-capability-warning <?php echo $initialCapabilityWarning === '' ? 'hidden' : ''; ?>><?php echo h($initialCapabilityWarning); ?></p>
            <?php } ?>
            <?php $selectedMode = deploy_form_value('mode', VIRTUSPHERE_DEPLOY_MODE_FULL); ?>
            <label><?php echo h(__t('deploy.label_mode')); ?>
                <select name="mode" required
                        data-stagger-modes="<?php echo h(implode(',', VIRTUSPHERE_DEPLOY_STAGGER_MODES)); ?>"
                        data-powercycle-modes="<?php echo h(implode(',', ansible_modes_using_powercycle())); ?>"
                        data-start-wait-modes="<?php echo h(implode(',', ansible_modes_using_start())); ?>">
                    <?php foreach (virtusphere_deploy_mode_labels() as $modeValue => $modeLabel) { ?>
                        <option value="<?php echo h($modeValue); ?>" <?php echo $selectedMode === (string) $modeValue ? 'selected' : ''; ?>><?php echo h($modeLabel); ?></option>
                    <?php } ?>
                </select>
                <small class="hint" data-stagger-lock hidden><?php echo h(__t('deploy.stagger_lock_hint')); ?></small>
            </label>
            <?php // Wait times and verbosity are one field group: each starts with a
                  // caption so the checkbox lines up with the number inputs, and the long
                  // verbose hint runs under all of them. ?>
            <div class="field-group">
                <label><?php echo h(__t('deploy.label_powercycle_wait')); ?>
                    <input type="number" name="powercycle_wait" value="<?php echo h(deploy_form_value('powercycle_wait', (string) VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT)); ?>" min="<?php echo h((string) VIRTUSPHERE_POWERCYCLE_WAIT_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_POWERCYCLE_WAIT_MAX); ?>" data-powercycle-input>
                    <small class="hint" data-powercycle-lock hidden><span class="hint-subject"><?php echo h(__t('deploy.label_powercycle_wait')); ?>:</span> <?php echo h(__t('deploy.powercycle_lock_hint')); ?></small>
                </label>
                <?php // The pause before the VMs are powered on. Visible because the
                      // right value depends on the MECM cadence of the environment;
                      // the bounds come from the constants the playbook is fed from,
                      // so the field cannot offer a pause the SSH layer would kill. ?>
                <label><?php echo h(__t('deploy.label_start_wait')); ?>
                    <input type="number" name="start_wait" value="<?php echo h(deploy_form_value('start_wait', (string) VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT)); ?>" min="<?php echo h((string) VIRTUSPHERE_START_WAIT_SECONDS_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_START_WAIT_SECONDS_MAX); ?>" data-start-wait-input>
                    <small class="hint"><span class="hint-subject"><?php echo h(__t('deploy.label_start_wait')); ?>:</span> <?php echo h(__t('deploy.start_wait_hint', ['max' => VIRTUSPHERE_START_WAIT_SECONDS_MAX])); ?></small>
                    <small class="hint" data-start-wait-lock hidden><span class="hint-subject"><?php echo h(__t('deploy.label_start_wait')); ?>:</span> <?php echo h(__t('deploy.start_wait_lock_hint')); ?></small>
                </label>
                <div class="field-stack">
                    <span class="field-label"><?php echo h(__t('deploy.verbose_heading')); ?></span>
                    <label class="checkbox-item">
                        <input type="checkbox" name="verbose" value="1" <?php echo deploy_form_value('verbose') === '1' ? 'checked' : ''; ?>>
                        <?php echo h(__t('deploy.label_verbose')); ?>
                    </label>
                </div>
                <p class="hint"><span class="hint-subject"><?php echo h(__t('deploy.label_verbose')); ?>:</span> <?php echo h(__t('deploy.verbose_hint')); ?></p>
            </div>
            <div class="form-grid-full">
                <span class="field-label"><?php echo h(__t('deploy.label_vms')); ?></span>
                <?php if ($selectedMission === null) { ?>
                    <p class="hint"><?php echo h(__t('deploy.vms_select_mission_first')); ?></p>
                <?php } elseif ($missionVms === []) { ?>
                    <p class="hint"><?php echo h(__t('deploy.vms_empty')); ?></p>
                <?php } else { ?>
                    <?php
                        // Reflect exactly what was submitted, so a corrected resubmit
                        // does not silently widen to the whole mission. null means the
                        // render carries no selection (a first render, and a mission
                        // change, whose checkboxes named another mission's VMs): then
                        // every VM starts checked, the original behaviour.
                        $vmSelection = deploy_form_vm_selection();
                        $vmIsChecked = static function (int $id) use ($vmSelection): bool {
                            return $vmSelection === null || isset($vmSelection[$id]);
                        };
                        $allVmsChecked = true;
                        foreach ($missionVms as $vmCheck) {
                            if (!$vmIsChecked((int) ($vmCheck['id'] ?? 0))) { $allVmsChecked = false; break; }
                        }
                    ?>
                    <p class="hint"><?php echo h(__t('deploy.vms_hint')); ?></p>
                    <label class="checkbox-item">
                        <input type="checkbox" data-vm-select-all <?php echo $allVmsChecked ? 'checked' : ''; ?>>
                        <?php echo h(__t('deploy.vms_toggle_all')); ?>
                    </label>
                    <div class="checkbox-grid">
                        <?php foreach ($missionVms as $vm) { $hasMac = !ansible_vm_needs_mac($selectedMission, $vm); ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="vm_ids[]" value="<?php echo h((string) $vm['id']); ?>" <?php echo $vmIsChecked((int) $vm['id']) ? 'checked' : ''; ?>>
                                <?php echo h((string) ($vm['vm_name'] ?? '')); ?>
                                <?php echo portal_badge($hasMac ? 'success' : 'warning', $hasMac ? __t('deploy.mac_present') : __t('deploy.mac_missing')); ?>
                            </label>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
            <?php if ($storageRows !== []) { ?>
                <div class="form-grid-full" data-storage-live>
                    <span class="field-label"><?php echo h(__t('deploy.storage_heading')); ?></span>
                    <?php deploy_render_storage_table($storageRows, $initialCapacity, true); ?>
                    <?php if ($storageIsland !== null) { ?>
                        <script type="application/json" data-deploy-storage nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
                            // JSON_HEX_TAG: datastore names come from ESXi and are
                            // user-controlled; "</script>" must not break out.
                            echo json_encode($storageIsland, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        ?></script>
                    <?php } ?>
                    <p class="hint"><?php echo h(__t('deploy.storage_hint')); ?></p>
                </div>
            <?php } ?>
            <?php // Schedule and staggering are one coupled group (the two-way lock in
                  // deploy.js): the timezone note and the pending warning belong under
                  // them, not as a stray full-width row later. ?>
            <div class="field-group">
                <?php $isScheduled = deploy_form_value('start_mode', 'now') === 'scheduled'; ?>
                <div class="field-stack">
                    <span class="field-label"><?php echo h(__t('deploy.schedule_heading')); ?></span>
                    <label class="checkbox-item">
                        <input type="radio" name="start_mode" value="now" data-schedule-mode <?php echo $isScheduled ? '' : 'checked'; ?>>
                        <?php echo h(__t('deploy.schedule_now')); ?>
                    </label>
                    <label class="checkbox-item">
                        <input type="radio" name="start_mode" value="scheduled" data-schedule-mode <?php echo $isScheduled ? 'checked' : ''; ?>>
                        <?php echo h(__t('deploy.schedule_at')); ?>
                    </label>
                    <label data-schedule-at <?php echo $isScheduled ? '' : 'hidden'; ?>><?php echo h(__t('deploy.schedule_at_label')); ?>
                        <input type="datetime-local" name="scheduled_at" min="<?php echo h($scheduleMinLocal); ?>" value="<?php echo h(deploy_form_value('scheduled_at')); ?>">
                        <?php echo form_error_html('schedule', 'scheduled_at'); ?>
                    </label>
                </div>
                <label><?php echo h(__t('deploy.stagger_label')); ?>
                    <input type="number" name="stagger_minutes" min="<?php echo h((string) VIRTUSPHERE_DEPLOY_STAGGER_MIN); ?>" max="<?php echo h((string) VIRTUSPHERE_DEPLOY_STAGGER_MAX); ?>" value="<?php echo h(deploy_form_value('stagger_minutes')); ?>" data-stagger-input placeholder="<?php echo h(__t('deploy.stagger_placeholder')); ?>">
                    <small class="hint"><?php echo h(__t('deploy.stagger_hint')); ?></small>
                    <?php echo form_error_html('schedule', 'stagger_minutes'); ?>
                </label>
                <p class="hint"><?php echo h(__t('deploy.schedule_tz_hint', ['tz' => portal_timezone()])); ?></p>
                <?php if ($selectedPendingScheduled > 0) { ?>
                    <p class="alert alert-warning"><?php echo h(__t('deploy.schedule_pending_warn', ['count' => $selectedPendingScheduled])); ?></p>
                <?php } ?>
            </div>
            <div class="actions actions-row"><button class="button" type="submit" <?php echo $canQueue ? '' : 'disabled'; ?>><?php echo h(__t('deploy.queue_button')); ?></button></div>
        </form>
    </section>

    <section class="panel">
        <div class="actions">
            <h2><?php echo h(__t('deploy.jobs_heading')); ?></h2>
            <?php // The filter writes the same mission_id as the queue form's select, so
                  // it re-renders that form too; deploy.js carries its values along. ?>
            <form class="inline-form" method="get" action="deploy.php" data-deploy-filter>
                <label class="filter-field"><?php echo h(__t('deploy.filter')); ?>
                    <select name="mission_id">
                        <option value="0"><?php echo h(__t('deploy.all_missions')); ?></option>
                        <?php foreach ($missions as $mission) { ?>
                            <option value="<?php echo h((string) $mission['id']); ?>" <?php echo (int) $mission['id'] === $selectedMissionId ? 'selected' : ''; ?>><?php echo h($mission['mission_name'] ?? ''); ?></option>
                        <?php } ?>
                    </select>
                </label>
                <button class="button button-secondary" type="submit"><?php echo h(__t('deploy.apply')); ?></button>
            </form>
        </div>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('deploy.th_id')); ?></th><th><?php echo h(__t('common.mission')); ?></th><th><?php echo h(__t('common.status')); ?></th><th><?php echo h(__t('deploy.label_mode')); ?></th><th><?php echo h(__t('deploy.th_credentials')); ?></th><th><?php echo h(__t('deploy.th_user')); ?></th><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job) { ?>
                <tr>
                    <td><?php echo h((string) $job['id']); ?></td>
                    <td><?php echo h($job['mission_name'] ?? ''); ?></td>
                    <td>
                        <?php echo portal_badge(deploy_job_status_badge_class((string) $job['status']), (string) ($job['status'] ?? '')); ?>
                        <?php if (($job['group_id'] ?? '') !== '' && isset($groupPositions[(int) $job['id']])) { [$gpos, $gtot] = $groupPositions[(int) $job['id']]; ?>
                            <?php echo portal_badge('info', __t('deploy.group_slot', ['pos' => $gpos, 'total' => $gtot])); ?>
                        <?php } ?>
                        <?php if (!empty($job['scheduled_at'])) { ?>
                            <div class="muted nowrap"><?php echo h(__t('deploy.scheduled_for', ['time' => portal_format_timestamp((string) $job['scheduled_at'])])); ?></div>
                        <?php } ?>
                    </td>
                    <td><?php echo h(deploy_job_payload_summary($job['payload_json'] ?? null)); ?></td>
                    <td><?php echo h(($job['esxi_credential_name'] ?? 'ESXi ?') . ' / ' . ($job['ansible_credential_name'] ?? 'Ansible ?')); ?></td>
                    <td><?php echo h($job['user_name'] ?? ($job['user_id'] ?? '')); ?></td>
                    <td><?php echo h(portal_format_timestamp($job['updated_at'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button button-secondary" href="deploy_log.php?id=<?php echo h((string) $job['id']); ?>"><?php echo h(__t('deploy.log')); ?></a>
                        <?php if (in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) { ?>
                            <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                                <?php // A system job (ESXi inventory) has no mission, so it names itself. ?>
                                <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel', ['name' => (int) ($job['mission_id'] ?? 0) > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')])); ?>" data-confirm-action="<?php echo h(__t('deploy.cancel_job')); ?>"><?php echo h(__t('common.cancel')); ?></button>
                            </form>
                        <?php } ?>
                        <?php if (deploy_job_is_retryable((string) $job['status'], $job['mission_id'] !== null ? (int) $job['mission_id'] : null)) { ?>
                            <?php
                            // A partial job never re-runs the whole deploy: its retry is
                            // export-only (deploy_job_retry_plan), so the question must say
                            // so and name the scope. Sentence picked by count (no "VM(s)");
                            // without a trustworthy failed set (the divergence rule) the
                            // export repeats the original selection and the text says that.
                            $retryName = (string) ($job['mission_name'] ?? '');
                            if ((string) ($job['status'] ?? '') === VIRTUSPHERE_DEPLOY_STATUS_PARTIAL) {
                                $retryResult = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
                                $retryFailedCount = $retryResult !== null && $retryResult['outcome'] === 'partial' ? count($retryResult['failed_vm_ids']) : 0;
                                if ($retryFailedCount === 1) {
                                    $retryConfirm = __t('deploy.confirm_retry_partial_one', ['name' => $retryName]);
                                } elseif ($retryFailedCount > 1) {
                                    $retryConfirm = __t('deploy.confirm_retry_partial_many', ['name' => $retryName, 'count' => $retryFailedCount]);
                                } else {
                                    $retryConfirm = __t('deploy.confirm_retry_partial', ['name' => $retryName]);
                                }
                            } else {
                                $retryConfirm = __t('deploy.confirm_retry', ['name' => $retryName]);
                            }
                            ?>
                            <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                                <button class="button button-secondary" type="submit" data-confirm="<?php echo h($retryConfirm); ?>"><?php echo h(__t('deploy.retry')); ?></button>
                            </form>
                        <?php } ?>
                        <?php if (($job['group_id'] ?? '') !== '' && isset($groupPositions[(int) $job['id']]) && $groupPositions[(int) $job['id']][0] === 1) { ?>
                            <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel_group">
                                <input type="hidden" name="group_id" value="<?php echo h((string) $job['group_id']); ?>">
                                <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel_group')); ?>"><?php echo h(__t('deploy.cancel_group')); ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($jobs === []) { ?><tr><td colspan="8"><?php echo h(__t('deploy.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
