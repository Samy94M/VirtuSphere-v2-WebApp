<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/log_filter.php';
require_once __DIR__ . '/../lib/logs_export.php';
require_once __DIR__ . '/../lib/portal_export.php';
require_once __DIR__ . '/../lib/repo/log.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);
if (!can('users.manage', $user)) {
    portal_forbid($connection, $user, 'users.manage');
}

const LOGS_PER_PAGE = 50;

$tabKeys = array_keys(VIRTUSPHERE_LOG_TABS);
// One validated struct for this request. The table below and the CSV export
// both read their arguments out of it, so the download always answers the same
// question as the screen it was started from (lib/log_filter.php).
$filter = log_filter_from_query($_GET);
$tab = $filter['tab'];
$search = $filter['search'];
$ip = $filter['ip'];
$category = $filter['category'];
$tabCategories = VIRTUSPHERE_LOG_TABS[$tab];
$retentionDays = log_retention_days_for_tab($tab);

// CSV list export: read-only GET download of the current tab + filters,
// streams and exits before layout. Ignores pagination on purpose (the export
// is "everything the filter matches", capped) and fetches its rows before the
// audit insert so the download never contains its own audit row.
if (($_GET['export'] ?? '') === 'csv') {
    logs_export_send_csv($connection, $filter, (int) $user['id']);
}

$page = max(1, request_int($_GET, 'page', 1));
$total = repo_count_logs($connection, ...log_filter_repo_args($filter));
$exportBounds = log_filter_export_bounds($total);
$totalPages = max(1, (int) ceil($total / LOGS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * LOGS_PER_PAGE;
$rows = repo_recent_logs($connection, LOGS_PER_PAGE, $offset, ...log_filter_repo_args($filter));

$pageUrl = static fn (int $targetPage): string => log_filter_url($filter, ['page' => $targetPage]);

// Same filter set as $pageUrl, but no page: the export always starts at the
// newest matching row.
$exportUrl = static fn (): string => log_filter_url($filter, ['export' => 'csv']);

// Switching tabs keeps the free-text/IP filters but drops the tab-scoped
// category and resets pagination.
$tabUrl = static fn (string $targetTab): string => log_filter_url(
    [...$filter, 'tab' => $targetTab],
    [],
    false
);

layout_header(__t('logs.title'), $user, 'logs', 'system-status');
?>
<div class="stack">
    <?php // Server-rendered page navigation through the one helper, so the
          // "exactly one aria-current, no role=tab" shape has a single
          // implementation (lib/layout_presenters.php). ?>
    <?php echo portal_page_nav(__t('logs.tabs_label'), array_map(
        static fn (string $tabKey): array => [
            'href' => $tabUrl($tabKey),
            'label' => log_tab_label($tabKey),
            'current' => $tab === $tabKey,
        ],
        $tabKeys
    )); ?>
    <section class="panel">
        <form class="form-grid" method="get" action="logs.php">
            <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
            <label><?php echo h(__t('logs.search')); ?><input name="q" value="<?php echo h($search); ?>" placeholder="<?php echo h(__t('logs.search_placeholder')); ?>"></label>
            <label><?php echo h(__t('logs.ip')); ?><input name="ip" value="<?php echo h($ip); ?>" placeholder="<?php echo h(__t('logs.ip_placeholder')); ?>"></label>
            <label><?php echo h(__t('logs.category')); ?>
                <select name="category">
                    <option value=""><?php echo h(__t('logs.category_all')); ?></option>
                    <?php foreach ($tabCategories as $categoryOption) { ?>
                        <option value="<?php echo h($categoryOption); ?>" <?php echo $category === $categoryOption ? 'selected' : ''; ?>><?php echo h(log_category_label($categoryOption)); ?></option>
                    <?php } ?>
                </select>
            </label>
            <div class="actions">
                <button class="button" type="submit"><?php echo h(__t('logs.apply')); ?></button>
                <a class="button button-secondary" href="<?php echo h($tabUrl($tab)); ?>"><?php echo h(__t('logs.reset')); ?></a>
                <?php if ($rows !== []) { ?><a class="button button-secondary" href="<?php echo h($exportUrl()); ?>"><?php echo h(__t('common.export_csv')); ?></a><?php } ?>
            </div>
        </form>
        <?php if ($rows !== [] && $exportBounds['truncated']) { ?>
            <p class="muted" data-export-truncated="1"><?php echo h(__t('logs.export_truncated_note', [
                'limit' => $exportBounds['limit'],
                'total' => $total,
            ])); ?></p>
        <?php } ?>
    </section>
    <section class="panel">
        <?php // The time column is the configured display timezone while the
              // rows are stored in UTC (ADR-0022). An audit row is read against
              // an incident whose time somebody else noted, so the zone has to
              // be on the page rather than inferred. ?>
        <p class="muted"><?php echo h(__t('logs.retention_note', ['days' => $retentionDays])); ?> <?php echo h(__t('logs.timezone_note', ['tz' => portal_timezone()])); ?></p>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('logs.th_id')); ?></th><th><?php echo h(__t('logs.th_time')); ?></th><th><?php echo h(__t('logs.th_category')); ?></th><th><?php echo h(__t('logs.th_user')); ?></th><th><?php echo h(__t('logs.th_ip')); ?></th><th><?php echo h(__t('logs.th_message')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td><?php echo h((string) $row['id']); ?></td>
                    <td class="nowrap"><?php echo h(portal_format_timestamp($row['created_at'] ?? '')); ?></td>
                    <td><span class="badge <?php echo h(log_category_badge_class((string) ($row['category'] ?? ''))); ?>"><?php echo h(log_category_label((string) ($row['category'] ?? ''))); ?></span></td>
                    <td><?php echo h($row['user_name'] ?? ($row['user_id'] ?? '')); ?></td>
                    <td><?php echo h($row['ip'] ?? ''); ?></td>
                    <td class="log-message"><?php echo h($row['log_message'] ?? ''); ?></td>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?><tr><td colspan="6" class="table-empty"><?php echo h(($search !== '' || $ip !== '' || $category !== '') ? __t('logs.empty_filtered') : __t('logs.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
        <?php if ($totalPages > 1) { ?>
        <nav class="pagination">
            <?php if ($page > 1) { ?>
                <a class="button button-secondary" href="<?php echo h($pageUrl($page - 1)); ?>">&laquo; <?php echo h(__t('logs.page_prev')); ?></a>
            <?php } ?>
            <span class="pagination-info"><?php echo h(__t('logs.page_info', ['page' => $page, 'total' => $totalPages])); ?></span>
            <?php if ($page < $totalPages) { ?>
                <a class="button button-secondary" href="<?php echo h($pageUrl($page + 1)); ?>"><?php echo h(__t('logs.page_next')); ?> &raquo;</a>
            <?php } ?>
        </nav>
        <?php } ?>
    </section>
</div>
<?php layout_footer(); ?>
