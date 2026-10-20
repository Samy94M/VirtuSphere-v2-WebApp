<?php

declare(strict_types=1);

/**
 * Renderers of the dashboard VM overview: the two dashboard tiles, the filter
 * navigation, the read-only table, its pagination and the one sentence that
 * explains a changed count. Every value is escaped here. The tile link, the filter
 * links and the page links come from vm_overview_url(); the sort headers come from
 * portal_sort_header(), which carries the same filter and snapshot.
 */

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/vm_overview.php';

function vm_overview_filter_label(string $filter): string
{
    return $filter === VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING
        ? __t('vm_overview.filter_mecm_pending')
        : __t('vm_overview.filter_all');
}

function vm_overview_title(string $filter): string
{
    return $filter === VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING
        ? __t('vm_overview.title_mecm_pending')
        : __t('vm_overview.title_all');
}

function vm_overview_intro(string $filter): string
{
    return $filter === VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING
        ? __t('vm_overview.intro_mecm_pending')
        : __t('vm_overview.intro_all');
}

/**
 * One dashboard tile. It is a link to the list with the same filter, and the
 * link carries the moment its number was counted, so the list can say what changed.
 */
function vm_overview_render_tile(string $filter, string $label, int $count, int $countedAt, bool $warning = false): void
{
    $href = vm_overview_url($filter, ['snapshot' => ['counted' => $countedAt, 'expected' => $count]]);
    ?>
    <a class="card kpi" href="<?php echo h($href); ?>">
        <span class="muted"><?php echo h($label); ?></span>
        <span class="value<?php echo $warning ? ' value-warning' : ''; ?>"><?php echo h((string) $count); ?></span>
    </a>
    <?php
}

function vm_overview_render_filter_nav(string $filter, string $sort, string $dir): void
{
    // A filter change starts a new list: the page and the tile's snapshot are not carried.
    $items = [];
    foreach (VIRTUSPHERE_VM_OVERVIEW_FILTERS as $option) {
        $items[] = [
            'href' => vm_overview_url($option, ['sort' => $sort, 'dir' => $dir]),
            'label' => vm_overview_filter_label($option),
            'current' => $option === $filter,
        ];
    }

    echo portal_page_nav(__t('vm_overview.filter_label'), $items);
}

/**
 * Sentence for a list whose total differs from the tile it was opened from. It
 * names both numbers and the capture time, and nothing else is changed.
 *
 * @param array{counted:int,expected:int}|null $snapshot
 */
function vm_overview_render_change_notice(?array $snapshot, int $total): void
{
    if (!vm_overview_count_changed($snapshot, $total)) {
        return;
    }
    ?>
    <div class="alert alert-info">
        <p><?php echo h(__t('vm_overview.count_changed', [
            'counted' => portal_format_epoch((int) $snapshot['counted']),
            'expected' => (int) $snapshot['expected'],
            'actual' => $total,
        ])); ?></p>
    </div>
    <?php
}

/**
 * The read-only table. The sort headers keep the filter and the snapshot but not
 * the page, so a new order starts at its first page.
 *
 * Each row owns the fragment vm-<id>; its VM link carries this list's view (filter,
 * sort, direction, page) to the editor, which returns here after saving or going
 * back (UX03).
 *
 * @param list<array<string,mixed>> $rows
 * @param array{counted:int,expected:int}|null $snapshot
 */
function vm_overview_render_table(array $rows, string $filter, string $sort, string $dir, ?array $snapshot, int $page = 1): void
{
    $base = ['filter' => $filter];
    if ($snapshot !== null) {
        $base['counted'] = (string) $snapshot['counted'];
        $base['expected'] = (string) $snapshot['expected'];
    }
    ?>
    <div class="table-wrap" tabindex="0">
        <table aria-label="<?php echo h(vm_overview_title($filter)); ?>">
            <thead>
                <tr>
                    <?php
                    echo portal_sort_header('vm_overview.php', 'mission', __t('common.mission'), $sort, $dir, $base);
                    echo portal_sort_header('vm_overview.php', 'name', __t('vms.th_vm_name'), $sort, $dir, $base);
                    echo portal_sort_header('vm_overview.php', 'hostname', __t('vms.th_hostname'), $sort, $dir, $base);
                    echo portal_sort_header('vm_overview.php', 'status', __t('vms.th_status'), $sort, $dir, $base);
                    echo portal_sort_header('vm_overview.php', 'mecm', __t('vms.th_mecm'), $sort, $dir, $base);
                    ?>
                    <th><?php echo h(__t('common.actions')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <?php
                $missionId = (int) $row['mission_id'];
                $missionName = (string) $row['mission_name'];
                ?>
                <tr id="vm-<?php echo h((string) $row['id']); ?>">
                    <td>
                        <a href="<?php echo h(mission_details_url($missionId)); ?>"><?php echo h($missionName); ?></a>
                        <?php if (mission_name_is_template($missionName)) { echo portal_badge('neutral', __t('vm_overview.template_badge')); } ?>
                    </td>
                    <td><?php echo h((string) $row['vm_name']); ?></td>
                    <td><?php echo h((string) $row['vm_hostname']); ?></td>
                    <td><?php echo status_badge((string) $row['vm_status']); ?></td>
                    <td><?php echo mecm_sync_badge((string) $row['mecm_sync_state']); ?> <span class="muted"><?php echo h(mecm_updated_display($row['updated'] ?? 0)); ?></span></td>
                    <td class="actions"><a class="button button-secondary" href="<?php echo h(vm_edit_url($missionId, (int) $row['id'], null, vm_overview_editor_context($filter, $sort, $dir, $page))); ?>"><?php echo h(__t('vm_overview.open_vm')); ?></a></td>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?>
                <tr><td colspan="6"><?php echo h($filter === VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING
                    ? __t('vm_overview.empty_mecm_pending')
                    : __t('vm_overview.empty_all')); ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Previous and next links for the list. The page numbers are clamped by the
 * reader, so the first and last link always name a page that exists.
 *
 * @param array{total:int,pages:int,page:int,rows:list<array<string,mixed>>} $listing
 * @param array{counted:int,expected:int}|null $snapshot
 */
function vm_overview_render_pagination(array $listing, string $filter, string $sort, string $dir, ?array $snapshot): void
{
    $page = (int) $listing['page'];
    $pages = (int) $listing['pages'];
    if ($pages <= 1) {
        return;
    }
    $state = ['sort' => $sort, 'dir' => $dir, 'snapshot' => $snapshot];
    ?>
    <nav class="pagination" aria-label="<?php echo h(__t('vm_overview.pagination_label')); ?>">
        <?php if ($page > 1) { ?><a class="button button-secondary" href="<?php echo h(vm_overview_url($filter, $state + ['page' => $page - 1])); ?>"><?php echo h(__t('vm_overview.previous')); ?></a><?php } ?>
        <span class="pagination-info"><?php echo h(__t('vm_overview.page_info', ['current' => $page, 'last' => $pages])); ?></span>
        <?php if ($page < $pages) { ?><a class="button button-secondary" href="<?php echo h(vm_overview_url($filter, $state + ['page' => $page + 1])); ?>"><?php echo h(__t('vm_overview.next')); ?></a><?php } ?>
    </nav>
    <?php
}
