<?php

declare(strict_types=1);

// The internal-services half of the System status page: the heartbeat rows of
// the workers plus the shared Ampel legend, which explains all three
// vocabularies the page renders and therefore closes the page (ADR-0006).
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/** @param array<string,mixed> $snapshot */
function system_status_render_internal(array $snapshot, array $user): void
{
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL); ?>"><div class="section-heading-actions"><div><h2><?php echo h(__t('system_status.internal_heading')); ?></h2><p class="muted"><?php echo h(__t('system_status.internal_hint')); ?></p></div><?php if (can('users.manage', $user)) { ?><a class="button button-secondary" href="<?php echo h(log_category_url(VIRTUSPHERE_LOG_CATEGORY_SYSTEM)); ?>"><?php echo h(__t('system_status.internal_logs')); ?></a><?php } ?></div><?php system_status_render_source_rows($snapshot['internal']['rows']); ?></section>
    <?php
    // The page renders three Ampeln with three vocabularies (heartbeat sources,
    // ESXi credentials, Ansible credentials); the legend explains all three, or
    // an operator has to guess which "warning" they are looking at.
    ?>
    <details class="panel status-legend"><summary><?php echo h(__t('system_status.legend_heading')); ?></summary>
        <p><?php echo h(__t('system_status.legend_intro')); ?></p>
        <h3><?php echo h(__t('system_status.legend_group_heartbeat')); ?></h3>
        <?php system_status_legend_items('heartbeat'); ?>
        <h3><?php echo h(__t('system_status.esxi_legend_heading')); ?></h3>
        <?php system_status_legend_items('esxi'); ?>
        <h3><?php echo h(__t('system_status.ansible_legend_heading')); ?></h3>
        <?php system_status_legend_items('ansible'); ?>
    </details>
    <?php
}
