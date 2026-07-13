<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/repo/log.php';

// Operating systems are owned by MECM: the packages sync (mecm_packages.php)
// upserts the MECM Task Sequences into deploy_os and retires missing ones, so
// this page does not offer create or edit (a typed name only gets retired, an
// edit gets overwritten on the next sync). Delete stays as a safe cleanup: if
// the Task Sequence still exists in MECM the next sync re-creates the row
// (self-healing); an OS still assigned to a VM keeps deploying via vm_os and is
// purge-protected. The legacy token API keeps createOS/updateOS until E3. See
// ADR-0020.
$user = portal_require_user($connection);
$canWrite = can('catalog.write', $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    if (!$canWrite) {
        portal_forbid($connection, $user, 'catalog.write');
    }

    try {
        $action = request_string($_POST, 'action');
        if ($action === 'delete') {
            deleteOS(request_int($_POST, 'os_id'), $connection);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_OS, 'deleted os id ' . request_int($_POST, 'os_id'), (int) $user['id']);
            flash_set('success', __t('os.flash_deleted'));
        }
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
    redirect_to('os.php');
}

$statusFilter = request_string($_GET, 'status', 'active');
if (!in_array($statusFilter, VIRTUSPHERE_CATALOG_FILTERS, true)) {
    $statusFilter = 'active';
}

$retired = VIRTUSPHERE_CATALOG_STATUS_RETIRED;
$rows = array_values(array_filter(getOS($connection, true), static function (array $row) use ($statusFilter, $retired): bool {
    $isRetired = (string) ($row['os_status'] ?? '') === $retired;

    return match ($statusFilter) {
        'retired' => $isRetired,
        'all' => true,
        default => !$isRetired,
    };
}));
$vmCounts = repo_os_vm_counts($connection);

layout_header(__t('os.title'), $user, 'os');
?>
<div class="stack">
    <section class="panel">
        <p class="muted"><?php echo h(__t('os.readonly_hint')); ?></p>
        <?php echo portal_catalog_status_filter('os.php', $statusFilter, [
            'label' => __t('os.filter_label'),
            'apply' => __t('os.filter_apply'),
            'active' => __t('os.filter_active'),
            'retired' => __t('os.filter_retired'),
            'all' => __t('os.filter_all'),
        ]); ?>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('common.status')); ?></th><th><?php echo h(__t('os.th_vm_usage')); ?></th><th><?php echo h(__t('os.th_retired_at')); ?></th><?php if ($canWrite) { ?><th><?php echo h(__t('common.actions')); ?></th><?php } ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <?php
                $osName = (string) ($row['os_name'] ?? '');
                $usage = (int) ($vmCounts[$osName] ?? 0);
                // __t() substitutes placeholders but does not pluralize, so the count
                // picks the sentence instead of forcing a "VM(s)" dodge that reads
                // wrong at zero and at one.
                $confirmDelete = match (true) {
                    $usage === 0 => __t('os.confirm_delete_unused', ['name' => $osName]),
                    $usage === 1 => __t('os.confirm_delete_one', ['name' => $osName]),
                    default => __t('os.confirm_delete_many', ['name' => $osName, 'count' => (string) $usage]),
                };
                ?>
                <tr>
                    <td><?php echo h($osName); ?></td>
                    <td><?php echo catalog_status_badge((string) ($row['os_status'] ?? '')); ?></td>
                    <td><?php echo h((string) $usage); ?></td>
                    <td><?php echo h(portal_format_timestamp((string) ($row['retired_at'] ?? ''))); ?></td>
                    <?php if ($canWrite) { ?>
                        <td>
                            <form method="post" action="os.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="os_id" value="<?php echo h((string) $row['id']); ?>">
                                <button class="button button-danger" type="submit" name="action" value="delete" data-confirm="<?php echo h($confirmDelete); ?>"><?php echo h(__t('common.delete')); ?></button>
                            </form>
                        </td>
                    <?php } ?>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?><tr><td colspan="<?php echo $canWrite ? '5' : '4'; ?>"><?php echo h(__t('os.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
