<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/correlation_display.php';
require_once __DIR__ . '/../lib/log_filter.php';
require_once __DIR__ . '/../lib/logs_correlation_panel.php';
require_once __DIR__ . '/../lib/logs_export.php';
require_once __DIR__ . '/../lib/logs_filter_form.php';
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
// both read it, so the download always answers the same question as the screen
// it was started from (lib/log_filter.php).
$filter = log_filter_from_query($_GET);
$tab = $filter['tab'];
$retentionDays = log_retention_days_for_tab($tab);
// A rejected value is not dropped and queried around. Doing that would show a
// WIDER result while the field still displays the value the operator believes
// is filtering, which is the one outcome worse than an error message.
$usable = log_filter_is_usable($filter);

// CSV list export: read-only GET download of the current tab + filters,
// streams and exits before layout. Ignores pagination on purpose (the export
// is "everything the filter matches", capped) and fetches its rows before the
// audit insert so the download never contains its own audit row. A filter the
// page refuses to run must not be exportable either, or the file would answer
// the wide question the screen declined to answer.
if ($usable && ($_GET['export'] ?? '') === 'csv') {
    logs_export_send_csv($connection, $filter, (int) $user['id']);
}

$page = max(1, request_int($_GET, 'page', 1));
$total = $usable ? repo_count_logs($connection, $filter) : 0;
$exportBounds = log_filter_export_bounds($total);
$totalPages = max(1, (int) ceil($total / LOGS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * LOGS_PER_PAGE;
$rows = $usable ? repo_recent_logs($connection, $filter, LOGS_PER_PAGE, $offset) : [];

$pageUrl = static fn (int $targetPage): string => log_filter_url($filter, ['page' => $targetPage]);

// Same filter set as $pageUrl, but no page: the export always starts at the
// newest matching row.
$exportUrl = $rows !== [] ? log_filter_url($filter, ['export' => 'csv']) : null;

// Reset clears every filter and keeps only the tab. Switching tabs does the
// same: a category, an event code or an object type belongs to the section it
// was chosen in, and carrying it into another tab selects nothing while looking
// like a filter.
$tabUrl = static fn (string $targetTab): string => log_filter_url(log_filter_empty($targetTab));

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
        <?php logs_render_filter_form($filter, $tabUrl($tab), $exportUrl); ?>
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
            <thead><tr><th><?php echo h(__t('logs.th_id')); ?></th><th><?php echo h(__t('logs.th_time')); ?></th><th><?php echo h(__t('logs.th_category')); ?></th><th><?php echo h(__t('logs.th_user')); ?></th><th><?php echo h(__t('logs.th_ip')); ?></th><th><?php echo h(__t('logs.th_message')); ?></th><th><?php echo h(__t('logs.th_correlation')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td><?php echo h((string) $row['id']); ?></td>
                    <td class="nowrap"><?php echo h(portal_format_timestamp($row['created_at'] ?? '')); ?></td>
                    <td><span class="badge <?php echo h(log_category_badge_class((string) ($row['category'] ?? ''))); ?>"><?php echo h(log_category_label((string) ($row['category'] ?? ''))); ?></span></td>
                    <td><?php echo h($row['user_name'] ?? ($row['user_id'] ?? '')); ?></td>
                    <td><?php echo h($row['ip'] ?? ''); ?></td>
                    <td class="log-message"><?php echo h($row['log_message'] ?? ''); ?></td>
                    <?php // The trace link is dropped while this view already IS
                          // that trace: a link to the page you are on reads as a
                          // different page. ?>
                    <td class="nowrap"><?php echo portal_correlation_id(
                        (string) ($row['correlation_id'] ?? ''),
                        $filter['correlation'] === '' ? log_filter_correlation_url($tab, (string) ($row['correlation_id'] ?? '')) : ''
                    ); ?></td>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?><tr><td colspan="7" class="table-empty"><?php
                // Three different answers. A refused filter has not been run at
                // all, so saying "nothing found" would report a result the page
                // never obtained.
                echo h(match (true) {
                    !$usable => __t('logs.empty_invalid'),
                    log_filter_is_narrowed($filter) => __t('logs.empty_filtered'),
                    default => __t('logs.empty'),
                });
            ?></td></tr><?php } ?>
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
    <?php logs_render_correlation_jobs($connection, $filter, $user); ?>
</div>
<?php layout_footer(); ?>
