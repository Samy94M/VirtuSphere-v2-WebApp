<?php

declare(strict_types=1);

/**
 * Compatibility facade for ESXi inventory services (ADR-0023).
 *
 * Scheduling, deviation analysis and display projections live in focused
 * modules. Existing callers keep this public require path.
 */

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_automation.php';
require_once __DIR__ . '/esxi_inventory_options.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/repo/settings.php';
require_once __DIR__ . '/esxi_inventory_scheduler.php';
require_once __DIR__ . '/esxi_inventory_deviations.php';
require_once __DIR__ . '/esxi_inventory_display.php';
