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
require_once __DIR__ . '/system_status_service_panel.php';
require_once __DIR__ . '/system_status_directory_panels.php';

/**
 * The overview strip.
 *
 * The fifth card is the deviation scan. It reads the count the page computed
 * once and handed to the section below as well, so the strip and the section
 * can never disagree about how many there are. The scan is a health signal like
 * the other four: an inventory that pulls perfectly while three missions point
 * at a portgroup that no longer exists is not a green installation, and until
 * now that fact lived only at the bottom of the page.
 *
 * The hrefs go through system_status_url() with the query the page is currently
 * showing. That keeps them same-document (the browser compares everything left
 * of the fragment), so a card is still an in-page jump and a selected ESXi
 * credential survives it, while no anchor is written out by hand.
 *
 * @param array<string,mixed> $snapshot
 * @param array<string,int|string> $query the page's current selection
 */
function system_status_render_overview(array $snapshot, ?int $deviationCount = null, array $query = [], ?array $serviceSnapshot = null, ?array $directoryData = null): void
{
    $cards = [
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE, __t('system_status.overview_deploy_service'), $serviceSnapshot, 'deploy'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM, __t('system_status.overview_mecm'), $snapshot['mecm']['state'], 'heartbeat'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE, __t('system_status.overview_ansible'), $snapshot['ansible']['state'] ?? 'unknown', 'ansible'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI, __t('system_status.overview_esxi'), $snapshot['esxi']['state'] ?? 'unknown', 'esxi'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS, __t('system_status.overview_deviations'), '', 'deviations'],
        [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL, __t('system_status.overview_internal'), $snapshot['internal']['state'], 'heartbeat'],
    ];
    if ($directoryData !== null) {
        $cards[] = [VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DIRECTORY, __t('system_status.overview_directory'), $directoryData['snapshot']['overall'], 'directory'];
    }
    ?>
    <nav class="status-overview" aria-label="<?php echo h(__t('system_status.overview_heading')); ?>">
        <?php foreach ($cards as [$anchor, $label, $state, $kind]) { ?>
            <a class="status-overview-card" href="<?php echo h(system_status_url((string) $anchor, $query)); ?>">
                <span><?php echo h((string) $label); ?></span>
                <?php
                echo match ($kind) {
                    'esxi' => esxi_state_badge((string) $state),
                    'ansible' => ansible_state_badge((string) $state),
                    'deviations' => deviation_count_badge($deviationCount),
                    'deploy' => $state === null
                        ? portal_badge('neutral', __t('system_status.service_unknown'))
                        : portal_badge((string) $state['badge'], deploy_service_summary_label($state)),
                    'directory' => directory_state_badge((string) $state),
                    default => heartbeat_badge((string) $state),
                };
                ?>
            </a>
        <?php } ?>
    </nav>
    <?php
}
