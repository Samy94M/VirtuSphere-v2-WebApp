<?php

declare(strict_types=1);

// Compose healthcheck entrypoint for the two loop workers (AP8): exit 0 while
// the worker's heartbeat file is fresh, 1 otherwise. No DB access - the point
// is to judge the worker process, not its dependencies.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/worker_heartbeat.php';

exit(worker_heartbeat_is_fresh() ? 0 : 1);
