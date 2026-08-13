<?php

declare(strict_types=1);

/**
 * Compatibility facade for the ESXi inventory repository (ADR-0023).
 *
 * Existing callers keep this public require path. Domain modules are loaded in
 * deterministic order; their public function names and behavior are unchanged.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../esxi_datastore_health.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/esxi_inventory_cache.php';
require_once __DIR__ . '/esxi_inventory_state.php';
require_once __DIR__ . '/esxi_inventory_queries.php';
require_once __DIR__ . '/esxi_inventory_vlan.php';
