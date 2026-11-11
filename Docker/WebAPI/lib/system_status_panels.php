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
require_once __DIR__ . '/system_status_backup_panels.php';
require_once __DIR__ . '/system_status_service_panel.php';
require_once __DIR__ . '/system_status_directory_panels.php';
require_once __DIR__ . '/system_status_overview.php';

/**
 * The headline and the overview strip.
 *
 * The cards come from system_status_overview_cards() in section order and carry
 * the tone their badge renders; the headline over them reads the same tones
 * (lib/system_status_overview.php). The deviation card reads the count the page
 * computed once and handed to the section below as well, so the strip and the
 * section can never disagree about how many there are.
 *
 * The hrefs go through system_status_url() with the query the page is currently
 * showing. That keeps them same-document (the browser compares everything left
 * of the fragment), so a card is still an in-page jump and a selected ESXi
 * credential survives it, while no anchor is written out by hand.
 *
 * @param array<string,mixed> $snapshot
 * @param array<string,int|string> $query the page's current selection
 * @param array<string, array<string,mixed>|null>|null $hostData from system_status_backup_panel_data()
 * @param array<string,mixed>|null $deviationReport the report the count came from
 */
function system_status_render_overview(array $snapshot, ?int $deviationCount = null, array $query = [], ?array $serviceSnapshot = null, ?array $directoryData = null, ?array $hostData = null, ?array $deviationReport = null): void
{
    $cards = system_status_overview_cards($snapshot, $deviationCount, $serviceSnapshot, $directoryData, $hostData, $deviationReport);
    // Three cards per row on desktop. A count that would leave a single card
    // alone in the last row (seven: 3 + 3 + 1) switches to four columns (4 + 3)
    // instead. It is derived from the same list the strip renders, so the
    // optional directory card cannot put the grid out of step with its cards.
    // The list always holds the seven fixed cards, so no lower bound is needed.
    $overviewModifier = count($cards) % 3 === 1 ? ' status-overview-four' : '';
    echo system_status_render_overview_headline($cards, $query);
    ?>
    <nav class="status-overview<?php echo $overviewModifier; ?>" aria-label="<?php echo h(__t('system_status.overview_heading')); ?>">
        <?php foreach ($cards as $card) { ?>
            <a class="status-overview-card" href="<?php echo h(system_status_url($card['anchor'], $query)); ?>" data-overview-tone="<?php echo h($card['tone']); ?>">
                <span class="status-overview-label"><?php echo h($card['label']); ?></span>
                <?php echo $card['html']; ?>
            </a>
        <?php } ?>
    </nav>
    <?php
}
