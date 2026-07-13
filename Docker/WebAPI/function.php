<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/repo/helpers.php';
require_once __DIR__ . '/lib/repo/log.php';
require_once __DIR__ . '/lib/repo/legacy.php';
require_once __DIR__ . '/lib/repo/status_events.php';
require_once __DIR__ . '/lib/repo/catalog.php';
require_once __DIR__ . '/lib/repo/missions.php';
require_once __DIR__ . '/lib/repo/vms.php';

// Log retention moved to lib/maintenance_worker.php (ADR-0018) so cleanup no
// longer rides on request handling and also runs when no traffic arrives.
