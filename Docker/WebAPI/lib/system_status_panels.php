<?php

declare(strict_types=1);

// Facade for the heartbeat half of the System status page. It owns only the
// overview strip, which is the one presenter that reads all four sources at
// once; every panel below it belongs to the module of its own data source, and
// the ESXi inventory cards plus the deviation scan live in
// lib/system_status_esxi_panels.php (ADR-0006). The require path of this file
// stays the page's single entry point, so a caller keeps loading one module.
require_once __DIR__ . '/credentials_status.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/system_status_ansible_activity.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_shared_panels.php';
require_once __DIR__ . '/system_status_mecm_panels.php';
require_once __DIR__ . '/system_status_ansible_panels.php';
require_once __DIR__ . '/system_status_internal_panels.php';

/** @param array<string,mixed> $snapshot */
function system_status_render_overview(array $snapshot): void
{
    $cards = [
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM, __t('system_status.overview_mecm'), $snapshot['mecm']['state'], 'heartbeat'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE, __t('system_status.overview_ansible'), $snapshot['ansible']['state'] ?? 'unknown', 'ansible'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI, __t('system_status.overview_esxi'), $snapshot['esxi']['state'] ?? 'unknown', 'esxi'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL, __t('system_status.overview_internal'), $snapshot['internal']['state'], 'heartbeat'],
    ];
    ?>
    <nav class="status-overview" aria-label="<?php echo h(__t('system_status.overview_heading')); ?>">
        <?php foreach ($cards as [$anchor, $label, $state, $kind]) { ?>
            <a class="status-overview-card" href="#<?php echo h((string) $anchor); ?>">
                <span><?php echo h((string) $label); ?></span>
                <?php
                echo match ($kind) {
                    'esxi' => esxi_state_badge((string) $state),
                    'ansible' => ansible_state_badge((string) $state),
                    default => heartbeat_badge((string) $state),
                };
                ?>
            </a>
        <?php } ?>
    </nav>
    <?php
}
