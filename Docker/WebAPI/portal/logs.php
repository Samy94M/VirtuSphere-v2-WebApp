<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/portal_export.php';
require_once __DIR__ . '/../lib/repo/log.php';

$user = portal_require_user($connection);
if (!can('users.manage', $user)) {
    portal_forbid($connection, $user, 'users.manage');
}

const LOGS_PER_PAGE = 50;

$tabKeys = array_keys(VIRTUSPHERE_LOG_TABS);
$tab = request_string($_GET, 'tab');
if (!in_array($tab, $tabKeys, true)) {
    $tab = $tabKeys[0];
}
$tabCategories = VIRTUSPHERE_LOG_TABS[$tab];
$retentionDays = log_retention_days_for_tab($tab);

$search = request_trimmed($_GET, 'q');
$ip = request_trimmed($_GET, 'ip');
// The category sub-filter is scoped to the active tab; anything outside it
// (or the "all" placeholder) means "every category in this tab".
$category = request_trimmed($_GET, 'category');
if (!in_array($category, $tabCategories, true)) {
    $category = '';
}
$activeCategories = $category !== '' ? [$category] : $tabCategories;

// CSV list export: read-only GET download of the current tab + filters,
// streams and exits before layout. Ignores pagination on purpose (the export
// is "everything the filter matches", capped) and fetches its rows before the
// audit insert so the download never contains its own audit row.
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    for ($exportOffset = 0; $exportOffset < VIRTUSPHERE_LOG_EXPORT_MAX_ROWS; $exportOffset += 500) {
        $chunk = repo_recent_logs($connection, 500, $exportOffset, $search, $ip, $activeCategories);
        foreach ($chunk as $row) {
            if (count($csvRows) >= VIRTUSPHERE_LOG_EXPORT_MAX_ROWS) {
                break 2;
            }
            $csvRows[] = [
                (string) ($row['id'] ?? ''),
                portal_format_timestamp((string) ($row['created_at'] ?? '')),
                log_category_label((string) ($row['category'] ?? '')),
                (string) ($row['user_name'] ?? ($row['user_id'] ?? '')),
                (string) ($row['ip'] ?? ''),
                (string) ($row['log_message'] ?? ''),
            ];
        }
        if (count($chunk) < 500) {
            break;
        }
    }
    $header = [
        __t('logs.th_id'), __t('logs.th_time'), __t('logs.th_category'),
        __t('logs.th_user'), __t('logs.th_ip'), __t('logs.th_message'),
    ];
    audit($connection, VIRTUSPHERE_LOG_CATEGORY_SYSTEM, 'exported logs tab ' . $tab . ' as CSV (' . count($csvRows) . ' row(s))', (int) $user['id']);
    portal_send_csv('logs-' . $tab, $header, $csvRows);
}

$page = max(1, request_int($_GET, 'page', 1));
$total = repo_count_logs($connection, $search, $ip, $activeCategories);
$totalPages = max(1, (int) ceil($total / LOGS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * LOGS_PER_PAGE;
$rows = repo_recent_logs($connection, LOGS_PER_PAGE, $offset, $search, $ip, $activeCategories);

$pageUrl = static function (int $targetPage) use ($tab, $search, $ip, $category): string {
    $query = ['tab' => $tab, 'page' => $targetPage];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($ip !== '') {
        $query['ip'] = $ip;
    }
    if ($category !== '') {
        $query['category'] = $category;
    }
    return 'logs.php?' . http_build_query($query);
};

// Same filter set as $pageUrl, but no page: the export always starts at the
// newest matching row.
$exportUrl = static function () use ($tab, $search, $ip, $category): string {
    $query = ['tab' => $tab];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($ip !== '') {
        $query['ip'] = $ip;
    }
    if ($category !== '') {
        $query['category'] = $category;
    }
    $query['export'] = 'csv';
    return 'logs.php?' . http_build_query($query);
};

// Switching tabs keeps the free-text/IP filters but drops the tab-scoped
// category and resets pagination.
$tabUrl = static function (string $targetTab) use ($search, $ip): string {
    $query = ['tab' => $targetTab];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($ip !== '') {
        $query['ip'] = $ip;
    }
    return 'logs.php?' . http_build_query($query);
};

layout_header(__t('logs.title'), $user, 'logs');
?>
<div class="stack">
    <nav class="tab-list" aria-label="<?php echo h(__t('logs.tabs_label')); ?>">
        <?php foreach ($tabKeys as $tabKey) { ?>
            <a class="tab" href="<?php echo h($tabUrl($tabKey)); ?>"<?php echo $tab === $tabKey ? ' aria-current="page"' : ''; ?>><?php echo h(log_tab_label($tabKey)); ?></a>
        <?php } ?>
    </nav>
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
    </section>
    <section class="panel">
        <p class="muted"><?php echo h(__t('logs.retention_note', ['days' => $retentionDays])); ?></p>
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