<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/format.php';
require_once __DIR__ . '/../lib/mission_transfer.php';
require_once __DIR__ . '/../lib/missions_import_panel.php';
require_once __DIR__ . '/../lib/portal_export.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);
$type = request_string($_GET, 'type', 'missions');
$type = $type === 'templates' ? 'templates' : 'missions';
$isTemplateView = $type === 'templates';
$active = $isTemplateView ? 'templates' : 'missions';
$title = $isTemplateView ? __t('missions.title_templates') : __t('missions.title_missions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    if (!can('missions.write', $user)) {
        portal_forbid($connection, $user, 'missions.write');
    }

    try {
        $action = request_string($_POST, 'action');
        if ($action === 'create') {
            $name = request_trimmed($_POST, 'mission_name');
            if ($type === 'templates') {
                // An empty name carries no prefix, so the repo layer could not tell
                // that this form creates a template. Say so here instead.
                if ($name === '') {
                    throw new ValidationException(['mission_name' => __t('validate.required', ['field' => __t('validate.field_template_name')])]);
                }
                if (!mission_name_is_template($name)) {
                    $name = VIRTUSPHERE_TEMPLATE_PREFIX . $name;
                }
            }
            if ($type === 'missions' && mission_name_is_template($name)) {
                throw new ValidationException(['mission_name' => __t('missions.err_prefix_reserved')]);
            }
            $newMissionId = repo_create_mission($connection, ['mission_name' => $name], false, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'created mission ' . $name, (int) $user['id']);
            flash_set('success', $isTemplateView ? __t('missions.flash_created_template') : __t('missions.flash_created_mission'));
            redirect_to('mission_details.php?id=' . $newMissionId);
        } elseif ($action === 'delete') {
            $missionId = request_int($_POST, 'mission_id');
            if ($missionId <= 0) {
                throw new RuntimeException(__t('missions.err_mission_id_required'));
            }
            deleteMission($missionId, $connection);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'deleted mission id ' . $missionId, (int) $user['id']);
            flash_set('success', $isTemplateView ? __t('missions.flash_deleted_template') : __t('missions.flash_deleted_mission'));
        } elseif ($action === 'import_preview') {
            $file = $_FILES['import_file'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(__t('missions.import_err_no_file'));
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES) {
                throw new RuntimeException(__t('missions.import_err_too_large', [
                    'max' => virtusphere_human_bytes(VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES),
                ]));
            }
            $raw = file_get_contents((string) $file['tmp_name']);
            if ($raw === false || $raw === '') {
                throw new RuntimeException(__t('missions.import_err_read'));
            }
            $payload = json_decode($raw, true, VIRTUSPHERE_MISSION_IMPORT_JSON_DEPTH);
            if (!is_array($payload)) {
                throw new RuntimeException(__t('missions.import_err_json'));
            }
            if ((int) ($payload['format_version'] ?? 0) !== VIRTUSPHERE_MISSION_EXPORT_VERSION) {
                throw new RuntimeException(__t('missions.import_err_version'));
            }
            $token = bin2hex(random_bytes(16));
            $_SESSION['mission_import'] = [
                'token' => $token,
                'created' => time(),
                'payload' => $payload,
                'suggested_name' => trim((string) ($payload['mission']['mission_name'] ?? '')),
            ];
            redirect_to('missions.php?type=missions&import=' . $token);
        } elseif ($action === 'import_confirm') {
            $token = request_string($_POST, 'import_token');
            $state = $_SESSION['mission_import'] ?? null;
            if (!is_array($state) || $token === '' || !hash_equals((string) ($state['token'] ?? ''), $token)
                || time() - (int) ($state['created'] ?? 0) > VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS) {
                unset($_SESSION['mission_import']);
                flash_set('error', __t('missions.import_err_expired'));
                redirect_to('missions.php?type=missions');
            }
            $name = request_trimmed($_POST, 'mission_name');
            try {
                $report = mission_import($connection, (array) $state['payload'], $name, false, (int) $user['id']);
            } catch (Throwable $importError) {
                // Keep the session hand-off alive so the admin can correct the
                // name and retry without re-uploading.
                flash_set('error', portal_error_message($importError));
                redirect_to('missions.php?type=missions&import=' . rawurlencode($token));
            }
            unset($_SESSION['mission_import']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'imported mission ' . $name . ' (' . (int) $report['counts']['vms'] . ' vms)', (int) $user['id']);
            flash_set('success', __t('missions.import_flash_done', ['count' => (int) $report['counts']['vms']]));
            redirect_to('mission_details.php?id=' . (int) $report['mission_id']);
        }
    } catch (ValidationException $exception) {
        form_remember('create', $_POST, $exception->errors());
        flash_set('error', portal_error_message($exception));
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
    redirect_to('missions.php?type=' . $type);
}

$rows = array_values(array_filter(getMissions($connection), static function (array $mission) use ($type): bool {
    $isTemplate = mission_name_is_template((string) ($mission['mission_name'] ?? ''));
    return $type === 'templates' ? $isTemplate : !$isTemplate;
}));
$attentionOnly = !$isTemplateView && request_string($_GET, 'attention') === '1';
$attentionCounts = $isTemplateView ? [] : repo_vm_progress_attention_counts_by_mission($connection);
foreach ($rows as &$missionRow) {
    $missionRow['progress_attention_count'] = $attentionCounts[(int) $missionRow['id']] ?? 0;
}
unset($missionRow);
if ($attentionOnly) {
    $rows = array_values(array_filter($rows, static fn (array $missionRow): bool => (int) $missionRow['progress_attention_count'] > 0));
}

// Column sorting (Name, VMs): whitelisted keys keep GET input safe and let the
// CSV export and the rendered table share the same order.
[$sort, $dir] = portal_sort_apply($rows, [
    'name' => portal_sort_text('mission_name'),
    'vms' => portal_sort_number('vm_count'),
    'attention' => portal_sort_number('progress_attention_count'),
], 'name');

// CSV list export (A3): read-only GET download of the current list. Streams and
// exits before any layout output.
if (($_GET['export'] ?? '') === 'csv') {
    // Datastore and datacenter ride along here and nowhere else on this page:
    // "which mission sits where" has no collective answer in the portal, and an
    // operator with six hosts had to open every mission to build one. The export
    // is the right place for that, precisely because the rendered list stays
    // restrained. An empty datacenter is the derived case (resolved from the
    // target host at deploy time), so the cell is empty rather than invented.
    $header = [
        __t('common.name'), __t('common.status'), __t('missions.th_datastore'),
        __t('missions.th_datacenter'), __t('common.vms'), __t('missions.th_attention'), __t('common.updated'),
    ];
    $csvRows = [];
    foreach ($rows as $mission) {
        $csvRows[] = [
            (string) ($mission['mission_name'] ?? ''),
            (string) ($mission['mission_status'] ?? ''),
            (string) ($mission['hypervisor_datastorage'] ?? ''),
            (string) ($mission['hypervisor_datacenter'] ?? ''),
            (string) ($mission['vm_count'] ?? 0),
            (string) ($mission['progress_attention_count'] ?? 0),
            portal_format_timestamp($mission['updated_at'] ?? ''),
        ];
    }
    // A read-only list download, allowed for any signed-in user; logged as data
    // egress like the full-mission JSON export, minus the per-record detail.
    audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'exported ' . ($isTemplateView ? 'template' : 'mission') . ' list as CSV (' . count($csvRows) . ' row(s))', (int) $user['id']);
    portal_send_csv($isTemplateView ? 'vorlagen' : 'missionen', $header, $csvRows);
}

// Missions with waiting scheduled jobs get a stronger delete confirmation, since
// the deploy_jobs FK cascade would silently drop those scheduled jobs (B4.5).
$missionsWithScheduled = [];
$scheduledResult = $connection->query("SELECT DISTINCT mission_id FROM deploy_jobs WHERE status = 'queued' AND cancelled_at IS NULL AND scheduled_at IS NOT NULL AND scheduled_at > UTC_TIMESTAMP()");
if ($scheduledResult !== false) {
    foreach ($scheduledResult->fetch_all(MYSQLI_ASSOC) as $scheduledRow) {
        $missionsWithScheduled[(int) $scheduledRow['mission_id']] = true;
    }
}

// Inventory-deviation badge (E4.3): missions whose datacenter/datastore/VLAN is
// not in the current ESXi inventory.
$deviatingMissions = $isTemplateView ? [] : esxi_inventory_deviating_mission_ids($connection);

// Import dry-run preview (A2/A4): if a valid, non-expired hand-off exists in the
// session, recompute the report against the live DB and render the confirm step.
$importPreview = null;
$importToken = request_string($_GET, 'import');
if ($importToken !== '' && !$isTemplateView && can('missions.write', $user)) {
    $state = $_SESSION['mission_import'] ?? null;
    if (is_array($state)
        && hash_equals((string) ($state['token'] ?? ''), $importToken)
        && time() - (int) ($state['created'] ?? 0) <= VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS) {
        $suggestedName = (string) ($state['suggested_name'] ?? '');
        try {
            $report = mission_import($connection, (array) $state['payload'], $suggestedName, true);
            $importPreview = [
                'token' => $importToken,
                'suggested_name' => $suggestedName,
                'exported_at' => (string) ($state['payload']['exported_at'] ?? ''),
                'report' => $report,
            ];
        } catch (Throwable $previewError) {
            // A dry run that cannot produce a report at all (an unreadable
            // document, a database failure) drops the hand-off, and it must say
            // so: this catch used to be the unset alone, so the page fell back to
            // the empty upload form with no flash and no log entry, which is
            // exactly the "nothing happens on Preview" the operator reported.
            unset($_SESSION['mission_import']);
            flash_set('error', portal_error_message($previewError));
        }
    } elseif (is_array($state) && hash_equals((string) ($state['token'] ?? ''), $importToken)) {
        // This link's own hand-off is still in the session and only its TTL has
        // run out: "expired" is an established fact here, so it may be said.
        unset($_SESSION['mission_import']);
        flash_set('error', __t('missions.import_err_expired'));
    } else {
        // No hand-off at all, or one belonging to a different upload. Reached
        // after Cancel, after a SUCCESSFUL import followed by the Back button,
        // and from a stale link out of another session. Which of those it was is
        // not knowable here, so the message states only what is established:
        // there is no preview behind this link any more. Claiming "expired"
        // would assert a cause this path never checked.
        unset($_SESSION['mission_import']);
        flash_set('error', __t('missions.import_err_gone'));
    }
}

layout_header($title, $user, $active, 'missions');
?>
<div class="stack">
    <?php if (can('missions.write', $user)) { ?>
        <section class="panel">
            <h2><?php echo h($isTemplateView ? __t('missions.create_heading_template') : __t('missions.create_heading_mission')); ?></h2>
            <form class="form-grid" method="post" action="missions.php?type=<?php echo h($type); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <label><?php echo h(__t('common.name')); ?><input name="mission_name" maxlength="255" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h(form_old('create', 'mission_name')); ?>"<?php echo form_input_class('create', 'mission_name'); ?> required><?php echo form_error_html('create', 'mission_name'); ?></label>
                <div class="actions"><button class="button" type="submit"><?php echo h(__t('common.create')); ?></button></div>
            </form>
        </section>
    <?php } ?>

    <?php if (!$isTemplateView && can('missions.write', $user)) { ?>
        <?php if ($importPreview !== null) {
            missions_render_import_preview($importPreview, $user);
        } else {
            missions_render_import_upload();
        } ?>
    <?php } ?>

    <section class="panel">
        <div class="actions">
            <a class="button <?php echo $type === 'missions' ? '' : 'button-secondary'; ?>" href="missions.php?type=missions"><?php echo h(__t('missions.tab_missions')); ?></a>
            <a class="button <?php echo $type === 'templates' ? '' : 'button-secondary'; ?>" href="missions.php?type=templates"><?php echo h(__t('missions.tab_templates')); ?></a>
            <?php if ($attentionOnly) { ?><a class="button button-secondary" href="missions.php?type=missions"><?php echo h(__t('missions.attention_clear')); ?></a><?php } ?>
            <?php if ($rows !== []) { ?><a class="button button-secondary" href="missions.php?type=<?php echo h($type); ?>&sort=<?php echo h($sort); ?>&dir=<?php echo h($dir); ?><?php echo $attentionOnly ? '&attention=1' : ''; ?>&export=csv"><?php echo h(__t('common.export_csv')); ?></a><?php } ?>
        </div>
        <?php if ($attentionOnly) { ?><p class="muted"><?php echo h(__t('missions.attention_filter_hint')); ?></p><?php } ?>
        <div class="table-wrap" tabindex="0">
            <table>
                <thead><tr><?php
                    $missionSortParams = ['type' => $type];
                    if ($attentionOnly) { $missionSortParams['attention'] = '1'; }
                    echo portal_sort_header('missions.php', 'name', __t('common.name'), $sort, $dir, $missionSortParams);
                    echo portal_sort_header('missions.php', 'vms', __t('common.vms'), $sort, $dir, $missionSortParams);
                    echo portal_sort_header('missions.php', 'attention', __t('missions.th_attention'), $sort, $dir, $missionSortParams);
                ?><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $mission) { ?>
                    <tr>
                        <td><?php echo h($mission['mission_name'] ?? ''); ?>
                            <?php if (isset($deviatingMissions[(int) $mission['id']])) { ?>
                                <span class="badge badge-warning" title="<?php echo h(__t('missions.deviation_title')); ?>"><?php echo h(__t('missions.deviation_badge')); ?></span>
                            <?php } ?>
                        </td>
                        <td><?php echo h((string) ($mission['vm_count'] ?? 0)); ?></td>
                        <td><?php echo (int) ($mission['progress_attention_count'] ?? 0) > 0
                            ? portal_badge('warning', __t('missions.attention_badge', ['count' => (int) $mission['progress_attention_count']]))
                            : '&mdash;'; ?></td>
                        <td><?php echo h(portal_format_timestamp($mission['updated_at'] ?? '')); ?></td>
                        <td class="actions">
                            <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $mission['id']); ?>"><?php echo h(__t('common.details')); ?></a>
                            <a class="button button-secondary" href="vms.php?mission_id=<?php echo h((string) $mission['id']); ?>"><?php echo h(__t('common.vms')); ?></a>
                            <?php if (can('missions.write', $user)) { ?>
                                <form class="inline-form" method="post" action="missions.php?type=<?php echo h($type); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="mission_id" value="<?php echo h((string) $mission['id']); ?>">
                                    <?php
                                    // A template has no VMs to take down with it and cannot carry
                                    // scheduled jobs, so it gets its own prompt rather than the
                                    // mission wording. The scheduled warning outranks the empty
                                    // one: waiting jobs matter more than an absent VM count.
                                    // Name the row so the dialog cannot be confirmed for the
                                    // wrong mission when several read alike.
                                    $confirmName = ['name' => (string) ($mission['mission_name'] ?? '')];
                                    if ($isTemplateView) {
                                        $confirmDelete = __t('missions.confirm_delete_template', $confirmName);
                                    } elseif (isset($missionsWithScheduled[(int) $mission['id']])) {
                                        $confirmDelete = __t('missions.confirm_delete_scheduled', $confirmName);
                                    } elseif ((int) ($mission['vm_count'] ?? 0) === 0) {
                                        $confirmDelete = __t('missions.confirm_delete_empty', $confirmName);
                                    } else {
                                        $confirmDelete = __t('missions.confirm_delete', $confirmName);
                                    }
                                    ?>
                                    <button class="button button-danger" type="submit" data-confirm="<?php echo h($confirmDelete); ?>"><?php echo h(__t('common.delete')); ?></button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if ($rows === []) { ?><tr><td colspan="5"><?php echo h($attentionOnly ? __t('missions.attention_empty') : __t('missions.empty')); ?></td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php layout_footer(); ?>
