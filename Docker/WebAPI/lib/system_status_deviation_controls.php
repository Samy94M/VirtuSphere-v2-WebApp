<?php

declare(strict_types=1);

require_once __DIR__ . '/system_status_deviation_view.php';

function system_status_render_deviation_evidence(?array $report): void
{
    if ($report === null) {
        return;
    }
    if (!empty($report['has_evidence'])) {
        ?><p class="muted"><?php echo h(__t(!empty($report['fully_current']) ? 'system_status.dev_evidence_current' : (!empty($report['fully_evaluable']) ? 'system_status.dev_evidence_historical' : 'system_status.dev_evidence_partial'))); ?></p><?php
    }
    $labels = [
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER => __t('system_status.inv_th_datacenters'),
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE => __t('system_status.inv_th_datastores'),
        VIRTUSPHERE_INVENTORY_KIND_NETWORK => __t('system_status.inv_th_networks'),
    ];
    $facts = [];
    foreach ((array) ($report['kinds'] ?? []) as $kind => $fact) {
        $state = !empty($fact['current']) ? 'current' : (!empty($fact['qualified']) ? 'historical' : 'unavailable');
        $facts[] = [
            'label' => (string) ($labels[$kind] ?? $kind),
            'html' => h(__t('system_status.dev_evidence_kind_' . $state)) . ' · ' . system_status_fact_time($fact['last_observed_at'] ?? null),
        ];
    }
    if ($facts !== []) {
        echo system_status_fact_list($facts);
    }
}

function system_status_render_deviation_filters(array $view, array $query): void
{
    ?>
    <form class="inline-form" method="get" action="system_status.php">
        <?php if (isset($query['inventory'])) { ?><input type="hidden" name="inventory" value="<?php echo h((string) $query['inventory']); ?>"><?php } ?>
        <label class="filter-field"><?php echo h(__t('system_status.dev_filter_kind')); ?><select name="deviation_kind">
            <?php foreach (VIRTUSPHERE_SYSTEM_STATUS_DEVIATION_FILTERS as $filter) { ?><option value="<?php echo h($filter); ?>"<?php echo $view['filter'] === $filter ? ' selected' : ''; ?>><?php echo h(__t('system_status.dev_filter_' . $filter)); ?></option><?php } ?>
        </select></label>
        <label class="filter-field"><?php echo h(__t('system_status.dev_filter_query')); ?><input name="deviation_q" maxlength="100" value="<?php echo h((string) $view['query']); ?>"></label>
        <button class="button button-secondary" type="submit"><?php echo h(__t('system_status.dev_filter_apply')); ?></button>
    </form>
    <p class="muted"><?php echo h(__t('system_status.dev_filter_result', ['count' => (int) $view['total']])); ?></p>
    <?php
}

function system_status_render_deviation_pagination(array $view, array $query): void
{
    if ((int) $view['pages'] <= 1) {
        return;
    }
    ?>
    <nav class="pagination" aria-label="<?php echo h(__t('system_status.dev_pagination')); ?>">
        <?php if ((int) $view['page'] > 1) { ?><a class="button button-secondary" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS, array_merge($query, ['deviation_page' => (int) $view['page'] - 1]))); ?>"><?php echo h(__t('system_status.dev_previous')); ?></a><?php } ?>
        <span class="pagination-info"><?php echo h(__t('system_status.dev_page_info', ['page' => (int) $view['page'], 'pages' => (int) $view['pages']])); ?></span>
        <?php if ((int) $view['page'] < (int) $view['pages']) { ?><a class="button button-secondary" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS, array_merge($query, ['deviation_page' => (int) $view['page'] + 1]))); ?>"><?php echo h(__t('system_status.dev_next')); ?></a><?php } ?>
    </nav>
    <?php
}
