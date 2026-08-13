<?php

declare(strict_types=1);

/**
 * Owner registry for the ESXi inventory repository and service layers.
 *
 * Static contracts must walk these lists instead of one historic source file:
 * both public require paths are facades, while their functions live in domain
 * modules. Paths are relative to Docker/WebAPI and are kept in load order.
 */
const VIRTUSPHERE_ESXI_INVENTORY_REPO_MODULES = [
    'lib/repo/esxi_inventory.php',
    'lib/repo/esxi_inventory_cache.php',
    'lib/repo/esxi_inventory_state.php',
    'lib/repo/esxi_inventory_queries.php',
    'lib/repo/esxi_inventory_vlan.php',
];

const VIRTUSPHERE_ESXI_INVENTORY_SERVICE_MODULES = [
    'lib/esxi_inventory.php',
    'lib/esxi_inventory_scheduler.php',
    'lib/esxi_inventory_deviations.php',
    'lib/esxi_inventory_display.php',
];
