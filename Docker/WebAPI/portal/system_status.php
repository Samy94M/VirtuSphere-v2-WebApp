<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/integration_health.php';
require_once __DIR__ . '/../lib/system_status_page.php';
require_once __DIR__ . '/../lib/system_status_panels.php';
require_once __DIR__ . '/../lib/system_status_esxi_panels.php';
require_once __DIR__ . '/../lib/repo/catalog.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    system_status_handle_post($connection, $user);
}

$snapshot = integration_health_snapshot($connection);

// A detail query is accepted only for an ESXi credential already present in
// the snapshot. Invalid, deleted and type-confused IDs collapse to no selection
// without revealing whether another credential exists under that number.
$selectedInventoryId = request_int($_GET, 'inventory');
$validEsxiIds = array_map(
    static fn (array $entry): int => (int) $entry['credential']['id'],
    $snapshot['esxi']['rows']
);
if (!in_array($selectedInventoryId, $validEsxiIds, true)) {
    $selectedInventoryId = 0;
}
$selectedInventory = $selectedInventoryId > 0
    ? esxi_inventory_detail($connection, $selectedInventoryId)
    : null;

// Without an inventory there is nothing to compare against, so the scan is
// skipped and the panel says so; an empty result would otherwise be rendered as
// a clean bill of health nobody checked.
$hasInventory = $snapshot['esxi']['rows'] !== [];
$deviations = $hasInventory
    ? esxi_inventory_mission_deviations($connection)
    : [];
$activeVlanNames = array_map(
    static fn (array $vlan): string => (string) $vlan['vlan_name'],
    repo_active_vlans($connection)
);
$reassignFrom = mb_substr(request_trimmed($_GET, 'reassign_from'), 0, 255);
$refreshUrl = $selectedInventoryId > 0
    ? system_status_url('credential-' . $selectedInventoryId, ['inventory' => $selectedInventoryId])
    : 'system_status.php';

layout_header(__t('system_status.title'), $user, 'system-status', 'system-status');
?>
<div class="stack system-status-page">
    <section class="page-intro">
        <div>
            <h1><?php echo h(__t('system_status.heading')); ?></h1>
            <p class="muted"><?php echo h(__t('system_status.hint')); ?></p>
            <p class="status-generated"><?php echo h(__t('system_status.generated_at')); ?>: <time><?php echo h(portal_format_timestamp($snapshot['generated_at'])); ?></time></p>
        </div>
        <div class="actions">
            <a class="button" href="<?php echo h($refreshUrl); ?>"><?php echo h(__t('system_status.refresh_status')); ?></a>
            <a class="button button-secondary" href="help.php#panel-system-status"><?php echo h(__t('common.help')); ?></a>
        </div>
    </section>

    <?php system_status_render_overview($snapshot); ?>
    <?php system_status_render_mecm($snapshot, $user); ?>
    <?php system_status_render_ansible($snapshot, $user); ?>
    <?php system_status_render_esxi($snapshot, $user, $selectedInventoryId, $selectedInventory); ?>
    <?php system_status_render_deviations($deviations, $activeVlanNames, $user, $reassignFrom, $hasInventory); ?>
    <?php system_status_render_internal($snapshot, $user); ?>
</div>
<?php layout_footer(); ?>
