<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/vms.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/repo/log.php';
require_once __DIR__ . '/../lib/repo/client_events.php';
require_once __DIR__ . '/../lib/esxi_inventory.php';
require_once __DIR__ . '/../lib/inventory_field.php';
require_once __DIR__ . '/../lib/vm_edit_form.php';
require_once __DIR__ . '/../lib/mecm_plan.php';
// For the deep link to the ESXi card of a credential that was never pulled.
require_once __DIR__ . '/../lib/system_status.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
require_once __DIR__ . '/../lib/vm_edit_page.php';
/** @var string $title Provided by vm_edit_page.php. */
/** @var bool $isTemplate Provided by vm_edit_page.php. */
layout_header($title, $user, $isTemplate ? 'templates' : 'missions', 'missions');
require __DIR__ . '/../lib/vm_edit_panels.php';
layout_footer();
