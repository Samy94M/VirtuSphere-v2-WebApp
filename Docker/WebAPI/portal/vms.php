<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/portal_export.php';

$user = portal_require_user($connection);
$missionId = request_int($_GET, 'mission_id');
$mission = repo_get_mission($connection, $missionId);
if ($mission === null) {
    flash_set('error', __t('portal.mission_not_found'));
    redirect_to('missions.php?type=missions');
}
$isTemplate = mission_name_is_template((string) $mission['mission_name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);

    $action = request_string($_POST, 'action');
    if (!can('vms.write', $user)) {
        portal_forbid($connection, $user, 'vms.write');
    }
    $vmId = request_int($_POST, 'vm_id');
    $redirectPath = 'vms.php?mission_id=' . $missionId;
    $returnTo = request_string($_POST, 'return_to');
    if (preg_match('/^vm_edit\.php\?mission_id=' . preg_quote((string) $missionId, '/') . '&vm_id=' . preg_quote((string) $vmId, '/') . '$/', $returnTo) === 1) {
        $redirectPath = $returnTo;
    }

    try {
        if ($action === 'reset_mecm_id') {
            if ($isTemplate) {
                throw new RuntimeException(__t('portal.vm_mecm_reset_template_blocked'));
            }
            repo_reset_vm_mecm_id($connection, $missionId, $vmId, (int) $user['id']);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_VMS, 'reset mecm id for vm id ' . $vmId . ' in mission id ' . $missionId, (int) $user['id']);
            flash_set('success', __t('portal.vm_mecm_reset_success'));
        } elseif ($action === 'delete') {
            repo_delete_vm_by_id($connection, $missionId, $vmId);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_VMS, 'deleted vm id ' . $vmId . ' from mission id ' . $missionId, (int) $user['id']);
            flash_set('success', __t('vms.flash_deleted'));
        } elseif ($action === 'bulk_delete' || $action === 'bulk_reset_mecm_id') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['vm_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                throw new ValidationException([], __t('vms.bulk_none_selected'));
            }
            if (count($ids) > VIRTUSPHERE_VM_BULK_CAP) {
                throw new ValidationException([], __t('vms.bulk_too_many', ['cap' => VIRTUSPHERE_VM_BULK_CAP]));
            }

            if ($action === 'bulk_reset_mecm_id' && $isTemplate) {
                throw new RuntimeException(__t('portal.vm_mecm_reset_template_blocked'));
            }

            if ($action === 'bulk_delete') {
                $result = repo_bulk_delete_vms($connection, $missionId, $ids);
                $done = (int) $result['deleted'];
                $verb = 'bulk deleted';
            } else {
                $result = repo_bulk_reset_mecm_ids($connection, $missionId, $ids, (int) $user['id']);
                $done = (int) $result['done'];
                $verb = 'bulk reset mecm id for';
            }

            audit($connection, VIRTUSPHERE_LOG_CATEGORY_VMS, $verb . ' ' . $done . ' vm(s) in mission id ' . $missionId . ' [' . implode(',', $ids) . ']', (int) $user['id']);

            $skippedReasons = [];
            foreach ($result['skipped'] as $skip) {
                $skippedReasons[$skip['reason']] = ($skippedReasons[$skip['reason']] ?? 0) + 1;
            }
            $message = __t('vms.bulk_done', ['done' => $done]);
            if ($skippedReasons !== []) {
                $parts = [];
                foreach ($skippedReasons as $reason => $count) {
                    $parts[] = $count . ' ' . __t('vms.skip_' . $reason);
                }
                $message .= ' ' . __t('vms.bulk_skipped', ['count' => count($result['skipped'])]) . ' (' . implode(', ', $parts) . ')';
            }
            flash_set('success', $message);
        }
    } catch (ValidationException $exception) {
        flash_set('error', portal_error_message($exception));
    } catch (Throwable $exception) {
        $message = $exception->getMessage() === 'VM needs an imported MAC address before MECM ID reset.'
            ? __t('portal.vm_mecm_reset_no_mac')
            : portal_error_message($exception);
        flash_set('error', $message);
    }
    redirect_to($redirectPath);
}

$rows = getVMs($connection, $missionId);
$canWrite = can('vms.write', $user);

// Column sorting: sort before the CSV export so the download matches the view.
[$sort, $dir] = portal_sort_apply($rows, [
    'name' => portal_sort_text('vm_name'),
    'hostname' => portal_sort_text('vm_hostname'),
    'os' => portal_sort_text('vm_os'),
    'cpu' => portal_sort_number('vm_cpu'),
    'ram' => portal_sort_number('vm_ram'),
    'status' => portal_sort_text('vm_status'),
], 'name');

// CSV list export (A3): read-only GET download, streams and exits before layout.
if (($_GET['export'] ?? '') === 'csv') {
    $header = [
        __t('common.name'), __t('vms.th_hostname'), __t('vms.th_os'), __t('vms.th_cpu'), __t('vms.th_ram'),
        __t('common.status'), __t('vms.th_mecm'), __t('vms.th_interfaces'), __t('vms.th_disks'), __t('vms.th_packages'),
    ];
    $csvRows = [];
    foreach ($rows as $vm) {
        $csvRows[] = [
            (string) ($vm['vm_name'] ?? ''),
            (string) ($vm['vm_hostname'] ?? ''),
            (string) ($vm['vm_os'] ?? ''),
            (string) ($vm['vm_cpu'] ?? ''),
            (string) ($vm['vm_ram'] ?? ''),
            (string) ($vm['vm_status'] ?? ''),
            (string) ($vm['mecm_sync_state'] ?? ''),
            (string) count($vm['interfaces'] ?? []),
            (string) count($vm['disks'] ?? []),
            (string) count($vm['packages'] ?? []),
        ];
    }
    audit($connection, VIRTUSPHERE_LOG_CATEGORY_VMS, 'exported vm list of mission id ' . $missionId . ' as CSV (' . count($csvRows) . ' row(s))', (int) $user['id']);
    portal_send_csv('vms-' . (string) $mission['mission_name'], $header, $csvRows);
}

layout_header(($isTemplate ? __t('vms.title_template') : __t('vms.title_mission')) . ': ' . (string) $mission['mission_name'], $user, $isTemplate ? 'templates' : 'missions');
?>
<div class="stack">
    <section class="panel">
        <div class="actions">
            <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vms.mission_details')); ?></a>
            <?php if (can('vms.write', $user)) { ?><a class="button" href="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?>"><?php echo h(__t('vms.add_vm')); ?></a><?php } ?>
            <?php if ($rows !== []) { ?><a class="button button-secondary" href="vms.php?mission_id=<?php echo h((string) $missionId); ?>&sort=<?php echo h($sort); ?>&dir=<?php echo h($dir); ?>&export=csv"><?php echo h(__t('common.export_csv')); ?></a><?php } ?>
        </div>
    </section>

    <?php if ($canWrite && $rows !== []) { ?>
        <section class="panel">
            <form id="bulk-vms" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>" class="actions" data-bulk-form>
                <?php echo csrf_field(); ?>
                <span class="muted"><span data-bulk-count>0</span> <?php echo h(__t('vms.bulk_selected')); ?></span>
                <button class="button button-danger" type="submit" name="action" value="bulk_delete" data-confirm="<?php echo h(__t('vms.bulk_confirm_delete')); ?>" data-bulk-submit disabled><?php echo h(__t('vms.bulk_delete_btn')); ?></button>
                <?php if (!$isTemplate) { ?>
                    <button class="button button-secondary" type="submit" name="action" value="bulk_reset_mecm_id" data-confirm="<?php echo h(__t('vms.bulk_confirm_reset')); ?>" data-bulk-submit disabled><?php echo h(__t('vms.bulk_reset_btn')); ?></button>
                <?php } ?>
            </form>
            <p class="muted"><?php echo h(__t('vms.bulk_hint')); ?></p>
        </section>
    <?php } ?>

    <section class="panel">
        <div class="table-wrap" tabindex="0">
            <table>
                <thead><tr><?php if ($canWrite) { ?><th><input type="checkbox" data-bulk-all aria-label="<?php echo h(__t('vms.bulk_select_all')); ?>"></th><?php } ?><?php
                    $vmSortParams = ['mission_id' => (string) $missionId];
                    echo portal_sort_header('vms.php', 'name', __t('common.name'), $sort, $dir, $vmSortParams);
                    echo portal_sort_header('vms.php', 'hostname', __t('vms.th_hostname'), $sort, $dir, $vmSortParams);
                    echo portal_sort_header('vms.php', 'os', __t('vms.th_os'), $sort, $dir, $vmSortParams);
                    echo portal_sort_header('vms.php', 'cpu', __t('vms.th_cpu'), $sort, $dir, $vmSortParams);
                    echo portal_sort_header('vms.php', 'ram', __t('vms.th_ram'), $sort, $dir, $vmSortParams);
                    echo portal_sort_header('vms.php', 'status', __t('common.status'), $sort, $dir, $vmSortParams);
                ?><th><?php echo h(__t('vms.th_mecm')); ?></th><th><?php echo h(__t('vms.th_interfaces')); ?></th><th><?php echo h(__t('vms.th_disks')); ?></th><th><?php echo h(__t('vms.th_packages')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $vm) { ?>
                    <tr>
                        <?php if ($canWrite) { ?><td><input type="checkbox" form="bulk-vms" name="vm_ids[]" value="<?php echo h((string) $vm['id']); ?>" data-bulk-item aria-label="<?php echo h((string) ($vm['vm_name'] ?? '')); ?>"></td><?php } ?>
                        <td><?php echo h($vm['vm_name'] ?? ''); ?></td>
                        <td><?php echo h($vm['vm_hostname'] ?? ''); ?></td>
                        <td><?php echo h($vm['vm_os'] ?? ''); ?></td>
                        <td><?php echo h($vm['vm_cpu'] ?? ''); ?></td>
                        <td><?php echo h($vm['vm_ram'] ?? ''); ?></td>
                        <td><?php echo status_badge((string) ($vm['vm_status'] ?? '')); ?></td>
                        <td><?php echo mecm_sync_badge((string) ($vm['mecm_sync_state'] ?? '')); ?> <span class="muted"><?php echo h((string) ($vm['updated'] ?? 0)); ?></span></td>
                        <td><?php echo h((string) count($vm['interfaces'] ?? [])); ?></td>
                        <td><?php echo h((string) count($vm['disks'] ?? [])); ?></td>
                        <td><?php echo h((string) count($vm['packages'] ?? [])); ?></td>
                        <td class="actions">
                            <a class="button button-secondary" href="vm_edit.php?mission_id=<?php echo h((string) $missionId); ?>&vm_id=<?php echo h((string) $vm['id']); ?>"><?php echo h(__t('common.edit')); ?></a>
                            <?php if (!$isTemplate && can('vms.write', $user)) { ?>
                                <form class="inline-form" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reset_mecm_id">
                                    <input type="hidden" name="vm_id" value="<?php echo h((string) $vm['id']); ?>">
                                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t('portal.vm_mecm_reset_confirm', ['name' => (string) ($vm['vm_name'] ?? '')])); ?>"><?php echo h(__t('portal.vm_mecm_reset_button')); ?></button>
                                </form>
                            <?php } ?>
                            <?php if (can('vms.write', $user)) { ?>
                                <form class="inline-form" method="post" action="vms.php?mission_id=<?php echo h((string) $missionId); ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vm_id" value="<?php echo h((string) $vm['id']); ?>">
                                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('vms.confirm_delete', ['name' => (string) ($vm['vm_name'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if ($rows === []) { ?><tr><td colspan="<?php echo $canWrite ? 12 : 11; ?>"><?php echo h(__t('vms.empty')); ?></td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php layout_footer(); ?>
