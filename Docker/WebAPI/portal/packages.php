<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/catalog.php';

// Packages are owned by MECM: mecm_packages.php performs a retire-missing +
// upsert sync from the MECM catalog, so this portal page is read-only. Editing
// here would be overwritten on the next sync.
$user = portal_require_user($connection);

$statusFilter = request_string($_GET, 'status', 'active');
if (!in_array($statusFilter, VIRTUSPHERE_CATALOG_FILTERS, true)) {
    $statusFilter = 'active';
}

$rows = getPackages($connection, $statusFilter);

// Column sorting: version uses natural order; timestamps sort on the raw UTC
// string, which is chronological for the DB datetime format.
[$sort, $dir] = portal_sort_apply($rows, [
    'name' => portal_sort_text('package_name'),
    'basename' => portal_sort_text('package_basename'),
    'version' => portal_sort_text('package_version'),
    'status' => portal_sort_text('package_status'),
    'retired' => portal_sort_text('retired_at'),
    'updated' => portal_sort_text('updated_at'),
], 'name');

layout_header(__t('packages.title'), $user, 'packages', 'packages');
?>
<div class="stack">
    <section class="panel">
        <p class="muted"><?php echo h(__t('packages.readonly_hint')); ?></p>
        <?php echo portal_catalog_status_filter('packages.php', $statusFilter, [
            'label' => __t('packages.filter_label'),
            'apply' => __t('packages.filter_apply'),
            'active' => __t('packages.filter_active'),
            'retired' => __t('packages.filter_retired'),
            'all' => __t('packages.filter_all'),
        ], ['sort' => $sort, 'dir' => $dir]); ?>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><?php
                $pkgSortParams = ['status' => $statusFilter];
                echo portal_sort_header('packages.php', 'name', __t('common.name'), $sort, $dir, $pkgSortParams);
                echo portal_sort_header('packages.php', 'basename', __t('packages.th_basename'), $sort, $dir, $pkgSortParams);
                echo portal_sort_header('packages.php', 'version', __t('packages.th_version'), $sort, $dir, $pkgSortParams);
                echo portal_sort_header('packages.php', 'status', __t('common.status'), $sort, $dir, $pkgSortParams);
                echo portal_sort_header('packages.php', 'retired', __t('packages.th_retired_at'), $sort, $dir, $pkgSortParams);
                echo portal_sort_header('packages.php', 'updated', __t('common.updated'), $sort, $dir, $pkgSortParams);
            ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td><?php echo h((string) ($row['package_name'] ?? '')); ?></td>
                    <td><?php echo h((string) ($row['package_basename'] ?? '')); ?></td>
                    <td><?php echo h((string) ($row['package_version'] ?? '')); ?></td>
                    <td><?php echo catalog_status_badge((string) ($row['package_status'] ?? '')); ?></td>
                    <td><?php echo h(portal_format_timestamp((string) ($row['retired_at'] ?? ''))); ?></td>
                    <td><?php echo h(portal_format_timestamp((string) ($row['updated_at'] ?? ''))); ?></td>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?><tr><td colspan="6"><?php echo h(__t('packages.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
